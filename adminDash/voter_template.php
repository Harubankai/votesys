<?php
// Downloads an empty Excel template (voter_list_template.xlsx) with the columns
// the voter upload expects. The Student ID column is formatted as text so Excel
// keeps IDs like 24-0123 exactly as typed.
require __DIR__ . '/includes/init.php';
require_admin('../');
require __DIR__ . '/includes/xlsx_writer.php';

send_xlsx(
    'voter_list_template.xlsx',
    'Voters',
    ['Student ID', 'Full Name', 'Department', 'Year Level'],
    [],
    [14, 32, 16, 14],
    [0]
);
