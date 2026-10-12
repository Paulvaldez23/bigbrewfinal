<?php
// ============================================================
// GLOBAL TIMEZONE: Asia/Manila (UTC+8, PHP defaults to UTC otherwise = 8hrs behind!)
// This fixes ALL date() / strtotime() on EVERY page (upload timestamps,
// shift publish timestamps, attendance archive week math, etc.)
// ============================================================
if (!ini_get('date.timezone') || strpos(ini_get('date.timezone'), 'Asia') === false) {
    @date_default_timezone_set('Asia/Manila');
}
if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Manila');
}
$host = 'localhost';
$dbname = 'bigbrew_final';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Also set MySQL session timezone per-connection (for NOW(), CURRENT_TIMESTAMP defaults)
    try { $conn->exec("SET time_zone = '+08:00'"); } catch (Exception $e) {}
} catch (PDOException $e) {
    $conn = null;
}
