<?php
session_start();

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: includes/login.php');
        exit;
    }
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function current_user() {
    if (is_logged_in()) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'name' => $_SESSION['name'] ?? ''
        ];
    }
    return null;
}

function is_admin() {
    if (!is_logged_in()) {
        return false;
    }
    if (isset($_SESSION['role'])) {
        return strcasecmp($_SESSION['role'], 'Admin') === 0;
    }
    global $conn;
    if ($conn && !empty($_SESSION['user_id'])) {
        try {
            $stmt = $conn->prepare("SELECT role, employee_id FROM admin_users WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$_SESSION['user_id']]);
            $row = $stmt->fetch();
            if ($row) {
                if (!empty($row['role'])) {
                    $_SESSION['role'] = $row['role'];
                    return strcasecmp($row['role'], 'Admin') === 0;
                }
                return empty($row['employee_id']);
            }
        } catch (Exception $e) {}
    }
    return false;
}
