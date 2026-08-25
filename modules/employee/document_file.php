<?php
/**
 * Streams ONE document — only if it belongs to the logged-in employee.
 * Paths are confined to the project's /uploads directory.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

$user = getCurrentUser();
$employeeId = $user['employee_id'] ?? null;
$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM employee_documents WHERE id = ? AND employee_id = ? LIMIT 1');
$stmt->execute([$id, $employeeId ?: 0]);
$doc = $stmt->fetch();

if (!$doc || empty($doc['file_path'])) {
    flash('danger', 'Document not found.');
    redirect(BASE_URL . '/modules/employee/documents.php');
}

$baseDir = dirname(__DIR__, 2);
$uploadsRoot = realpath($baseDir . '/uploads');
$relative = str_replace('\\', '/', ltrim((string) $doc['file_path'], '/'));
if (str_starts_with($relative, 'uploads/')) {
    $relative = substr($relative, strlen('uploads/'));
}
$fullPath = realpath($baseDir . '/uploads/' . $relative);

if (!$uploadsRoot || !$fullPath || !str_starts_with($fullPath, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($fullPath)) {
    flash('danger', 'Document file is no longer available.');
    redirect(BASE_URL . '/modules/employee/documents.php');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$contentTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];

header('Content-Type: ' . ($contentTypes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\-]/', '_', (string) $doc['document_name']) . '.' . $ext . '"');
readfile($fullPath);
exit;
