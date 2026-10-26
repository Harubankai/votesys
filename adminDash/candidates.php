<?php
require __DIR__ . '/includes/init.php';

$page = 'candidates';
$root_url = '../';
require_admin($root_url);   // admins only; also checks the CSRF token of every POST

// $partylists, $candidates, $positions and $independent come from includes/init.php
// (the voter pages use them too)

// ---------------------------------------------------------------
// Create partylist
// ---------------------------------------------------------------
$partylist_errors = [];
$new_partylist = ['name' => '', 'description' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_partylist'])) {
    $new_partylist['name'] = trim($_POST['party_name'] ?? '');
    $new_partylist['description'] = trim($_POST['party_description'] ?? '');

    if ($new_partylist['name'] === '') {
        $partylist_errors[] = 'Partylist name is required.';
    } elseif (strcasecmp($new_partylist['name'], $independent) === 0) {
        $partylist_errors[] = '"Independent" is reserved for candidates without a partylist.';
    } else {
        foreach ($partylists as $party) {
            if (strcasecmp($party['name'], $new_partylist['name']) === 0) {
                $partylist_errors[] = 'A partylist with this name already exists.';
                break;
            }
        }
    }

    if (empty($partylist_errors)) {
        $db->prepare('INSERT INTO partylists (name, description) VALUES (?, ?)')
            ->execute([$new_partylist['name'], $new_partylist['description']]);
        header('Location: candidates.php?party_added=1');
        exit;
    }
}

$partylist_names = array_column($partylists, 'name');
$partylist_ids = array_column($partylists, 'id', 'name');

// ---------------------------------------------------------------
// Add candidate (photos saved in images/candidates/)
// ---------------------------------------------------------------
$photo_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$max_photo_size = 2 * 1024 * 1024;

$candidate_errors = [];
$new_candidate = array_fill_keys(
    ['name', 'position', 'student_id', 'department', 'course', 'year', 'section', 'partylist', 'achievements', 'platform'],
    ''
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_candidate'])) {
    foreach (array_keys($new_candidate) as $field) {
        $new_candidate[$field] = trim($_POST[$field] ?? '');
    }

    $required = [
        'name' => 'Full name', 'position' => 'Position', 'student_id' => 'Student ID',
        'department' => 'Department', 'course' => 'Course', 'year' => 'Year level', 'partylist' => 'Partylist',
    ];
    foreach ($required as $field => $label) {
        if ($new_candidate[$field] === '') {
            $candidate_errors[] = "$label is required.";
        }
    }

    if ($new_candidate['position'] !== '' && !in_array($new_candidate['position'], $positions, true)) {
        $candidate_errors[] = 'Please choose a valid position.';
    }
    if ($new_candidate['department'] !== '' && !isset($departments[$new_candidate['department']])) {
        $candidate_errors[] = 'Please choose a valid department.';
    }
    if ($new_candidate['year'] !== ''
        && !in_array($new_candidate['year'], $dept_year_levels[$new_candidate['department']] ?? [], true)) {
        $candidate_errors[] = 'Please choose a year level offered by the selected department.';
    } else {
        [$new_candidate['section'], $section_error] = check_section(
            $dept_sections, $new_candidate['department'], $new_candidate['year'], $new_candidate['section']
        );
        if ($section_error !== null) {
            $candidate_errors[] = $section_error;
        }
    }
    if ($new_candidate['partylist'] !== ''
        && $new_candidate['partylist'] !== $independent
        && !in_array($new_candidate['partylist'], $partylist_names, true)) {
        $candidate_errors[] = 'Please choose a valid partylist.';
    }
    foreach ($candidates as $existing) {
        if (strcasecmp($existing['student_id'], $new_candidate['student_id']) === 0) {
            $candidate_errors[] = 'A candidate with this Student ID already exists.';
            break;
        }
    }

    $photo = $_FILES['photo'] ?? null;
    $photo_ext = null;
    if (!$photo || $photo['error'] === UPLOAD_ERR_NO_FILE) {
        $candidate_errors[] = 'Please upload a photo of the candidate.';
    } elseif (in_array($photo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $photo['size'] > $max_photo_size) {
        $candidate_errors[] = 'The photo must be 2 MB or smaller.';
    } elseif ($photo['error'] !== UPLOAD_ERR_OK) {
        $candidate_errors[] = 'The photo could not be uploaded. Please try again.';
    } else {
        $info = @getimagesize($photo['tmp_name']);
        if (!$info || !isset($photo_types[$info['mime']])) {
            $candidate_errors[] = 'The photo must be a JPG, PNG or WEBP image.';
        } else {
            $photo_ext = $photo_types[$info['mime']];
        }
    }

    if (empty($candidate_errors)) {
        // Insert first to get the new ID, which names the photo file;
        // roll back if the photo can't be saved
        $db->beginTransaction();
        $db->prepare(
            'INSERT INTO candidates
                (student_id, name, position_id, partylist_id, department_code, course, year_level, section, platform, achievements)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $new_candidate['student_id'],
            $new_candidate['name'],
            $position_ids[$new_candidate['position']],
            $new_candidate['partylist'] === $independent ? null : $partylist_ids[$new_candidate['partylist']],
            $new_candidate['department'],
            $new_candidate['course'],
            $new_candidate['year'],
            $new_candidate['section'],
            $new_candidate['platform'],
            $new_candidate['achievements'],
        ]);
        $new_id = (int) $db->lastInsertId();
        $photo_path = "images/candidates/$new_id.$photo_ext";

        if (!is_dir(BASE_DIR . '/images/candidates')) {
            mkdir(BASE_DIR . '/images/candidates', 0755, true);
        }

        if (move_uploaded_file($photo['tmp_name'], BASE_DIR . '/' . $photo_path)) {
            $db->prepare('UPDATE candidates SET photo = ? WHERE id = ?')->execute([$photo_path, $new_id]);
            $db->commit();
            header('Location: candidates.php?added=1');
            exit;
        }
        $db->rollBack();
        $candidate_errors[] = 'The photo could not be saved. Please try again.';
    }
}

