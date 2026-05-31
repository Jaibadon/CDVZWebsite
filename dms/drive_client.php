<?php
/**
 * Google OAuth 2.0 + minimal Drive REST helpers.
 *
 * Use cases (current):
 *   • Keynotes editor — read/write the Revit keynotes.txt in a project's
 *     Drive folder (keynotes_edit.php, keynotes_copy.php)
 *
 * Use cases (planned):
 *   • PDF proxying for magic-link stakeholder reviews
 *   • Legacy-project activate-DMS folder enumeration
 *
 * OAuth shape mirrors smtp_oauth.php / xero_client.php. Singleton token
 * storage (Drive_Tokens table, latest row wins). Reuses the existing
 * GOOGLE_OAUTH_CLIENT_ID/SECRET from the SMTP setup — only the new
 * GOOGLE_OAUTH_DRIVE_REDIRECT_URI constant has to be added to config.php
 * and the redirect URI registered in Google Cloud Console.
 *
 * Scope: full 'drive' (read/write across the user's accessible Drive).
 * The keynotes editor needs write access. The connecting Workspace user
 * needs Drive access to whichever folders project files live under (true
 * by default for files in Workspace shared drives).
 */

if (!defined('GOOGLE_OAUTH_CLIENT_ID') && file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

class DriveOAuthException extends \Exception {}

class DriveClient
{
    private const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const USERINFO_URL  = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const FILES_API     = 'https://www.googleapis.com/drive/v3/files';
    private const UPLOAD_API    = 'https://www.googleapis.com/upload/drive/v3/files';
    private const SCOPE         = 'https://www.googleapis.com/auth/drive openid email';

    public static function isConfigured(): bool
    {
        return defined('GOOGLE_OAUTH_CLIENT_ID') && GOOGLE_OAUTH_CLIENT_ID !== ''
            && GOOGLE_OAUTH_CLIENT_ID !== 'PUT-CLIENT-ID-HERE.apps.googleusercontent.com'
            && defined('GOOGLE_OAUTH_CLIENT_SECRET') && GOOGLE_OAUTH_CLIENT_SECRET !== ''
            && defined('GOOGLE_OAUTH_DRIVE_REDIRECT_URI') && GOOGLE_OAUTH_DRIVE_REDIRECT_URI !== '';
    }

    public static function isConnected(PDO $pdo): bool
    {
        try {
            return (bool)$pdo->query("SELECT 1 FROM Drive_Tokens ORDER BY id DESC LIMIT 1")->fetchColumn();
        } catch (Exception $e) { return false; }
    }

    public static function buildAuthorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id'     => GOOGLE_OAUTH_CLIENT_ID,
            'redirect_uri'  => GOOGLE_OAUTH_DRIVE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    public static function exchangeCodeAndPersist(PDO $pdo, string $code, string $connectedBy): array
    {
        $resp = self::httpForm(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => GOOGLE_OAUTH_CLIENT_ID,
            'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_OAUTH_DRIVE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);
        if (empty($resp['access_token']) || empty($resp['refresh_token'])) {
            throw new DriveOAuthException('Token exchange failed: ' . json_encode($resp));
        }
        $accessToken = $resp['access_token'];

        $email = '';
        try {
            $u = self::httpJsonGet(self::USERINFO_URL, $accessToken);
            $email = (string)($u['email'] ?? '');
        } catch (Exception $e) { /* non-fatal */ }

        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)($resp['expires_in'] ?? 3600));
        $pdo->exec("DELETE FROM Drive_Tokens");
        $pdo->prepare(
            "INSERT INTO Drive_Tokens (email, access_token, refresh_token, expires_at, scope, connected_by, connected_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $email,
            $accessToken,
            $resp['refresh_token'],
            $expiresAt,
            $resp['scope'] ?? self::SCOPE,
            $connectedBy,
        ]);
        return ['email' => $email];
    }

    /** Returns a fresh access_token, refreshing if it's about to expire. */
    public static function getAccessToken(PDO $pdo): string
    {
        $row = $pdo->query("SELECT * FROM Drive_Tokens ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DriveOAuthException('Google Drive not connected — visit menu.php → Connect Google Drive.');

        $expiresAt = strtotime($row['expires_at'] . ' UTC');
        if ($expiresAt - time() > 60) return $row['access_token'];   // still fresh

        $resp = self::httpForm(self::TOKEN_URL, [
            'client_id'     => GOOGLE_OAUTH_CLIENT_ID,
            'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
            'refresh_token' => $row['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]);
        if (empty($resp['access_token'])) {
            throw new DriveOAuthException('Refresh failed: ' . json_encode($resp));
        }
        $newExpires = gmdate('Y-m-d H:i:s', time() + (int)($resp['expires_in'] ?? 3600));
        $pdo->prepare(
            "UPDATE Drive_Tokens SET access_token = ?, expires_at = ?, last_refresh_at = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([$resp['access_token'], $newExpires, $row['id']]);
        return $resp['access_token'];
    }

    public static function disconnect(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM Drive_Tokens");
    }

    public static function authenticatedUser(PDO $pdo): string
    {
        $r = $pdo->query("SELECT email FROM Drive_Tokens ORDER BY id DESC LIMIT 1")->fetchColumn();
        return $r ?: '';
    }

    // ── Drive REST helpers ──────────────────────────────────────────────

    /**
     * Extract a folder ID from a pasted Drive URL (e.g.
     *   https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUv?usp=sharing
     * → '1AbCdEfGhIjKlMnOpQrStUv'). Also accepts a bare ID. Returns null if
     * the input doesn't look like either.
     */
    public static function extractFolderId(string $input): ?string
    {
        $s = trim($input);
        if ($s === '') return null;
        // Drive IDs are alphanumeric + - + _, typically 25-44 chars.
        if (preg_match('#/folders/([A-Za-z0-9_\-]{10,})#', $s, $m)) return $m[1];
        if (preg_match('#[?&]id=([A-Za-z0-9_\-]{10,})#', $s, $m))   return $m[1];
        if (preg_match('#^[A-Za-z0-9_\-]{10,}$#', $s))               return $s;
        return null;
    }

    /**
     * Find files in a folder matching a name. Returns array of
     * [{id, name, mimeType, modifiedTime}, ...]. Empty array if none.
     */
    public static function findFilesInFolder(PDO $pdo, string $folderId, string $name): array
    {
        $token = self::getAccessToken($pdo);
        // Drive's `q` param needs single-quote-escaping on values
        $folderIdEsc = str_replace("'", "\\'", $folderId);
        $nameEsc     = str_replace("'", "\\'", $name);
        $q = "name = '$nameEsc' and '$folderIdEsc' in parents and trashed = false";
        $resp = self::httpJsonGet(
            self::FILES_API . '?' . http_build_query([
                'q'      => $q,
                'fields' => 'files(id,name,mimeType,modifiedTime,size)',
                'pageSize' => 50,
            ]),
            $token
        );
        return $resp['files'] ?? [];
    }

    /** Download a file's content as a raw string. */
    public static function getFileContent(PDO $pdo, string $fileId): string
    {
        $token = self::getAccessToken($pdo);
        $url = self::FILES_API . '/' . rawurlencode($fileId) . '?alt=media';
        return self::httpRawGet($url, $token);
    }

    /** Update an existing file's content. mimeType defaults to text/plain. */
    public static function updateFileContent(PDO $pdo, string $fileId, string $content, string $mimeType = 'text/plain'): void
    {
        $token = self::getAccessToken($pdo);
        $url = self::UPLOAD_API . '/' . rawurlencode($fileId) . '?uploadType=media';
        self::httpUpload($url, 'PATCH', $token, $mimeType, $content);
    }

    /**
     * Create a new file inside a folder with text content. Returns the new
     * file's id. Uses Drive's multipart upload to combine metadata + bytes
     * in one request.
     */
    public static function createTextFile(PDO $pdo, string $folderId, string $name, string $content, string $mimeType = 'text/plain'): string
    {
        $token = self::getAccessToken($pdo);
        $boundary = 'cadvizboundary' . bin2hex(random_bytes(8));
        $metadata = json_encode([
            'name'    => $name,
            'parents' => [$folderId],
            'mimeType' => $mimeType,
        ], JSON_UNESCAPED_SLASHES);

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: $mimeType\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= "--$boundary--";

        $url = self::UPLOAD_API . '?uploadType=multipart&fields=id,name';
        $resp = self::httpUpload($url, 'POST', $token, "multipart/related; boundary=$boundary", $body);
        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json['id'])) {
            throw new DriveOAuthException('Drive createTextFile returned: ' . $resp);
        }
        return (string)$json['id'];
    }

    /** Get a single file's metadata. */
    public static function getFileMeta(PDO $pdo, string $fileId): array
    {
        $token = self::getAccessToken($pdo);
        $url = self::FILES_API . '/' . rawurlencode($fileId) . '?fields=id,name,mimeType,modifiedTime,size,parents,webViewLink';
        return self::httpJsonGet($url, $token);
    }

    // ── HTTP helpers ────────────────────────────────────────────────────

    private static function httpForm(string $url, array $form): array
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
        if ($raw === false) throw new DriveOAuthException("Drive endpoint network error: $url");
        $json = json_decode($raw, true);
        if ($code >= 400) throw new DriveOAuthException("Drive $code: " . ($json['error']['message'] ?? $raw));
        return is_array($json) ? $json : [];
    }

    private static function httpJsonGet(string $url, string $accessToken): array
    {
        $raw = self::httpRawGet($url, $accessToken);
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    private static function httpRawGet(string $url, string $accessToken): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) throw new DriveOAuthException("Drive GET network error: $url");
        if ($code >= 400) {
            $msg = $raw;
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['error']['message'])) $msg = $j['error']['message'];
            throw new DriveOAuthException("Drive GET $code: $msg");
        }
        return $raw;
    }

    private static function httpUpload(string $url, string $method, string $accessToken, string $contentType, string $body): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: ' . $contentType,
                'Content-Length: ' . strlen($body),
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) throw new DriveOAuthException("Drive upload network error: $url");
        if ($code >= 400) {
            $msg = $raw;
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['error']['message'])) $msg = $j['error']['message'];
            throw new DriveOAuthException("Drive upload $code: $msg");
        }
        return $raw;
    }
}
