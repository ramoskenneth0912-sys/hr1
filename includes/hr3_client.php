<?php
/**
 * HR1 -> HR3 outbound integration client (server-side only).
 *
 * This is the single, dedicated place in HR1 that talks to the HR3 subsystem
 * over HTTP. It reads everything it needs from the environment (via the
 * existing hr1_env() loader in includes/environment.php) and attaches the HR3
 * API key using a configurable HTTP header (default `X-API-Key`), matching the
 * system-to-system convention HR1 itself documents for its own integrated
 * endpoints (see docs/integration/hr1-api.md).
 *
 * Security rules enforced here:
 *   - The API key NEVER appears in a URL, query string, HTML, JavaScript,
 *     error log, or any returned array. It is attached only as an HTTP header.
 *   - The key is never echoed or truncated; only its presence/length is known
 *     to callers.
 *   - The base URL is never guessed or hardcoded; it comes from configuration
 *     (HR3_BASE_URL). When it is blank, a safe `config` error is returned
 *     instead of guessing a device address.
 *   - Failures are classified so callers can distinguish:
 *         config      -> HR3 not configured (no base URL or no API key)
 *         connection  -> transport failure (DNS/TCP/TLS/timeout)
 *         auth        -> HR3 rejected the API key (401/403)
 *         not_found   -> HR3 has no data for the employee (404)
 *         hr3_error   -> HR3 returned an application/HTTP error (4xx/5xx)
 *
 * Loaded once by includes/functions.php; defines functions only, no side
 * effects. Available to every web page, API route and CLI script that includes
 * the HR1 bootstrap.
 */

declare(strict_types=1);

if (!function_exists('hr1_hr3_auth_header')) {

    /**
     * Name of the HTTP header that carries the HR3 API key.
     * Default `X-API-Key`; override via HR3_API_KEY_HEADER when the external
     * contract differs. Never returns an empty string.
     */
    function hr1_hr3_auth_header(): string
    {
        $name = hr1_env('HR3_API_KEY_HEADER');
        if ($name === null || trim($name) === '') {
            return 'X-API-Key';
        }
        return trim($name);
    }

    /**
     * The configured HR3 API key, or null when not configured.
     * Callers must never echo, render or log this value.
     */
    function hr1_hr3_api_key(): ?string
    {
        $key = hr1_env('HR3_API_KEY');
        return ($key === null || $key === '') ? null : $key;
    }

    /**
     * The configured HR3 base URL (trailing slash stripped), or '' when unset.
     * Never hardcoded, never guessed. When '' the client returns a safe
     * `config` error instead of inventing an address.
     */
    function hr1_hr3_base_url(): string
    {
        $url = hr1_env('HR3_BASE_URL');
        return ($url === null || $url === '') ? '' : rtrim($url, "/ \t\n\r");
    }

    /**
     * Perform an authenticated GET against HR3 and return [status, body].
     * Throws RuntimeException only on transport-level failure (unreachable,
     * DNS/TCP/TLS, timeout), so callers can classify it independently of any
     * HTTP status code.
     *
     * @return array{0:int,1:string} [HTTP status, raw body]
     */
    function hr1_hr3_http_get(string $url, int $timeoutSeconds): array
    {
        $key     = hr1_hr3_api_key();
        $header  = hr1_hr3_auth_header() . ': ' . $key;
        $headers = [$header, 'Accept: application/json'];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Could not initialise cURL for the HR3 request.');
            }
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET         => true,
                CURLOPT_HTTPHEADER      => $headers,
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_TIMEOUT         => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT  => max(3, min(10, $timeoutSeconds)),
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
            ]);
            $response = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($chowed);
            if ($response === false) {
                throw new RuntimeException('HR3 transport error (connection failed).');
            }
            return [$status, (string) $response];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('HR3 transport error (connection failed).');
        }
        $status = 200;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
                    $status = (int) $m[1];
                }
            }
        }
        return [$status, (string) $response];
    }

    /**
     * Fetch an employee's competencies from HR3.
     *
     * @param string $employeeNo HR1 employee number, e.g. "E021".
     * @return array{ok:bool,employee_id:int|string|null,error_type?:string,status:int|null,message?:string,data?:array,retryable?:bool}
     */
    function hr1_hr3_fetch_competencies(string $employeeNo): array
    {
        $employeeNo = strtoupper(trim($employeeNo));
        if (preg_match('/^[A-Z][0-9]{3}$/', $employeeNo) !== 1) {
            return [
                'ok'          => false,
                'employee_id' => $employeeNo,
                'error_type'  => 'invalid_input',
                'status'      => null,
                'message'     => 'Invalid employee number. Expected the E### convention (e.g. E021).',
                'retryable'   => false,
            ];
        }

        $baseUrl = hr1_hr3_base_url();
        if ($baseUrl === '') {
            return [
                'ok'          => false,
                'employee_id' => $employeeNo,
                'error_type'  => 'config',
                'status'      => null,
                'message'     => 'HR3 is not configured: HR3_BASE_URL is not set.',
                'retryable'   => false,
            ];
        }

        if (hr1_hr3_api_key() === null) {
            return [
                'ok'          => false,
                'employee_id' => $employeeNo,
                'error_type'  => 'config',
                'status'      => null,
                'message'     => 'HR3 is not configured: HR3_API_KEY is not set.',
                'retryable'   => false,
            ];
        }

        $url = $baseUrl . '/api/integration/hr1_competencies.php?employee_id=' . rawurlencode($employeeNoWeekly);
        if (stripos(PHP_OS, 'WIN') !== false) {
            // no-op; kept for parity with non-cURL path on Windows
        }

        $started = microtime(true);
        try {
            [$status, $body] = hr1_hr3_http_get($url, 15);
        } catch (RuntimeException $e) {
            return [
                'ok'          => false,
                'employee_id' => $employeeNo,
                'error_type'  => 'connection',
                'status'      => null,
                'message'     => 'HR3 could not be reached (connection failed or timed out).',
                'retryable'   => true,
            ];
        }
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if ($status === 401 || $status === 403) {
            return [
                'ok'          => false,
                'employee_id' => $employeeNo,
                'error_type'  => 'auth',
                'status'      => $status,
                'message'     => 'HR3 rejected the API key (HTTP ' . $status . ').',
                'retryable'   => false,
            ];
        }
        if ($status === 404) {
            return [
                'ok'          => false,
                'employee_id' => $employeeNo,
                'error_type'  => 'not_found',
                'status'      => $status,
                'message'     => 'HR3 has no competency data for employee ' . $employeeNo . ' (HTTP 404).',
                'retryable'   => false,
            ];
        }
        if ($status < 200 || $status >= 300) {
            return [
                'ok'          => false,
                'employee_id' => $employeeNo,
                'error_type'  => 'hr3_error',
                'status'      => $status,
                'message'     => 'HR3 reported an application error (HTTP ' . $status . ').',
                'retryable'   => $status >= 500,
            ];
        }

        $decoded = json_decode($body !== '' ? $body : 'null', true);
        if (!is_array($decoded)) {
            return [
                'ok'          => false,
                'employee_id' => $employeeNo,
                'error_type'  => 'hr3_error',
                'status'      => $status,
                'message'     => 'HR3 returned a response that could not be parsed as JSON.',
                'retryable'   => false,
            ];
        }

        return [
            'ok'          => true,
            'employee_id' => $employeeNo,
            'status'      => $status,
            'data'        => $decoded,
            'duration_ms' => $elapsed,
        ];
    }
}
