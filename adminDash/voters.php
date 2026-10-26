<?php
// $db, $departments, $dept_year_levels and $voters come from includes/init.php
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/spreadsheet.php';

$page = 'voters';
$root_url = '../';
require_admin($root_url);   // admins only; also checks the CSRF token of every POST

$preview_count = 5;            // voters shown per department on this page
$max_upload_size = 5 * 1024 * 1024;
$max_upload_rows = 5000;

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

// "dcs", "DCS", "Department of Computer Studies" or a course such as "BSCS" -> "DCS"
function normalize_department($value, array $departments)
{
    $value = trim($value);
    if (isset($departments[strtoupper($value)])) {
        return strtoupper($value);
    }
    foreach ($departments as $code => $name) {
        if (strcasecmp($name, $value) === 0) {
            return $code;
        }
    }
    $course = preg_replace('/[^A-Z]/', '', strtoupper($value));   // "Prof-Ed" -> "PROFED"
    if (isset(COURSE_DEPARTMENTS[$course])) {
        return COURSE_DEPARTMENTS[$course];
    }
    return $value;
}

// Courses offered by each department, for voter lists that have a Course column instead of Department
const COURSE_DEPARTMENTS = [
    'BSCS' => 'DCS',
    'BSA' => 'DBA', 'BSBA' => 'DBA',
    'BEED' => 'TED', 'BSED' => 'TED', 'PROFED' => 'TED',
    'CRIM' => 'CRIM', 'BSCRIM' => 'CRIM',
];

// "CRIM-1" -> CRIM, 1; "BSBA-3-EVENING CLASS" -> DBA, 3; "BSA-1-DRP" -> dropped
function parse_course($value, array $departments)
{
    $parts = preg_split('/\s*-\s*|\s+/', strtoupper(trim($value)), -1, PREG_SPLIT_NO_EMPTY);
    $result = ['department' => '', 'year' => '', 'dropped' => in_array('DRP', $parts, true)];

    // The course code may span parts ("PROF-ED"); the year is the first number after it
    $course = '';
    foreach ($parts as $part) {
        if (ctype_digit($part)) {
            $result['year'] = $part;
            break;
        }
        $course .= preg_replace('/[^A-Z]/', '', $part);
        if ($result['department'] === '') {
            if (isset(COURSE_DEPARTMENTS[$course])) {
                $result['department'] = COURSE_DEPARTMENTS[$course];
            } elseif (isset($departments[$course])) {
                $result['department'] = $course;
            }
        }
    }

    // A course that isn't recognised keeps the original text so the skipped-row message shows it
    if ($result['department'] === '') {
        $result['department'] = trim($value);
    }
    return $result;
}

// "1", "1st", "first year", "1st Year" -> "1st Year"
function normalize_year($value)
{
    $value = strtolower(trim($value));
    $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5];
    $number = null;
    foreach ($words as $word => $n) {
        if (str_contains($value, $word)) {
            $number = $n;
        }
    }
    if ($number === null && preg_match('/^([1-5])(st|nd|rd|th)?( ?yr| ?year)?$/', $value, $match)) {
        $number = (int) $match[1];
    }
    if ($number === null) {
        return $value;
    }
    return $number . ['', 'st', 'nd', 'rd', 'th', 'th'][$number] . ' Year';
}

