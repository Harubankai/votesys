<?php
// Live tally: vote counts per candidate, open to everyone (linked from the login page).
// tally.php shows the page; tally.php?format=json returns the latest counts, which the
// page fetches every few seconds to update itself.
require __DIR__ . '/adminDash/includes/init.php';

// ---------------------------------------------------------------
// Count the votes
// ---------------------------------------------------------------
$votes = [];   // $votes[position_id][candidate_id or 'abstain'] = count
$rows = $db->query(
    'SELECT position_id, candidate_id, COUNT(*) AS votes
     FROM ballot_choices
     GROUP BY position_id, candidate_id'
)->fetchAll();
foreach ($rows as $row) {
    $votes[$row['position_id']][$row['candidate_id'] ?? 'abstain'] = (int) $row['votes'];
}

$by_position = array_fill_keys($positions, []);
foreach ($candidates as $candidate) {
    $by_position[$candidate['position']][] = $candidate;
}

$tally_positions = [];
foreach ($by_position as $position => $list) {
    if (empty($list)) {
        continue;
    }
    $position_id = $position_ids[$position];
    $abstain = $votes[$position_id]['abstain'] ?? 0;
    $total = array_sum($votes[$position_id] ?? []);   // everyone who answered this position

    $rows = [];
    foreach ($list as $candidate) {
        $count = $votes[$position_id][$candidate['id']] ?? 0;
        $rows[] = [
            'name'      => $candidate['name'],
            'initials'  => initials($candidate['name']),
            'partylist' => $candidate['partylist'],
            'details'   => trim($candidate['department'] . ' · ' . $candidate['year']
                . ($candidate['section'] !== '' ? ' · Section ' . $candidate['section'] : '')),
            'photo'     => $candidate['photo'] !== '' && is_file(BASE_DIR . '/' . $candidate['photo']) ? $candidate['photo'] : null,
            'votes'     => $count,
            'percent'   => $total > 0 ? round($count / $total * 100, 1) : 0,
        ];
    }
    usort($rows, fn($a, $b) => $b['votes'] <=> $a['votes'] ?: strcasecmp($a['name'], $b['name']));

    // Rank by votes; tied candidates share a rank (e.g. 1, 2, 2, 4)
    foreach ($rows as $i => &$row) {
        $row['rank'] = ($i > 0 && $row['votes'] === $rows[$i - 1]['votes']) ? $rows[$i - 1]['rank'] : $i + 1;
    }
    unset($row);

    $tally_positions[] = [
        'position'        => $position,
        'candidates'      => $rows,
        'abstain'         => $abstain,
        'abstain_percent' => $total > 0 ? round($abstain / $total * 100, 1) : 0,
        'total'           => $total,
    ];
}

$total_voters = count($voters);
$total_voted = count(array_filter($voters, fn($v) => $v['voted']));

$tally = [
    'title'     => $election_title,
    'status'    => election_status($settings),
    'start'     => $settings['start'] !== '' ? date('F j, Y g:i A', strtotime($settings['start'])) : null,
    'end'       => $settings['end'] !== '' ? date('F j, Y g:i A', strtotime($settings['end'])) : null,
    'voters'    => $total_voters,
    'voted'     => $total_voted,
    'turnout'   => $total_voters > 0 ? round($total_voted / $total_voters * 100, 1) : 0,
    'positions' => $tally_positions,
    'updated'   => date('g:i:s A'),
];

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($tally);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Tally | Online Voting System</title>
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="css/tally.css">
</head>
<body>
<main class="tally-page">
    <header class="tally-header">
        <img src="images/logo.png" alt="St. Rose College logo">
        <div>
            <p class="tally-kicker">Online Voting System of Supreme Student Council</p>
            <h1 id="tallyTitle"><?php echo h($election_title); ?></h1>
            <p class="tally-sub">St. Rose College Educational Foundation Inc.</p>
        </div>
        <a href="index.php" class="btn btn-secondary btn-small tally-back"><i class="bx bx-log-in"></i> Back to Login</a>
    </header>

    <div class="tally-status">
        <span class="live-badge" id="statusBadge"><span class="pulse"></span> <span id="statusText">Loading…</span></span>
        <span class="tally-updated" id="updatedText"></span>
    </div>

    <div class="stats">
        <div class="stat"><span><i class="bx bx-group"></i> Registered Voters</span><strong id="statVoters">0</strong></div>
        <div class="stat"><span><i class="bx bx-check-circle"></i> Votes Cast</span><strong id="statVoted">0</strong></div>
        <div class="stat"><span><i class="bx bx-pie-chart-alt-2"></i> Turnout</span><strong id="statTurnout">0%</strong></div>
    </div>

    <div id="positions"></div>
