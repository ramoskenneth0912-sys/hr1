<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
$pageTitle = 'Recruitment Management';
$currentModule = 'recruitment';
require_once __DIR__ . '/../../includes/header.php';

$jobs = db()->query(
    'SELECT j.*, d.name AS department_name FROM job_postings j
     LEFT JOIN departments d ON j.department_id = d.id
     WHERE j.status <> \'inactive\'
     ORDER BY j.created_at DESC'
)->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Recruitment Management</h1>
        <p class="page-subtitle"></p>
    </div>
    <div class="btn-group">
        <a href="job_create.php" class="btn btn-primary">+ Job Posting</a>
        <a href="departments.php" class="btn btn-outline">Departments</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2 id="job-postings">Job Postings</h2>
    <div class="table-wrap">
    <table class="data-table" id="jobsTable">
        <thead>
            <tr>
                <th>Job Code</th>
                <th>Title</th>
                <th>Department</th>
                <th>Vacancies</th>
                <th>Status</th>
                <th>Posted</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($jobs)): ?>
            <tr><td colspan="7" class="empty">No active job postings available.</td></tr>
            <?php else: foreach ($jobs as $row): ?>
            <tr data-job-id="<?= (int) $row['id'] ?>">
                <td><?= e($row['job_code']) ?></td>
                <td><?= e($row['title']) ?></td>
                <td><?= e($row['department_name'] ?? '—') ?></td>
                <td><?= (int) $row['vacancies'] ?></td>
                <td><?= statusBadge($row['status']) ?></td>
                <td><?= formatDate($row['posted_date']) ?></td>
                <td class="actions-cell">
                    <a href="job_edit.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    <form method="post" action="job_remove.php" class="job-remove-form" data-title="<?= e($row['title']) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline btn-remove" data-confirm-removejob>Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<!-- Remove job posting confirmation dialog (keeps the action as POST + CSRF) -->
<div id="jobRemoveConfirm" class="logout-modal" hidden role="dialog" aria-modal="true" aria-labelledby="jobRemoveConfirmTitle" aria-describedby="jobRemoveConfirmText">
    <div class="logout-modal-backdrop" data-jobremove-close></div>
    <div class="logout-modal-box">
        <h3 id="jobRemoveConfirmTitle">Remove this job posting?</h3>
        <p id="jobRemoveConfirmText">Are you sure you want to remove this job posting?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn btn-outline" data-jobremove-close>Cancel</button>
            <button type="button" class="btn btn-danger-solid" data-jobremove-confirm>Remove</button>
        </div>
    </div>
</div>

<style>
.actions-cell { white-space: nowrap; }
.actions-cell .btn { display: inline-flex; align-items: center; }
.job-remove-form { display: inline-block; margin-left: .5rem; }
.btn-remove { color: var(--danger); border-color: rgba(var(--danger-rgb, 220,53,69), .35); }
.btn-remove:hover { background: var(--danger); border-color: var(--danger); color: #fff; }
.job-remove-toast {
    position: fixed; top: 20px; right: 20px; z-index: 3000;
    max-width: 360px; box-shadow: 0 16px 40px -16px rgba(27,37,89,.45);
    animation: jobToastIn .2s ease;
}
@keyframes jobToastIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>

<script>
(function () {
    var modal = document.getElementById('jobRemoveConfirm');
    if (!modal) return;

    var table = document.getElementById('jobsTable');
    var pendingForm = null;
    var toastTimer = null;

    function open() { modal.hidden = false; document.addEventListener('keydown', onKey, true); }
    function close() { modal.hidden = true; pendingForm = null; document.removeEventListener('keydown', onKey, true); }
    function showToast(type, message) {
        var toast = document.getElementById('jobRemoveToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'jobRemoveToast';
            document.body.appendChild(toast);
        }
        toast.className = 'alert alert-' + type + ' job-remove-toast';
        toast.textContent = message;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { var t = document.getElementById('jobRemoveToast'); if (t) t.remove(); }, 4000);
    }
    function checkEmpty() {
        var rows = table.querySelectorAll('tbody tr[data-job-id]');
        if (rows.length) return;
        table.querySelector('tbody').innerHTML =
            '<tr><td colspan="7" class="empty">No active job postings available.</td></tr>';
    }
    function onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); close(); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            e.stopPropagation();
            confirmRemove();
        }
    }

    function doRemove(f) {
        if (!f) return;
        if (typeof fetch !== 'function') { f.requestSubmit(); return; }
        var fd = new FormData(f);
        fetch(f.getAttribute('action') || 'job_remove.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (data) {
                return { status: r.status, data: data };
            });
        }).then(function (res) {
            if (res.data && (res.data.ok || res.data.success)) {
                var tr = f.closest('tr');
                if (tr) tr.remove();
                checkEmpty();
                showToast('success', res.data.message || 'Job posting removed successfully.');
            } else {
                showToast('danger', (res.data && (res.data.error || res.data.message)) || 'Unable to remove the job posting. Please try again.');
            }
        }).catch(function () {
            showToast('danger', 'Unable to remove the job posting. Please try again.');
        });
    }

    function confirmRemove() {
        var f = pendingForm;
        close();
        if (!f) return;
        doRemove(f);
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm-removejob]');
        if (!btn) return;
        var f = btn && btn.form;
        if (!f || f.method.toUpperCase() !== 'POST') return;
        pendingForm = f;
        var title = f.getAttribute('data-title') || 'this job posting';
        var textEl = document.getElementById('jobRemoveConfirmText');
        if (textEl) {
            textEl.textContent =
                'Are you sure you want to remove "' + title + '" from the active job postings?';
        }
        e.preventDefault();
        open();
    });

    modal.addEventListener('click', function (e) {
        if (e.target.closest('[data-jobremove-close]')) { close(); return; }
        if (e.target.closest('[data-jobremove-confirm]')) { confirmRemove(); }
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>