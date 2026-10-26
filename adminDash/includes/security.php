<?php
// Security shared by every page: browser security headers, the login session,
// CSRF tokens for forms, and the admin sign-in check.
if (!defined('BASE_DIR')) {
    exit;
}

const SESSION_IDLE_MINUTES = 30;        // logged out after this long without opening a page
const SESSION_REGENERATE_MINUTES = 15;  // new session ID this often, so a leaked one stops working
const ADMIN_MAX_ATTEMPTS = 5;           // wrong passwords allowed before the account locks
const ADMIN_LOCK_MINUTES = 15;

// ---------------------------------------------------------------
// Security headers
// ---------------------------------------------------------------
// Inline scripts and styles are allowed (the pages use them), but nothing can be
// loaded from other sites except the Boxicons stylesheet and fonts, and no other
// site can show these pages in a frame or receive their forms.
function send_security_headers()
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://unpkg.com; font-src 'self' https://unpkg.com; "
        . "img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; "
        . "form-action 'self'; frame-ancestors 'none'"
    );
}

// Plain error page (same look as db_fail) for rejected requests
function security_fail($status, $title, $message, $back_url)
{
    http_response_code($status);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . h($title) . '</title></head>'
        . '<body style="font-family: Segoe UI, sans-serif; max-width: 640px; margin: 60px auto; padding: 0 16px; color: #3d1f26">'
        . '<h2 style="color: #8e1b26">' . h($title) . '</h2><p>' . h($message) . '</p>'
        . '<p><a href="' . h($back_url) . '" style="color: #b92b3a; font-weight: 600">Go back</a></p></body></html>';
    exit;
}

// ---------------------------------------------------------------
// Session
// ---------------------------------------------------------------
function is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

// Starts the session with a cookie JavaScript can't read and other sites can't send,
// logs out idle sessions, ties the session to one browser, and changes the session ID
// every few minutes.
function start_secure_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');   // never accept a session ID the server didn't create
    ini_set('session.use_only_cookies', '1');  // never take the session ID from the URL
    ini_set('session.use_trans_sid', '0');
    session_name('VOTESYS_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    $browser = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

    $idle = isset($_SESSION['last_seen']) && $now - $_SESSION['last_seen'] > SESSION_IDLE_MINUTES * 60;
    $other_browser = isset($_SESSION['browser']) && !hash_equals($_SESSION['browser'], $browser);
    if ($idle || $other_browser) {
        $was_logged_in = !empty($_SESSION['voter_id']) || !empty($_SESSION['admin_id']);
        $_SESSION = [];
        session_regenerate_id(true);
        if ($was_logged_in) {
            $_SESSION['notice'] = 'Your session expired. Please log in again.';
        }
    }

    if (!isset($_SESSION['regenerated_at'])) {
        $_SESSION['regenerated_at'] = $now;
    } elseif ($now - $_SESSION['regenerated_at'] > SESSION_REGENERATE_MINUTES * 60) {
        session_regenerate_id(true);
        $_SESSION['regenerated_at'] = $now;
    }

    $_SESSION['browser'] = $browser;
    $_SESSION['last_seen'] = $now;
}

// New session ID and CSRF token after logging in, so an ID or token
// someone saw before the login is useless afterwards
function renew_session_after_login()
{
    session_regenerate_id(true);
    $_SESSION['regenerated_at'] = time();
    unset($_SESSION['csrf_token']);
}

// Clears the session and its cookie
function end_session()
{
    start_secure_session();
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    session_destroy();
}

// One-time message for the next page (e.g. "Your session expired")
function take_notice()
{
    $notice = $_SESSION['notice'] ?? '';
    unset($_SESSION['notice']);
    return $notice;
}

// ---------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------
// Every POST form includes csrf_field(); verify_csrf() rejects a POST whose token
// doesn't match this session's, so another site can't submit forms as the user.
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf($back_url)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    // PHP drops the whole form when an upload is bigger than post_max_size
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        security_fail(413, 'File too large', 'The file you uploaded is too large. Please choose a smaller file.', $back_url);
    }

    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        security_fail(
            403,
            'Form expired',
            'This form has expired or did not come from this site. Go back, reload the page and try again.',
            $back_url
        );
    }
}

// ---------------------------------------------------------------
// Admin sign-in
// ---------------------------------------------------------------
// Call at the top of every admin page. Sends anyone who isn't signed in as an admin
// to the admin login page, and checks the CSRF token of every POST.
// $root_url: '' from the project root, '../' from adminDash/.
function require_admin($root_url)
{
    start_secure_session();

    $admin = null;
    if (!empty($_SESSION['admin_id'])) {
        $find = db()->prepare('SELECT id, username FROM admins WHERE id = ?');
        $find->execute([$_SESSION['admin_id']]);
        $admin = $find->fetch() ?: null;
    }
    if ($admin === null) {
        unset($_SESSION['admin_id']);
        header('Location: ' . $root_url . 'adminDash/login.php');
        exit;
    }

    verify_csrf($_SERVER['REQUEST_URI'] ?? $root_url . 'admin_dashB.php');
    return $admin;
}

// The first admin account can only be created on the server computer itself,
// so nobody on the network can claim it before the real admin does
function is_local_request()
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}
