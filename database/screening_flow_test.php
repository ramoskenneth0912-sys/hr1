<?php
/**
 * Full-flow end-to-end test for the HR1 Hybrid Screening engine.
 *
 * Run:  php database/screening_flow_test.php
 *
 * Exercises the complete chain with TEMPORARY data that is deleted afterwards:
 *
 *   public application (insert) -> resume upload (temp DOCX) -> automatic
 *   screening (autoScreenApplicant) -> ai_screening DB record (hybrid-v2) ->
 *   applicant list score query -> getScreeningResult detail -> HR re-screen
 *   (runAiScreening) -> API-style re-screen -> cleanup.
 *
 * Also verifies pre-existing rows were NOT relabeled (screening_version NULL).
 * Requires an OPEN "Warehouse Associate" job posting.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai_screening.php';

$pass = 0;
$fail = 0;

function f(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function makeTestDocx(string $path, array $lines): bool
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>');
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
    foreach ($lines as $line) {
        $xml .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
    }
    $xml .= '</w:body></w:document>';
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    return true;
}

$waJob = null;
$opts = db()->prepare("SELECT id, title FROM job_postings WHERE status = 'open'");
$opts->execute();
foreach ($opts->fetchAll() as $row) {
    if (strcasecmp($row['title'], 'Warehouse Associate') === 0) {
        $waJob = $row;
        break;
    }
}

if (!$waJob) {
    echo "Cannot run flow test: no open 'Warehouse Associate' posting exists.\n";
    exit(2);
}

$jobId = (int) $waJob['id'];
$email = 'flowtest.' . time() . '.' . random_int(1000, 9999) . '@example.test';

$resumeRelPath = 'uploads/screening_flow_test_' . time() . '.docx';
$resumeAbsPath = dirname(__DIR__) . '/' . $resumeRelPath;
$applicantId = 0;

try {
    echo "== Full flow: public application -> screening -> DB -> list -> detail -> rescreen ==\n";

    // 1) Resume upload (temp DOCX with the Kargador-style content).
    f('create temp DOCX resume',
        makeTestDocx($resumeAbsPath, [
            'PEDRO KARGADOR',
            'Kargador',
            'Responsible for loading and unloading delivery trucks, arranging stocks inside the warehouse, checking inventory, and assisting with delivery preparation.',
            '2 years experience.',
        ]),
        $resumeAbsPath);

    $extracted = extractResumeText($resumeAbsPath);
    f('resume parser extracts text from the temp DOCX', stripos($extracted, 'Kargador') !== false, 'len=' . strlen($extracted));

    // 2) Public application insert (mirrors public/apply.php column set).
    $applicantNo = 'APP' . str_pad(((int) db()->query("SELECT MAX(CAST(SUBSTRING(applicant_no, 4) AS UNSIGNED)) AS m FROM applicants WHERE applicant_no LIKE 'APP%'")->fetch()['m']) + 1, 5, '0', STR_PAD_LEFT);
    db()->prepare(
        'INSERT INTO applicants
            (applicant_no, first_name, last_name, email, phone, address, position_applied, department_id,
             job_posting_id, resume_path, status, applied_date, education, skills, work_experience, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $applicantNo, 'ZZFlow', 'Test', $email, '09170000000', 'Test address',
        'Kargador', $jobId, $resumeRelPath, 'new', date('Y-m-d'),
        'High School Graduate', 'warehousing, inventory, forklift',
        'Kargador — loading/unloading, warehouse stocking, inventory assistance.',
        'Automated flow-test applicant — delete me.',
    ]);
    $applicantId = (int) db()->lastInsertId();
    f('applicant created', $applicantId > 0, 'id=' . $applicantId);

    // 3) Automatic screening (the apply.php post-submit path).
    $result = autoScreenApplicant($applicantId);
    f('autoScreenApplicant succeeded', !empty($result['success']), $result['error'] ?? '');

    // 4) ai_screening DB record is created with hybrid metadata.
    $row = db()->prepare('SELECT * FROM ai_screening WHERE applicant_id = ? AND job_posting_id = ? ORDER BY id DESC LIMIT 1');
    $row->execute([$applicantId, $jobId]);
    $record = $row->fetch();
    f('ai_screening record stored', (bool) $record, '');
    f('record status = analyzed', ($record['status'] ?? '') === 'analyzed', $record['status'] ?? 'none');
    f('record screening_version = hybrid-v2', ($record['screening_version'] ?? '') === 'hybrid-v2', $record['screening_version'] ?? 'null');
    f('record confidence set', in_array($record['confidence'] ?? '', ['High', 'Medium', 'Low'], true), $record['confidence'] ?? 'none');
    f('partial_requirements is valid JSON', is_array(json_decode((string) $record['partial_requirements'], true)), (string) $record['partial_requirements']);
    f('evidence is valid JSON', is_array(json_decode((string) $record['evidence'], true)), '');
    f('concerns is valid JSON', is_array(json_decode((string) $record['concerns'], true)), '');
    f('overall score recorded (> 0)', (int) ($record['overall_score'] ?? 0) > 0, 'score=' . $record['overall_score'] ?? 'n/a');
    f('experience credited as relevant (>= 50)', (int) ($record['experience_score'] ?? 0) >= 50, 'exp=' . ($record['experience_score'] ?? 'n/a'));

    // 5) getScreeningResult (screening_view.php path) exposes the fields.
    $detail = getScreeningResult($applicantId);
    f('getScreeningResult returns hybrid fields', $detail && $detail['screening_version'] === 'hybrid-v2',
        $detail['screening_version'] ?? 'none');
    f('getScreeningResult returns job title from DB', ($detail['job_title'] ?? '') === 'Warehouse Associate', $detail['job_title'] ?? 'none');

    // 6) Applicant list score query (mirrors modules/applicants/index.php).
    $list = db()->prepare(
        'SELECT a.id, a.first_name, a.last_name, s.overall_score, s.recommendation, s.confidence, s.screening_version
         FROM applicants a
         LEFT JOIN (
             SELECT applicant_id, MAX(id) AS max_id FROM ai_screening GROUP BY applicant_id
         ) lm ON lm.applicant_id = a.id
         LEFT JOIN ai_screening s ON s.id = lm.max_id
         WHERE a.id = ?'
    );
    $list->execute([$applicantId]);
    $listRow = $list->fetch();
    f('applicant list row carries score + confidence + version',
        $listRow && (int) $listRow['overall_score'] > 0 && in_array($listRow['confidence'], ['High', 'Medium', 'Low'], true) && $listRow['screening_version'] === 'hybrid-v2',
        json_encode($listRow ?: []));

    // 7) HR/Admin re-run screening (screening.php path).
    $re = runAiScreening($applicantId, $jobId, true, 'flow-test-runner');
    f('HR re-screen succeeded', !empty($re['success']), $re['error'] ?? '');
    f('re-screen advanced applicant status to screening',
        db()->query("SELECT status FROM applicants WHERE id = $applicantId")->fetchColumn() === 'screening',
        db()->query("SELECT status FROM applicants WHERE id = $applicantId")->fetchColumn());

    // 8) API-style application screening (ApplicationsController path uses the same runAiScreening).
    $api = runAiScreening($applicantId, $jobId, true, 'api-app');
    f('API-style screening succeeded', !empty($api['success']), $api['error'] ?? '');
    f('API-style result includes version + confidence',
        ($api['screening_version'] ?? '') === 'hybrid-v2' && ($api['confidence'] ?? '') !== '',
        ($api['screening_version'] ?? 'none') . ' / ' . ($api['confidence'] ?? 'none'));

    // 9) Legacy rows were NOT relabeled.
    $legacy = db()->query("SELECT COUNT(*) FROM ai_screening WHERE screening_version IS NULL")->fetchColumn();
    f('existing (pre-migration) rows still have screening_version NULL', (int) $legacy >= 43, "legacy_rows=$legacy");
} finally {
    // Cleanup: remove temp applicant (cascade deletes its ai_screening rows) + file.
    if ($applicantId > 0) {
        db()->prepare('DELETE FROM applicants WHERE id = ? AND last_name = ?')->execute([$applicantId, 'Test']);
        $leftover = db()->prepare('SELECT COUNT(*) FROM ai_screening WHERE applicant_id = ?');
        $leftover->execute([$applicantId]);
        f('cleanup: temp applicant + cascaded ai_screening rows removed', (int) $leftover->fetchColumn() === 0, '');
    }
    if (is_file($resumeAbsPath)) {
        @unlink($resumeAbsPath);
    }
    f('cleanup: temp resume file removed', !is_file($resumeAbsPath), '');
}

echo "\n----------------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);