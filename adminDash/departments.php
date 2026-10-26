<?php
// Manage Departments: add, rename and delete departments, and set each
// department's year levels and the sections inside each year level.
require __DIR__ . '/includes/init.php';

$page = 'departments';
$root_url = '../';
require_admin($root_url);   // admins only; also checks the CSRF token of every POST

// ---------------------------------------------------------------
// Usage counts: a department in use can't be deleted, and a year level
// with voters or candidates in it can't be removed
// ---------------------------------------------------------------
$usage = [];           // $usage['DCS'] = ['voters' => 10, 'candidates' => 2]
$year_usage = [];      // $year_usage['DCS']['1st Year'] = 4
$section_usage = [];   // $section_usage['DCS']['1st Year']['a'] = 2 (section in lowercase)
foreach (['voters' => $voters, 'candidates' => $candidates] as $kind => $students) {
    foreach ($students as $s) {
        $dept = $s['department'];
        $usage[$dept][$kind] = ($usage[$dept][$kind] ?? 0) + 1;
        $year_usage[$dept][$s['year']] = ($year_usage[$dept][$s['year']] ?? 0) + 1;
        if ($s['section'] !== '') {
            $key = mb_strtolower($s['section']);
            $section_usage[$dept][$s['year']][$key] = ($section_usage[$dept][$s['year']][$key] ?? 0) + 1;
        }
    }
}

// Finds $name in $list ignoring capital letters; returns the matching entry or null
function find_ci($name, array $list)
{
    foreach ($list as $item) {
        if (mb_strtolower($item) === mb_strtolower($name)) {
            return $item;
        }
    }
    return null;
}

// "A, b ,  C" -> ['A', 'b', 'C'] (duplicates removed, natural order)
function parse_sections($text)
{
    $sections = [];
    foreach (preg_split('/[,\n]+/', $text) as $part) {
        $part = trim(preg_replace('/\s+/', ' ', $part));
        if ($part !== '' && !isset($sections[mb_strtolower($part)])) {
            $sections[mb_strtolower($part)] = $part;
        }
    }
    $sections = array_values($sections);
    natcasesort($sections);
    return array_values($sections);
}

$form_errors = [];
$reopen_form = null;   // submitted values, used to reopen the form when there are errors
$page_error = '';

