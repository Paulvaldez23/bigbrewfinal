<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/db_con.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to change your password.']);
    exit;
}

$currentPassword = (string)($_POST['current_password'] ?? '');
$newPassword     = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if (trim($currentPassword) === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter your current password.']);
    exit;
}

if (strlen($newPassword) < 6) {
    echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters long.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'New password and confirmation password do not match.']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, username, password FROM admin_users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Account not found.']);
        exit;
    }

    if (!password_verify($currentPassword, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Incorrect current password. Please try again.']);
        exit;
    }

    if (password_verify($newPassword, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'New password cannot be the same as your current password.']);
        exit;
    }

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $upStmt = $conn->prepare("UPDATE admin_users SET password = ? WHERE id = ?");
    $upStmt->execute([$hashed, $userId]);

    echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
