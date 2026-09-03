<?php
/**
 * /api/v1/jobs — public read access, hr/manager write access.
 * Also serves the admin endpoints (delegated from AdminController).
 */

declare(strict_types=1);

class JobsController
{
    /** Columns a client may send when creating/updating a job. */
    private const EDITABLE = [
        'title', 'department_id', 'description', 'requirements', 'qualifications',
        'required_skills', 'education_requirement', 'experience_requirement',
        'work_location', 'job_employment_type', 'vacancies', 'status', 'posted_date', 'closing_date',
    ];

    private const JOB_STATUSES = ['draft', 'open', 'closed', 'filled'];
    private const EMPLOYMENT_TYPES = ['regular', 'contractual', 'probationary', 'part_time', 'internship'];

    // ---- GET /jobs ---------------------------------------------------------

    public static function index(bool $adminView = false): never
    {
        if (!$adminView && Auth::role() === 'employee') {
            Response::forbidden('Employees cannot browse job listings through the API.');
        }
        $isAdmin = Auth::isAdmin();
        $page = max(1, (int) (api_query('page') ?? 1));
        $limit = min(100, max(1, (int) (api_query('limit') ?? 10)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        $requestedStatus = api_query('status');
        if ($adminView || $isAdmin) {
            if ($requestedStatus !== null && in_array(strtolower($requestedStatus), self::JOB_STATUSES, true)) {
                $where[] = 'j.status = :status';
                $params[':status'] = strtolower($requestedStatus);
            }
        } else {
            // Public listing only exposes OPEN positions
            $where[] = "j.status = 'open'";
        }

        $search = api_query('search');
        if ($search !== null) {
            if ($adminView || $isAdmin) {
                $where[] = '(j.title LIKE :q1 OR j.job_code LIKE :q2 OR j.description LIKE :q3 OR j.required_skills LIKE :q4)';
            } else {
                $where[] = '(j.title LIKE :q1 OR j.description LIKE :q2 OR j.required_skills LIKE :q3)';
            }
            $params[':q1'] = '%' . $search . '%';
            $params[':q2'] = '%' . $search . '%';
            $params[':q3'] = '%' . $search . '%';
            if ($adminView || $isAdmin) {
                $params[':q4'] = '%' . $search . '%';
            }
        }

        if ($loc = api_query('location')) {
            $where[] = 'j.work_location LIKE :loc';
            $params[':loc'] = '%' . $loc . '%';
        }

        if ($cat = api_query('category')) {
            $where[] = 'd.name LIKE :cat';
            $params[':cat'] = '%' . $cat . '%';
        }

        if ($deptId = api_query('department_id')) {
            if (ctype_digit($deptId)) {
                $where[] = 'j.department_id = :dept';
                $params[':dept'] = (int) $deptId;
            }
        }

        $empType = Auth::normalizeEmployment(api_query('employment_type'));
        if ($empType !== null) {
            $where[] = 'j.job_employment_type = :et';
            $params[':et'] = $empType;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = db()->prepare(
            "SELECT COUNT(*) AS c FROM job_postings j LEFT JOIN departments d ON d.id = j.department_id $whereSql"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = db()->prepare(
            "SELECT j.id, j.job_code, j.title, j.department_id, d.name AS department,
                    j.description, j.requirements, j.qualifications, j.required_skills,
                    j.education_requirement, j.experience_requirement, j.work_location AS location,
                    j.job_employment_type AS employment_type, j.vacancies, j.status,
                    j.posted_date, j.closing_date, j.created_at, j.updated_at
             FROM job_postings j LEFT JOIN departments d ON d.id = j.department_id
             $whereSql
             ORDER BY j.posted_date DESC, j.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        Response::list($stmt->fetchAll(), $adminView ? 'Jobs retrieved successfully' : 'Open jobs retrieved successfully', $page, $limit, $total);
    }

    // ---- GET /jobs/{id} ------------------------------------------------------

    public static function show(int $id): never
    {
        if (Auth::role() === 'employee') {
            Response::forbidden('Employees cannot view job details through the API.');
        }
        $stmt = db()->prepare(
            'SELECT j.id, j.job_code, j.title, j.department_id, d.name AS department,
                    j.description, j.requirements, j.qualifications, j.required_skills,
                    j.education_requirement, j.experience_requirement, j.work_location AS location,
                    j.job_employment_type AS employment_type, j.vacancies, j.status,
                    j.posted_date, j.closing_date, j.created_at, j.updated_at
             FROM job_postings j LEFT JOIN departments d ON d.id = j.department_id
             WHERE j.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $job = $stmt->fetch();

        if (!$job) {
            Response::notFound('Job not found.');
        }
        if ($job['status'] !== 'open' && !Auth::isAdmin()) {
            Response::notFound('Job not found.'); // hide non-open jobs from the public
        }
        Response::item($job, 'Job retrieved successfully');
    }

    // ---- POST /jobs ----------------------------------------------------------

    public static function store(): never
    {
        Auth::requireAdmin(true); // API keys with jobs:write scope allowed (validated by router)
        $in = api_body();

        $in['employment_type'] = Auth::normalizeEmployment(
            $in['employment_type'] ?? $in['job_employment_type'] ?? null
        );
        unset($in['job_employment_type']);

        $errors = [];
        if (!isset($in['title']) || trim((string) $in['title']) === '') {
            $errors['title'] = 'Title is required.';
        }
        if ((int) ($in['vacancies'] ?? 1) < 0) {
            $errors['vacancies'] = 'Vacancies must be an integer >= 0.';
        }
        if (isset($in['department_id']) && !self::departmentExists((int) $in['department_id'])) {
            $errors['department_id'] = 'Department does not exist.';
        }
        if (isset($in['employment_type']) && !in_array($in['employment_type'], self::EMPLOYMENT_TYPES, true)) {
            $errors['employment_type'] = 'Must be one of: regular, contractual, probationary, part_time, internship.';
        }
        foreach (['posted_date', 'closing_date'] as $f) {
            if (isset($in[$f]) && trim((string) $in[$f]) !== '') {
                $d = DateTime::createFromFormat('Y-m-d', trim((string) $in[$f]));
                if (!$d || $d->format('Y-m-d') !== trim((string) $in[$f])) {
                    $errors[$f] = 'Must use YYYY-MM-DD format.';
                } elseif (trim((string) $in[$f]) <= date('Y-m-d')) {
                    $errors[$f] = 'Date must be a future date.';
                }
            }
        }
        if (isset($in['posted_date'], $in['closing_date'])
            && trim((string) $in['posted_date']) !== ''
            && trim((string) $in['closing_date']) !== ''
            && trim((string) $in['closing_date']) <= trim((string) $in['posted_date'])) {
            $errors['closing_date'] = 'Closing date must be later than the posting date.';
        }
        if (isset($in['status']) && !in_array(strtolower((string) $in['status']), self::JOB_STATUSES, true)) {
            $errors['status'] = 'Must be one of: draft, open, closed, filled.';
        }
        if ($errors) {
            Response::validation($errors);
        }

        $data = self::filterEditable($in);
        $data['title'] = mb_substr(trim((string) $in['title']), 0, 150);
        $data['status'] = strtolower((string) ($data['status'] ?? 'draft'));
        $data['job_employment_type'] = $data['employment_type'] ?? 'regular';
        unset($data['employment_type']);
        if (($data['status'] ?? '') === 'open' && empty($data['posted_date'])) {
            $data['posted_date'] = date('Y-m-d');
        }
        if (empty($data['vacancies'])) {
            $data['vacancies'] = 1;
        }

        $code = generateCode('JOB', 'job_postings', 'job_code');

        $stmt = db()->prepare(
            'INSERT INTO job_postings
                (job_code, title, department_id, description, requirements, qualifications, required_skills,
                 education_requirement, experience_requirement, work_location, job_employment_type,
                 vacancies, status, posted_date, closing_date)
             VALUES (:job_code, :title, :department_id, :description, :requirements, :qualifications, :required_skills,
                 :education_requirement, :experience_requirement, :work_location, :job_employment_type,
                 :vacancies, :status, :posted_date, :closing_date)'
        );
        $stmt->execute([
            ':job_code' => $code,
            ':title' => $data['title'],
            ':department_id' => $data['department_id'] ?? null,
            ':description' => $data['description'] ?? null,
            ':requirements' => $data['requirements'] ?? null,
            ':qualifications' => $data['qualifications'] ?? null,
            ':required_skills' => $data['required_skills'] ?? null,
            ':education_requirement' => $data['education_requirement'] ?? null,
            ':experience_requirement' => $data['experience_requirement'] ?? null,
            ':work_location' => $data['work_location'] ?? null,
            ':job_employment_type' => $data['job_employment_type'],
            ':vacancies' => (int) ($data['vacancies'] ?? 1),
            ':status' => $data['status'],
            ':posted_date' => $data['posted_date'] ?? null,
            ':closing_date' => $data['closing_date'] ?? null,
        ]);
        $id = (int) db()->lastInsertId();

        $stmt = db()->prepare(
            'SELECT j.id, j.job_code, j.title, j.department_id, d.name AS department,
                    j.description, j.requirements, j.qualifications, j.required_skills,
                    j.education_requirement, j.experience_requirement, j.work_location AS location,
                    j.job_employment_type AS employment_type, j.vacancies, j.status,
                    j.posted_date, j.closing_date, j.created_at, j.updated_at
             FROM job_postings j LEFT JOIN departments d ON d.id = j.department_id
             WHERE j.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        Response::created($stmt->fetch() ?: [], 'Job created successfully');
    }

    // ---- PUT/PATCH /jobs/{id} --------------------------------------------------

    public static function update(int $id, bool $partial): never
    {
        Auth::requireAdmin(true); // API keys with jobs:write scope allowed

        $stmt = db()->prepare('SELECT id FROM job_postings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            Response::notFound('Job not found.');
        }

        $in = api_body();
        if ($partial) {
            $in = array_intersect_key($in, array_flip(self::EDITABLE + ['employment_type']));
        } else {
            // Full update requires the core fields
            if (!isset($in['title']) || trim((string) $in['title']) === '') {
                Response::validation(['title' => 'Title is required.']);
            }
        }
        if ($in === []) {
            Response::badRequest('No updatable fields provided.', [
                'fields' => 'Allowed: ' . implode(', ', self::EDITABLE),
            ]);
        }

        $errors = [];
        if (array_key_exists('employment_type', $in)) {
            $norm = Auth::normalizeEmployment((string) $in['employment_type']);
            if ($norm === null) {
                $errors['employment_type'] = 'Must be one of: regular, contractual, probationary, part_time, internship.';
            } else {
                $in['job_employment_type'] = $norm;
            }
        }
        if (array_key_exists('status', $in) && !in_array(strtolower(trim((string) $in['status'])), self::JOB_STATUSES, true)) {
            $errors['status'] = 'Must be one of: draft, open, closed, filled.';
        }
        if (array_key_exists('department_id', $in) && !self::departmentExists((int) $in['department_id'])) {
            $errors['department_id'] = 'Department does not exist.';
        }
        foreach (['posted_date', 'closing_date'] as $f) {
            if (array_key_exists($f, $in) && trim((string) ($in[$f] ?? '')) !== '') {
                $d = DateTime::createFromFormat('Y-m-d', trim((string) $in[$f]));
                if (!$d || $d->format('Y-m-d') !== trim((string) $in[$f])) {
                    $errors[$f] = 'Must use YYYY-MM-DD format.';
                } elseif (trim((string) $in[$f]) <= date('Y-m-d')) {
                    $errors[$f] = 'Date must be a future date.';
                }
            }
        }
        if (!$errors
            && (array_key_exists('posted_date', $in) || array_key_exists('closing_date', $in))) {
            $cur = db()->prepare('SELECT posted_date, closing_date FROM job_postings WHERE id = :id');
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            $posted = array_key_exists('posted_date', $in) ? trim((string) $in['posted_date']) : (string) ($row['posted_date'] ?? '');
            $closing = array_key_exists('closing_date', $in) ? trim((string) $in['closing_date']) : (string) ($row['closing_date'] ?? '');
            if ($posted !== '' && $closing !== '' && $closing <= $posted) {
                $errors['closing_date'] = 'Closing date must be later than the posting date.';
            }
        }
        if (array_key_exists('vacancies', $in) && filter_var($in['vacancies'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            $errors['vacancies'] = 'Vacancies must be an integer >= 0.';
        }
        if ($errors) {
            Response::validation($errors);
        }

        $sets = [];
        $params = [':id' => $id];
        foreach (self::EDITABLE as $field) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $val = $in[$field];
            if ($val === null || (is_string($val) && trim($val) === '')) {
                $sets[] = "`$field` = :" . $field;
                $params[":" . $field] = null;
                continue;
            }
            if ($field === 'status') {
                $val = strtolower(trim((string) $val));
            }
            if ($field === 'title') {
                $val = mb_substr(trim((string) $val), 0, 150);
            }
            if ($field === 'vacancies') {
                $val = (int) $val;
            }
            $sets[] = "`$field` = :" . $field;
            $params[":" . $field] = $val;
        }

        $sql = 'UPDATE job_postings SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id';
        db()->prepare($sql)->execute($params);

        self::show($id);
    }

    // ---- DELETE /jobs/{id} -------------------------------------------------------

    public static function destroy(int $id): never
    {
        Auth::requireAdmin(true); // API keys with jobs:write scope allowed
        $stmt = db()->prepare('DELETE FROM job_postings WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            Response::notFound('Job not found.');
        }
        Response::noContent();
    }

    // ---- Helpers -------------------------------------------------------------------

    private static function departmentExists(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = db()->prepare('SELECT 1 FROM departments WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return (bool) $stmt->fetch();
    }

    private static function filterEditable(array $in): array
    {
        return array_intersect_key($in, array_flip(self::EDITABLE));
    }
}
