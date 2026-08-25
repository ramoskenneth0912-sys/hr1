<?php
/**
 * CLI-only tool: create the initial HR account or reset an existing
 * account's password. Never web-accessible; never logs or stores the
 * generated password — it is printed ONCE to this local console.
 *
 * Usage (from the project root):
 *   php database/create_admin.php                -> prompts for a username
 *   php database/create_admin.php <username>     -> resets that account
 *
 * If the username does not exist, an HR account is created with the
 * email you supply at the prompt. Existing accounts keep their email,
 * phone, role and employee link — only password_hash changes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/database.php';

function prompt(string $label, string $default = ''): string
{
    echo $label . ($default !== '' ? " [$default]" : '') . ': ';
    $value = trim((string) fgets(STDIN));
    return $value !== '' ? $value : $default;
}

function generateStrongPassword(int $length = 20): string
{
    // Unambiguous alphabet (no look-alikes), cryptographically random.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*()-_=+';
    $max = strlen($alphabet) - 1;
    $pw = '';
    for ($i = 0; $i < $length; $i++) {
        $pw .= $alphabet[random_int(0, $max)];
    }
    return $pw;
}

$username = $argv[1] ?? '';
if ($username === '') {
    $username = prompt('Username to create or reset');
}
$username = trim($username);
if ($username === '' || !preg_match('/^[\w.@-]{2,80}$/', $username)) {
    fwrite(STDERR, "Invalid username.\n");
    exit(1);
}

try {
    $stmt = db()->prepare('SELECT id, username, email FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        echo "Resetting password for existing account '{$user['username']}' (email/role unchanged).\n";
        $userId = (int) $user['id'];
        $email = $user['email'];
    } else {
        $email = filter_var(prompt('New account — email address'), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            fwrite(STDERR, "Invalid email address.\n");
            exit(1);
        }
        $dupe = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $dupe->execute([$email]);
        if ((int) $dupe->fetchColumn() > 0) {
            fwrite(STDERR, "That email is already used by another account.\n");
            exit(1);
        }
        $insert = db()->prepare(
            "INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, '', 'hr', 1)"
        );
        $insert->execute([$username, $email]);
        $userId = (int) db()->lastInsertId();
        echo "Created new HR account '{$username}'.\n";
    }

    $password = generateStrongPassword();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $update = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $update->execute([$hash, $userId]);

    echo "\n============================================================\n";
    echo " One-time password for '{$username}' (shown only here):\n\n";
    echo "     {$password}\n\n";
    echo " Log in and change it via Settings > Security.\n";
    echo " This value is NOT stored in any file or log by this tool.\n";
    echo "============================================================\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}
