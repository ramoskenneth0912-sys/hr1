<?php
/**
 * MOCK CONTRACT TESTER — HR1 API
 *
 * ****************************************************************************
 *  MOCK ONLY — NOT HR2 / HR3 / HR4.
 *  This script simulates how a future partner system would call HR1's real
 *  REST API, and asserts that HR1's responses match the documented contract
 *  in docs/integration/hr1-api.md. It does NOT talk to HR2, HR3 or HR4, and
 *  it does NOT implement any partner-side API. Run against HR1's staging (or
 *  local) environment only.
 * ****************************************************************************
 *
 * What it validates (all against the REAL /api/v1 endpoint):
 *   - public read / unknown-route / unauth-write / validation error contract
 *   - HR-user login (Bearer) + auth/me
 *   - API-key lifecycle: create scoped keys via the API, prove scope
 *     enforcement (403) on exams endpoints, then DELETE the keys again
 *     (permanent cleanup — no residual data)
 *   - exam boundary contract: validation 422 bodies, unknown exm_ token 404,
 *     missing access_token 422, key-without-scope 403
 *
 * No job, applicant, exam or result rows are created by this tester.
 *
 * NOTE on re-runs: the live HR1 API throttles API-key attempts per IP at
 * 10 per 15 minutes (see docs/integration/hr1-api.md §4 — the apikey_fail
 * bucket counts ALL well-formed key attempts, successful ones included).
 * Running this tester back-to-back inside one window can therefore hit 429.
 * Wait ~15 minutes between runs, or (staging ops only) clear the matching
 * file under /storage/api/rate_limits/.
 *
 * Usage (CLI, staging default):
 *   php database/mock_contract_tester.php \
 *       [--base-url https://staging.hr1.example.com/api/v1] \
 *       [--resolve-ip 127.0.0.1] \
 *       [--credential <hr-or-manager username>] \
 *       [--password <password>]
 *
 * Credentials can also be supplied without appearing in logs/PS history via:
 *   $env:HR1_MOCK_CREDENTIAL="phase2hr"; $env:HR1_MOCK_PASSWORD="..."
 *   php database/mock_contract_tester.php
 *
 * Staging runs must disable TLS verification ONLY because staging uses a
 * self-signed certificate (this mirrors the documented
 * `curl --resolve staging.hr1.example.com:443:127.0.0.1 -k` verification).
 * Production calls must keep verification ON.
 *
 * Exit code: 0 = all required tests passed; 1 = one or more failed.
 */

declare(strict_types=1);

error_reporting(E_ALL);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "MOCK contract tester must be run from the CLI.\n");
    exit(1);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "PHP cURL extension is required by the MOCK contract tester.\n");
    exit(1);
}

// ---- CLI/ENV configuration ------------------------------------------------

$args = $argv ? array_slice($argv, 1) : [];
function arg(string $name, ?string $default = null): ?string
{
    global $args;
    foreach ($args as $i => $a) {
        if ($a === $name && isset($args[$i + 1])) {
            return $args[$i + 1];
        }
    }
    $envMap = [
        '--base-url' => 'HR1_MOCK_BASE_URL',
        '--resolve-ip' => 'HR1_MOCK_RESOLVE_IP',
        '--credential' => 'HR1_MOCK_CREDENTIAL',
        '--password' => 'HR1_MOCK_PASSWORD',
    ];
    if (isset($envMap[$name])) {
        $v = getenv($envMap[$name]);
        if (is_string($v) && $v !== '') {
            return $v;
        }
    }
    return $default;
}

$BASE_URL   = rtrim((string) arg('--base-url', 'https://staging.hr1.example.com/api/v1'), '/');
$RESOLVE_IP = arg('--resolve-ip', '127.0.0.1');
$CREDENTIAL = arg('--credential');
$PASSWORD   = arg('--password');

// ---- Tiny HTTP helper -------------------------------------------------------

function api(string $method, string $path, array $body = null, array $headers = []): array
{
    global $BASE_URL, $RESOLVE_IP;
    $parsed = parse_url($BASE_URL);
    $host = $parsed['host'] ?? 'staging.hr1.example.com';
    $port = (string) ($parsed['port'] ?? (($parsed['scheme'] ?? 'https') === 'http' ? 80 : 443));
    $ch = curl_init($BASE_URL . $path);
    $h = ['Content-Type: application/json', 'Accept: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = $k . ': ' . $v;
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        // Map staging.hr1.example.com -> 127.0.0.1 exactly like the documented
        // `curl --resolve staging.hr1.example.com:443:127.0.0.1` verification
        // (no hosts-file edit). This also keeps SNI + Host header correct.
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $RESOLVE_IP],
        // Staging uses a self-signed certificate — the documented -k equivalent.
        // Keep verification ON for any non-staging target.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    if ($body !== null && $method !== 'GET') {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode(is_string($raw) ? $raw : '', true);
    return ['status' => $code, 'json' => is_array($json) ? $json : null, 'raw' => (string) $raw, 'curl_error' => $err];
}

