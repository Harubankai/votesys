<?php
// Shared setup for every page: helpers, database connection, and the election data
// (schedule, departments, positions, partylists, candidates and voters).

// Project root (votesys/), used for file paths such as candidate photos
define('BASE_DIR', dirname(__DIR__, 2));

// Philippine time, so "today" and the schedule dates match the school's clock
date_default_timezone_set('Asia/Manila');

require __DIR__ . '/db.php';
require __DIR__ . '/security.php';

send_security_headers();

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function initials($name)
{
    $letters = '';
    foreach (preg_split('/\s+/', trim($name)) as $part) {
        if ($part !== '' && ctype_alpha($part[0])) {
            $letters .= strtoupper($part[0]);
        }
    }
    return substr($letters, 0, 2);
}

// Random, hard-to-guess voter ID such as "DCS-7K3P9X".
// Leaves out 0/O and 1/I so students don't mix them up when typing.
function generate_voter_id($department, array $taken_ids)
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $voter_id = "$department-$code";
    } while (isset($taken_ids[$voter_id]));

    return $voter_id;
}

// 'unset', 'upcoming', 'open' or 'ended', based on the schedule in Settings
function election_status(array $settings)
{
    if ($settings['start'] === '' || $settings['end'] === '') {
        return 'unset';
    }
    $now = time();
    if ($now < strtotime($settings['start'])) {
        return 'upcoming';
    }
    if ($now < strtotime($settings['end'])) {
        return 'open';
    }
    return 'ended';
}

function group_voters_by_dept(array $voters, array $departments)
{
    $grouped = array_fill_keys(array_keys($departments), []);
    foreach ($voters as $voter) {
        $grouped[$voter['department']][] = $voter;
    }
    return $grouped;
}

// Year level options for a department's <select>, keeping $selected chosen
function year_options(array $dept_year_levels, $department, $selected)
{
    if (!isset($dept_year_levels[$department])) {
        return '<option value="">Select department first</option>';
    }
    $html = '<option value="">Select year level</option>';
    foreach ($dept_year_levels[$department] as $year) {
        $html .= '<option value="' . h($year) . '"' . ($year === $selected ? ' selected' : '') . '>' . h($year) . '</option>';
    }
    return $html;
}

// Sorts students by year level (in the department's order), then section, then name
function sort_by_class(array $students, array $year_order)
{
    $year_rank = array_flip($year_order);
    usort($students, fn($a, $b) =>
        (($year_rank[$a['year']] ?? 99) <=> ($year_rank[$b['year']] ?? 99))
        ?: strnatcasecmp($a['section'] ?? '', $b['section'] ?? '')
        ?: strcasecmp($a['name'], $b['name'])
    );
    return $students;
}

// Section for display: "—" when the student has none
function section_label($section)
{
    return ($section ?? '') !== '' ? $section : '—';
}

// Section options for a department + year level <select>, keeping $selected chosen
function section_options(array $dept_sections, $department, $year, $selected)
{
    if (!isset($dept_sections[$department][$year])) {
        return '<option value="">Select year level first</option>';
    }
    if (empty($dept_sections[$department][$year])) {
        return '<option value="">No sections</option>';
    }
    $html = '<option value="">Select section</option>';
    foreach ($dept_sections[$department][$year] as $section) {
        $html .= '<option value="' . h($section) . '"' . ($section === $selected ? ' selected' : '') . '>' . h($section) . '</option>';
    }
    return $html;
}

// Checks a student's section against the sections set up for their department and year level.
// Accepts "A", "a" or "Section A" for a section named "A". Returns [section as stored, error or null].
// The section is required when that year level has sections, and must be empty when it has none.
function check_section(array $dept_sections, $department, $year, $section)
{
    if (!isset($dept_sections[$department][$year])) {
        return ['', null];   // department/year level errors are reported separately
    }
    $available = $dept_sections[$department][$year];
    $section = trim(preg_replace('/\s+/', ' ', (string) $section));
    $without_prefix = trim(preg_replace('/^(section|sec\.?)\s*/i', '', $section));

    if (empty($available)) {
        return $section === ''
            ? ['', null]
            : ['', "$department $year has no sections. Leave the section empty, or add sections in Manage Departments."];
    }
    if ($section === '') {
        return ['', "Section is required for $department $year (" . implode(', ', $available) . ').'];
    }
    foreach ($available as $name) {
        if (strcasecmp($name, $section) === 0 || strcasecmp($name, $without_prefix) === 0) {
            return [$name, null];
        }
    }
    return [$section, "Section for $department $year must be one of: " . implode(', ', $available) . '.'];
}