function validate_voter(array $voter, array $departments, array $dept_year_levels, array $taken_student_ids)
{
    $errors = [];
    if ($voter['student_id'] === '') {
        $errors[] = 'Student ID is required.';
    } elseif (!preg_match('/^\d{2}-(\d{4}|\d{6}-\d{2})$/', $voter['student_id'])) {
        $errors[] = 'Student ID must follow the format 00-0000 or 00-000000-00 (e.g. 24-0123 or 26-000513-01).';
    } elseif (isset($taken_student_ids[$voter['student_id']])) {
        $errors[] = 'A voter with this Student ID already exists.';
    }
    if ($voter['name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if (!isset($departments[$voter['department']])) {
        $errors[] = 'Department must be one of: ' . implode(', ', array_keys($departments)) . '.';
    } else {
        $dept_years = $dept_year_levels[$voter['department']];
        if (empty($dept_years)) {
            $errors[] = "{$voter['department']} has no year levels yet. Add them in Manage Departments.";
        } elseif (!in_array($voter['year'], $dept_years, true)) {
            $errors[] = "Year level for {$voter['department']} must be one of: " . implode(', ', $dept_years) . '.';
        }
    }
    return $errors;
}

$insert_voter = $db->prepare(
    "INSERT INTO voters (voter_id, student_id, name, department_code, year_level, section) VALUES (?, ?, ?, ?, ?, '')"
);

// Lookups for duplicate checks and ID generation
$taken_student_ids = [];
$taken_voter_ids = [];
foreach ($voters as $voter) {
    if (!empty($voter['student_id'])) {
        $taken_student_ids[$voter['student_id']] = true;
    }
    $taken_voter_ids[strtoupper($voter['voter_id'])] = true;
}

// ---------------------------------------------------------------
// Add one voter
// ---------------------------------------------------------------
$voter_errors = [];
$new_voter = array_fill_keys(['student_id', 'name', 'department', 'year'], '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_voter'])) {
    foreach (array_keys($new_voter) as $field) {
        $new_voter[$field] = trim($_POST[$field] ?? '');
    }

    $voter_errors = validate_voter($new_voter, $departments, $dept_year_levels, $taken_student_ids);

    if (empty($voter_errors)) {
        $voter_id = generate_voter_id($new_voter['department'], $taken_voter_ids);
        $insert_voter->execute([
            $voter_id, $new_voter['student_id'], $new_voter['name'],
            $new_voter['department'], $new_voter['year'],
        ]);
        header('Location: voters.php?added=' . urlencode($voter_id));
        exit;
    }
}

