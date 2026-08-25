<?php
/**
 * API authentication — Bearer tokens on top of the EXISTING users table.
 * Passwords are verified with password_verify(); only SHA-256 token hashes
 * are stored. A valid website PHP session also authenticates API calls so
 * the existing front-end can talk to the API without a second login.
 */

declare(strict_types=1);

class Auth
{
    private const STATUS_ALIASES = [
        'pending' => 'new',
        'new' => 'new',
        'reviewing' => 'screening',
        'screening' => 'screening',
        'shortlisted' => 'shortlisted',
        'interview' => 'interview',
        'accepted' => 'offered',
        'offered' => 'offered',
        'hired' => 'hired',
        'rejected' => 'rejected',
    ];

    public const EMPLOYMENT_ALIASES = [
        'regular' => 'regular', 'full-time' => 'regular', 'full_time' => 'regular', 'fulltime' => 'regular',
        'contractual' => 'contractual', 'contract' => 'contractual',
        'probationary' => 'probationary',
        'part_time' => 'part_time', 'part-time' => 'part_time', 'parttime' => 'part_time',
        'internship' => 'internship', 'intern' => 'internship',
    ];

    /** @var array{id:int,username:string,email:string,role:string}|null */
    private static ?array $user = null;
    /** Raw bearer token of the current request (for logout revocation). */
    private static ?string $bearer = null;

    // ---- Credential check (same rules as the website login) -------------

    public static function verifyCredentials(string $credential, string $password): ?array
    {
        $stmt = db()->prepare(
            'SELECT id, username, email, role, password_hash FROM users
             WHERE (username = :uname OR email = :uemail) AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':uname' => $credential, ':uemail' => $credential]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        unset($user['password_hash']);
        return $user;
    }

    // ---- Token lifecycle -------------------------------------------------

    public static function issueToken(int $userId, ?string $name = null): array
    {
        $raw = bin2hex(random_bytes(32));
        $stmt = db()->prepare(
            'INSERT INTO api_tokens (user_id, token_hash, name, expires_at)
             VALUES (:u, :h, :n, DATE_ADD(NOW(), INTERVAL :d DAY))'
        );
        $stmt->execute([
            ':u' => $userId,
            ':h' => hash('sha256', $raw),
            ':n' => $name !== null ? mb_substr($name, 0, 80) : null,
            ':d' => API_TOKEN_TTL_DAYS,
        ]);
        return ['token' => $raw, 'expires_in_days' => API_TOKEN_TTL_DAYS];
    }

    public static function revokeToken(string $rawToken): bool
    {
        $stmt = db()->prepare(
            'UPDATE api_tokens SET revoked_at = NOW()
             WHERE token_hash = :h AND revoked_at IS NULL'
        );
        $stmt->execute([':h' => hash('sha256', $rawToken)]);
        return $stmt->rowCount() > 0;
    }

    /** Revokes the Bearer token presented on the current request, if any. */
    public static function revokeRequestToken(): bool
    {
        $token = self::bearerFromHeader();
        return $token !== '' ? self::revokeToken($token) : false;
    }

    // ---- Request authentication ------------------------------------------

    private static function bearerFromHeader(): ?string
    {
        if (self::$bearer !== null) {
            return self::$bearer ?: null;
        }
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $header = $v;
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return self::$bearer = $m[1];
        }
        return self::$bearer = '';
    }

    /** Resolve the current user via Bearer token or existing website session. */
    public static function authenticate(): void
    {
        $token = self::bearerFromHeader();
        if ($token !== '') {
            $stmt = db()->prepare(
                'SELECT u.id, u.username, u.email, u.role
                 FROM api_tokens t JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = :h AND t.revoked_at IS NULL AND t.expires_at > NOW() AND u.is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([':h' => hash('sha256', $token)]);
            $user = $stmt->fetch();
            if ($user) {
                // opportunistic cleanup of expired tokens (~2% of requests)
                if (random_int(1, 50) === 1) {
                    db()->exec('DELETE FROM api_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY');
                }
                self::$user = $user;
            } else {
                // An invalid/explicit token must NOT fall back to the session.
                Response::unauthorized('Invalid or expired API token.');
            }
        } else {
            if (!empty($_SESSION['user_id'])) {
                $stmt = db()->prepare(
                    'SELECT id, username, email, role FROM users WHERE id = :id AND is_active = 1 LIMIT 1'
                );
                $stmt->execute([':id' => (int) $_SESSION['user_id']]);
                $user = $stmt->fetch();
                if ($user) {
                    self::$user = $user;
                }
            }
        }

        // The website bootstrap starts a session on every request; the API
        // only reads it above. Always close it and drop the cookie.
        if (session_status() === PHP_SESSION_ACTIVE) {
            header_remove('Set-Cookie');
            session_write_close();
        }
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::$user['id'] ?? null;
    }

    public static function role(): ?string
    {
        return self::$user['role'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return in_array(self::role(), ['hr', 'manager'], true);
    }

    public static function requireAuth(): array
    {
        if (!self::$user) {
            Response::unauthorized();
        }
        return self::$user;
    }

    /** hr + manager are the administrator roles of this system. */
    public static function requireAdmin(): array
    {
        $user = self::requireAuth();
        if (!self::isAdmin()) {
            Response::forbidden('Administrator access required.');
        }
        return $user;
    }

    // ---- Value normalisation shared across controllers --------------------

    /** Map a human/application status to its stored enum value; null when unknown. */
    public static function normalizeStatus(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $key = strtolower(trim($input));
        return self::STATUS_ALIASES[$key] ?? null;
    }

    public static function allStatusValues(): array
    {
        return array_values(array_unique(array_values(self::STATUS_ALIASES)));
    }

    public static function statusDisplayMap(): array
    {
        return [
            'new' => 'Pending', 'screening' => 'Reviewing', 'shortlisted' => 'Shortlisted',
            'interview' => 'Interview', 'offered' => 'Accepted', 'hired' => 'Hired', 'rejected' => 'Rejected',
        ];
    }

    public static function normalizeEmployment(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        return self::EMPLOYMENT_ALIASES[strtolower(trim($input))] ?? null;
    }
}
