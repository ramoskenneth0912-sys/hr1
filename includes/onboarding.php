<?php
/**
 * HR1 Applicant Onboarding Workflow helpers.
 *
 * Manages the stage-tracked onboarding process for hired applicants:
 *   Hired → Orientation Scheduled → Orientation Completed →
 *   Documents Submitted → Documents Verified → Employee Account Created →
 *   Onboarding Completed → Active Employee
 *
 * Reuses existing hr1Sendmail(), employee/user creation patterns,
 * document upload infrastructure, and notification system.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security_log.php';

/** Ordered onboarding stages (index = stage order). */
function onboardingStages(): array
{
    return [
        'orientation_scheduled',
        'orientation_completed',
        'documents_submitted',
        'documents_verified',
        'account_created',
        'onboarding_completed',
    ];
}

function onboardingStageLabel(string $stage): string
{
    $labels = [
        'orientation_scheduled' => 'Orientation Scheduled',
        'orientation_completed' => 'Orientation Completed',
        'documents_submitted'   => 'Requirements / Documents',
        'documents_verified'    => 'Documents Verified',
        'account_created'       => 'Employee Account Created',
        'onboarding_completed'  => 'Onboarding Completed',
    ];
    return $labels[$stage] ?? ucfirst(str_replace('_', ' ', $stage));
}

/** Check if a stage has been reached or completed. */
function onboardingStageReached(string $currentStage, string $checkStage): bool
{
    $stages = onboardingStages();
    $currentIdx = array_search($currentStage, $stages);
    $checkIdx = array_search($checkStage, $stages);
    if ($currentIdx === false || $checkIdx === false) {
        return false;
    }
    return $currentIdx >= $checkIdx;
}

/** Can the applicant advance from currentStage to the next stage? */
function canAdvanceOnboarding(string $currentStage): bool
{
    $stages = onboardingStages();
    $idx = array_search($currentStage, $stages);
    if ($idx === false || $idx >= count($stages) - 1) {
        return false;
    }
    return true;
}