// ---------------------------------------------------------------
// Add or edit a department
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_department'])) {
    $mode = ($_POST['mode'] ?? '') === 'edit' ? 'edit' : 'add';
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
    $years_in = is_array($_POST['year_level'] ?? null) ? $_POST['year_level'] : [];
    $sections_in = is_array($_POST['sections'] ?? null) ? $_POST['sections'] : [];

    // Department code and name
    if ($mode === 'add') {
        if ($code === '') {
            $form_errors[] = 'Department code is required.';
        } elseif (!preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $code)) {
            $form_errors[] = 'Department code must be 2 to 10 letters or numbers, starting with a letter (e.g. DCS).';
        } elseif (isset($departments[$code])) {
            $form_errors[] = "The department code $code is already used.";
        }
    } elseif (!isset($departments[$code])) {
        $form_errors[] = 'That department no longer exists.';
    }

    if ($name === '') {
        $form_errors[] = 'Department name is required.';
    } elseif (mb_strlen($name) > 100) {
        $form_errors[] = 'Department name must be 100 characters or fewer.';
    } else {
        foreach ($departments as $other_code => $other_name) {
            if ($other_code !== $code && strcasecmp($other_name, $name) === 0) {
                $form_errors[] = 'Another department already has this name.';
                break;
            }
        }
    }

    // Year levels and their sections
    $year_rows = [];   // '1st Year' => ['A', 'B']
    foreach ($years_in as $i => $year) {
        $year = trim((string) $year);
        $sections = parse_sections((string) ($sections_in[$i] ?? ''));

        if ($year === '') {
            $form_errors[] = 'Choose a year level for every row, or remove the empty row.';
            continue;
        }
        if (!in_array($year, $year_level_options, true)) {
            $form_errors[] = 'Please choose a valid year level.';
            continue;
        }
        if (isset($year_rows[$year])) {
            $form_errors[] = "$year is listed more than once.";
            continue;
        }
        foreach ($sections as $section) {
            if (mb_strlen($section) > 30) {
                $form_errors[] = "Section \"$section\" in $year is too long (30 characters at most).";
            } elseif (!preg_match('/^[\p{L}\p{N} .\-]+$/u', $section)) {
                $form_errors[] = "Section \"$section\" in $year may only contain letters, numbers, spaces, dots and dashes.";
            }
        }
        $year_rows[$year] = $sections;
    }
    if (empty($year_rows) && empty($years_in)) {
        $form_errors[] = 'Add at least one year level.';
    }

    // Year levels and sections being removed must have no students in them
    if ($mode === 'edit' && isset($departments[$code])) {
        foreach (array_diff($dept_year_levels[$code], array_keys($year_rows)) as $removed) {
            $count = $year_usage[$code][$removed] ?? 0;
            if ($count > 0) {
                $form_errors[] = "Can't remove $removed: $count voter(s) or candidate(s) in $code are in $removed.";
            }
        }
        foreach ($year_rows as $year => $sections) {
            foreach ($dept_sections[$code][$year] ?? [] as $old_section) {
                $count = $section_usage[$code][$year][mb_strtolower($old_section)] ?? 0;
                if ($count > 0 && find_ci($old_section, $sections) === null) {
                    $form_errors[] = "Can't remove Section $old_section from $year: $count voter(s) or candidate(s) are in it.";
                }
            }
        }
    }

    $form_errors = array_values(array_unique($form_errors));

    if (empty($form_errors)) {
        $db->beginTransaction();
        try {
            if ($mode === 'add') {
                $next_order = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM departments')->fetchColumn();
                $db->prepare('INSERT INTO departments (code, name, sort_order) VALUES (?, ?, ?)')
                    ->execute([$code, $name, $next_order]);
            } else {
                $db->prepare('UPDATE departments SET name = ? WHERE code = ?')->execute([$name, $code]);
            }

            // Sync year levels: remove the ones taken out, add new ones, keep the order
            $find_years = $db->prepare('SELECT name, id FROM year_levels WHERE department_code = ?');
            $find_years->execute([$code]);
            $existing_years = $find_years->fetchAll(PDO::FETCH_KEY_PAIR);

            $delete_year = $db->prepare('DELETE FROM year_levels WHERE id = ?');
            foreach ($existing_years as $year => $year_id) {
                if (!isset($year_rows[$year])) {
                    $delete_year->execute([$year_id]);   // its sections are removed with it
                }
            }

            $insert_year = $db->prepare('INSERT INTO year_levels (department_code, name, sort_order) VALUES (?, ?, ?)');
            $update_year = $db->prepare('UPDATE year_levels SET sort_order = ? WHERE id = ?');
            $find_sections = $db->prepare('SELECT name, id FROM sections WHERE year_level_id = ?');
            $delete_section = $db->prepare('DELETE FROM sections WHERE id = ?');
            $insert_section = $db->prepare('INSERT INTO sections (year_level_id, name) VALUES (?, ?)');
            $rename_section = $db->prepare('UPDATE sections SET name = ? WHERE id = ?');
            $rename_students = [
                $db->prepare('UPDATE voters SET section = ? WHERE department_code = ? AND year_level = ? AND section = ?'),
                $db->prepare('UPDATE candidates SET section = ? WHERE department_code = ? AND year_level = ? AND section = ?'),
            ];

            foreach ($year_rows as $year => $sections) {
                $sort_order = array_search($year, $year_level_options, true) + 1;
                if (isset($existing_years[$year])) {
                    $year_id = $existing_years[$year];
                    $update_year->execute([$sort_order, $year_id]);
                } else {
                    $insert_year->execute([$code, $year, $sort_order]);
                    $year_id = (int) $db->lastInsertId();
                }

                // Sync sections (matched ignoring capital letters):
                // delete removed ones, rename "a" -> "A" along with its students, add new ones
                $find_sections->execute([$year_id]);
                $existing_sections = $find_sections->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($existing_sections as $old_name => $section_id) {
                    $new_name = find_ci($old_name, $sections);
                    if ($new_name === null) {
                        $delete_section->execute([$section_id]);
                    } elseif ($new_name !== $old_name) {
                        $rename_section->execute([$new_name, $section_id]);
                        foreach ($rename_students as $statement) {
                            $statement->execute([$new_name, $code, $year, $old_name]);
                        }
                    }
                }
                foreach ($sections as $section) {
                    if (find_ci($section, array_keys($existing_sections)) === null) {
                        $insert_section->execute([$year_id, $section]);
                    }
                }
            }

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            throw $e;
        }

        header('Location: departments.php?saved=' . ($mode === 'add' ? 'added' : 'updated') . '&dept=' . urlencode($code));
        exit;
    }

    // Reopen the form with what was typed
    $rows = [];
    foreach ($years_in as $i => $year) {
        $rows[] = ['year' => (string) $year, 'sections' => (string) ($sections_in[$i] ?? '')];
    }
    $reopen_form = ['mode' => $mode, 'code' => $code, 'name' => $name, 'rows' => $rows];
}

