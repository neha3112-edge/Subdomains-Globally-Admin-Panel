<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
$db = get_db_connection();

$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'admin@example.com';
$_SESSION['login_time'] = time();

// Test Syllabus API with subject ID 1
$sub = $db->query("SELECT id, subject_name FROM course_syllabus_subjects_master WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($sub) {
    $_GET['subject_id'] = $sub['id'];
    ob_start();
    include __DIR__ . '/api/get_syllabus_mappings.php';
    $raw = ob_get_clean();
    $data = json_decode($raw, true);
    echo "Raw response: " . $raw . "\n";
}
