<?php
/**
 * Universal Helper Functions
 */

function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die("CSRF validation failed.");
    }
}

function set_flash_message($arg1, $arg2 = 'success') {
    if (in_array($arg1, ['success', 'error', 'info', 'warning'])) {
        $type = $arg1;
        $message = $arg2;
    } else {
        $message = $arg1;
        $type = $arg2;
    }
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

function display_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        $class = $flash['type'] === 'error' ? 'alert-error' : ($flash['type'] === 'success' ? 'alert-success' : 'alert-info');
        return '<div class="admin-alert ' . $class . '">' . htmlspecialchars($flash['message']) . '<button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button></div>';
    }
    return '';
}

function get_user_initials($name) {
    $parts = explode(' ', trim($name));
    $initials = '';
    if (!empty($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
    if (isset($parts[1])) $initials .= strtoupper(substr($parts[1], 0, 1));
    return !empty($initials) ? $initials : 'U';
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
