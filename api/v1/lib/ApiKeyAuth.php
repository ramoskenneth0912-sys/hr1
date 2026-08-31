<?php
/**
 * API Key authentication for system-to-system communication.
 *
 * Keys use the format: hr1_<base64url(48 bytes)> = 64 chars total.
 * Only SHA-256 hashes are stored in the database.
 * Authentication is via the X-API-Key HTTP header.
 *
 * This class is independent of user-based Bearer token authentication.
 * An API key identifies a trusted application/service, NOT a user.
 * Authorization (what the key can do) is determined by its scopes.
 */

declare(strict_types=1);

class ApiKeyAuth
{
    private const KEY_PREFIX = 'hr1_';
    private const KEY_RANDOM_BYTES = 48;
    private const RATE_LIMIT_KEY = 'apikey_fail:';
    private const RATE_LIMIT_MAX = 10;
    private const RATE_LIMIT_WINDOW = 900; // 15 minutes

    /** All valid scope values in the system. */
    public const VALID_SCOPES = [
        'applicants:read',
        'applicants:write',
        'jobs:read',
        'jobs:write',
        'employees:read',
        'employees:write',
        'users:read',
        'users:write',
        'departments:read',
        'admin:read',
        'admin:write',
    ];

    /** Maps endpoint resources to their required read scope. */
    private const READ_SCOPES = [
        'jobs'          => 'jobs:read',
        'applications'  => 'applicants:read',
        'users'         => 'users:read',
        'departments'   => 'departments:read',
        'admin'         => 'admin:read',
    ];

    /** Maps endpoint resources to their required write scope. */
    private const WRITE_SCOPES = [
        'jobs'          => 'jobs:write',
        'applications'  => 'applicants:write',
        'users'         => 'users:write',
        'admin'         => 'admin:write',
    ];

    /** Resolved API key record from the database. */
    private static ?array $keyRecord = null;

    /** Scopes granted to the current API key. */
    private static array $keyScopes = [];

    // ---- Key generation ----------------------------------------------------

    /**
     * Generate a new API key pair.
     * Returns ['key' => 'hr1_...', 'key_hash' => '...', 'key_prefix' => 'hr1_...'].
     * The plaintext key must be shown to the user ONCE and never stored.
     */
    public static function generateKey(): array
    {
        $random = random_bytes(self::KEY_RANDOM_BYTES);
        $key = self::KEY_PREFIX . rtrim(strtr(base64_encode($random), '+/', '-_'), '=');
        $keyHash = hash('sha256', $key);
        $keyPrefix = substr($key, 0, 12) . '****';

        return [
            'key'        => $key,
            'key_hash'   => $keyHash,
            'key_prefix' => $keyPrefix,
        ];
    }

    // ---- Request authentication --------------------------------------------

