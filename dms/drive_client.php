<?php
/**
 * Google OAuth 2.0 + minimal Drive REST helpers.
 *
 * Use cases (current):
 *   • Keynotes editor — read/write the Revit keynotes.txt in a project's
 *     Drive folder (keynotes_edit.php, keynotes_copy.php)
 *   • Project provisioning — clone the _0TEMPLATE skeleton (drive_provision.php)
 *   • Coverage trainer — import a project's keynote categories (coverage_admin.php)
 *
 * OAuth shape mirrors smtp_oauth.php / xero_client.php. Singleton token
 * storage (Drive_Tokens table, latest row wins). Reuses the existing
 * GOOGLE_OAUTH_CLIENT_ID/SECRET from the SMTP setup — only the new
 * GOOGLE_OAUTH_DRIVE_REDIRECT_URI constant has to be added to config.php
 * and the redirect URI registered in Google Cloud Console.
 *
 * Scope: full 'drive' (read/write across the user's accessible Drive). The
 * keynotes editor needs write access. Because that scope can technically see
 * the WHOLE Drive, every file/folder operation here is confined by
 * assertWithinRoot() to the menu.php-configured root folder
 * (App_Meta dms_drive_root_folder_id) + the template folder, or a descendant —
 * see "Containment" below. It fails closed: no root configured → no Drive access.
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

    // ── Containment state (see assertWithinRoot) ────────────────────────────
    /** @var string[]|null configured root folder ids, resolved once per request */
    private static $allowedRoots = null;
    /** @var array<string,bool> ids confirmed to be within a root (per-request cache) */
    private static $withinCache = [];

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

    // ── Containment ─────────────────────────────────────────────────────────
    // The broad 'drive' scope can see the whole Drive, so we hard-limit every
    // operation to the folder(s) configured in menu.php. allowedRoots() = the
    // DMS root (dms_drive_root_folder_id) + the template (dms_template_folder_id).
    // assertWithinRoot() refuses any id that isn't a root or a descendant of one.

    /**
     * Configured root folder ids (read straight from App_Meta so this doesn't
     * depend on helpers.php being loaded). Cached per request.
     */
    private static function allowedRoots(PDO $pdo): array
    {
        if (self::$allowedRoots !== null) return self::$allowedRoots;
        $roots = [];
        foreach (['dms_drive_root_folder_id', 'dms_template_folder_id'] as $k) {
            try {
                $st = $pdo->prepare("SELECT meta_value FROM App_Meta WHERE meta_key = ? LIMIT 1");
                $st->execute([$k]);
                $v = trim((string)($st->fetchColumn() ?: ''));
            } catch (\Throwable $e) { $v = ''; }
            if ($v !== '') $roots[$v] = true;
        }
        return self::$allowedRoots = array_keys($roots);
    }

    /** Unguarded parents lookup — used ONLY by assertWithinRoot's walk. */
    private static function rawParents(PDO $pdo, string $fileId): array
    {
        $token = self::getAccessToken($pdo);
        $url = self::FILES_API . '/' . rawurlencode($fileId) . '?fields=id,parents&supportsAllDrives=true';
        $meta = self::httpJsonGet($url, $token);
        return is_array($meta['parents'] ?? null) ? $meta['parents'] : [];
    }

    /**
     * HARD BOUNDARY. Refuse any folder/file that isn't a configured root or a
     * descendant of one. Walks the parent chain (bounded, with a cache so a
     * recursive copy or repeated calls don't re-walk). Fails CLOSED: if no root
     * is configured in menu.php, ALL Drive access is blocked.
     */
    public static function assertWithinRoot(PDO $pdo, string $fileId): void
    {
        $fileId = trim($fileId);
        if ($fileId === '') throw new DriveOAuthException('Drive access denied: empty folder/file id.');
        if (isset(self::$withinCache[$fileId])) return;

        $roots = self::allowedRoots($pdo);
        if (empty($roots)) {
            throw new DriveOAuthException(
                'Drive access blocked: no DMS Drive root folder is configured in menu.php '
                . '(DMS auto-provisioning). Set it before using any Drive feature.'
            );
        }
        $rootSet = array_fill_keys($roots, true);

        $cur = $fileId; $path = [];
        for ($i = 0; $i < 25 && $cur !== ''; $i++) {
            if (isset($rootSet[$cur]) || isset(self::$withinCache[$cur])) {
                self::markWithin($path);
                self::markWithin([$fileId]);
                return;
            }
            $path[] = $cur;
            $parents = self::rawParents($pdo, $cur);
            $cur = isset($parents[0]) ? (string)$parents[0] : '';
        }
        throw new DriveOAuthException(
            "Drive access denied: \"$fileId\" is outside the configured DMS root folder "
            . '(set in menu.php). Operations are confined to that folder and its subfolders.'
        );
    }

    /** Mark ids known within a root (verified children / things created under a verified parent). */
    private static function markWithin(array $ids): void
    {
        foreach ($ids as $id) { $id = (string)$id; if ($id !== '') self::$withinCache[$id] = true; }
    }

    // ── Drive REST helpers ──────────────────────────────────────────────
    // Every method below confines itself to the configured root via
    // assertWithinRoot() before touching Drive.

    /**
     * Extract a folder ID from a pasted Drive URL (e.g.
     *   https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUv?usp=sharing
     * → '1AbCdEfGhIjKlMnOpQrStUv'). Also accepts a bare ID. Returns null if
     * the input doesn't look like either. (Pure string parsing — no Drive call,
     * so no containment check; the resulting id is checked when it's used.)
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
        self::assertWithinRoot($pdo, $folderId);
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
                // Required for the connected account's Shared Drives to be
                // visible at all — without these, only My Drive is searched.
                'supportsAllDrives'         => 'true',
                'includeItemsFromAllDrives' => 'true',
            ]),
            $token
        );
        $files = $resp['files'] ?? [];
        foreach ($files as $f) self::markWithin([$f['id'] ?? '']);   // children of a verified folder
        return $files;
    }

    /** Download a file's content as a raw string. */
    public static function getFileContent(PDO $pdo, string $fileId): string
    {
        self::assertWithinRoot($pdo, $fileId);
        $token = self::getAccessToken($pdo);
        $url = self::FILES_API . '/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true';
        return self::httpRawGet($url, $token);
    }

    /** Update an existing file's content. mimeType defaults to text/plain. */
    public static function updateFileContent(PDO $pdo, string $fileId, string $content, string $mimeType = 'text/plain'): void
    {
        self::assertWithinRoot($pdo, $fileId);
        $token = self::getAccessToken($pdo);
        $url = self::UPLOAD_API . '/' . rawurlencode($fileId) . '?uploadType=media&supportsAllDrives=true';
        self::httpUpload($url, 'PATCH', $token, $mimeType, $content);
    }

    /**
     * Create a new file inside a folder with text content. Returns the new
     * file's id. Uses Drive's multipart upload to combine metadata + bytes
     * in one request.
     */
    public static function createTextFile(PDO $pdo, string $folderId, string $name, string $content, string $mimeType = 'text/plain'): string
    {
        self::assertWithinRoot($pdo, $folderId);
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

        $url = self::UPLOAD_API . '?uploadType=multipart&fields=id,name&supportsAllDrives=true';
        $resp = self::httpUpload($url, 'POST', $token, "multipart/related; boundary=$boundary", $body);
        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json['id'])) {
            throw new DriveOAuthException('Drive createTextFile returned: ' . $resp);
        }
        self::markWithin([$json['id']]);
        return (string)$json['id'];
    }

    /** Get a single file's metadata. */
    public static function getFileMeta(PDO $pdo, string $fileId): array
    {
        self::assertWithinRoot($pdo, $fileId);
        $token = self::getAccessToken($pdo);
        $url = self::FILES_API . '/' . rawurlencode($fileId) . '?fields=id,name,mimeType,modifiedTime,size,parents,webViewLink&supportsAllDrives=true';
        return self::httpJsonGet($url, $token);
    }

    /** List all (non-trashed) children of a folder, paged. Returns [{id,name,mimeType,...}]. */
    public static function listFolder(PDO $pdo, string $folderId): array
    {
        self::assertWithinRoot($pdo, $folderId);
        $token = self::getAccessToken($pdo);
        $fEsc = str_replace("'", "\\'", $folderId);
        $q = "'$fEsc' in parents and trashed = false";
        $out = []; $pageToken = '';
        do {
            $params = [
                'q'        => $q,
                'fields'   => 'nextPageToken, files(id,name,mimeType,modifiedTime,size)',
                'pageSize' => 200,
                'supportsAllDrives'         => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if ($pageToken !== '') $params['pageToken'] = $pageToken;
            $resp = self::httpJsonGet(self::FILES_API . '?' . http_build_query($params), $token);
            foreach (($resp['files'] ?? []) as $f) { $out[] = $f; self::markWithin([$f['id'] ?? '']); }
            $pageToken = (string)($resp['nextPageToken'] ?? '');
        } while ($pageToken !== '');
        return $out;
    }

    /** Find a SUBFOLDER by name within a parent. Returns its id, or null. */
    public static function findSubfolder(PDO $pdo, string $parentId, string $name): ?string
    {
        self::assertWithinRoot($pdo, $parentId);
        $token = self::getAccessToken($pdo);
        $pEsc = str_replace("'", "\\'", $parentId);
        $nEsc = str_replace("'", "\\'", $name);
        $q = "name = '$nEsc' and '$pEsc' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $resp = self::httpJsonGet(self::FILES_API . '?' . http_build_query([
            'q' => $q, 'fields' => 'files(id,name)', 'pageSize' => 5,
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ]), $token);
        $files = $resp['files'] ?? [];
        if (empty($files)) return null;
        self::markWithin([$files[0]['id'] ?? '']);
        return (string)$files[0]['id'];
    }

    /** Create a folder under a parent. Returns the new folder id. */
    public static function createFolder(PDO $pdo, string $parentId, string $name): string
    {
        self::assertWithinRoot($pdo, $parentId);
        $token = self::getAccessToken($pdo);
        $metadata = json_encode([
            'name'     => $name,
            'parents'  => [$parentId],
            'mimeType' => 'application/vnd.google-apps.folder',
        ], JSON_UNESCAPED_SLASHES);
        $resp = self::httpUpload(self::FILES_API . '?supportsAllDrives=true&fields=id', 'POST',
                                 $token, 'application/json; charset=UTF-8', $metadata);
        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json['id'])) throw new DriveOAuthException('createFolder returned: ' . $resp);
        self::markWithin([$json['id']]);   // created under a verified parent → within root
        return (string)$json['id'];
    }

    /** Find-or-create a subfolder by name; returns its id. (Both delegates are guarded.) */
    public static function ensureSubfolder(PDO $pdo, string $parentId, string $name): string
    {
        $existing = self::findSubfolder($pdo, $parentId, $name);
        return $existing ?? self::createFolder($pdo, $parentId, $name);
    }

    /** Server-side copy of a file into a new parent (optionally renamed). Returns new id. */
    public static function copyFile(PDO $pdo, string $fileId, string $destParentId, ?string $newName = null): string
    {
        self::assertWithinRoot($pdo, $fileId);
        self::assertWithinRoot($pdo, $destParentId);
        $token = self::getAccessToken($pdo);
        $meta = ['parents' => [$destParentId]];
        if ($newName !== null && $newName !== '') $meta['name'] = $newName;
        $url  = self::FILES_API . '/' . rawurlencode($fileId) . '/copy?supportsAllDrives=true&fields=id';
        $resp = self::httpUpload($url, 'POST', $token, 'application/json; charset=UTF-8',
                                 json_encode($meta, JSON_UNESCAPED_SLASHES));
        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json['id'])) throw new DriveOAuthException('copyFile returned: ' . $resp);
        self::markWithin([$json['id']]);
        return (string)$json['id'];
    }

    /** Rename a file/folder. */
    public static function renameFile(PDO $pdo, string $fileId, string $newName): void
    {
        self::assertWithinRoot($pdo, $fileId);
        $token = self::getAccessToken($pdo);
        $url = self::FILES_API . '/' . rawurlencode($fileId) . '?supportsAllDrives=true';
        self::httpUpload($url, 'PATCH', $token, 'application/json; charset=UTF-8',
                         json_encode(['name' => $newName], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Recursively copy a folder (subfolders recreated, files server-side copied)
     * into destParentId under newName. Returns the new folder id. Drive copies
     * file bytes server-side (no download), so even a large .rvt is cheap.
     */
    public static function copyFolderRecursive(PDO $pdo, string $srcFolderId, string $destParentId, string $newName, int $depth = 0): string
    {
        if ($depth > 15) throw new DriveOAuthException('Template folder nested too deep.');
        self::assertWithinRoot($pdo, $srcFolderId);    // source (template) must be inside a configured root too
        self::assertWithinRoot($pdo, $destParentId);
        $newFolderId = self::createFolder($pdo, $destParentId, $newName);
        foreach (self::listFolder($pdo, $srcFolderId) as $child) {
            $isFolder = (($child['mimeType'] ?? '') === 'application/vnd.google-apps.folder');
            if ($isFolder) {
                self::copyFolderRecursive($pdo, (string)$child['id'], $newFolderId, (string)$child['name'], $depth + 1);
            } else {
                self::copyFile($pdo, (string)$child['id'], $newFolderId, (string)$child['name']);
            }
        }
        return $newFolderId;
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
