<?php
/**
 * /api/v1/applications — job applications.
 * POST is public (multipart/form-data or JSON); reads/updates are
 * owner-scoped; status changes and deletes are administrator-only.
 * Reuses the existing `applicants` table, generateCode() numbering and
 * the same accepted file types as the website apply form.
 */

declare(strict_types=1);

class ApplicationsController
{
    private const ALLOWED_EXT = ['pdf', 'doc', 'docx'];
    private const ALLOWED_MIME = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    /** Fields an owner may edit while the application is still 'new'. */
    private const OWNER_EDITABLE = ['first_name', 'last_name', 'email', 'phone', 'address', 'education', 'skills', 'work_experience', 'cover_letter'];

    // ---- GET /applications ---------------------------------------------------

    /** Employees have no application access in the API — ESS only. */
    private static function denyEmployee(): void
    {
        if (Auth::role() === 'employee') {
            Response::forbidden('Employees cannot access application resources.');
        }
    }

    public static function index(): never
    {
        $user = Auth::requireAuth();
        self::denyEmployee();
        $isAdmin = Auth::isAdmin();

        // Applicants may list their own applications, scoped to their rows;
        // administrators see everything.
        if (!$isAdmin && $user['role'] !== 'applicant') {
            Response::forbidden('Only applicants and administrators may list applications.');
        }

        $page = max(1, (int) (api_query('page') ?? 1));
        $limit = min(100, max(1, (int) (api_query('limit') ?? 10)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        // "Applications" are job applications; registration-profile rows
        // (no job attached) are excluded for every caller.
        $where[] = 'a.job_posting_id IS NOT NULL';

        if (!$isAdmin) {
            $where[] = '(a.user_id = :uid OR a.email = :uemail)';
            $params[':uid'] = (int) $user['id'];
            $params[':uemail'] = $user['email'];
        } else {
            if ($email = api_query('email')) {
                $where[] = 'a.email LIKE :femail';
                $params[':femail'] = '%' . $email . '%';
            }
            if ($status = Auth::normalizeStatus(api_query('status'))) {
                $where[] = 'a.status = :status';
                $params[':status'] = $status;
            }
            if ($jid = api_query('job_id')) {
                if (!ctype_digit($jid)) {
                    Response::validation(['job_id' => 'job_id must be a positive integer.']);
                }
                $where[] = 'a.job_posting_id = :jid';
                $params[':jid'] = (int) $jid;
            }
            if ($q = api_query('search')) {
                $where[] = '(a.first_name LIKE :q1 OR a.last_name LIKE :q2 OR a.email LIKE :q3 OR a.applicant_no LIKE :q4)';
                $params[':q1'] = '%' . $q . '%';
                $params[':q2'] = '%' . $q . '%';
                $params[':q3'] = '%' . $q . '%';
                $params[':q4'] = '%' . $q . '%';
            }
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = db()->prepare("SELECT COUNT(*) c FROM applicants a $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = db()->prepare(
            "SELECT a.id, a.applicant_no, a.user_id, a.first_name, a.last_name, a.email, a.phone,
                    a.address, a.education, a.skills, a.work_experience,
                    a.position_applied, a.department_id, d.name AS department,
                    a.job_posting_id, j.title AS job_title, a.resume_path, a.status,
                    a.applied_date, a.notes AS cover_letter, a.created_at, a.updated_at
             FROM applicants a
             LEFT JOIN departments d ON d.id = a.department_id
             LEFT JOIN job_postings j ON j.id = a.job_posting_id
             $whereSql
             ORDER BY a.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_map([self::class, 'present'], $stmt->fetchAll());

        Response::list($rows, 'Applications retrieved successfully', $page, $limit, $total);
    }

    // ---- GET /applications/{id} ------------------------------------------------

    public static function show(int $id): never
    {
        self::denyEmployee();
        $app = self::findAuthorized($id);
        Response::item(self::present($app), 'Application retrieved successfully');
    }

    // ---- POST /applications -------------------------------------------------------

    public static function store(): never
    {
        self::denyEmployee();

        // Brute-force / spam guard on public submissions
        RateLimit::attempt('apply:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 20, 900)
            or Response::tooManyRequests('Too many application submissions from this address. Try again later.');

        $in = api_body();

        // ---- Job must exist and be open ------------------------------------
        $jobId = isset($in['job_id']) ? (int) $in['job_id'] : 0;
        $errors = [];
        if ($jobId <= 0) {
            $errors['job_id'] = 'A valid job_id is required.';
        } else {
            $jStmt = db()->prepare('SELECT id, title, department_id, status FROM job_postings WHERE id = :id LIMIT 1');
            $jStmt->execute([':id' => $jobId]);
            $job = $jStmt->fetch();
            if (!$job) {
                $errors['job_id'] = 'The selected job does not exist.';
                $job = null;
            } elseif ($job['status'] !== 'open') {
                Response::conflict('This position is no longer accepting applications.', [
                    'job_id' => 'Job status is "' . $job['status'] . '".',
                ]);
            }
        }

        // ---- Field validation ----------------------------------------------
        foreach (['first_name', 'last_name', 'email'] as $f) {
            if (!isset($in[$f]) || trim((string) $in[$f]) === '') {
                $errors[$f] = ucfirst(str_replace('_', ' ', $f)) . ' is required.';
            }
        }
        if (isset($in['email']) && trim((string) $in['email']) !== ''
            && !filter_var(trim((string) $in['email']), FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (isset($in['phone']) && trim((string) $in['phone']) !== ''
            && !preg_match('/^[0-9+\-\s().]{7,20}$/', trim((string) $in['phone']))) {
            $errors['phone'] = 'Phone number may contain digits, spaces, + - ( ) . and must be 7-20 characters.';
        }
        foreach (['first_name', 'last_name'] as $f) {
            if (isset($in[$f]) && mb_strlen(trim((string) $in[$f])) > 80) {
                $errors[$f] = ucfirst(str_replace('_', ' ', $f)) . ' may not exceed 80 characters.';
            }
        }
        foreach (['education'] as $f) {
            if (isset($in[$f]) && mb_strlen((string) $in[$f]) > 150) {
                $errors[$f] = ucfirst($f) . ' may not exceed 150 characters.';
            }
        }
        if ($errors) {
            Response::validation($errors);
        }

        $firstName = mb_substr(trim((string) $in['first_name']), 0, 80);
        $lastName = mb_substr(trim((string) $in['last_name']), 0, 80);
        $email = strtolower(trim((string) $in['email']));
        $phone = isset($in['phone']) ? mb_substr(trim((string) $in['phone']), 0, 30) : null;
        $address = isset($in['address']) ? mb_substr(trim((string) $in['address']), 0, 500) : null;
        $education = isset($in['education']) ? mb_substr(trim((string) $in['education']), 0, 150) : null;
        $skills = isset($in['skills']) ? mb_substr(trim((string) $in['skills']), 0, 2000) : null;
        $workExp = isset($in['work_experience']) ? mb_substr(trim((string) $in['work_experience']), 0, 3000) : null;
        $coverLetter = isset($in['cover_letter']) ? mb_substr(trim((string) $in['cover_letter']), 0, 4000) : null;

        // ---- Duplicate check (same email + same job, unless previous rejected)
        $dup = db()->prepare(
            "SELECT id FROM applicants WHERE email = :e AND job_posting_id = :j AND status <> 'rejected' LIMIT 1"
        );
        $dup->execute([':e' => $email, ':j' => $jobId]);
        if ($dup->fetch()) {
            Response::conflict('You have already applied for this position.', [
                'email' => 'An active application already exists for this email and job.',
            ]);
        }

        // ---- Resume upload (required, same rules as website form) -------------
        if (!isset($_FILES['resume']) || !is_array($_FILES['resume'])) {
            Response::validation(['resume' => 'A resume/CV file is required.']);
        }
        $file = $_FILES['resume'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::validation(['resume' => 'Resume upload failed. Please retry with a valid PDF, DOC or DOCX file.']);
        }
        if (($file['size'] ?? 0) <= 0 || $file['size'] > API_MAX_UPLOAD_BYTES) {
            Response::validation(['resume' => 'Resume must be between 1 byte and 5 MB.']);
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            Response::validation(['resume' => 'Resume must be a PDF, DOC or DOCX file.']);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if ($mime !== self::ALLOWED_MIME[$ext]) {
            Response::validation(['resume' => 'File content does not match its extension. Only genuine PDF/DOC/DOCX files are allowed.']);
        }

        if (!is_dir(API_RESUME_DIR)) {
            @mkdir(API_RESUME_DIR, 0775, true);
        }

        // ---- Persist ------------------------------------------------------------
        $applicantNo = generateCode('APP', 'applicants', 'applicant_no');
        $storedName = $applicantNo . '_api_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = API_RESUME_DIR . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::serverError('Could not store the uploaded resume.');
        }
        @chmod($destPath, 0644);

        $userId = null;
        $authUser = Auth::user();
        if ($authUser && $authUser['role'] === 'applicant') {
            $userId = (int) $authUser['id'];
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO applicants
                    (user_id, applicant_no, first_name, last_name, email, phone, address,
                     education, skills, work_experience, position_applied, department_id,
                     job_posting_id, resume_path, status, applied_date, notes)
                 VALUES (:user_id, :applicant_no, :first_name, :last_name, :email, :phone, :address,
                     :education, :skills, :work_experience, :position_applied, :department_id,
                     :job_posting_id, :resume_path, \'new\', CURDATE(), :notes)'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':applicant_no' => $applicantNo,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':phone' => $phone,
                ':address' => $address,
                ':education' => $education,
                ':skills' => $skills,
                ':work_experience' => $workExp,
                ':position_applied' => mb_substr((string) $job['title'], 0, 120),
                ':department_id' => $job['department_id'],
                ':job_posting_id' => $jobId,
                ':resume_path' => API_RESUME_REL . '/' . $storedName,
                ':notes' => $coverLetter,
            ]);
        } catch (PDOException $ex) {
            @unlink($destPath); // don't leave orphan files when insert fails
            throw $ex;
        }

        $id = (int) db()->lastInsertId();
        Response::created(self::present(self::findById($id)), 'Application submitted successfully.');
    }

    // ---- PUT/PATCH /applications/{id} ----------------------------------------------

    public static function update(int $id, bool $partial): never
    {
        self::denyEmployee();
        $app = self::findAuthorized($id);
        $isAdmin = Auth::isAdmin();
        $isOwner = (int) $app['user_id'] === (int) Auth::id()
            || (Auth::user() && strtolower(Auth::user()['email']) === strtolower($app['email']));

        $in = api_body();

        if (array_key_exists('status', $in)) {
            if (!$isAdmin) {
                Response::forbidden('Application status can only be changed by HR/Manager via the admin endpoint.');
            }
            $norm = Auth::normalizeStatus((string) $in['status']);
            if ($norm === null) {
                Response::validation(['status' => 'Must be one of: pending/new, reviewing/screening, shortlisted, interview, accepted/offered, hired, rejected.']);
            }
        }

        $fieldSet = $isAdmin ? self::OWNER_EDITABLE : self::OWNER_EDITABLE;
        $data = [];
        foreach ($fieldSet as $f) {
            if (array_key_exists($f, $in)) {
                $data[$f] = $in[$f];
            }
        }
        if (!$partial) {
            foreach (['first_name', 'last_name', 'email'] as $req) {
                if (!array_key_exists($req, $data) && !array_key_exists($req, $app)) {
                    $data[$req] = null; // will fail validation below if truly missing
                }
                $data[$req] ??= $app[$req] ?? null;
            }
        }

        $v = new Validator($data);
        if (!$partial) {
            $v->required('first_name', 'last_name');
        }
        $v->email('email')
          ->phone('phone')
          ->maxLen('first_name', 80)
          ->maxLen('last_name', 80)
          ->maxLen('education', 150);
        // Owners cannot change their email to someone else's identity
        if (isset($data['email']) && !$isAdmin) {
            $newEmail = strtolower(trim((string) $data['email']));
            if ($newEmail !== strtolower((string) $app['email'])) {
                Response::forbidden('The application email cannot be changed after submission.');
            }
        }
        if ($v->fails()) {
            Response::validation($v->errors());
        }

        $sets = [];
        $params = [':id' => $id];
        $map = [
            'first_name' => 'first_name', 'last_name' => 'last_name',
            'phone' => 'phone', 'address' => 'address',
            'education' => 'education', 'skills' => 'skills',
            'work_experience' => 'work_experience', 'cover_letter' => 'notes',
            'email' => 'email',
        ];
        foreach ($map as $inputKey => $column) {
            if (!array_key_exists($inputKey, $data)) {
                continue;
            }
            $val = $data[$inputKey];
            if (is_string($val)) {
                $val = trim($val);
                if ($val === '') {
                    $val = ($inputKey === 'first_name' || $inputKey === 'last_name') ? null : null;
                }
            }
            $sets[] = "`$column` = :" . $column;
            $params[":" . $column] = $val;
        }
        if (isset($norm)) {
            $sets[] = 'status = :status';
            $params[':status'] = $norm;
        }
        if (!$sets) {
            Response::badRequest('No updatable fields provided.');
        }

        $sql = 'UPDATE applicants SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id';
        db()->prepare($sql)->execute($params);

        Response::item(self::present(self::findById($id)), 'Application updated successfully.');
    }

    // ---- DELETE /applications/{id} ----------------------------------------------------

    public static function destroy(int $id): never
    {
        Auth::requireAdmin();
        $app = self::findById($id);
        if (!$app) {
            Response::notFound('Application not found.');
        }
        db()->prepare('DELETE FROM applicants WHERE id = :id')->execute([':id' => $id]);
        Response::noContent();
    }

    // ---- Shared helpers -----------------------------------------------------------------

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT a.*, d.name AS department, j.title AS job_title
             FROM applicants a
             LEFT JOIN departments d ON d.id = a.department_id
             LEFT JOIN job_postings j ON j.id = a.job_posting_id
             WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Loads the record only when the caller is admin or the owner; else 404/403. */
    private static function findAuthorized(int $id): array
    {
        Auth::requireAuth();
        $app = self::findById($id);
        if (!$app) {
            Response::notFound('Application not found.');
        }
        if (!Auth::isAdmin()) {
            $mine = ((int) ($app['user_id'] ?? 0)) === (int) Auth::id()
                || strtolower(Auth::user()['email']) === strtolower($app['email']);
            if (!$mine) {
                // 404 instead of 403: do not leak other people's applications
                Response::notFound('Application not found.');
            }
        }
        return $app;
    }

    /** Public JSON shape — never includes internal paths beyond resume filename. */
    private static function present(array $a): array
    {
        $display = Auth::statusDisplayMap();
        $out = [
            'id' => (int) $a['id'],
            'applicant_no' => $a['applicant_no'],
            'job_id' => $a['job_posting_id'] !== null ? (int) $a['job_posting_id'] : null,
            'job_title' => $a['job_title'] ?? null,
            'first_name' => $a['first_name'],
            'last_name' => $a['last_name'],
            'email' => $a['email'],
            'phone' => $a['phone'],
            'address' => $a['address'],
            'education' => $a['education'] ?? null,
            'skills' => $a['skills'] ?? null,
            'work_experience' => $a['work_experience'] ?? null,
            'cover_letter' => $a['notes'] ?? null,
            'department' => $a['department'] ?? null,
            'status' => $a['status'],
            'status_label' => $display[$a['status']] ?? ucfirst($a['status']),
            'applied_date' => $a['applied_date'],
            'created_at' => $a['created_at'],
            'updated_at' => $a['updated_at'],
        ];
        // Resume path is exposed to admins only, and only the filename portion.
        if (Auth::isAdmin() && !empty($a['resume_path'])) {
            $out['resume_file'] = basename((string) $a['resume_path']);
        }
        return $out;
    }
}