function load_voters(PDO $db)
{
    return $db->query(
        'SELECT voter_id, student_id, name, department_code AS department, year_level AS year, section,
                has_voted AS voted, voted_at
         FROM voters
         ORDER BY id'
    )->fetchAll();
}

// ---------------------------------------------------------------
// Sidebar pages (URLs are relative to the project root)
// ---------------------------------------------------------------
$admin_pages = [
    'dashboard'  => ['label' => 'Dashboard',  'url' => 'admin_dashB.php',          'icon' => 'bx-grid-alt'],
    'candidates' => ['label' => 'Candidates', 'url' => 'adminDash/candidates.php', 'icon' => 'bx-id-card'],
    'voters'     => ['label' => 'Voters',     'url' => 'adminDash/voters.php',     'icon' => 'bx-group'],
    'departments' => ['label' => 'Manage Departments', 'url' => 'adminDash/departments.php', 'icon' => 'bx-buildings'],
    'settings'   => ['label' => 'Settings',   'url' => 'adminDash/settings.php',   'icon' => 'bx-cog'],
];

// Year levels a department can offer (each department picks its own in Manage Departments)
$year_level_options = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];
$independent = 'Independent';   // shown for candidates without a partylist

// ---------------------------------------------------------------
// Load the election data from the database
// ---------------------------------------------------------------
$db = db();

try {
    // Schedule, as "Y-m-dTH:i" strings for the datetime-local inputs and the countdowns
    $row = $db->query('SELECT election_title, voting_start, voting_end FROM settings WHERE id = 1')->fetch();
    $election_title = $row['election_title'] ?? 'SSC Election';
    $settings = [
        'start' => !empty($row['voting_start']) ? date('Y-m-d\TH:i', strtotime($row['voting_start'])) : '',
        'end'   => !empty($row['voting_end'])   ? date('Y-m-d\TH:i', strtotime($row['voting_end']))   : '',
    ];

    $departments = $db->query('SELECT code, name FROM departments ORDER BY sort_order, name')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    // $dept_year_levels['DCS'] = ['1st Year', ...]
    // $dept_sections['DCS']['1st Year'] = ['A', 'B', ...]
    $dept_year_levels = array_fill_keys(array_keys($departments), []);
    $dept_sections = [];
    $rows = $db->query(
        'SELECT y.department_code, y.name AS year_level, s.name AS section
         FROM year_levels y
         LEFT JOIN sections s ON s.year_level_id = y.id
         ORDER BY y.sort_order, y.id'
    )->fetchAll();
    foreach ($rows as $row) {
        $code = $row['department_code'];
        $year = $row['year_level'];
        if (!isset($dept_sections[$code][$year])) {
            $dept_year_levels[$code][] = $year;
            $dept_sections[$code][$year] = [];
        }
        if ($row['section'] !== null) {
            $dept_sections[$code][$year][] = $row['section'];
        }
    }
    foreach ($dept_sections as &$years) {
        foreach ($years as &$list) {
            natcasesort($list);
            $list = array_values($list);
        }
        unset($list);
    }
    unset($years);

    $positions = $db->query('SELECT name FROM positions ORDER BY sort_order, id')
        ->fetchAll(PDO::FETCH_COLUMN);
    $position_ids = $db->query('SELECT name, id FROM positions')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    $partylists = $db->query('SELECT id, name, description FROM partylists ORDER BY id')->fetchAll();

    $candidates = $db->query(
        "SELECT c.id, c.student_id, c.name, p.name AS position,
                COALESCE(pl.name, 'Independent') AS partylist,
                c.department_code AS department, c.course, c.year_level AS year, c.section, c.photo,
                COALESCE(c.platform, '') AS platform, COALESCE(c.achievements, '') AS achievements
         FROM candidates c
         JOIN positions p ON p.id = c.position_id
         LEFT JOIN partylists pl ON pl.id = c.partylist_id
         ORDER BY c.id"
    )->fetchAll();

    $voters = load_voters($db);
} catch (PDOException $e) {
    db_fail('The votesys database is missing some tables. Import database/votesys.sql in phpMyAdmin, then reload this page.');
}

$voters_by_dept = group_voters_by_dept($voters, $departments);
