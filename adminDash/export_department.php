<?php
// Downloads one department's voter list, with each student's Voter's ID, as Excel:
// export_department.php?dept=DCS
require __DIR__ . '/includes/init.php';
require_admin('../');
require __DIR__ . '/includes/xlsx_writer.php';

$code = strtoupper(trim((string) ($_GET['dept'] ?? '')));
if (!isset($departments[$code])) {
    header('Location: voters.php');
    exit;
}

// One class after another: by year level, then section, then name
$dept_voters = sort_by_class($voters_by_dept[$code], $dept_year_levels[$code]);

$rows = [];
foreach ($dept_voters as $i => $voter) {
    $rows[] = [
        $i + 1,
        $voter['voter_id'],
        $voter['student_id'],
        $voter['name'],
        $code,
        $voter['year'],
        $voter['section'],
        $voter['voted'] ? 'Voted' : 'Not yet voted',
    ];
}

send_xlsx(
    $code . '_voters_' . date('Y-m-d') . '.xlsx',
    $code . ' Voters',
    ['No.', "Voter's ID", 'Student ID', 'Full Name', 'Department', 'Year Level', 'Section', 'Status'],
    $rows,
    [6, 16, 12, 32, 12, 12, 12, 16],
    [1, 2, 6]
);
