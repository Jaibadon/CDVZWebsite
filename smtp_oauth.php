<?php
/**
 * Google OAuth 2.0 helper for Gmail SMTP (XOAUTH2).
 * Loads / refreshes / persists access tokens used by SmtpMailer.
 */

if (!defined('GOOGLE_OAUTH_CLIENT_ID') && file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

class SmtpOAuthException extends \Exception {}

class SmtpOAuth
{
    private const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const SCOPE         = 'https://mail.google.com/';

    public static function isConfigured(): bool
    {
        return defined('GOOGLE_OAUTH_CLIENT_ID') && GOOGLE_OAUTH_CLIENT_ID !== ''
            && GOOGLE_OAUTH_CLIENT_ID !== 'PUT-CLIENT-ID-HERE.apps.googleusercontent.com'
            && defined('GOOGLE_OAUTH_CLIENT_SECRET') && GOOGLE_OAUTH_CLIENT_SECRET !== '';
    }

    public static function isConnected(PDO $pdo): bool
    {
        try {
            return (bool)$pdo->query("SELECT 1 FROM Smtp_Tokens ORDER BY id DESC LIMIT 1")->fetchColumn();
        } catch (Exception $e) { return false; }
    }

    public static function buildAuthorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id'     => GOOGLE_OAUTH_CLIENT_ID,
            'redirect_uri'  => GOOGLE_OAUTH_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',           // force refresh_token issuance
            'login_hint'    => SMTP_OAUTH_USER,     // pre-fills the Google login screen
            'state'         => $state,
        ]);
    }

    public static function exchangeCodeAndPersist(PDO $pdo, string $code, string $connectedBy): array
    {
        $resp = self::httpPost(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => GOOGLE_OAUTH_CLIENT_ID,
            'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_OAUTH_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);
        if (empty($resp['access_token']) || empty($resp['refresh_token'])) {
            throw new SmtpOAuthException('Token exchange failed: ' . json_encode($resp));
        }
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)($resp['expires_in'] ?? 3600));
        // Replace previous token row(s) — singleton-ish design
        $pdo->exec("DELETE FROM Smtp_Tokens");
        $pdo->prepare(
            "INSERT INTO Smtp_Tokens (email, access_token, refresh_token, expires_at, scope, connected_by, connected_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            SMTP_OAUTH_USER,
            $resp['access_token'],
            $resp['refresh_token'],
            $expiresAt,
            $resp['scope'] ?? self::SCOPE,
            $connectedBy,
        ]);
        return ['email' => SMTP_OAUTH_USER];
    }

    /** Returns a fresh access_token, refreshing first if it's about to expire. */
    public static function getAccessToken(PDO $pdo): string
    {
        $row = $pdo->query("SELECT * FROM Smtp_Tokens ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new SmtpOAuthException('Google SMTP not connected — visit menu.php → Connect Google SMTP first.');

        $expiresAt = strtotime($row['expires_at'] . ' UTC');
        if ($expiresAt - time() > 60) return $row['access_token'];   // still fresh

        // Refresh
        $resp = self::httpPost(self::TOKEN_URL, [
            'client_id'     => GOOGLE_OAUTH_CLIENT_ID,
            'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
            'refresh_token' => $row['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]);
        if (empty($resp['access_token'])) {
            throw new SmtpOAuthException('Refresh failed: ' . json_encode($resp));
        }
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)($resp['expires_in'] ?? 3600));
        $pdo->prepare(
            "UPDATE Smtp_Tokens SET access_token = ?, expires_at = ?, last_refresh_at = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([$resp['access_token'], $expiresAt, $row['id']]);
        return $resp['access_token'];
    }

    public static function disconnect(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM Smtp_Tokens");
    }

    public static function authenticatedUser(PDO $pdo): string
    {
        $r = $pdo->query("SELECT email FROM Smtp_Tokens ORDER BY id DESC LIMIT 1")->fetchColumn();
        return $r ?: (defined('SMTP_OAUTH_USER') ? SMTP_OAUTH_USER : '');
    }

    private static function httpPost(string $url, array $form): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) throw new SmtpOAuthException('Token endpoint network error');
        $json = json_decode($raw, true);
        if ($code >= 400) throw new SmtpOAuthException("Token endpoint $code: " . ($json['error_description'] ?? $raw));
        return is_array($json) ? $json : [];
    }
}
