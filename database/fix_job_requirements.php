<?php
/**
 * CLI remediation: replace placeholder/incomplete job requirement content in
 * four demo job postings (#8 kargador, #9 graphic designer, #41 janitor,
 * #43 housekeeping) with correct, job-appropriate requirements.
 *
 * The previous content was informal test gibberish (e.g. #9 required_skills
 * "qerqwerwe", #8 description "basta malakas pasok ka", #43 entirely empty),
 * which made AI matching against these positions meaningless. This fills in
 * professional requirements appropriate to each real role, matching the style
 * already used by the well-formed postings (#1, #40, #42).
 *
 * No scoring algorithm is changed. Only job_posting requirement content.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/../config/database.php';

$updates = [
    8 => [
        'description'  => 'Responsible for loading and unloading goods, stock, and materials, moving items to and from storage or vehicles, and maintaining an organized, safe work area. Reports to the warehouse lead.',
        'requirements' => 'NBI Clearance, Barangay Clearance, and valid government-issued ID; physically capable of carrying/moving loads.',
        'qualifications' => 'Physically fit and able to lift and carry heavy items; reliable, hardworking, and able to follow instructions; willing to work shifting schedules and on-call during peak periods.',
        'required_skills' => 'Manual material handling, safe lifting techniques, inventory stacking and organizing, attention to detail, teamwork and communication',
        'education_requirement' => 'At least high school graduate',
        'experience_requirement' => 'Preferably with some warehouse, logistics, or material-handling experience; training on site is provided',
    ],
    9 => [
        'description'  => 'Create and deliver visually engaging graphic design work for marketing, branding, digital, and print materials in line with company guidelines and deadlines.',
        'requirements' => 'A portfolio demonstrating design work submitted with the application.',
        'qualifications' => 'Bachelor degree in Graphic Design, Visual Arts, Multimedia, or a related field; strong portfolio required.',
        'required_skills' => 'Adobe Photoshop, Adobe Illustrator, Figma, typography, layout and composition, brand identity, print and digital design, attention to detail',
        'education_requirement' => 'Bachelor\'s Degree in Graphic Design, Visual Arts, Multimedia, or a related field (or equivalent professional experience)',
        'experience_requirement' => 'At least 2 years of professional graphic design experience; a strong portfolio is required',
    ],
    41 => [
        'description'  => 'Perform general cleaning and maintenance duties to keep offices, facilities, and work areas clean, hygienic, safe, and presentable.',
        'requirements' => 'Willing to perform manual cleaning tasks; reliable and able to work a set shift schedule.',
        'qualifications' => 'Hardworking, trustworthy, and reliable; able to follow cleaning schedules and safety guidelines.',
        'required_skills' => 'General cleaning and sanitation, floor care (sweeping, mopping, buffing), waste disposal, restroom hygiene, safe use of cleaning supplies, time management',
        'education_requirement' => 'At least elementary or high school graduate',
        'experience_requirement' => 'Experience in general cleaning or facility upkeep is preferred but not required; training is provided',
    ],
    43 => [
        'description'  => 'Clean and maintain guest accommodations, common areas, and facilities; change linens, restock supplies, and ensure rooms meet company cleanliness standards.',
        'requirements' => 'Physically capable of standing, bending, and moving for extended periods; able to follow housekeeping checklists.',
        'qualifications' => 'Dependable, detail-oriented, and courteous; able to work independently and as part of a team.',
        'required_skills' => 'Room cleaning and turnaround, linen and bed making, guest amenity restocking, sanitation and hygiene, organization, customer service',
        'education_requirement' => 'At least high school graduate',
        'experience_requirement' => 'Housekeeping or janitorial experience is preferred but not required; training is provided on site',
    ],
];

$dryRun = in_array('--dry-run', $argv, true);

echo "=== JOB REQUIREMENT CORRECTIONS ===\n";
foreach ($updates as $id => $fields) {
    $j = db()->prepare('SELECT id, title, description, requirements, qualifications, required_skills, education_requirement, experience_requirement FROM job_postings WHERE id = ?');
    $j->execute([$id]);
    $row = $j->fetch();
    if (!$row) {
        echo "  job #$id not found - skipped\n";
        continue;
    }
    echo "\n  job #$id {$row['title']} (BEFORE -> AFTER)\n";
    foreach (['description','requirements','qualifications','required_skills','education_requirement','experience_requirement'] as $f) {
        $before = trim((string) ($row[$f] ?? ''));
        echo "    - {$f}: '" . ($before === '' ? '(empty)' : mb_substr($before, 0, 50)) . "'\n";
        printf("       -> '%s'\n", mb_substr($fields[$f], 0, 70));
    }
    if ($dryRun) { continue; }
    $stmt = db()->prepare('UPDATE job_postings SET description=?, requirements=?, qualifications=?, required_skills=?, education_requirement=?, experience_requirement=? WHERE id=?');
    $stmt->execute([
        $fields['description'], $fields['requirements'], $fields['qualifications'],
        $fields['required_skills'], $fields['education_requirement'], $fields['experience_requirement'],
        $id,
    ]);
}

echo $dryRun ? "\n[DRY-RUN] no changes made.\n" : "\nDone.\n";