// Group candidates per partylist (partylist order first, then Independent),
// sorted by position inside each group
$position_order = array_flip($positions);
$party_descriptions = array_column($partylists, 'description', 'name');

$candidates_by_party = array_fill_keys($partylist_names, []);
foreach ($candidates as $candidate) {
    $candidates_by_party[$candidate['partylist']][] = $candidate;
}
foreach ($candidates_by_party as &$party_candidates) {
    usort($party_candidates, fn($a, $b) =>
        (($position_order[$a['position']] ?? 99) <=> ($position_order[$b['position']] ?? 99))
        ?: strcmp($a['name'], $b['name'])
    );
}
unset($party_candidates);

// Photo URL per candidate (relative to this page), or null when the file is missing
$candidate_photos = [];
foreach ($candidates as $candidate) {
    $candidate_photos[$candidate['id']] = is_file(BASE_DIR . '/' . $candidate['photo'])
        ? $root_url . $candidate['photo']
        : null;
}

require __DIR__ . '/includes/header.php';
?>
<?php if (isset($_GET['added'])): ?>
    <p class="alert success">Candidate added successfully.</p>
<?php elseif (isset($_GET['party_added'])): ?>
    <p class="alert success">Partylist created successfully.</p>
<?php endif; ?>

<div class="toolbar">
    <p>
        <?php echo count($candidates); ?> candidate(s) across
        <?php echo count($partylists); ?> partylist(s)
    </p>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary" onclick="openAddPartylist()"><i class="bx bx-flag"></i> Create Partylist</button>
        <button type="button" class="btn" onclick="openAddCandidate()"><i class="bx bx-user-plus"></i> Add Candidate</button>
    </div>
</div>

<?php foreach ($candidates_by_party as $party_name => $party_candidates): ?>
    <section class="card party">
        <div class="party-header">
            <div>
                <h2>
                    <?php echo h($party_name); ?>
                    <span class="dept-count">(<?php echo count($party_candidates); ?> candidate(s))</span>
                </h2>
                <?php if (!empty($party_descriptions[$party_name])): ?>
                    <p class="party-desc"><?php echo h($party_descriptions[$party_name]); ?></p>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-secondary btn-small"
                    data-party="<?php echo h($party_name); ?>"
                    onclick="openAddCandidate(this.dataset.party)"><i class="bx bx-plus"></i> Add Candidate</button>
        </div>

        <?php if (empty($party_candidates)): ?>
            <p class="empty">No candidates in this partylist yet.</p>
        <?php else: ?>
            <div class="candidate-grid">
                <?php foreach ($party_candidates as $candidate): ?>
                    <div class="candidate">
                        <div class="candidate-photo">
                            <?php if ($candidate_photos[$candidate['id']]): ?>
                                <img src="<?php echo h($candidate_photos[$candidate['id']]); ?>" alt="<?php echo h($candidate['name']); ?>">
                            <?php else: ?>
                                <?php echo h(initials($candidate['name'])); ?>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo h($candidate['name']); ?></h3>
                        <p class="position"><?php echo h($candidate['position']); ?></p>
                        <p class="meta">
                            <?php echo h($candidate['department']); ?> · <?php echo h($candidate['year']); ?>
                            <?php if ($candidate['section'] !== ''): ?> · Section <?php echo h($candidate['section']); ?><?php endif; ?>
                        </p>
                        <button type="button" class="btn" onclick="openCandidate(<?php echo (int) $candidate['id']; ?>)"><i class="bx bx-show"></i> View</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<!-- Create partylist modal -->