/** Advance to the next stage, or mark completed if at the end. Returns [ok, message]. */
function advanceOnboardingStage(int $applicantId): array
{
    $stages = onboardingStages();
    $stmt = db()->prepare('SELECT * FROM onboarding_progress WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    $progress = $stmt->fetch();
    if (!$progress) {
        return [false, 'No onboarding record found.'];
    }
    $idx = array_search($progress['current_stage'], $stages);
    if ($idx === false) {
        return [false, 'Invalid current onboarding stage.'];
    }
    if ($idx >= count($stages) - 1) {
        return [false, 'Onboarding is already completed.'];
    }
    $nextStage = $stages[$idx + 1];
    $updates = ['current_stage' => $nextStage];
    if ($nextStage === 'orientation_completed') {
        $updates['orientation_completed_at'] = date('Y-m-d H:i:s');
    } elseif ($nextStage === 'documents_verified') {
        $updates['documents_verified_at'] = date('Y-m-d H:i:s');
    } elseif ($nextStage === 'account_created') {
        $updates['account_created_at'] = date('Y-m-d H:i:s');
    } elseif ($nextStage === 'onboarding_completed') {
        $updates['onboarding_completed_at'] = date('Y-m-d H:i:s');
    }
    $sets = [];
    $params = [];
    foreach ($updates as $k => $v) {
        $sets[] = "{$k} = ?";
        $params[] = $v;
    }
    $params[] = $applicantId;
    db()->prepare('UPDATE onboarding_progress SET ' . implode(', ', $sets) . ' WHERE applicant_id = ?')
        ->execute($params);
    db()->prepare('UPDATE applicants SET onboarding_stage = ? WHERE id = ?')
        ->execute([$nextStage, $applicantId]);
    securityLog('onboarding_stage_advanced', "applicant_id={$applicantId} stage={$nextStage}", $_SESSION['user_id'] ?? null);
    return [true, onboardingStageLabel($nextStage)];
}

/** Get or create onboarding_progress record for an applicant. Returns the row. */
function getOrCreateOnboardingProgress(int $applicantId): ?array
{
    $stmt = db()->prepare('SELECT * FROM onboarding_progress WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    db()->prepare(
        'INSERT INTO onboarding_progress (applicant_id, current_stage, started_at) VALUES (?, ?, NOW())'
    )->execute([$applicantId, 'orientation_scheduled']);
    db()->prepare('UPDATE applicants SET onboarding_status = 1, onboarding_stage = ? WHERE id = ?')
        ->execute(['orientation_scheduled', $applicantId]);
    $stmt2 = db()->prepare('SELECT * FROM onboarding_progress WHERE applicant_id = ?');
    $stmt2->execute([$applicantId]);
    return $stmt2->fetch() ?: null;
}

/** Get the onboarding progress row (does not auto-create). */
function getOnboardingProgress(int $applicantId): ?array
{
    $stmt = db()->prepare('SELECT * FROM onboarding_progress WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    return $stmt->fetch() ?: null;
}

/**
 * Check if all required onboarding documents are verified.
 * Returns true only when at least one document of each required type
 * exists with verification_status = 'verified'.
 */
function allRequiredDocumentsVerified(int $applicantId): bool
{
    $requiredTypes = ['government_id', 'contract', 'diploma', 'medical_certificate', 'nbi_clearance'];
    $stmt = db()->prepare(
        'SELECT document_type, COUNT(*) AS cnt
         FROM onboarding_documents
         WHERE applicant_id = ? AND verification_status = ?
         GROUP BY document_type'
    );
    $stmt->execute([$applicantId, 'verified']);
    $verified = [];
    while ($row = $stmt->fetch()) {
        $verified[$row['document_type']] = (int) $row['cnt'];
    }
    foreach ($requiredTypes as $type) {
        if (empty($verified[$type])) {
            return false;
        }
    }
    return true;
}

/**
 * Get document status summary for the required document types.
 * Returns an array of [type => [required, submitted, verified, rejected]].
 */
function onboardingDocumentStatus(int $applicantId): array
{
    $requiredTypes = [
        'government_id'       => 'Government IDs (SSS, PhilHealth, Pag-IBIG, TIN)',
        'contract'            => 'Employment Contract',
        'diploma'             => 'Diploma / Transcript of Records',
        'medical_certificate' => 'Medical Certificate',
        'nbi_clearance'       => 'NBI Clearance',
    ];
    $result = [];
    foreach ($requiredTypes as $type => $label) {
        $stmt = db()->prepare(
            'SELECT id, document_name, file_path, verification_status, rejection_reason, uploaded_at
             FROM onboarding_documents
             WHERE applicant_id = ? AND document_type = ?
             ORDER BY uploaded_at DESC LIMIT 1'
        );
        $stmt->execute([$applicantId, $type]);
        $doc = $stmt->fetch();
        $result[$type] = [
            'label'    => $label,
            'required' => true,
            'document' => $doc,
            'status'   => $doc ? $doc['verification_status'] : 'missing',
        ];
    }
    // Also fetch any extra (non-required) uploaded documents
    $extraStmt = db()->prepare(
        'SELECT * FROM onboarding_documents WHERE applicant_id = ? AND document_type NOT IN (?,?,?,?,?) ORDER BY uploaded_at DESC'
    );
    $extraStmt->execute([$applicantId, ...array_keys($requiredTypes)]);
    $result['_extra'] = $extraStmt->fetchAll();
    return $result;
}

/**
 * Create employee record + user account from an applicant during onboarding.
 * Returns [ok, message, employeeNo?, tempPassword?].
 * The applicant record is NOT deleted or modified (they become an employee).
 */
function createEmployeeAccountFromApplicant(array $applicant, int $createdBy): array
{
    if (empty($applicant['email']) || filter_var($applicant['email'], FILTER_VALIDATE_EMAIL) === false) {
        return [false, 'Applicant does not have a valid email address for account creation.'];
    }
    $email = strtolower(trim($applicant['email']));
    $existingUser = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existingUser->execute([$email]);
    if ($existingUser->fetch()) {
        return [false, 'A user account with this email already exists.'];
    }
    $employeeNo = generateCode('E', 'employees', 'employee_no', 3);
    $tempPassword = bin2hex(random_bytes(6));
    try {
        db()->beginTransaction();
        $hireDate = date('Y-m-d');
        db()->prepare(
            'INSERT INTO employees (employee_no, first_name, last_name, email, phone, department_id, job_title, employment_type, hire_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $employeeNo,
            $applicant['first_name'],
            $applicant['last_name'],
            $email,
            $applicant['phone'] ?? '',
            $applicant['department_id'] ?? null,
            $applicant['position_applied'],
            'regular',
            $hireDate,
            'active',
        ]);
        $employeeId = (int) db()->lastInsertId();
        db()->prepare(
            'INSERT INTO users (username, email, password_hash, role, employee_id, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([
            $email,
            $email,
            password_hash($tempPassword, PASSWORD_DEFAULT),
            'employee',
            $employeeId,
        ]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('createEmployeeAccountFromApplicant failed: ' . $e->getMessage());
        return [false, 'Failed to create employee account.'];
    }
    securityLog('onboarding_account_created', "applicant_id={$applicant['id']} employee_id={$employeeId} employee_no={$employeeNo}", $createdBy);
    return [true, "Employee account created. Employee No: {$employeeNo}", $employeeNo, $tempPassword];
}

/**
 * Check if orientation has been completed for an applicant.
 */
function orientationCompleted(int $applicantId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM orientation_schedules WHERE applicant_id = ? AND is_completed = 1 LIMIT 1');
    $stmt->execute([$applicantId]);
    return (bool) $stmt->fetch();
}
