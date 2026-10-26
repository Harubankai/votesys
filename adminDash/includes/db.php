<?php
// MySQL connection for the votesys database (XAMPP defaults).
// Change these if your MySQL user or password are different.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'votesys';
const DB_USER = 'root';
const DB_PASS = '';

// Shows a plain error page and stops (used when the database is missing or down)
function db_fail($message)
{
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Database Error</title></head>'
        . '<body style="font-family: Segoe UI, sans-serif; max-width: 640px; margin: 60px auto; padding: 0 16px; color: #3d1f26">'
        . '<h2 style="color: #8e1b26">Database error</h2><p>' . htmlspecialchars($message) . '</p></body></html>';
    exit;
}

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            // Philippine time, matching PHP's timezone
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            db_fail('Could not connect to the "' . DB_NAME . '" database. Make sure MySQL is running in the '
                . 'XAMPP Control Panel and that database/votesys.sql has been imported.');
        }
    }
    return $pdo;
}
