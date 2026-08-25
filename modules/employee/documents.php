<?php
/**
 * MY DOCUMENTS (employee) — the employee sees ONLY their own documents.
 * View is streamed through document_file.php which re-verifies ownership.
 * Employees may upload / replace their own certificates, IDs and general
 * documents; HR-managed records (contract, evaluation, disciplinary) are
 * read-only here.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

$user = getCurrentUser();
$employeeId = $user['employee_id'] ?? null;

if (!$employeeId) {
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="page-header fade-in-up"><div><h1 class="page-title">My Documents</h1></div></div>';
    echo '<div class="alert alert-warning">No employee profile linked to your account. Please contact HR.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

const SELF_UPLOAD_TYPES = ['certificate', 'id', 'other'];
const ALLOWED_DOC_EXT = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
const MAX_DOC_BYTES = 5 * 1024 * 1024;

$docDirRel = 'uploads/documents/emp' . (int) $employeeId;
$docDirAbs = dirname(__DIR__, 2) . '/' . $docDirRel;

/** Stores an uploaded file and returns its project-relative path, or null on failure. */
function storeEmployeeDocument(array $file, string $absDir, string $relDir): ?string
{
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset(ALLOWED_DOC_EXT[$ext])) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($file['tmp_name']) !== ALLOWED_DOC_EXT[$ext]) {
        return null;
    }
    if (!is_dir($absDir)) {
        @mkdir($absDir, 0775, true);
    }
    $storedName = bin2hex(random_bytes(8)) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $absDir . '/' . $storedName) ? $relDir . '/' . $storedName : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_document' && isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        if ((int) ($_FILES['document']['size'] ?? 0) > MAX_DOC_BYTES) {
            flash('danger', 'File is too large. Maximum size is 5 MB.');
        } else {
            $type = in_array($_POST['document_type'], SELF_UPLOAD_TYPES, true) ? $_POST['document_type'] : 'other';
            $path = storeEmployeeDocument($_FILES['document'], $docDirAbs, $docDirRel);
            if ($path) {
                db()->prepare(
                    'INSERT INTO employee_documents (employee_id, document_type, document_name, file_path)
                     VALUES (?,?,?,?)'
                )->execute([
                    $employeeId,
                    $type,
                    mb_substr(trim($_POST['document_name'] ?? 'Untitled document'), 0, 150),
                    $path,
                ]);
                flash('success', 'Document uploaded.');
            } else {
                flash('danger', 'Upload failed. Only PDF, DOC, DOCX, JPG and PNG files are allowed.');
            }
        }
        redirect(BASE_URL . '/modules/employee/documents.php');
    }

    if ($action === 'replace_document') {
        // Only rows that belong to this employee AND were self-uploaded can be replaced.
        $stmt = db()->prepare(
            "SELECT * FROM employee_documents
             WHERE id = ? AND employee_id = ? AND document_type IN ('certificate','id','other')
               AND file_path LIKE ?"
        );
        $stmt->execute([(int) $_POST['document_id'], $employeeId, $docDirRel . '/%']);
        $doc = $stmt->fetch();

        if ($doc && isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            if ((int) ($_FILES['document']['size'] ?? 0) > MAX_DOC_BYTES) {
                flash('danger', 'File is too large. Maximum size is 5 MB.');
            } else {
                $path = storeEmployeeDocument($_FILES['document'], $docDirAbs, $docDirRel);
                if ($path) {
                    db()->prepare('UPDATE employee_documents SET file_path = ?, uploaded_at = NOW() WHERE id = ?')
                        ->execute([$path, (int) $doc['id']]);
                    // Remove the previous version from disk.
                    $oldAbs = realpath(dirname(__DIR__, 2) . '/' . $doc['file_path']);
                    $dirRoot = realpath(dirname(__DIR__, 2) . '/' . $docDirRel);
                    if ($oldAbs && $dirRoot && str_starts_with($oldAbs, $dirRoot . DIRECTORY_SEPARATOR)) {
                        @unlink($oldAbs);
                    }
                    flash('success', 'Document replaced.');
                } else {
                    flash('danger', 'Replace failed. Only PDF, DOC, DOCX, JPG and PNG files are allowed.');
                }
            }
        }
        redirect(BASE_URL . '/modules/employee/documents.php');
    }
}

$stmt = db()->prepare('SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$employeeId]);
$documents = $stmt->fetchAll();

$typeIcons = [
    'resume'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'contract'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'id'           => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M15 8h4M15 12h4M7 16h10"/>',
    'certificate'  => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
    'evaluation'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'disciplinary' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    'other'        => '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>',
];

$pageTitle = 'My Documents';
$currentModule = 'my-documents';
$bodyClass = 'page-dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">My Documents</h1>
        <p class="page-subtitle">Your employment documents, certificates and files</p>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Upload a Document</h2>
    <form method="post" enctype="multipart/form-data" class="form-panel compact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_document">
        <div class="form-grid">
            <div class="form-group">
                <label for="document_name">Document Name *</label>
                <input type="text" id="document_name" name="document_name" required placeholder="e.g. TESDA Certificate">
            </div>
            <div class="form-group">
                <label for="document_type">Category</label>
                <select id="document_type" name="document_type">
                    <option value="certificate">Certificate</option>
                    <option value="id">Government ID</option>
                    <option value="other">Other Document</option>
                </select>
            </div>
            <div class="form-group">
                <label for="document">File (PDF, DOC, DOCX, JPG, PNG — max 5 MB) *</label>
                <input type="file" id="document" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Upload Document</button>
        </div>
    </form>
</section>

<div class="dashboard-grid">
    <?php if (empty($documents)): ?>
    <div class="dash-card fade-in-up" style="grid-column:1/-1;text-align:center;">
        <div class="dash-card-icon" style="margin:0 auto;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <h3>No documents yet</h3>
        <p>Your contracts and company documents will appear here once HR adds them.</p>
    </div>
    <?php else: foreach ($documents as $i => $doc): ?>
    <div class="dash-card fade-in-up" style="animation-delay:.<?= min(2 + $i, 9) ?>s">
        <div class="dash-card-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $typeIcons[$doc['document_type']] ?? $typeIcons['other'] ?></svg>
        </div>
        <h3><?= e(ucfirst(str_replace('_', ' ', $doc['document_type']))) ?></h3>
        <p><?= e($doc['document_name']) ?><br>
        <?php if (!empty($doc['issue_date'])): ?>Issued <?= formatDate($doc['issue_date']) ?><br><?php endif; ?>
        Uploaded <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></p>
        <div class="btn-group" style="margin-top:.75rem;">
            <a href="<?= BASE_URL ?>/modules/employee/document_file.php?id=<?= (int) $doc['id'] ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener">View</a>
            <?php if (in_array($doc['document_type'], SELF_UPLOAD_TYPES, true) && str_starts_with((string) $doc['file_path'], $docDirRel . '/')): ?>
            <button type="button" class="btn btn-sm btn-outline" onclick="var f=document.getElementById('replace-form-<?= (int) $doc['id'] ?>');f.hidden=!f.hidden;">Replace</button>
            <?php endif; ?>
        </div>
        <?php if (in_array($doc['document_type'], SELF_UPLOAD_TYPES, true) && str_starts_with((string) $doc['file_path'], $docDirRel . '/')): ?>
        <form method="post" enctype="multipart/form-data" id="replace-form-<?= (int) $doc['id'] ?>" hidden style="margin-top:.75rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="replace_document">
            <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
            <div class="form-group">
                <input type="file" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Save Replacement</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
