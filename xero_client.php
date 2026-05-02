<?php
// Pull in DB credentials AND XERO_* constants. xero_connect.php and other
// Xero scripts may require this file without first loading db_connect.php,
// so config must be self-loaded here.
if (!defined('XERO_CLIENT_ID') && file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Lightweight Xero API client (no Composer dependency).
 *
 * Handles OAuth 2.0 token storage + auto-refresh, then exposes a few high-
 * level methods used by the rest of the app:
 *   - createInvoice(), updateInvoice(), getInvoice(), getInvoicesByStatus()
 *   - emailInvoice(), getOnlineInvoiceUrl()
 *
 * Tokens live in the Xero_Tokens table (singleton-ish — the latest row
 * wins). Refresh happens automatically when an access token is within
 * 60 seconds of expiry.
 *
 * Errors throw XeroException; callers should catch and surface to the user.
 */

class XeroException extends \Exception {}

class XeroClient
{
    private const TOKEN_URL    = 'https://identity.xero.com/connect/token';
    private const CONNECTIONS  = 'https://api.xero.com/connections';
    private const API_BASE     = 'https://api.xero.com/api.xro/2.0';

    private PDO $pdo;
    private array $token; // [tenant_id, access_token, refresh_token, expires_at, ...]

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->loadToken();
    }

    // ── Token management ───────────────────────────────────────────────

    public static function isConfigured(): bool
    {
        return defined('XERO_CLIENT_ID') && XERO_CLIENT_ID !== ''
            && XERO_CLIENT_ID !== 'PUT-YOUR-CLIENT-ID-HERE'
            && defined('XERO_CLIENT_SECRET') && XERO_CLIENT_SECRET !== '';
    }

    public static function isConnected(PDO $pdo): bool
    {
        try {
            return (bool)$pdo->query("SELECT 1 FROM Xero_Tokens ORDER BY id DESC LIMIT 1")->fetchColumn();
        } catch (Exception $e) { return false; }
    }

    private function loadToken(): void
    {
        $row = $this->pdo->query("SELECT * FROM Xero_Tokens ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new XeroException('Not connected to Xero. Visit Menu → Connect to Xero first.');
        $this->token = $row;
        // Refresh proactively if expiring within 60s
        $expiresAt = strtotime($row['expires_at'] . ' UTC');
        if ($expiresAt - time() < 60) $this->refreshAccessToken();
    }

    public static function buildAuthorizeUrl(string $state): string
    {
        return 'https://login.xero.com/identity/connect/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => XERO_CLIENT_ID,
            'redirect_uri'  => XERO_REDIRECT_URI,
            'scope'         => XERO_SCOPES,
            'state'         => $state,
        ]);
    }

    /**
     * Exchange the authorization code from xero_callback.php for tokens
     * and persist them. Called once during the OAuth flow.
     */
    public static function exchangeCodeAndPersist(PDO $pdo, string $code, string $connectedBy): array
    {
        $resp = self::httpForm(self::TOKEN_URL, [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => XERO_REDIRECT_URI,
        ], [
            'Authorization: Basic ' . base64_encode(XERO_CLIENT_ID . ':' . XERO_CLIENT_SECRET),
        ]);

        if (empty($resp['access_token']) || empty($resp['refresh_token'])) {
            throw new XeroException('Token exchange failed: ' . json_encode($resp));
        }

        // Pick the first connected tenant (CADViz org)
        $conns = self::httpGet(self::CONNECTIONS, $resp['access_token']);
        if (!is_array($conns) || empty($conns)) {
            throw new XeroException('No Xero organisations linked to this connection.');
        }
        $tenant = $conns[0];

        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)($resp['expires_in'] ?? 1800));
        $pdo->prepare(
            "INSERT INTO Xero_Tokens
                (tenant_id, tenant_name, access_token, refresh_token, expires_at, scope, connected_by, connected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $tenant['tenantId'],
            $tenant['tenantName'] ?? '',
            $resp['access_token'],
            $resp['refresh_token'],
            $expiresAt,
            $resp['scope'] ?? '',
            $connectedBy,
        ]);

        return ['tenant_name' => $tenant['tenantName'] ?? $tenant['tenantId']];
    }

    private function refreshAccessToken(): void
    {
        $resp = self::httpForm(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->token['refresh_token'],
        ], [
            'Authorization: Basic ' . base64_encode(XERO_CLIENT_ID . ':' . XERO_CLIENT_SECRET),
        ]);
        if (empty($resp['access_token'])) {
            throw new XeroException('Token refresh failed — Erik needs to reconnect Xero. ' . json_encode($resp));
        }
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)($resp['expires_in'] ?? 1800));
        $this->pdo->prepare(
            "UPDATE Xero_Tokens
                SET access_token = ?, refresh_token = ?, expires_at = ?, last_refresh_at = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([
            $resp['access_token'],
            $resp['refresh_token'] ?? $this->token['refresh_token'],
            $expiresAt,
            $this->token['id'],
        ]);
        $this->token['access_token']  = $resp['access_token'];
        $this->token['refresh_token'] = $resp['refresh_token'] ?? $this->token['refresh_token'];
        $this->token['expires_at']    = $expiresAt;
    }

    public static function disconnect(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM Xero_Tokens");
    }

    public function tenantName(): string { return $this->token['tenant_name'] ?? ''; }

    // ── Invoice operations ─────────────────────────────────────────────

    /**
     * Create or update an invoice. $invoice is the body-shape from the Xero docs
     * (Type / Contact / LineItems / etc). Returns the Xero invoice object.
     */
    public function postInvoice(array $invoice): array
    {
        $body = ['Invoices' => [$invoice]];
        $resp = $this->apiCall('POST', '/Invoices?summarizeErrors=false', $body);
        $row = $resp['Invoices'][0] ?? null;
        if (!$row) throw new XeroException('Xero did not return an invoice in the response.');
        if (!empty($row['ValidationErrors'])) {
            $msgs = array_column($row['ValidationErrors'], 'Message');
            throw new XeroException('Xero rejected: ' . implode(' · ', $msgs));
        }
        return $row;
    }

    public function getInvoicesByStatus(array $statuses, ?string $modifiedAfter = null): array
    {
        $qs = ['Statuses' => implode(',', $statuses), 'page' => 1];
        $extra = $modifiedAfter ? ['If-Modified-Since: ' . $modifiedAfter] : [];
        $resp = $this->apiCall('GET', '/Invoices?' . http_build_query($qs), null, $extra);
        return $resp['Invoices'] ?? [];
    }

    public function getInvoice(string $xeroId): array
    {
        $resp = $this->apiCall('GET', '/Invoices/' . urlencode($xeroId));
        return $resp['Invoices'][0] ?? [];
    }

    public function emailInvoice(string $xeroId): void
    {
        // 204 on success; apiCall returns [] for 204
        $this->apiCall('POST', '/Invoices/' . urlencode($xeroId) . '/Email', new stdClass());
    }

    public function getOnlineInvoiceUrl(string $xeroId): ?string
    {
        $resp = $this->apiCall('GET', '/Invoices/' . urlencode($xeroId) . '/OnlineInvoice');
        return $resp['OnlineInvoices'][0]['OnlineInvoiceUrl'] ?? null;
    }

    /**
     * Fetch the rendered invoice PDF from Xero. Returns raw bytes.
     * Used by xero_invoice_email.php so we can send it ourselves from
     * accounts@cadviz.co.nz instead of using Xero's email feature
     * (which always sends from a Xero-controlled From: address).
     */
    public function getInvoicePdf(string $xeroId): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::API_BASE . '/Invoices/' . urlencode($xeroId),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token['access_token'],
                'Xero-Tenant-Id: '       . $this->token['tenant_id'],
                'Accept: application/pdf',
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 401) {  // refresh + retry
            $this->refreshAccessToken();
            return $this->getInvoicePdf($xeroId);
        }
        if ($code >= 400 || !$raw) throw new XeroException("Xero PDF fetch failed: $code");
        return $raw;
    }

    /**
     * Mark a Xero invoice as "sent to contact" — flips the Sent indicator
     * in the Xero UI without actually sending anything from Xero. We call
     * this after we email the PDF ourselves, so Xero stays in sync.
     */
    public function markSentToContact(string $xeroId): void
    {
        $this->apiCall('POST', '/Invoices/' . urlencode($xeroId), [
            'InvoiceID' => $xeroId,
            'SentToContact' => true,
        ]);
    }

    /**
     * Look up (or create) a Xero contact for the given client name.
     * Returns the ContactID GUID. We avoid creating duplicates by searching
     * Name first.
     */
    public function ensureContact(string $name, ?string $email = null): string
    {
        $where = 'Name=="' . str_replace('"', '\"', $name) . '"';
        $resp  = $this->apiCall('GET', '/Contacts?' . http_build_query(['where' => $where]));
        $list  = $resp['Contacts'] ?? [];
        if (!empty($list)) return $list[0]['ContactID'];

        // Create a new one
        $body = ['Contacts' => [array_filter([
            'Name'         => $name,
            'EmailAddress' => $email,
        ])]];
        $resp = $this->apiCall('POST', '/Contacts', $body);
        $c = $resp['Contacts'][0] ?? null;
        if (!$c || empty($c['ContactID'])) throw new XeroException('Could not create Xero contact "' . $name . '"');
        return $c['ContactID'];
    }

    // ── HTTP plumbing ──────────────────────────────────────────────────

    private function apiCall(string $method, string $path, $body = null, array $extraHeaders = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::API_BASE . $path,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => array_merge([
                'Authorization: Bearer ' . $this->token['access_token'],
                'Xero-Tenant-Id: '       . $this->token['tenant_id'],
                'Accept: application/json',
                'Content-Type: application/json',
            ], $extraHeaders),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new XeroException("Xero $method $path: $err");
        if ($code === 204) return [];
        $json = json_decode($raw, true);
        if ($code >= 400) {
            $msg = $json['Detail'] ?? $json['Message'] ?? $raw;
            // Common: 401 means token invalid — try one refresh + retry
            if ($code === 401 && empty($extraHeaders['_retried'])) {
                $this->refreshAccessToken();
                $extraHeaders['_retried'] = true;
                return $this->apiCall($method, $path, $body, $extraHeaders);
            }
            throw new XeroException("Xero API $code on $path: $msg");
        }
        return is_array($json) ? $json : [];
    }

    private static function httpForm(string $url, array $form, array $headers = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => array_merge([
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ], $headers),
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new XeroException("Xero token endpoint: $err");
        $json = json_decode($raw, true);
        if ($code >= 400) {
            throw new XeroException("Xero token endpoint $code: " . ($json['error_description'] ?? $raw));
        }
        return is_array($json) ? $json : [];
    }

    private static function httpGet(string $url, string $accessToken): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) throw new XeroException("Xero GET $url: $code $raw");
        return json_decode($raw, true) ?? [];
    }
}
