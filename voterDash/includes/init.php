<?php
// Shared setup for every voter page. Set $root_url ('' from the project root,
// '../' from voterDash/) before including.

// Election data and helpers (schedule, voters, partylists, candidates)
require dirname(__DIR__, 2) . '/adminDash/includes/init.php';

start_secure_session();

// ---------------------------------------------------------------
// Logged-in voter (set by index.php) — send everyone else to the login page
// ---------------------------------------------------------------
$voter = null;
foreach ($voters as $v) {
    if (isset($_SESSION['voter_id']) && $v['voter_id'] === $_SESSION['voter_id']) {
        $voter = $v;
        break;
    }
}
if ($voter === null) {
    unset($_SESSION['voter_id']);
    header('Location: ' . $root_url . 'index.php');
    exit;
}

// Every POST (casting the vote) must carry this session's CSRF token
verify_csrf($_SERVER['REQUEST_URI'] ?? $root_url . 'voter_dashB.php');

// ---------------------------------------------------------------
// Sidebar pages (URLs are relative to the project root)
// ---------------------------------------------------------------
$voter_pages = [
    'dashboard'  => ['label' => 'Dashboard',       'url' => 'voter_dashB.php',          'icon' => 'bx-grid-alt'],
    'candidates' => ['label' => 'View Candidates', 'url' => 'voterDash/candidates.php', 'icon' => 'bx-id-card'],
    'vote'       => ['label' => 'Cast your Vote',  'url' => 'voterDash/vote.php',       'icon' => 'bx-check-square'],
];

$status = election_status($settings);

function format_datetime($value)
{
    return date('F j, Y g:i A', is_numeric($value) ? (int) $value : strtotime($value));
}

// Candidate photo URL relative to the current page, or null if there is no photo file
function photo_url(array $candidate, $root_url)
{
    return !empty($candidate['photo']) && is_file(BASE_DIR . '/' . $candidate['photo'])
        ? $root_url . $candidate['photo']
        : null;
}

// Candidates grouped by position in ballot order; positions with no candidates are left out
function candidates_by_position(array $candidates, array $positions)
{
    $grouped = array_fill_keys($positions, []);
    foreach ($candidates as $candidate) {
        $grouped[$candidate['position']][] = $candidate;
    }
    foreach ($grouped as &$list) {
        usort($list, fn($a, $b) => strcmp($a['partylist'], $b['partylist']) ?: strcmp($a['name'], $b['name']));
    }
    unset($list);
    return array_filter($grouped);
}

// ---------------------------------------------------------------
// Notifications (bell in the top bar)
// ---------------------------------------------------------------
$notifications = [];
if ($voter['voted']) {
    $notifications[] = [
        'icon' => 'bx-check-circle',
        'text' => !empty($voter['voted_at'])
            ? 'Your vote was recorded on ' . format_datetime($voter['voted_at']) . '. Thank you!'
            : 'Your vote has been recorded. Thank you!',
    ];
} elseif ($status === 'open') {
    $notifications[] = [
        'icon' => 'bx-error-circle',
        'text' => 'Voting is open until ' . format_datetime($settings['end']) . '. Cast your vote now!',
    ];
}
if ($status === 'upcoming') {
    $notifications[] = ['icon' => 'bx-calendar', 'text' => 'Voting opens on ' . format_datetime($settings['start']) . '.'];
} elseif ($status === 'ended') {
    $notifications[] = ['icon' => 'bx-flag', 'text' => 'Voting has ended. Thank you for taking part in the election.'];
} elseif ($status === 'unset') {
    $notifications[] = ['icon' => 'bx-info-circle', 'text' => 'The election schedule has not been announced yet.'];
}

// Red dot on the bell when the voter still needs to do something
$needs_attention = !$voter['voted'] && $status === 'open';
