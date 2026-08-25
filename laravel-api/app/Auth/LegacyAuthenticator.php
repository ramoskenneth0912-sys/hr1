<?php

namespace App\Auth;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Read-only authentication bridge onto the EXISTING HR1 auth artifacts.
 *
 * Path A: "Authorization: Bearer <token>" -> SHA-256 lookup in api_tokens
 *         joined to users (same semantics as api/v1/lib/Auth.php).
 * Path B: PHPSESSID cookie -> parse the LEGACY PHP session file
 *         (pure file read, never written back) -> $_SESSION['user_id'].
 *
 * No passwords are verified, no tokens issued or revoked, no sessions
 * created or destroyed here. Any failure resolves to unauthenticated.
 */
class LegacyAuthenticator
{
    public function resolve(Request $request): ?User
    {
        try {
            $user = $this->viaBearer($request) ?? $this->viaLegacySession($request);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return ($user !== null && (int) $user->is_active === 1) ? $user : null;
    }

    // ---- Path A ----------------------------------------------------------

    private function viaBearer(Request $request): ?User
    {
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim((string) $request->header('Authorization')), $m)) {
            return null;
        }

        return User::query()
            ->join('api_tokens', 'api_tokens.user_id', '=', 'users.id')
            ->where('api_tokens.token_hash', hash('sha256', $m[1]))
            ->whereNull('api_tokens.revoked_at')
            ->where('api_tokens.expires_at', '>', now())
            ->where('users.is_active', 1)
            ->select('users.*')
            ->first();
    }

    // ---- Path B ----------------------------------------------------------

    private function viaLegacySession(Request $request): ?User
    {
        $sid = (string) $request->cookie(session_name());
        if ($sid === '' || preg_match('/[^a-zA-Z0-9,\-_]/', $sid)) {
            return null;
        }

        $path = rtrim((string) config('legacy.session_path'), '/\\');
        if ($path === '') {
            return null;
        }

        $file = $path.DIRECTORY_SEPARATOR.'sess_'.$sid;
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $userId = $this->parseSessionFile($contents)['user_id'] ?? null;
        if (!$userId) {
            return null;
        }

        return User::query()
            ->where('id', (int) $userId)
            ->where('is_active', 1)
            ->first();
    }

    /**
     * Parser for the default PHP session serialization format
     * ("session.serialize_handler = php"): key|serialized;key|serialized...
     * Handles the value types the legacy app stores: bool, int, float,
     * null, string and nested arrays of those. Pure function of its input.
     *
     * @return array<string, mixed>
     */
    private function parseSessionFile(string $raw): array
    {
        $out = [];
        $i = 0;
        $n = strlen($raw);

        while ($i < $n) {
            $pipe = strpos($raw, '|', $i);
            if ($pipe === false) {
                break;
            }
            [$value, $next] = $this->unserializeAt($raw, $pipe + 1);
            if ($next === null) {
                break;
            }
            $out[substr($raw, $i, $pipe - $i)] = $value;
            $i = $next;
        }

        return $out;
    }

    /** @return array{0:mixed,1:?int} value and offset just past it */
    private function unserializeAt(string $s, int $i): array
    {
        $n = strlen($s);
        if ($i >= $n) {
            return [null, null];
        }

        switch ($s[$i]) {
            case 'b': // b:0; | b:1;
                $end = strpos($s, ';', $i);

                return [substr($s, $i + 2, 1) === '1', $end === false ? null : $end + 1];

            case 'i': // i:123;
                $end = strpos($s, ';', $i);

                return [(int) substr($s, $i + 2, $end - $i - 2), $end === false ? null : $end + 1];

            case 'd': // d:1.5;
                $end = strpos($s, ';', $i);

                return [(float) substr($s, $i + 2, $end - $i - 2), $end === false ? null : $end + 1];

            case 'N': // N;
                return [null, $i + 2];

            case 's': // s:LEN:"DATA";
                $colon = strpos($s, ':', $i + 2);
                if ($colon === false || $colon >= $n) {
                    return [null, null];
                }
                $len = (int) substr($s, $i + 2, $colon - $i - 2);
                $start = $colon + 2;

                return [substr($s, $start, $len), $start + $len + 2 <= $n ? $start + $len + 2 : null];

            case 'a': // a:COUNT:{k|v pairs}
                $colon = strpos($s, ':', $i + 2);
                if ($colon === false || $colon >= $n) {
                    return [null, null];
                }
                $count = (int) substr($s, $i + 2, $colon - $i - 2);
                $pos = $colon + 2;
                $arr = [];
                for ($k = 0; $k < $count; $k++) {
                    [$key, $pos] = $this->unserializeAt($s, (int) $pos);
                    if ($pos === null) {
                        return [null, null];
                    }
                    [$val, $pos] = $this->unserializeAt($s, (int) $pos);
                    if ($pos === null) {
                        return [null, null];
                    }
                    $arr[$key] = $val;
                }

                return [$arr, min($n, $pos + 1)];
        }

        return [null, null];
    }
}