<div class="modal" id="addPartylistModal" onclick="if (event.target === this) closeAddPartylist()">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closeAddPartylist()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3>Create Partylist</h3>
        </div>

        <?php if (!empty($partylist_errors)): ?>
            <div class="alert error">
                <ul>
                    <?php foreach ($partylist_errors as $error): ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="candidates.php">
            <?php echo csrf_field(); ?>
            <div class="form-grid">
                <div class="form-group full">
                    <label for="party_name">Partylist Name</label>
                    <input type="text" id="party_name" name="party_name" value="<?php echo h($new_partylist['name']); ?>" required>
                </div>
                <div class="form-group full">
                    <label for="party_description">Description / Motto <span class="optional">(optional)</span></label>
                    <textarea id="party_description" name="party_description"><?php echo h($new_partylist['description']); ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddPartylist()">Cancel</button>
                <button type="submit" name="add_partylist" class="btn"><i class="bx bx-save"></i> Create Partylist</button>
            </div>
        </form>
    </div>
</div>

<!-- Add candidate modal -->
<div class="modal" id="addCandidateModal" onclick="if (event.target === this) closeAddCandidate()">
    <div class="modal-content wide">
        <button type="button" class="modal-close" onclick="closeAddCandidate()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3>Add Candidate</h3>
        </div>

        <?php if (!empty($candidate_errors)): ?>
            <div class="alert error">
                <ul>
                    <?php foreach ($candidate_errors as $error): ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="candidates.php" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <div class="photo-upload">
                <div class="candidate-photo" id="addPhotoPreview">?</div>
                <div class="form-group">
                    <label for="photo">Candidate Photo</label>
                    <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" required>
                    <p class="hint">JPG, PNG or WEBP, up to 2 MB.</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" value="<?php echo h($new_candidate['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="student_id">Student ID</label>
                    <input type="text" id="student_id" name="student_id" placeholder="e.g. 2023-00123" value="<?php echo h($new_candidate['student_id']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="position">Position</label>
                    <select id="position" name="position" required>
                        <option value="">Select position</option>
                        <?php foreach ($positions as $position): ?>
                            <option value="<?php echo h($position); ?>" <?php echo $new_candidate['position'] === $position ? 'selected' : ''; ?>>
                                <?php echo h($position); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="partylist">Partylist</label>
                    <select id="partylist" name="partylist" required>
                        <option value="">Select partylist</option>
                        <?php foreach (array_merge($partylist_names, [$independent]) as $party_name): ?>
                            <option value="<?php echo h($party_name); ?>" <?php echo $new_candidate['partylist'] === $party_name ? 'selected' : ''; ?>>
                                <?php echo h($party_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="department">Department</label>
                    <select id="department" name="department" required>
                        <option value="">Select department</option>
                        <?php foreach ($departments as $code => $dept_name): ?>
                            <option value="<?php echo h($code); ?>" <?php echo $new_candidate['department'] === $code ? 'selected' : ''; ?>>
                                <?php echo h($dept_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="course">Course</label>
                    <input type="text" id="course" name="course" placeholder="e.g. BS Information Technology" value="<?php echo h($new_candidate['course']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="year">Year Level</label>
                    <select id="year" name="year" data-year-for="department" required>
                        <?php echo year_options($dept_year_levels, $new_candidate['department'], $new_candidate['year']); ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="section">Section</label>
                    <select id="section" name="section" data-section-for="year">
                        <?php echo section_options($dept_sections, $new_candidate['department'], $new_candidate['year'], $new_candidate['section']); ?>
                    </select>
                </div>
                <div class="form-group full">
                    <label for="achievements">Achievements <span class="optional">(optional)</span></label>
                    <textarea id="achievements" name="achievements"><?php echo h($new_candidate['achievements']); ?></textarea>
                </div>
                <div class="form-group full">
                    <label for="platform">Platform <span class="optional">(optional)</span></label>
                    <textarea id="platform" name="platform"><?php echo h($new_candidate['platform']); ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddCandidate()">Cancel</button>
                <button type="submit" name="add_candidate" class="btn"><i class="bx bx-save"></i> Save Candidate</button>
            </div>
        </form>
    </div>
</div>

<!-- Candidate details modal -->
<div class="modal" id="candidateModal" onclick="if (event.target === this) closeCandidate()">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closeCandidate()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <div class="candidate-photo" id="mPhoto"></div>
            <h3 id="mName"></h3>
            <p class="position" id="mPosition"></p>
        </div>
        <dl class="details">
            <dt>Student ID</dt>   <dd id="mStudentId"></dd>
            <dt>Department</dt>   <dd id="mDepartment"></dd>
            <dt>Course</dt>       <dd id="mCourse"></dd>
            <dt>Year Level</dt>   <dd id="mYear"></dd>
            <dt>Section</dt>      <dd id="mSection"></dd>
            <dt>Partylist</dt>    <dd id="mPartylist"></dd>
            <dt>Achievements</dt> <dd id="mAchievements"></dd>
            <dt>Platform</dt>     <dd id="mPlatform"></dd>
        </dl>
    </div>
</div>

<script>
// ---------- Candidate details modal ----------
const candidates = <?php echo json_encode(array_column($candidates, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const departments = <?php echo json_encode($departments, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const candidatePhotos = <?php echo json_encode($candidate_photos, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

function openCandidate(id) {
    const c = candidates[id];
    if (!c) return;

    const photo = document.getElementById('mPhoto');
    photo.innerHTML = '';
    if (candidatePhotos[id]) {
        const img = document.createElement('img');
        img.src = candidatePhotos[id];
        img.alt = c.name;
        photo.appendChild(img);
    } else {
        photo.textContent = c.name.split(/\s+/).map(p => p[0]).join('').slice(0, 2).toUpperCase();
    }

    document.getElementById('mName').textContent = c.name;
    document.getElementById('mPosition').textContent = c.position;
    document.getElementById('mStudentId').textContent = c.student_id;
    document.getElementById('mDepartment').textContent = departments[c.department] || c.department;
    document.getElementById('mCourse').textContent = c.course;
    document.getElementById('mYear').textContent = c.year;
    document.getElementById('mSection').textContent = c.section || '—';
    document.getElementById('mPartylist').textContent = c.partylist;
    document.getElementById('mAchievements').textContent = c.achievements;
    document.getElementById('mPlatform').textContent = c.platform;

    document.getElementById('candidateModal').classList.add('open');
}

function closeCandidate() {
    document.getElementById('candidateModal').classList.remove('open');
}

// ---------- Add candidate modal ----------
function openAddCandidate(partylist) {
    if (partylist) {
        document.getElementById('partylist').value = partylist;
    }
    document.getElementById('addCandidateModal').classList.add('open');
}

function closeAddCandidate() {
    document.getElementById('addCandidateModal').classList.remove('open');
}

document.getElementById('photo').addEventListener('change', function () {
    const preview = document.getElementById('addPhotoPreview');
    const file = this.files[0];
    preview.innerHTML = '';
    if (!file) {
        preview.textContent = '?';
        return;
    }
    const img = document.createElement('img');
    img.src = URL.createObjectURL(file);
    img.alt = 'Photo preview';
    preview.appendChild(img);
});

// ---------- Create partylist modal ----------
function openAddPartylist() {
    document.getElementById('addPartylistModal').classList.add('open');
}

function closeAddPartylist() {
    document.getElementById('addPartylistModal').classList.remove('open');
}

// Reopen a form if its last submission had errors
if (<?php echo json_encode(!empty($candidate_errors)); ?>) {
    openAddCandidate();
}
if (<?php echo json_encode(!empty($partylist_errors)); ?>) {
    openAddPartylist();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeCandidate();
        closeAddCandidate();
        closeAddPartylist();
    }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