// ---------------------------------------------------------------
// Delete a department (only when no voters or candidates use it)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_department'])) {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $in_use = ($usage[$code]['voters'] ?? 0) + ($usage[$code]['candidates'] ?? 0);

    if (!isset($departments[$code])) {
        $page_error = 'That department no longer exists.';
    } elseif ($in_use > 0) {
        $page_error = "Can't delete {$departments[$code]}: it still has voters or candidates.";
    } else {
        // Year levels and sections are removed with it
        $db->prepare('DELETE FROM departments WHERE code = ?')->execute([$code]);
        header('Location: departments.php?saved=deleted&name=' . urlencode($departments[$code]));
        exit;
    }
}

// Department data for the edit form
$dept_data = [];
foreach ($departments as $code => $dept_name) {
    $rows = [];
    foreach ($dept_year_levels[$code] as $year) {
        $rows[] = ['year' => $year, 'sections' => implode(', ', $dept_sections[$code][$year] ?? [])];
    }
    $dept_data[$code] = ['mode' => 'edit', 'code' => $code, 'name' => $dept_name, 'rows' => $rows];
}

require __DIR__ . '/includes/header.php';
?>
<?php if ($page_error !== ''): ?>
    <p class="alert error"><?php echo h($page_error); ?></p>
<?php elseif (($_GET['saved'] ?? '') === 'added' || ($_GET['saved'] ?? '') === 'updated'): ?>
    <p class="alert success">
        <?php echo h($departments[$_GET['dept'] ?? ''] ?? 'Department'); ?>
        was <?php echo $_GET['saved'] === 'added' ? 'added' : 'updated'; ?> successfully.
    </p>
<?php elseif (($_GET['saved'] ?? '') === 'deleted'): ?>
    <p class="alert success"><?php echo h($_GET['name'] ?? 'The department'); ?> was deleted.</p>
<?php endif; ?>

<div class="toolbar">
    <p><?php echo count($departments); ?> department(s)</p>
    <div class="toolbar-actions">
        <button type="button" class="btn" onclick="openDeptForm()"><i class="bx bx-plus"></i> Add Department</button>
    </div>
</div>

<?php if (empty($departments)): ?>
    <section class="card">
        <p class="empty">No departments yet. Click <strong>Add Department</strong> to create one.</p>
    </section>
<?php endif; ?>