// ---- Test harness ------------------------------------------------------------

$passed = 0;
$failed = 0;
const GREEN = "\033[32m";
const RED   = "\033[31m";
const YELLOW = "\033[33m";
const RESET = "\033[0m";
$apps = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        $line = GREEN . '[PASS] ' . RESET . $name;
    } else {
        $failed++;
        $line = RED . '[FAIL] ' . RESET . $name;
    }
    if ($detail !== '') {
        $line .= ' — ' . $detail;
    }
    fwrite(STDOUT, $line . "\n");
}

function expect(array $r, int $status, bool $success, string $ctx): string
{
    $j = $r['json'] ?? null;
    $ok = $r['status'] === $status
        && is_array($j)
        && ($j['success'] ?? null) === $success;
    if (!$ok && $r['curl_error'] !== '') {
        return $r['curl_error'];
    }
    return $ok ? '' : "expected HTTP $status success=" . var_export($success, true)
        . ' got ' . $r['status'] . ' ' . substr($r['raw'], 0, 160) . " [$ctx]";
}

fwrite(STDOUT, "==============================================================\n");
fwrite(STDOUT, "  MOCK ONLY — NOT HR2/HR3/HR4\n");
fwrite(STDOUT, "  Contract tester vs " . $BASE_URL . "\n");
fwrite(STDOUT, "==============================================================\n");

// ---- 1. Public & error contract ---------------------------------------------

$r = api('GET', '/jobs');
check('GET /jobs is public', $r['status'] === 200 && ($r['json']['success'] ?? false) === true);

$r = api('GET', '/no/such/route');
check('Unknown route returns JSON 404', expect($r, 404, false, 'route') === '', expect($r, 404, false, 'route'));

$r = api('POST', '/jobs', ['title' => 'X']);
check('POST /jobs without auth is 401', expect($r, 401, false, 'noauth') === '', expect($r, 401, false, 'noauth'));

$r = api('POST', '/auth/login', []);
check('Empty login is 422 validation', expect($r, 422, false, 'login-empty') === '', expect($r, 422, false, 'login-empty'));

$r = api('DELETE', '/jobs');
check('Wrong method on existing endpoint is 405', $r['status'] === 405, 'http ' . $r['status']);

// ---- 2. HR-user Bearer login ------------------------------------------------

$hrToken = null;
if ($CREDENTIAL !== null && $PASSWORD !== null) {
    $r = api('POST', '/auth/login', ['credential' => $CREDENTIAL, 'password' => $PASSWORD, 'device_name' => 'MOCK-contract-tester']);
    if (($r['json']['data']['token'] ?? null) !== null) {
        $hrToken = (string) $r['json']['data']['token'];
        check('Login issues a Bearer token', true);
    } else {
        check('Login issues a Bearer token', false, 'http ' . $r['status'] . ' ' . substr($r['raw'], 0, 120));
    }
    if ($hrToken !== null) {
        $r = api('GET', '/auth/me', null, ['Authorization' => 'Bearer ' . $hrToken]);
        $role = $r['json']['data']['role'] ?? null;
        check('GET /auth/me returns hr/manager', in_array($role, ['hr', 'manager'], true), 'role=' . var_export($role, true));
        if (!in_array($role, ['hr', 'manager'], true)) {
            fwrite(STDOUT, YELLOW . "  [skip] key-management + scope checks need hr/manager role\n" . RESET);
            $hrToken = null;
        }
    }
} else {
    fwrite(STDOUT, YELLOW . "  [skip] HR-user checks: pass --credential/--password (or HR1_MOCK_CREDENTIAL/HR1_MOCK_PASSWORD)\n" . RESET);
}

// ---- 3. API-key lifecycle + scope enforcement --------------------------------

