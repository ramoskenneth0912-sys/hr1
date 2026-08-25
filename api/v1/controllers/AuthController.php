<?php
/**
 * /api/v1/auth — register, login, logout, me.
 * Registration creates rows ONLY in the existing `users` table with role
 * 'applicant' (exactly what the site's own signup flow produces).
 */

declare(strict_types=1);

class AuthController
{
    private const ROLES = ['applicant', 'employee', 'manager', 'hr'];

    // ---- POST /auth/register ------------------------------------------------

    public static function register(): never
    {
        RateLimit::attempt('register:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 10, 900)
            or Response::tooManyRequests('Too many registration attempts. Try again later.');

        $in = api_body();
        $errors = [];

        foreach (['username', 'email', 'password'] as $f) {
            if (!isset($in[$f]) || trim((string) $in[$f]) === '') {
                $errors[$f] = ucfirst($f) . ' is required.';
            }
        }
        if ($errors) {
            Response::validation($errors);
        }

        $username = trim((string) $in['username']);
        $email = strtolower(trim((string) $in['email']));
        $password = (string) $in['password'];

        if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
            $errors['username'] = 'Username must be 3-50 characters: letters, numbers, dot or underscore.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 72) {
            $errors['password'] = 'Password must be between 8 and 72 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors['password'] = 'Password must contain at least one letter and one number.';
        }

        // Optional profile fields for the applicants table (best-effort, not required)
        $firstName = isset($in['first_name']) ? mb_substr(trim((string) $in['first_name']), 0, 80) : null;
        $lastName  = isset($in['last_name']) ? mb_substr(trim((string) $in['last_name']), 0, 80) : null;
        $phone     = isset($in['phone']) ? mb_substr(trim((string) $in['phone']), 0, 30) : null;

        if ($errors) {
            Response::validation($errors);
        }

        // ---- Uniqueness (mirrors the users.username/email UNIQUE keys) --------
        $dupe = db()->prepare('SELECT username, email FROM users WHERE username = :u OR email = :e LIMIT 1');
        $dupe->execute([':u' => $username, ':e' => $email]);
        if ($row = $dupe->fetch()) {
            $conflicts = [];
            if (strcasecmp($row['username'], $username) === 0) {
                $conflicts['username'] = 'This username is already taken.';
            }
            if (strcasecmp($row['email'], $email) === 0) {
                $conflicts['email'] = 'This email is already registered.';
            }
            Response::conflict('An account with these details already exists.', $conflicts);
        }

        db()->beginTransaction();
        try {
            $stmt = db()->prepare(
                "INSERT INTO users (username, email, password_hash, role, is_active)
                 VALUES (:u, :e, :p, 'applicant', 1)"
            );
            $stmt->execute([
                ':u' => $username,
                ':e' => $email,
                ':p' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) db()->lastInsertId();

            // Mirror the applicant profile row when names were supplied.
            if ($firstName !== null || $lastName !== null) {
                $applicantNo = generateCode('APP', 'applicants', 'applicant_no');
                db()->prepare(
                    'INSERT INTO applicants (user_id, applicant_no, first_name, last_name, email, phone, status, applied_date)
                     VALUES (:uid, :no, :fn, :ln, :em, :ph, \'new\', CURDATE())'
                )->execute([
                    ':uid' => $userId,
                    ':no' => $applicantNo,
                    ':fn' => $firstName ?? '',
                    ':ln' => $lastName ?? '',
                    ':em' => $email,
                    ':ph' => $phone ?: null,
                ]);
            }
            db()->commit();
        } catch (PDOException $ex) {
            db()->rollBack();
            throw $ex;
        }

        $token = Auth::issueToken($userId, 'registration');
        Response::created([
            'user' => self::userShape(self::findUser($userId)),
            'token' => $token['token'],
            'token_type' => 'Bearer',
            'expires_in_days' => $token['expires_in_days'],
        ], 'Registration successful. Welcome to TRI-M Global!');
    }

    // ---- POST /auth/login -------------------------------------------------------

    public static function login(): never
    {
        RateLimit::attempt('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 10, 900)
            or Response::tooManyRequests('Too many login attempts. Please wait before trying again.');

        $in = api_body();
        $credential = trim((string) ($in['credential'] ?? $in['username'] ?? $in['email'] ?? ''));
        $password = (string) ($in['password'] ?? '');

        if ($credential === '' || $password === '') {
            Response::validation(
                array_filter([
                    'credential' => $credential === '' ? 'Username or email is required.' : null,
                    'password'   => $password === '' ? 'Password is required.' : null,
                ])
            );
        }

        $user = Auth::verifyCredentials($credential, $password);
        if (!$user) {
            Response::unauthorized('Invalid username/email or password.');
        }

        $token = Auth::issueToken((int) $user['id'], $in['device_name'] ?? null);
        Response::item([
            'user' => self::userShape($user),
            'token' => $token['token'],
            'token_type' => 'Bearer',
            'expires_in_days' => $token['expires_in_days'],
        ], 'Login successful.');
    }

    // ---- GET /auth/me --------------------------------------------------------------

    public static function me(): never
    {
        $user = Auth::requireAuth();
        Response::item(self::userShape($user), 'Current user retrieved successfully.');
    }

    // ---- POST /auth/logout -------------------------------------------------------------

    public static function logout(): never
    {
        Auth::requireAuth(); // 401 when no valid token/session
        $revoked = Auth::revokeRequestToken();
        Response::item(['revoked' => $revoked], $revoked ? 'Logged out successfully.' : 'No active token to revoke.');
    }

    private static function findUser(int $id): array
    {
        $stmt = db()->prepare('SELECT id, username, email, role, employee_id, created_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: [];
    }

    /** Public user shape — NEVER includes password_hash. */
    public static function userShape(array $u): array
    {
        return [
            'id' => isset($u['id']) ? (int) $u['id'] : null,
            'username' => $u['username'] ?? null,
            'email' => $u['email'] ?? null,
            'role' => $u['role'] ?? null,
            'employee_id' => isset($u['employee_id']) && $u['employee_id'] !== null ? (int) $u['employee_id'] : null,
            'created_at' => $u['created_at'] ?? null,
        ];
    }
}
