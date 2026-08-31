<?php
/**
 * /api/v1/api-keys — API key management (hr/manager only).
 *
 * Creates, lists, revokes, and deletes API keys for system-to-system
 * communication. The plaintext key is returned ONCE at creation time
 * and never stored or displayed again.
 *
 * All endpoints require user authentication (Bearer token or session)
 * with hr/manager role. API keys cannot manage themselves.
 */

declare(strict_types=1);

class ApiKeysController
{
    // ---- POST /api-keys — create a new API key ----------------------------

    public static function store(): never
    {
        Auth::requireAdmin();
        $adminId = Auth::id();

        $in = api_body();

        // Validate name
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            Response::validation(['name' => 'Name is required.']);
        }
        if (mb_strlen($name) > 80) {
            Response::validation(['name' => 'Name may not exceed 80 characters.']);
        }

        // Validate scopes
        $scopes = $in['scopes'] ?? [];
        if (!is_array($scopes) || $scopes === []) {
            Response::validation(['scopes' => 'At least one scope is required.']);
        }
        $invalidScopes = array_diff($scopes, ApiKeyAuth::VALID_SCOPES);
        if ($invalidScopes !== []) {
            Response::validation(['scopes' => 'Invalid scopes: ' . implode(', ', $invalidScopes) . '.']);
        }
        $scopes = array_values(array_unique($scopes));

        // Validate expiry (optional)
        $expiresInDays = isset($in['expires_in_days']) ? (int) $in['expires_in_days'] : null;
        if ($expiresInDays !== null && $expiresInDays < 1) {
            Response::validation(['expires_in_days' => 'Must be a positive integer (number of days).']);
        }

        $result = ApiKeyAuth::createKey($name, $scopes, $adminId, $expiresInDays !== null ? (string) $expiresInDays : null);

