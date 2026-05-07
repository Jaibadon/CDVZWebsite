<?php
/**
 * Akahu API client — replaces XeroClient for the payments side.
 *
 * Akahu's auth model is simpler than Xero's: there's no OAuth refresh
 * loop. You get two tokens from https://genie.akahu.io for personal /
 * Genie use (or app-token + per-user user-token from Akahu's developer
 * console for production multi-tenant use). We send both on every
 * request:
 *
 *   X-Akahu-Id: <App Token>
 *   Authorization: Bearer <User Token>
 *
 * Tokens live in Akahu_Tokens (singleton row id=1) and don't expire,
 * which means there's no callback flow and no token refresh — admins
 * just paste them once via akahu_connect.php.
 *
 * Reference docs: https://developers.akahu.nz/reference
 */

require_once __DIR__ . '/db_connect.php';

class AkahuClient
{
    const BASE = 'https://api.akahu.io/v1';

    private string $appToken;
    private string $userToken;
    private PDO    $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $row = $pdo->query("SELECT app_token, user_token FROM Akahu_Tokens WHERE id = 1")->fetch();
        if (!$row || empty($row['app_token']) || empty($row['user_token'])) {
            throw new RuntimeException('Akahu not connected. Visit /akahu_connect.php to add tokens.');
        }
        $this->appToken  = (string)$row['app_token'];
        $this->userToken = (string)$row['user_token'];
    }

    /** Test the connection. Returns the user record on success. */
    public function me(): array
    {
        return $this->get('/me');
    }

    /** GET /accounts — list accounts the user has connected via Akahu. */
    public function accounts(): array
    {
        $r = $this->get('/accounts');
        return $r['items'] ?? [];
    }

    /**
     * GET /transactions — paginated. Use $cursor to pick up where the last
     * sync left off. Akahu returns up to 1000 per page; pass `start`/`end`
     * (ISO 8601) to bound the window.
     *
     * Returns ['items' => [...], 'cursor' => 'next_cursor_string_or_null'].
     */
    public function transactions(?string $start = null, ?string $end = null, ?string $cursor = null): array
    {
        $qs = [];
        if ($start  !== null) $qs['start']  = $start;
        if ($end    !== null) $qs['end']    = $end;
        if ($cursor !== null) $qs['cursor'] = $cursor;
        $path = '/transactions' . ($qs ? '?' . http_build_query($qs) : '');
        $r = $this->get($path);
        return [
            'items'  => $r['items']  ?? [],
            'cursor' => $r['cursor']['next'] ?? null,
        ];
    }

    /**
     * Same shape but scoped to one account. Useful when the user has
     * multiple accounts connected and we only care about the receivables
     * one (Bank_Accounts.is_default).
     */
    public function transactionsForAccount(string $accountId, ?string $start = null, ?string $end = null, ?string $cursor = null): array
    {
        $qs = [];
        if ($start  !== null) $qs['start']  = $start;
        if ($end    !== null) $qs['end']    = $end;
        if ($cursor !== null) $qs['cursor'] = $cursor;
        $path = '/accounts/' . rawurlencode($accountId) . '/transactions'
              . ($qs ? '?' . http_build_query($qs) : '');
        $r = $this->get($path);
        return [
            'items'  => $r['items']  ?? [],
            'cursor' => $r['cursor']['next'] ?? null,
        ];
    }

    // ── Static helpers (mirror the XeroClient::isConfigured/isConnected shape) ──
    public static function isConfigured(): bool
    {
        try {
            $pdo = get_db();
            $pdo->query("SHOW TABLES LIKE 'Akahu_Tokens'")->fetch();
            return true;
        } catch (Exception $e) { return false; }
    }

    public static function isConnected(PDO $pdo): bool
    {
        try {
            $r = $pdo->query("SELECT app_token, user_token FROM Akahu_Tokens WHERE id = 1")->fetch();
            return $r && !empty($r['app_token']) && !empty($r['user_token']);
        } catch (Exception $e) { return false; }
    }

    public static function disconnect(PDO $pdo): void
    {
        try { $pdo->exec("DELETE FROM Akahu_Tokens WHERE id = 1"); } catch (Exception $e) {}
    }

    // ── Internals ────────────────────────────────────────────────────────
    private function get(string $path): array
    {
        $url = self::BASE . $path;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'X-Akahu-Id: '   . $this->appToken,
                'Authorization: Bearer ' . $this->userToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Akahu HTTP error ($url): $err");
        }
        $j = json_decode((string)$body, true);
        if (!is_array($j)) {
            throw new RuntimeException("Akahu non-JSON response (HTTP $code): " . substr((string)$body, 0, 200));
        }
        if ($code >= 400 || ($j['success'] ?? true) === false) {
            $msg = $j['message'] ?? ('HTTP ' . $code);
            throw new RuntimeException("Akahu API error: $msg (HTTP $code)");
        }
        return $j;
    }
}
