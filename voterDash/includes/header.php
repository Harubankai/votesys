<?php
// Page top, sidebar and top bar for voter pages. Set $page (a key of $voter_pages)
// and include init.php before this file. $page_title is optional.
if (!defined('BASE_DIR')) {
    exit;
}
$page_title = $page_title ?? $voter_pages[$page]['label'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page_title); ?> | Online Voting System</title>
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="<?php echo $root_url; ?>css/admin.css">
    <link rel="stylesheet" href="<?php echo $root_url; ?>css/voter.css">
</head>
<body>
<div class="layout">

    <!-- ===================== SIDEBAR ===================== -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="<?php echo $root_url; ?>images/logo.png" alt="St. Rose College logo">
            <p>Online Voting System<br><span>Voter Portal</span></p>
        </div>

        <nav class="nav">
            <?php foreach ($voter_pages as $key => $info): ?>
                <a href="<?php echo h($root_url . $info['url']); ?>" class="<?php echo $page === $key ? 'active' : ''; ?>">
                    <i class="bx <?php echo h($info['icon']); ?>"></i>
                    <?php echo h($info['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <a href="<?php echo $root_url; ?>voterDash/logout.php" class="logout"
           onclick="openLogout(); return false;"><i class="bx bx-log-out"></i> Log out</a>
    </aside>

    <main class="main">
        <!-- ===================== TOP BAR ===================== -->
        <header class="topbar">
            <h1 class="page-title"><?php echo h($page_title); ?></h1>

            <div class="topbar-actions">
                <div class="dropdown">
                    <button type="button" class="icon-btn" onclick="toggleMenu('notifMenu')"
                            aria-label="Notifications" aria-haspopup="true">
                        <i class="bx bx-bell"></i>
                        <?php if ($needs_attention): ?><span class="dot"></span><?php endif; ?>
                    </button>
                    <div class="menu" id="notifMenu">
                        <p class="menu-title">Notifications</p>
                        <ul class="notif-list">
                            <?php foreach ($notifications as $note): ?>
                                <li><i class="bx <?php echo h($note['icon']); ?>"></i> <span><?php echo h($note['text']); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="dropdown">
                    <button type="button" class="avatar" onclick="toggleMenu('profileMenu')"
                            aria-label="Profile" aria-haspopup="true"><?php echo h(initials($voter['name'])); ?></button>
                    <div class="menu" id="profileMenu">
                        <div class="profile-head">
                            <span class="avatar large"><?php echo h(initials($voter['name'])); ?></span>
                            <div>
                                <strong><?php echo h($voter['name']); ?></strong>
                                <span class="voter-id"><?php echo h($voter['voter_id']); ?></span>
                            </div>
                        </div>
                        <dl class="profile-details">
                            <dt>Student ID</dt> <dd><?php echo h($voter['student_id'] ?? '—'); ?></dd>
                            <dt>Department</dt> <dd><?php echo h($departments[$voter['department']] ?? $voter['department']); ?></dd>
                            <dt>Year Level</dt> <dd><?php echo h($voter['year']); ?></dd>
                            <dt>Section</dt>    <dd><?php echo h(section_label($voter['section'])); ?></dd>
                            <dt>Status</dt>
                            <dd>
                                <?php if ($voter['voted']): ?>
                                    <span class="badge voted">Voted</span>
                                <?php else: ?>
                                    <span class="badge pending">Not yet voted</span>
                                <?php endif; ?>
                            </dd>
                        </dl>
                        <a href="<?php echo $root_url; ?>voterDash/logout.php" class="btn btn-secondary btn-small menu-logout"
                           onclick="openLogout(); return false;"><i class="bx bx-log-out"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>