        Response::created([
            'id'         => $result['id'],
            'name'       => $result['name'],
            'key'        => $result['key'],
            'key_prefix' => $result['key_prefix'],
            'scopes'     => $result['scopes'],
            'expires_at' => $result['expires_at'],
            'created_at' => $result['created_at'],
        ], 'API key created. Save the key securely — it will not be shown again.');
    }

    // ---- GET /api-keys — list all API keys --------------------------------

    public static function index(): never
    {
        Auth::requireAdmin();

        $page  = max(1, (int) (api_query('page') ?? 1));
        $limit = min(100, max(1, (int) (api_query('limit') ?? 20)));

        $result = ApiKeyAuth::listKeys($page, $limit);

        Response::list(
            $result['rows'],
            'API keys retrieved successfully.',
            $page,
            $limit,
            $result['total']
        );
    }

    // ---- GET /api-keys/{id} — get a single API key ------------------------

    public static function show(int $id): never
    {
        Auth::requireAdmin();

        $key = ApiKeyAuth::getKey($id);
        if (!$key) {
            Response::notFound('API key not found.');
        }

        Response::item($key, 'API key retrieved successfully.');
    }

    // ---- PATCH /api-keys/{id} — update name/scopes/expiry -----------------

    public static function update(int $id): never
    {
        Auth::requireAdmin();
        $adminId = Auth::id();

        $key = ApiKeyAuth::getKey($id);
        if (!$key) {
            Response::notFound('API key not found.');
        }

        $in = api_body();
        $sets = [];
        $params = [':id' => $id];

        // Update name
        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if ($name === '') {
                Response::validation(['name' => 'Name cannot be empty.']);
            }
            if (mb_strlen($name) > 80) {
                Response::validation(['name' => 'Name may not exceed 80 characters.']);
            }
            $sets[] = 'name = :name';
            $params[':name'] = $name;
        }

        // Update scopes
        if (array_key_exists('scopes', $in)) {
            $scopes = $in['scopes'];
            if (!is_array($scopes) || $scopes === []) {
                Response::validation(['scopes' => 'At least one scope is required.']);
            }
            $invalidScopes = array_diff($scopes, ApiKeyAuth::VALID_SCOPES);
            if ($invalidScopes !== []) {
                Response::validation(['scopes' => 'Invalid scopes: ' . implode(', ', $invalidScopes) . '.']);
            }
            $scopes = array_values(array_unique($scopes));
            $sets[] = 'scopes = :scopes';
            $params[':scopes'] = json_encode($scopes);
        }

        // Update expiry
        if (array_key_exists('expires_in_days', $in)) {
            $expiresInDays = $in['expires_in_days'];
            if ($expiresInDays === null || (int) $expiresInDays <= 0) {
                $sets[] = 'expires_at = NULL';
            } else {
                $days = (int) $expiresInDays;
                if ($days < 1) {
                    Response::validation(['expires_in_days' => 'Must be a positive integer.']);
                }
                $sets[] = 'expires_at = DATE_ADD(NOW(), INTERVAL :exp_days DAY)';
                $params[':exp_days'] = $days;
            }
        }

        // Toggle active status
        if (array_key_exists('is_active', $in)) {
            $isActive = $in['is_active'] ? 1 : 0;
            $sets[] = 'is_active = :active';
            $params[':active'] = $isActive;
            if (!$isActive) {
                $sets[] = 'revoked_at = IF(revoked_at IS NULL, NOW(), revoked_at)';
            }
        }

        if ($sets === []) {
            Response::badRequest('No updatable fields provided.');
        }

        $sql = 'UPDATE api_keys SET ' . implode(', ', $sets) . ' WHERE id = :id';
        db()->prepare($sql)->execute($params);

        securityLog('api_key_update', "api_key_id={$id}", $adminId);

        $updated = ApiKeyAuth::getKey($id);
        Response::item($updated, 'API key updated successfully.');
    }

    // ---- POST /api-keys/{id}/revoke — soft-revoke an API key ---------------

    public static function revoke(int $id): never
    {
        Auth::requireAdmin();
        $adminId = Auth::id();

        $key = ApiKeyAuth::getKey($id);
        if (!$key) {
            Response::notFound('API key not found.');
        }

        if ($key['revoked_at'] !== null) {
            Response::conflict('API key is already revoked.');
        }

        ApiKeyAuth::revokeKey($id, $adminId);

        $revoked = ApiKeyAuth::getKey($id);
        Response::item($revoked, 'API key revoked successfully.');
    }

    // ---- POST /api-keys/{id}/activate — re-activate a revoked key ----------

    public static function activate(int $id): never
    {
        Auth::requireAdmin();
        $adminId = Auth::id();

        $key = ApiKeyAuth::getKey($id);
        if (!$key) {
            Response::notFound('API key not found.');
        }

        if ($key['revoked_at'] === null && $key['is_active']) {
            Response::conflict('API key is already active.');
        }

        ApiKeyAuth::activateKey($id, $adminId);

        $activated = ApiKeyAuth::getKey($id);
        Response::item($activated, 'API key activated successfully.');
    }

    // ---- DELETE /api-keys/{id} — permanently delete ------------------------

    public static function destroy(int $id): never
    {
        Auth::requireAdmin();
        $adminId = Auth::id();

        $key = ApiKeyAuth::getKey($id);
        if (!$key) {
            Response::notFound('API key not found.');
        }

        ApiKeyAuth::deleteKey($id, $adminId);
        Response::noContent();
    }

    // ---- GET /api-keys/scopes — list all valid scopes ----------------------

    public static function scopes(): never
    {
        Auth::requireAdmin();

        Response::item([
            'valid_scopes' => ApiKeyAuth::VALID_SCOPES,
            'description' => [
                'applicants:read'    => 'Read applicant data',
                'applicants:write'   => 'Create, update, or delete applicants',
                'jobs:read'          => 'Read job postings',
                'jobs:write'         => 'Create, update, or delete job postings',
                'employees:read'     => 'Read employee data',
                'employees:write'    => 'Create, update, or delete employee data',
                'users:read'         => 'List or view user accounts',
                'users:write'        => 'Create, update, or delete users',
                'departments:read'   => 'List departments',
                'admin:read'         => 'View dashboard statistics',
                'admin:write'        => 'Change application statuses',
            ],
        ], 'Available API key scopes.');
    }
}
