<?php
/**
 * /api/v1/users — self-service profile access.
 * A user may read/update their own record; hr/manager may read/update anyone.
 * password_hash is NEVER returned. Role changes are administrator-only
 * (handled by AdminController).
 */

declare(strict_types=1);

class UsersController
{
    /** users table only carries identity fields; profile data lives in applicants. */
    private const SELF_EDITABLE = ['email'];

    // ---- GET /users ------------------------------------------------------------

    public static function index(): never
    {
        Auth::requireAdmin();

        $page = max(1, (int) (api_query('page') ?? 1));
        $limit = min(100, max(1, (int) (api_query('limit') ?? 10)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        if ($role = api_query('role')) {
            if (!in_array($role, ['hr', 'manager', 'employee', 'applicant'], true)) {
                Response::validation(['role' => "Role must be one of: hr, manager, employee, applicant."]);
            }
            $where[] = 'u.role = :role';
            $params[':role'] = $role;
        }
        if ($q = api_query('search')) {
            $where[] = '(u.username LIKE :q1 OR u.email LIKE :q2)';
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . $q . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = db()->prepare("SELECT COUNT(*) c FROM users u $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = db()->prepare(
            "SELECT u.id, u.username, u.email, u.role, u.employee_id, u.is_active, u.created_at
             FROM users u $whereSql ORDER BY u.id ASC LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_map([AuthController::class, 'userShape'], $stmt->fetchAll());

        Response::list($rows, 'Users retrieved successfully.', $page, $limit, $total);
    }

    // ---- GET /users/{id} -----------------------------------------------------------

    public static function show(int $id): never
    {
        Auth::requireAuth();
        if (!Auth::isAdmin() && (int) Auth::id() !== $id) {
            Response::forbidden('You may only view your own user record.');
        }
        $user = self::findUser($id);
        if (!$user) {
            Response::notFound('User not found.');
        }
        Response::item(AuthController::userShape($user), 'User retrieved successfully.');
    }

    // ---- PUT/PATCH /users/{id} ---------------------------------------------------------

    public static function update(int $id, bool $partial): never
    {
        Auth::requireAuth();
        $isAdmin = Auth::isAdmin();
        if (!$isAdmin && (int) Auth::id() !== $id) {
            Response::forbidden('You may only update your own user record.');
        }
        $user = self::findUser($id);
        if (!$user) {
            Response::notFound('User not found.');
        }

        $in = api_body();

        // Password change is a separate, explicit action.
        if (array_key_exists('password', $in) || array_key_exists('new_password', $in)) {
            $newPassword = (string) ($in['new_password'] ?? $in['password']);
            if (mb_strlen($newPassword) < 8 || mb_strlen($newPassword) > 72) {
                Response::validation(['password' => 'New password must be between 8 and 72 characters.']);
            }
            // Non-admins must confirm their CURRENT password first.
            if (!$isAdmin) {
                $current = (string) ($in['current_password'] ?? '');
                if ($current === '' || !Auth::verifyCredentials($user['username'], $current)) {
                    Response::validation(['current_password' => 'Your current password is required and must be correct.']);
                }
            }
            db()->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                ->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $id]);
            unset($in['password'], $in['new_password'], $in['current_password']);
        }

        // Role/username/is_active changes are admin-only via the admin endpoint.
        foreach (['role', 'username', 'is_active'] as $guarded) {
            if (array_key_exists($guarded, $in) && !$isAdmin) {
                Response::forbidden(ucfirst($guarded) . ' can only be changed by HR/Manager.');
            } elseif (array_key_exists($guarded, $in)) {
                unset($in[$guarded]); // use PATCH /admin/users/{id} for these
            }
        }

        $data = array_intersect_key($in, array_flip(self::SELF_EDITABLE));

        $v = new Validator($data);
        $v->email('email');
        if (isset($data['email']) && strtolower(trim((string) $data['email'])) !== strtolower((string) $user['email'])) {
            $dupe = db()->prepare('SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1');
            $dupe->execute([':e' => strtolower(trim((string) $data['email'])), ':id' => $id]);
            if ($dupe->fetch()) {
                Response::conflict('Email already in use by another account.', [
                    'email' => 'This email is already registered.',
                ]);
            }
        }
        if ($v->fails()) {
            Response::validation($v->errors());
        }

        if ($data !== []) {
            $sets = [];
            $params = [':id' => $id];
            foreach ($data as $field => $val) {
                if ($field === 'email') {
                    $val = strtolower(trim((string) $val));
                    if ($val === '') {
                        continue; // email cannot be emptied
                    }
                }
                $sets[] = "`$field` = :$field";
                $params[":$field"] = is_string($val) ? trim($val) : $val;
            }
            if ($sets) {
                db()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
            }
        }

        Response::item(AuthController::userShape(self::findUser($id)), 'Profile updated successfully.');
    }

    // ---- DELETE /users/{id} — admins only (soft-deactivate) -------------------------------

    public static function destroy(int $id): never
    {
        Auth::requireAdmin();
        if ((int) Auth::id() === $id) {
            Response::conflict('You cannot deactivate your own account.');
        }
        $stmt = db()->prepare('UPDATE users SET is_active = 0 WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            Response::notFound('Active user not found.');
        }
        // Revoke all tokens of the deactivated account immediately.
        db()->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = :id AND revoked_at IS NULL')
            ->execute([':id' => $id]);
        Response::noContent();
    }

    private static function findUser(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT id, username, email, role, employee_id, is_active, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