// ---------------------------------------------------------------
// Upload a voter list (.xlsx or .csv)
// ---------------------------------------------------------------
$upload_errors = [];
$import_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_voters'])) {
    $file = $_FILES['voter_file'] ?? null;
    $extension = $file ? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) : '';
    $rows = null;

    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $upload_errors[] = 'Please choose a file to upload.';
    } elseif (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > $max_upload_size) {
        $upload_errors[] = 'The file must be 5 MB or smaller.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors[] = 'The file could not be uploaded. Please try again.';
    } elseif ($extension === 'xls') {
        $upload_errors[] = 'Old .xls files are not supported. In Excel, use File > Save As > Excel Workbook (.xlsx), then upload that file.';
    } elseif (!in_array($extension, ['xlsx', 'csv'], true)) {
        $upload_errors[] = 'Please upload an Excel (.xlsx) or CSV (.csv) file.';
    } else {
        $rows = read_spreadsheet($file['tmp_name'], $extension);
        if ($rows === null) {
            $upload_errors[] = 'The file could not be read. Make sure it is a valid Excel (.xlsx) or CSV file.';
        }
    }

    // Find the header row and match its columns
    $column_names = [
        'student_id' => ['studentid', 'studentno', 'studentnumber', 'idnumber', 'idno'],
        'name'       => ['fullname', 'name', 'studentname'],
        'department' => ['department', 'dept'],
        'year'       => ['yearlevel', 'year', 'yr', 'level'],
        'course'     => ['course', 'program', 'courseyear'],   // e.g. "CRIM-1": department and year in one column
    ];
    $column_labels = ['student_id' => 'Student ID', 'name' => 'Full Name', 'department' => 'Department', 'year' => 'Year Level'];
    $columns = [];
    $header_row = null;

    // Finds the header columns in one row: ['student_id' => 0, 'name' => 1, ...]
    $match_headers = function (array $row) use ($column_names) {
        $found = [];
        foreach ($row as $index => $header) {
            $key = preg_replace('/[^a-z]/', '', strtolower($header));
            foreach ($column_names as $field => $aliases) {
                if (!isset($found[$field]) && in_array($key, $aliases, true)) {
                    $found[$field] = $index;
                }
            }
        }
        return $found;
    };

    if (empty($upload_errors)) {
        // The header is usually the first non-empty row, but registrar lists often
        // put a title above it, so use whichever of the first few rows matches best
        $checked = 0;
        foreach ($rows as $row_number => $row) {
            if (implode('', $row) === '') {
                continue;
            }
            $found = $match_headers($row);
            if ($header_row === null || count($found) > count($columns)) {
                $header_row = $row_number;
                $columns = $found;
            }
            if (++$checked === 10) {
                break;
            }
        }

        if ($header_row === null) {
            $upload_errors[] = 'The file is empty.';
        } else {
            // A "Course" column like "CRIM-1" can stand in for Department and Year Level
            if (isset($columns['course'])) {
                $columns += ['department' => $columns['course'], 'year' => $columns['course']];
            }

            // A name header merged over two columns (row number, then name): use the name column
            if (isset($columns['name'])) {
                $index = $columns['name'];
                $next_is_untitled = ($rows[$header_row][$index + 1] ?? null) === '';
                $only_numbers = true;
                foreach ($rows as $row_number => $row) {
                    $value = $row[$index] ?? '';
                    if ($row_number > $header_row && $value !== '' && !ctype_digit($value)) {
                        $only_numbers = false;
                        break;
                    }
                }
                if ($next_is_untitled && $only_numbers) {
                    $columns['name'] = $index + 1;
                }
            }

            $missing = array_diff_key($column_labels, $columns);
            if ($missing) {
                $upload_errors[] = 'The header row must contain these column headers: '
                    . implode(', ', $column_labels) . ' (or a Course column such as CRIM-1 in place of Department and Year Level). '
                    . 'Missing: ' . implode(', ', $missing) . '.';
            } elseif (count($rows) - 1 > $max_upload_rows) {
                $upload_errors[] = "The file has too many rows. Please upload at most $max_upload_rows students at a time.";
            }
        }
    }

    if (empty($upload_errors)) {
        $has_students = false;
        foreach ($rows as $row_number => $row) {
            if ($row_number > $header_row && implode('', $row) !== '') {
                $has_students = true;
                break;
            }
        }
        if (!$has_students) {
            $upload_errors[] = 'No students were found in the file. Add one student per row below the header row.';
        }
    }

    // Add each valid row; skip and report the rest
    if (empty($upload_errors)) {
        $import_result = ['added' => [], 'skipped' => []];
        $ids_in_file = [];

        // All rows are saved together, or none if something goes wrong
        $db->beginTransaction();

        foreach ($rows as $row_number => $row) {
            if ($row_number <= $header_row || implode('', $row) === '') {
                continue;
            }

            $voter = [
                'student_id' => $row[$columns['student_id']] ?? '',
                'name'       => trim(preg_replace('/\s+/', ' ', $row[$columns['name']] ?? '')),
                'department' => $row[$columns['department']] ?? '',
                'year'       => $row[$columns['year']] ?? '',
            ];

            // Rows with no student in them yet (only a row number or a reserved Student ID) are not students
            if ($voter['name'] === '' && $voter['department'] === '' && $voter['year'] === '') {
                continue;
            }

            $label = $voter['name'] !== '' ? " ({$voter['name']})" : '';

            if (isset($columns['course']) && in_array($columns['course'], [$columns['department'], $columns['year']], true)) {
                $course = parse_course($row[$columns['course']] ?? '', $departments);
                if ($course['dropped']) {
                    $import_result['skipped'][] = "Row $row_number$label: Marked as dropped (DRP) in the Course column.";
                    continue;
                }
                if ($columns['department'] === $columns['course']) {
                    $voter['department'] = $course['department'];
                }
                if ($columns['year'] === $columns['course']) {
                    $voter['year'] = $course['year'];
                }
            }
            $voter['department'] = normalize_department($voter['department'], $departments);
            $voter['year'] = normalize_year($voter['year']);

            if (isset($ids_in_file[$voter['student_id']])) {
                $import_result['skipped'][] = "Row $row_number$label: Student ID {$voter['student_id']} "
                    . "appears more than once in the file (first on row {$ids_in_file[$voter['student_id']]}).";
                continue;
            }
            if ($voter['student_id'] !== '') {
                $ids_in_file[$voter['student_id']] = $row_number;
            }

            $errors = validate_voter($voter, $departments, $dept_year_levels, $taken_student_ids);
            if ($errors) {
                $import_result['skipped'][] = "Row $row_number$label: " . implode(' ', $errors);
                continue;
            }

            $voter_id = generate_voter_id($voter['department'], $taken_voter_ids);
            $taken_voter_ids[$voter_id] = true;
            $taken_student_ids[$voter['student_id']] = true;

            $insert_voter->execute([
                $voter_id, $voter['student_id'], $voter['name'], $voter['department'], $voter['year'],
            ]);
            $import_result['added'][] = ['voter_id' => $voter_id] + $voter;
        }

        $db->commit();

        if ($import_result['added']) {
            $voters = load_voters($db);
            $voters_by_dept = group_voters_by_dept($voters, $departments);
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<?php if (!empty($_GET['added'])): ?>
    <p class="alert success">
        Voter added successfully. Their Voter's ID is
        <strong class="voter-id"><?php echo h($_GET['added']); ?></strong>.
    </p>
<?php endif; ?>

<?php if ($import_result !== null): ?>
    <?php $added_count = count($import_result['added']); $skipped_count = count($import_result['skipped']); ?>
    <div class="alert <?php echo $added_count > 0 ? ($skipped_count > 0 ? 'warning' : 'success') : 'error'; ?>">
        <strong><?php echo $added_count; ?> voter(s) added</strong><?php if ($skipped_count > 0): ?>, <strong><?php echo $skipped_count; ?> row(s) skipped</strong><?php endif; ?>.
        <?php if ($added_count > 0): ?>
            Each new voter was given a Voter's ID automatically.
        <?php endif; ?>

        <?php if ($added_count > 0): ?>
            <details>
                <summary>Show added voters and their Voter's IDs</summary>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Voter's ID</th><th>Student ID</th><th>Name</th><th>Department</th><th>Year Level</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($import_result['added'] as $voter): ?>
                                <tr>
                                    <td class="voter-id"><?php echo h($voter['voter_id']); ?></td>
                                    <td><?php echo h($voter['student_id']); ?></td>
                                    <td><?php echo h($voter['name']); ?></td>
                                    <td><?php echo h($voter['department']); ?></td>
                                    <td><?php echo h($voter['year']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($skipped_count > 0): ?>
            <details <?php echo $added_count === 0 ? 'open' : ''; ?>>
                <summary>Show skipped rows</summary>
                <ul>
                    <?php foreach ($import_result['skipped'] as $reason): ?>
                        <li><?php echo h($reason); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="toolbar">
    <p><?php echo count($voters); ?> registered voter(s)</p>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary" onclick="openUpload()"><i class="bx bx-upload"></i> Upload List</button>
        <button type="button" class="btn" onclick="openAddVoter()"><i class="bx bx-user-plus"></i> Add Voter</button>
    </div>
</div>

<?php foreach ($departments as $code => $dept_name): ?>
    <?php
    $dept_voters = $voters_by_dept[$code];
    $preview = array_slice(array_reverse($dept_voters), 0, $preview_count);
    ?>
    <section class="card">
        <div class="party-header">
            <h2>
                <?php echo h($dept_name); ?>
                <span class="dept-count">(<?php echo count($dept_voters); ?> voters)</span>
            </h2>
            <a href="department.php?dept=<?php echo urlencode($code); ?>" class="btn btn-secondary btn-small"><i class="bx bx-list-ul"></i> View all</a>
        </div>

        <?php if (empty($dept_voters)): ?>
            <p class="empty">No voters registered in this department yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Voter's ID</th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Year Level</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview as $voter): ?>
                            <tr>
                                <td class="voter-id"><?php echo h($voter['voter_id']); ?></td>
                                <td><?php echo h($voter['student_id'] ?? '—'); ?></td>
                                <td><?php echo h($voter['name']); ?></td>
                                <td><?php echo h($voter['year']); ?></td>
                                <td>
                                    <?php if ($voter['voted']): ?>
                                        <span class="badge voted">Voted</span>
                                    <?php else: ?>
                                        <span class="badge pending">Not yet voted</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($dept_voters) > $preview_count): ?>
                <p class="table-note">
                    Showing the <?php echo $preview_count; ?> most recently added of <?php echo count($dept_voters); ?> voters.
                    <a href="department.php?dept=<?php echo urlencode($code); ?>">View all</a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<!-- Upload voter list modal -->
<div class="modal" id="uploadModal" onclick="if (event.target === this) closeUpload()">
    <div class="modal-content wide">
        <button type="button" class="modal-close" onclick="closeUpload()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3>Upload Voter List</h3>
        </div>

        <?php if (!empty($upload_errors)): ?>
            <div class="alert error">
                <ul>
                    <?php foreach ($upload_errors as $error): ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p class="note">Upload an Excel (.xlsx) or CSV file. Only the first sheet is read. The header row must have these column headers:</p>
        <ul class="column-list">
            <li><strong>Student ID</strong> in the format 00-0000 or 00-000000-00, e.g. 24-0123 or 26-000513-01</li>
            <li><strong>Full Name</strong></li>
            <li><strong>Department</strong>: <?php echo h(implode(', ', array_keys($departments))); ?>, the full department name, or a course code such as BSCS or BEED</li>
            <li><strong>Year Level</strong>: one of the department's year levels, e.g. 1st Year (or just 1)</li>
        </ul>
        <p class="note">
            Registrar lists with a <strong>Course</strong> column (e.g. CRIM-1, BSCS-2) can be uploaded as they are:
            the course gives the department and year level, and rows marked DRP are skipped.
        </p>
        <p class="note">
            Every student gets a Voter's ID automatically. Rows with a Student ID that is already
            registered, or with missing or invalid details, are skipped and listed after the upload.
        </p>
        <p class="note"><a href="voter_template.php"><i class="bx bx-download"></i> Download the Excel template</a></p>

        <form method="POST" action="voters.php" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <div class="form-grid">
                <div class="form-group full">
                    <label for="voter_file">Voter List File</label>
                    <input type="file" id="voter_file" name="voter_file" accept=".xlsx,.csv" required>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeUpload()">Cancel</button>
                <button type="submit" name="import_voters" class="btn"><i class="bx bx-upload"></i> Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Add voter modal -->
<div class="modal" id="addVoterModal" onclick="if (event.target === this) closeAddVoter()">
    <div class="modal-content wide">
        <button type="button" class="modal-close" onclick="closeAddVoter()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3>Add Voter</h3>
        </div>

        <?php if (!empty($voter_errors)): ?>
            <div class="alert error">
                <ul>
                    <?php foreach ($voter_errors as $error): ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p class="note">The Voter's ID is generated automatically when you save.</p>

        <form method="POST" action="voters.php">
            <?php echo csrf_field(); ?>
            <div class="form-grid">
                <div class="form-group">
                    <label for="student_id">Student ID</label>
                    <input type="text" id="student_id" name="student_id" placeholder="00-0000"
                           pattern="\d{2}-(\d{4}|\d{6}-\d{2})" maxlength="12" inputmode="numeric"
                           title="Use the format 00-0000 or 00-000000-00, e.g. 24-0123 or 26-000513-01"
                           value="<?php echo h($new_voter['student_id']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" placeholder="e.g. Juan Dela Cruz" value="<?php echo h($new_voter['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="department">Department</label>
                    <select id="department" name="department" required>
                        <option value="">Select department</option>
                        <?php foreach ($departments as $code => $dept_name): ?>
                            <option value="<?php echo h($code); ?>" <?php echo $new_voter['department'] === $code ? 'selected' : ''; ?>>
                                <?php echo h($dept_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="year">Year Level</label>
                    <select id="year" name="year" data-year-for="department" required>
                        <?php echo year_options($dept_year_levels, $new_voter['department'], $new_voter['year']); ?>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddVoter()">Cancel</button>
                <button type="submit" name="add_voter" class="btn"><i class="bx bx-save"></i> Save Voter</button>
            </div>
        </form>
    </div>
</div>

<script>
// ---------- Add voter modal ----------
function openAddVoter() {
    document.getElementById('addVoterModal').classList.add('open');
}

function closeAddVoter() {
    document.getElementById('addVoterModal').classList.remove('open');
}

// ---------- Upload list modal ----------
function openUpload() {
    document.getElementById('uploadModal').classList.add('open');
}

function closeUpload() {
    document.getElementById('uploadModal').classList.remove('open');
}

// Reopen a form if its last submission had errors
if (<?php echo json_encode(!empty($voter_errors)); ?>) {
    openAddVoter();
}
if (<?php echo json_encode(!empty($upload_errors)); ?>) {
    openUpload();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeAddVoter();
        closeUpload();
    }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