    /**
     * Extract the API key from the X-API-Key header.
     * Returns the raw key string or empty string if not present.
     */
    public static function keyFromHeader(): string
    {
        $key = $_SERVER['HTTP_X_API_KEY'] ?? '';

        if ($key === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, 'X-API-Key') === 0) {
                    $key = trim((string) $v);
                    break;
                }
            }
        }

        return $key;
    }

    /**
     * Validate format: must start with "hr1_" and be 64 characters total.
     */
    public static function isValidFormat(string $key): bool
    {
        return preg_match('/^hr1_[A-Za-z0-9_-]{56,64}$/', $key) === 1
            && strlen($key) <= 76; // reasonable max
    }

    /**
     * Attempt to authenticate using the X-API-Key header.
     * Returns true if authenticated, false otherwise.
     * On failure, logs to audit and applies rate limiting.
     */
    public static function authenticate(): bool
    {
        $raw = self::keyFromHeader();

        if ($raw === '') {
            return false; // no API key provided — not an error, just not API key auth
        }

        if (!self::isValidFormat($raw)) {
            self::logAudit(null, '', 'invalid_format', 'failure', 'Invalid key format');
            return false;
        }

        $keyHash = hash('sha256', $raw);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';

        // Rate limit repeated failures for this IP
        if (!RateLimit::attempt(self::RATE_LIMIT_KEY . $ip, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
            self::logAudit(null, substr($raw, 0, 12), 'rate_limited', 'failure', 'Too many failed attempts');
            Response::tooManyRequests('Too many API key authentication attempts. Try again later.');
        }

        // Look up the key
        $stmt = db()->prepare(
            'SELECT id, name, key_prefix, scopes, is_active, expires_at, revoked_at, last_used_at
             FROM api_keys
             WHERE key_hash = :h
             LIMIT 1'
        );
        $stmt->execute([':h' => $keyHash]);
        $keyRecord = $stmt->fetch();

        if (!$keyRecord) {
            self::logAudit(null, substr($raw, 0, 12), 'auth', 'failure', 'Key not found');
            return false;
        }

        // Check revoked
        if ($keyRecord['revoked_at'] !== null) {
            self::logAudit((int) $keyRecord['id'], $keyRecord['key_prefix'], 'auth', 'failure', 'Key revoked');
            return false;
        }

        // Check active
        if (!$keyRecord['is_active']) {
            self::logAudit((int) $keyRecord['id'], $keyRecord['key_prefix'], 'auth', 'failure', 'Key inactive');
            return false;
        }

        // Check expiry
        if ($keyRecord['expires_at'] !== null && strtotime($keyRecord['expires_at']) < time()) {
            self::logAudit((int) $keyRecord['id'], $keyRecord['key_prefix'], 'auth', 'failure', 'Key expired');
            return false;
        }

        // Success — store the resolved key record
        self::$keyRecord = $keyRecord;
        self::$keyScopes = json_decode($keyRecord['scopes'], true) ?: [];

        // Update last_used_at (fire-and-forget, non-critical)
        db()->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = :id')
            ->execute([':id' => (int) $keyRecord['id']]);

        self::logAudit((int) $keyRecord['id'], $keyRecord['key_prefix'], 'auth', 'success');

        return true;
    }

    // ---- Scope checking ----------------------------------------------------

    /**
     * Check whether the current API key has a specific scope.
     * Returns false if not authenticated via API key or scope missing.
     */
    public static function hasScope(string $scope): bool
    {
        return in_array($scope, self::$keyScopes, true);
    }

    /**
     * Require a specific scope; returns 403 if missing.
     */
    public static function requireScope(string $scope): void
    {
        if (self::$keyRecord === null) {
            return; // not API key auth — let Bearer/session auth handle it
        }
        if (!self::hasScope($scope)) {
            Response::forbidden('API key lacks required scope: ' . $scope . '.');
        }
    }

    /**
     * Determine the required scope for a given resource and method.
     */
    public static function requiredScopeFor(string $resource, string $method): ?string
    {
        $writeMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        if (in_array($method, $writeMethods, true)) {
            return self::WRITE_SCOPES[$resource] ?? null;
        }
        return self::READ_SCOPES[$resource] ?? null;
    }

    // ---- Key state accessors -----------------------------------------------

    public static function keyRecord(): ?array
    {
        return self::$keyRecord;
    }

    public static function keyId(): ?int
    {
        return self::$keyRecord['id'] ?? null;
    }

    public static function keyName(): ?string
    {
        return self::$keyRecord['name'] ?? null;
    }

    public static function isAuthenticated(): bool
    {
        return self::$keyRecord !== null;
    }

    public static function scopes(): array
    {
        return self::$keyScopes;
    }

    // ---- Key management (admin only) ---------------------------------------

    /**
     * Create a new API key. Returns the key record (including plaintext ONCE).
     * The plaintext key is in the returned array and must be shown to the user.
     */
    public static function createKey(string $name, array $scopes, int $createdBy, ?string $expiresInDays = null): array
    {
        $generated = self::generateKey();

        $expiresAt = null;
        if ($expiresInDays !== null && (int) $expiresInDays > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int) $expiresInDays * 86400);
        }

        $stmt = db()->prepare(
            'INSERT INTO api_keys (name, key_hash, key_prefix, scopes, created_by, expires_at)
             VALUES (:name, :kh, :kp, :scopes, :cb, :exp)'
        );
        $stmt->execute([
            ':name'   => mb_substr(trim($name), 0, 80),
            ':kh'     => $generated['key_hash'],
            ':kp'     => $generated['key_prefix'],
            ':scopes' => json_encode(array_values(array_unique($scopes))),
            ':cb'     => $createdBy,
            ':exp'    => $expiresAt,
        ]);
        $id = (int) db()->lastInsertId();

        self::logSecurityEvent('api_key_create', "api_key_id={$id} name=" . mb_substr($name, 0, 40), $createdBy);

        return [
            'id'         => $id,
            'name'       => mb_substr(trim($name), 0, 80),
            'key'        => $generated['key'],       // plaintext — shown ONCE
            'key_prefix' => $generated['key_prefix'],
            'scopes'     => array_values(array_unique($scopes)),
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Soft-revoke an API key.
     */
    public static function revokeKey(int $keyId, int $revokedBy): bool
    {
        $stmt = db()->prepare(
            'UPDATE api_keys SET revoked_at = NOW(), is_active = 0
             WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([':id' => $keyId]);
        $revoked = $stmt->rowCount() > 0;

        if ($revoked) {
            self::logSecurityEvent('api_key_revoke', "api_key_id={$keyId}", $revokedBy);
        }
        return $revoked;
    }

    /**
     * Re-activate a revoked or inactive key (un-revoke).
     */
    public static function activateKey(int $keyId, int $activatedBy): bool
    {
        $stmt = db()->prepare(
            'UPDATE api_keys SET revoked_at = NULL, is_active = 1
             WHERE id = :id AND (revoked_at IS NOT NULL OR is_active = 0)'
        );
        $stmt->execute([':id' => $keyId]);
        $activated = $stmt->rowCount() > 0;

        if ($activated) {
            self::logSecurityEvent('api_key_activate', "api_key_id={$keyId}", $activatedBy);
        }
        return $activated;
    }

    /**
     * List API keys (admin only). Never returns key_hash.
     */
    public static function listKeys(int $page = 1, int $limit = 20): array
    {
        $offset = (max(1, $page) - 1) * min(100, max(1, $limit));

        $countRow = db()->query('SELECT COUNT(*) c FROM api_keys')->fetch();
        $total = (int) $countRow['c'];

        $stmt = db()->prepare(
            'SELECT id, name, key_prefix, scopes, created_by, is_active,
                    expires_at, last_used_at, revoked_at, created_at, updated_at
             FROM api_keys
             ORDER BY created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', min(100, max(1, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Decode scopes for each row
        foreach ($rows as &$row) {
            $row['scopes'] = json_decode($row['scopes'], true) ?: [];
            $row['is_active'] = (bool) $row['is_active'];
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Get a single API key record (never includes key_hash).
     */
    public static function getKey(int $keyId): ?array
    {
        $stmt = db()->prepare(
            'SELECT id, name, key_prefix, scopes, created_by, is_active,
                    expires_at, last_used_at, revoked_at, created_at, updated_at
             FROM api_keys WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $keyId]);
        $row = $stmt->fetch();
        if ($row) {
            $row['scopes'] = json_decode($row['scopes'], true) ?: [];
            $row['is_active'] = (bool) $row['is_active'];
        }
        return $row ?: null;
    }

    /**
     * Delete an API key permanently (admin only, for keys never issued or test keys).
     */
    public static function deleteKey(int $keyId, int $deletedBy): bool
    {
        $stmt = db()->prepare('DELETE FROM api_keys WHERE id = :id');
        $stmt->execute([':id' => $keyId]);
        $deleted = $stmt->rowCount() > 0;

        if ($deleted) {
            self::logSecurityEvent('api_key_delete', "api_key_id={$keyId}", $deletedBy);
        }
        return $deleted;
    }

    /**
     * Best-effort security event logger for key management operations.
     * Records a row in api_key_audit. Never blocks key management on logging.
     */
    private static function logSecurityEvent(string $eventType, ?string $details = null, ?int $userId = null): void
    {
        try {
            db()->prepare(
                'INSERT INTO api_key_audit (api_key_id, key_prefix, event_type, endpoint, ip_address, status, failure_reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                null,
                'mgmt',
                substr($eventType, 0, 32),
                'management: ' . $details,
                $_SERVER['REMOTE_ADDR'] ?? 'cli',
                'success',
                null,
            ]);
        } catch (Throwable $e) {
            error_log('api_key management log failed: ' . $e->getMessage());
        }
    }

    // ---- Audit logging -----------------------------------------------------

    /**
     * Record an authentication attempt. Never logs the actual key value.
     */
    public static function logAudit(
        ?int $keyId,
        string $keyPrefix,
        string $eventType,
        string $status,
        ?string $failureReason = null
    ): void {
        $endpoint = ($_SERVER['REQUEST_METHOD'] ?? 'CLI')
            . ' ' . ($_SERVER['REQUEST_URI'] ?? '/');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($ua !== null) {
            $ua = mb_substr($ua, 0, 255);
        }

        try {
            db()->prepare(
                'INSERT INTO api_key_audit (api_key_id, key_prefix, event_type, endpoint, ip_address, user_agent, status, failure_reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $keyId,
                $keyPrefix,
                substr($eventType, 0, 32),
                $endpoint,
                $ip,
                $ua,
                $status,
                $failureReason !== null ? substr($failureReason, 0, 100) : null,
            ]);
        } catch (Throwable $e) {
            error_log('api_key_audit log failed: ' . $e->getMessage());
        }
    }
}