<?php foreach ($departments as $code => $dept_name): ?>
    <?php
    $voter_count = $usage[$code]['voters'] ?? 0;
    $candidate_count = $usage[$code]['candidates'] ?? 0;
    $in_use = $voter_count + $candidate_count > 0;
    ?>
    <section class="card">
        <div class="party-header">
            <div>
                <h2><?php echo h($dept_name); ?> <span class="code-badge"><?php echo h($code); ?></span></h2>
                <p class="party-desc">
                    <?php echo $voter_count; ?> voter(s) · <?php echo $candidate_count; ?> candidate(s) ·
                    <?php echo count($dept_year_levels[$code]); ?> year level(s)
                </p>
            </div>
            <div class="toolbar-actions">
                <button type="button" class="btn btn-secondary btn-small"
                        data-code="<?php echo h($code); ?>" onclick="openDeptForm(this.dataset.code)">
                    <i class="bx bx-edit"></i> Edit
                </button>
                <button type="button" class="btn btn-secondary btn-small"
                        data-code="<?php echo h($code); ?>" data-name="<?php echo h($dept_name); ?>"
                        onclick="openDelete(this.dataset.code, this.dataset.name)"
                        <?php if ($in_use): ?>disabled title="Can't delete: this department still has voters or candidates"<?php endif; ?>>
                    <i class="bx bx-trash"></i> Delete
                </button>
            </div>
        </div>

        <?php if (empty($dept_year_levels[$code])): ?>
            <p class="empty">No year levels yet. Click <strong>Edit</strong> to add them.</p>
        <?php else: ?>
            <div class="year-grid">
                <?php foreach ($dept_year_levels[$code] as $year): ?>
                    <div class="year-box">
                        <h3>
                            <?php echo h($year); ?>
                            <?php $students = $year_usage[$code][$year] ?? 0; ?>
                            <span class="dept-count">(<?php echo $students . ($students === 1 ? ' student' : ' students'); ?>)</span>
                        </h3>
                        <?php if (empty($dept_sections[$code][$year])): ?>
                            <p class="empty">No sections yet</p>
                        <?php else: ?>
                            <div class="chips">
                                <?php foreach ($dept_sections[$code][$year] as $section): ?>
                                    <span class="chip">
                                        Section <?php echo h($section); ?>
                                        (<?php echo $section_usage[$code][$year][mb_strtolower($section)] ?? 0; ?>)
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<!-- Add / edit department modal -->
<div class="modal" id="deptModal" onclick="if (event.target === this) closeDeptForm()">
    <div class="modal-content wide">
        <button type="button" class="modal-close" onclick="closeDeptForm()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3 id="deptFormTitle">Add Department</h3>
        </div>

        <?php if (!empty($form_errors)): ?>
            <div class="alert error" id="deptErrors">
                <ul>
                    <?php foreach ($form_errors as $error): ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="departments.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="mode" id="deptMode" value="add">

            <div class="form-grid">
                <div class="form-group">
                    <label for="deptName">Department Name</label>
                    <input type="text" id="deptName" name="name" maxlength="100"
                           placeholder="e.g. Department of Computer Studies" required>
                </div>
                <div class="form-group">
                    <label for="deptCode">Department Code</label>
                    <input type="text" id="deptCode" name="code" maxlength="10"
                           pattern="[A-Za-z][A-Za-z0-9]{1,9}" placeholder="e.g. DCS"
                           title="2 to 10 letters or numbers, starting with a letter" required>
                    <p class="hint" id="deptCodeHint">Used in Voter's IDs (e.g. DCS-7K3P9X) and can't be changed later.</p>
                </div>
            </div>

            <h4 class="sub-heading">Year Levels &amp; Sections</h4>
            <p class="note">
                Add each year level this department offers, then type its sections separated by commas
                (e.g. <strong>A, B, C</strong>). Leave sections empty if the year level has none yet.
            </p>

            <div id="yearRows"></div>
            <button type="button" class="btn btn-secondary btn-small add-year" onclick="addNextYearRow()">
                <i class="bx bx-plus"></i> Add Year Level
            </button>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeDeptForm()">Cancel</button>
                <button type="submit" name="save_department" class="btn"><i class="bx bx-save"></i> Save Department</button>
            </div>
        </form>
    </div>