</main>

<script>
// ---------- Live tally ----------
const REFRESH_SECONDS = 5;
const STATUS_TEXT = {
    open: 'Voting is live',
    upcoming: 'Voting has not started',
    ended: 'Final results',
    unset: 'No election scheduled',
};

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function photo(candidate) {
    const box = el('span', 'tally-photo');
    if (candidate.photo) {
        const img = el('img');
        img.src = candidate.photo;
        img.alt = '';
        box.appendChild(img);
    } else {
        box.textContent = candidate.initials;
    }
    return box;
}

function bar(percent) {
    const track = el('div', 'bar-track');
    const fill = el('div', 'bar-fill');
    fill.style.width = percent + '%';
    track.appendChild(fill);
    return track;
}

function render(data) {
    document.getElementById('tallyTitle').textContent = data.title;
    document.getElementById('statusText').textContent = STATUS_TEXT[data.status] || '';
    document.getElementById('statusBadge').className = 'live-badge status-' + data.status;
    document.getElementById('updatedText').textContent = 'Updated ' + data.updated
        + (data.status === 'open' ? ' · refreshes every ' + REFRESH_SECONDS + ' seconds' : '');
    document.getElementById('statVoters').textContent = data.voters;
    document.getElementById('statVoted').textContent = data.voted;
    document.getElementById('statTurnout').textContent = data.turnout + '%';

    const container = document.getElementById('positions');
    container.innerHTML = '';

    if (data.positions.length === 0) {
        const card = el('section', 'card');
        card.appendChild(el('p', 'empty', 'No candidates have been announced yet.'));
        container.appendChild(card);
        return;
    }

    data.positions.forEach(position => {
        const card = el('section', 'card');
        const head = el('div', 'party-header');
        head.appendChild(el('h2', '', position.position));
        head.appendChild(el('span', 'dept-count', position.total + ' vote(s)'));
        card.appendChild(head);

        // Leader(s): the highest count, if anyone has votes yet
        const top = position.candidates.length ? position.candidates[0].votes : 0;
        const leaders = position.candidates.filter(c => c.votes === top && top > 0).length;

        position.candidates.forEach(candidate => {
            const row = el('div', 'tally-row');
            const isLeader = top > 0 && candidate.votes === top;
            if (isLeader) row.classList.add('leader');

            row.appendChild(el('span', 'tally-rank rank-' + candidate.rank, '#' + candidate.rank));
            row.appendChild(photo(candidate));

            const info = el('div', 'tally-info');
            const nameLine = el('div', 'tally-name');
            nameLine.appendChild(el('strong', '', candidate.name));
            if (isLeader) {
                const label = leaders > 1 ? 'Tied' : (data.status === 'ended' ? 'Winner' : 'Leading');
                nameLine.appendChild(el('span', 'leader-badge', label));
            }
            info.appendChild(nameLine);
            info.appendChild(el('small', '', candidate.partylist + ' · ' + candidate.details));
            info.appendChild(bar(candidate.percent));
            row.appendChild(info);

            const count = el('div', 'tally-count');
            count.appendChild(el('strong', '', candidate.votes));
            count.appendChild(el('small', '', candidate.percent + '%'));
            row.appendChild(count);

            card.appendChild(row);
        });

        if (position.abstain > 0) {
            card.appendChild(el('p', 'table-note',
                'Abstained: ' + position.abstain + ' (' + position.abstain_percent + '%)'));
        }
        container.appendChild(card);
    });
}

render(<?php echo json_encode($tally, JSON_HEX_TAG | JSON_HEX_AMP); ?>);

// Fetch the latest counts every few seconds (skipped while the tab is hidden)
setInterval(async () => {
    if (document.hidden) return;
    try {
        const response = await fetch('tally.php?format=json', { cache: 'no-store' });
        if (!response.ok) throw new Error(response.status);
        render(await response.json());
    } catch (error) {
        document.getElementById('updatedText').textContent = 'Connection lost. Retrying…';
    }
}, REFRESH_SECONDS * 1000);
</script>
</body>
</html>
