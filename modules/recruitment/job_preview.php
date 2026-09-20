<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireHRorManager();

// The preview never saves anything — it only renders the in-progress form data
// with the SAME view used by the real public page (public/job_detail.php).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect(BASE_URL . '/modules/recruitment/job_create.php');
}

csrf_require();

$employmentTypes = ['regular', 'contractual', 'probationary', 'part_time', 'internship'];
$statuses = ['open', 'closed'];

$departmentId = (int) ($_POST['department_id'] ?? 0);
$departmentName = null;
if ($departmentId > 0) {
    $stmt = db()->prepare('SELECT name FROM departments WHERE id = ?');
    $stmt->execute([$departmentId]);
    $departmentName = $stmt->fetchColumn();
    if ($departmentName !== false && $departmentName !== null) {
        $departmentName = (string) $departmentName;
    }
}

$employmentType = (string) ($_POST['job_employment_type'] ?? 'regular');
if (!in_array($employmentType, $employmentTypes, true)) {
    $employmentType = 'regular';
}

$status = (string) ($_POST['status'] ?? 'open');
if (!in_array($status, $statuses, true)) {
    $status = 'open';
}

$job = [
    'id' => 0,
    'job_code' => null,
    'title' => trim((string) ($_POST['title'] ?? '')),
    'work_location' => trim((string) ($_POST['work_location'] ?? '')),
    'job_employment_type' => $employmentType,
    'department_name' => $departmentName,
    'salary_compensation' => trim((string) ($_POST['salary_compensation'] ?? '')),
    'posted_date' => date('Y-m-d'),
    'closing_date' => trim((string) ($_POST['closing_date'] ?? '')),
    'status' => $status,
    'vacancies' => max(1, (int) ($_POST['vacancies'] ?? 1)),
    'about_role' => trim((string) ($_POST['about_role'] ?? '')),
    'description' => trim((string) ($_POST['description'] ?? '')),
    'qualifications' => trim((string) ($_POST['qualifications'] ?? '')),
    'required_skills' => trim((string) ($_POST['required_skills'] ?? '')),
    'education_requirement' => trim((string) ($_POST['education_requirement'] ?? '')),
    'experience_requirement' => trim((string) ($_POST['experience_requirement'] ?? '')),
];

$preview = true;

// Render the applicant view exactly as public applicants see it.
require __DIR__ . '/../../public/job_detail.php';