</div>

<template id="yearRowTemplate">
    <div class="year-row">
        <select name="year_level[]" aria-label="Year level" required>
            <option value="">Year level</option>
            <?php foreach ($year_level_options as $option): ?>
                <option value="<?php echo h($option); ?>"><?php echo h($option); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="sections[]" placeholder="Sections, e.g. A, B, C" aria-label="Sections">
        <button type="button" class="remove-row" onclick="this.closest('.year-row').remove()" aria-label="Remove year level">
            <i class="bx bx-x"></i>
        </button>
    </div>
</template>

<!-- Delete confirmation modal -->
<div class="modal" id="deleteModal" onclick="if (event.target === this) closeDelete()">
    <div class="modal-content small">
        <button type="button" class="modal-close" onclick="closeDelete()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3>Delete Department</h3>
        </div>
        <p>Delete <strong id="deleteName"></strong>? Its year levels and sections will also be removed. This can't be undone.</p>
        <form method="POST" action="departments.php" class="form-actions">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="code" id="deleteCode">
            <button type="button" class="btn btn-secondary" onclick="closeDelete()">Cancel</button>
            <button type="submit" name="delete_department" class="btn"><i class="bx bx-trash"></i> Delete</button>
        </form>
    </div>
</div>

<script>
// ---------- Add / edit department form ----------
const DEPARTMENTS = <?php echo json_encode($dept_data, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const YEAR_OPTIONS = <?php echo json_encode($year_level_options, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const NEW_DEPARTMENT = {
    mode: 'add', code: '', name: '',
    rows: ['1st Year', '2nd Year', '3rd Year', '4th Year'].map(year => ({ year, sections: '' })),
};

function addYearRow(year = '', sections = '') {
    const row = document.getElementById('yearRowTemplate').content.firstElementChild.cloneNode(true);
    row.querySelector('select').value = year;
    row.querySelector('input').value = sections;
    document.getElementById('yearRows').appendChild(row);
}

// Adds the first year level not already in the form
function addNextYearRow() {
    const used = [...document.querySelectorAll('#yearRows select')].map(select => select.value);
    addYearRow(YEAR_OPTIONS.find(year => !used.includes(year)) || '');
}

function fillDeptForm(form) {
    const editing = form.mode === 'edit';
    document.getElementById('deptMode').value = form.mode;
    document.getElementById('deptFormTitle').textContent = editing ? 'Edit Department' : 'Add Department';
    document.getElementById('deptName').value = form.name;

    const code = document.getElementById('deptCode');
    code.value = form.code;
    code.readOnly = editing;
    document.getElementById('deptCodeHint').textContent = editing
        ? "The code can't be changed because it is part of this department's Voter's IDs."
        : "Used in Voter's IDs (e.g. DCS-7K3P9X) and can't be changed later.";

    document.getElementById('yearRows').innerHTML = '';
    form.rows.forEach(row => addYearRow(row.year, row.sections));
}

function openDeptForm(code) {
    const errors = document.getElementById('deptErrors');
    if (errors) errors.remove();   // old errors don't apply to a freshly opened form
    fillDeptForm(code ? DEPARTMENTS[code] : NEW_DEPARTMENT);
    document.getElementById('deptModal').classList.add('open');
}

function closeDeptForm() {
    document.getElementById('deptModal').classList.remove('open');
}

// ---------- Delete confirmation ----------
function openDelete(code, name) {
    document.getElementById('deleteCode').value = code;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}

function closeDelete() {
    document.getElementById('deleteModal').classList.remove('open');
}

// Reopen the form with what was typed if saving failed
const REOPEN_FORM = <?php echo json_encode($reopen_form, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
if (REOPEN_FORM) {
    fillDeptForm(REOPEN_FORM);
    document.getElementById('deptModal').classList.add('open');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeDeptForm();
        closeDelete();
    }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
