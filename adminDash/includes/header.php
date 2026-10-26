<?php
// Page top + sidebar. Set $page (a key of $admin_pages) and $root_url
// ('' from the project root, '../' from adminDash/) before including.
// $page_title is optional and defaults to the sidebar label.
if (!defined('BASE_DIR')) {
    exit;
}
$page_title = $page_title ?? $admin_pages[$page]['label'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page_title); ?> | Admin – Online Voting System</title>
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="<?php echo $root_url; ?>css/admin.css">
</head>
<body>
<div class="layout">

    <!-- ===================== SIDEBAR ===================== -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="<?php echo $root_url; ?>images/logo.png" alt="St. Rose College logo">
            <p>Online Voting System<br><span>Admin Panel</span></p>
        </div>

        <nav class="nav">
            <?php foreach ($admin_pages as $key => $info): ?>
                <a href="<?php echo h($root_url . $info['url']); ?>" class="<?php echo $page === $key ? 'active' : ''; ?>">
                    <i class="bx <?php echo h($info['icon']); ?>"></i>
                    <?php echo h($info['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <a href="<?php echo $root_url; ?>logout.php" class="logout"
           onclick="openLogout(); return false;"><i class="bx bx-log-out"></i> Logout</a>
    </aside>

    <main class="main">
        <h1 class="page-title"><?php echo h($page_title); ?></h1>