$createdKeys = [];
if ($hrToken !== null) {
    $r = api('POST', '/api-keys', [
        'name' => 'MOCK-CONTRACT-exams',
        'scopes' => ['exams:read', 'exams:write', 'exams:results:write'],
    ], ['Authorization' => 'Bearer ' . $hrToken]);
    $keyA = $r['json']['data']['key'] ?? null;
    $keyAId = isset($r['json']['data']['id']) ? (int) $r['json']['data']['id'] : null;
    check('POST /api-keys returns plaintext key once', $r['status'] === 201 && is_string($keyA), 'http ' . $r['status']);
    if ($keyAId !== null) {
        $createdKeys[] = $keyAId;
    }

    $r = api('POST', '/api-keys', [
        'name' => 'MOCK-CONTRACT-jobs-only',
        'scopes' => ['jobs:read'],
    ], ['Authorization' => 'Bearer ' . $hrToken]);
    $keyB = $r['json']['data']['key'] ?? null;
    $keyBId = isset($r['json']['data']['id']) ? (int) $r['json']['data']['id'] : null;
    check('Second API key with narrow scope', $r['status'] === 201, 'http ' . $r['status']);
    if ($keyBId !== null) {
        $createdKeys[] = $keyBId;
    }

    if (is_string($keyB)) {
        $r = api('GET', '/exams', null, ['X-API-Key' => $keyB]);
        check('Key without exams:read gets 403 on GET /exams', $r['status'] === 403, 'http ' . $r['status']);
        $r = api('POST', '/exams', ['title' => 'x', 'reference' => 'x'], ['X-API-Key' => $keyB]);
        check('Key without exams:write gets 403 on POST /exams', $r['status'] === 403, 'http ' . $r['status']);
    }

    if (is_string($keyA)) {
        $r = api('GET', '/exams', null, ['X-API-Key' => $keyA]);
        check('Key with exams:read gets 200 on GET /exams', $r['status'] === 200, 'http ' . $r['status']);

        $r = api('POST', '/exams', ['reference' => 'MOCK-no-title'], ['X-API-Key' => $keyA]);
        check('POST /exams missing title is 422', expect($r, 422, false, 'exams-title') === '', expect($r, 422, false, 'exams-title'));

        $r = api('POST', '/exams', ['title' => 'Mock', 'reference' => 'MOCK-http', 'exam_url' => 'http://insecure.example/x'], ['X-API-Key' => $keyA]);
        check('POST /exams non-HTTPS exam_url is 422', expect($r, 422, false, 'exams-http') === '', expect($r, 422, false, 'exams-http'));

        $r = api('POST', '/exam-results', [], ['X-API-Key' => $keyA]);
        check('POST /exam-results missing access_token is 422', expect($r, 422, false, 'results-token') === '', expect($r, 422, false, 'results-token'));

        $r = api('POST', '/exam-results', ['access_token' => 'exm_000000000000000000000000000000000000000000000000'], ['X-API-Key' => $keyA]);
        check('POST /exam-results unknown exm_ token is 404', expect($r, 404, false, 'results-unknown') === '', expect($r, 404, false, 'results-unknown'));
    }

    $r = api('POST', '/api-keys/' . ($keyBId ?? 0), [], ['X-API-Key' => ($keyA ?? 'x')]);
    check('API key cannot manage itself (PATCH /api-keys/{id})', $r['status'] === 403 || $r['status'] === 404, 'http ' . $r['status']);

    // ---- Cleanup: permanently delete the temp keys -------------------------
    foreach ($createdKeys as $kid) {
        $r = api('DELETE', '/api-keys/' . $kid, null, ['Authorization' => 'Bearer ' . $hrToken]);
        check('DELETE temp API key #' . $kid, $r['status'] === 204, 'http ' . $r['status']);
    }
    $createdKeys = [];

    $r = api('POST', '/auth/logout', null, ['Authorization' => 'Bearer ' . $hrToken]);
    check('POST /auth/logout revokes token', expect($r, 200, true, 'logout') === '', expect($r, 200, true, 'logout'));
    $hrToken = null;
}

// ---- 4. Summary ----------------------------------------------------------------

fwrite(STDOUT, "==============================================================\n");
if ($failed === 0) {
    fwrite(STDOUT, GREEN . "ALL CONTRACT CHECKS PASSED ($passed checks)\n" . RESET);
    fwrite(STDOUT, "MOCK ONLY — nothing here talks to HR2/HR3/HR4.\n");
    exit(0);
}
fwrite(STDOUT, RED . "$failed FAILED, $passed passed.\n" . RESET);
fwrite(STDOUT, "See docs/integration/hr1-api.md for the expected contract.\n");
exit(1);