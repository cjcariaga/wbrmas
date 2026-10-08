<?php
require_once __DIR__ . '/../includes/auth_check.php';
header('Content-Type: application/json; charset=utf-8');

function healthResponse($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function healthInput($value, $max_length) {
    $value = trim(strip_tags((string)$value));
    if (mb_strlen($value, 'UTF-8') > $max_length) return null;
    return $value;
}

$allowed_roles = ['System Administrator', 'Barangay Staff'];
if (!in_array($_SESSION['role_name'] ?? '', $allowed_roles, true)) {
    http_response_code(403);
    healthResponse(false, 'Access denied.');
}
if (!user_has_permission('view_health_records')) {
    http_response_code(403);
    healthResponse(false, 'Access denied.');
}
if (!user_has_permission('manage_health_records')) {
    http_response_code(403);
    healthResponse(false, 'You do not have permission to edit health records.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    healthResponse(false, 'Method not allowed.');
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    healthResponse(false, 'Invalid CSRF token.');
}

$action = sanitize_input($_GET['action'] ?? '');
if (!in_array($action, ['create', 'update'], true)) {
    http_response_code(400);
    healthResponse(false, 'Unknown health record action.');
}

$conn = getDBConnection();
$user_id = (int)$_SESSION['user_id'];
$record_id = (int)($_POST['health_record_id'] ?? 0);
$resident_id = (int)($_POST['resident_id'] ?? 0);
$record_type = healthInput($_POST['record_type'] ?? '', 20);
$item_name = healthInput($_POST['item_name'] ?? '', 150);
$event_date = healthInput($_POST['event_date'] ?? '', 10);
$details = healthInput($_POST['details'] ?? '', 2000);
$allowed_types = ['vaccination', 'condition', 'allergy', 'program'];

if (($action === 'update' && !$record_id) || !$resident_id || !$record_type || !$item_name || $item_name === '' || $details === null || !in_array($record_type, $allowed_types, true)) {
    healthResponse(false, 'Resident, record type, and item name are required. Keep item names to 150 characters and notes to 2,000 characters.');
}
if ($event_date === null) healthResponse(false, 'Date must use the YYYY-MM-DD format.');
if ($event_date !== '') {
    $date_parts = explode('-', $event_date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date)
        || count($date_parts) !== 3
        || !checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
        healthResponse(false, 'Enter a valid date.');
    }
}

$resident_check = $conn->prepare("SELECT resident_id, resident_code, is_archived FROM tbl_residents WHERE resident_id=?");
if (!$resident_check) healthResponse(false, 'Could not validate the resident record.');
$resident_check->bind_param('i', $resident_id);
$resident_check->execute();
$resident = $resident_check->get_result()->fetch_assoc();
$resident_check->close();
if (!$resident) healthResponse(false, 'Resident record was not found.');
if ($action === 'create' && (int)$resident['is_archived'] === 1) healthResponse(false, 'New health records cannot be added to an archived resident.');

$type_cipher = aes_encrypt($record_type);
$name_cipher = aes_encrypt($item_name);
$date_cipher = $event_date !== '' ? aes_encrypt($event_date) : null;
$details_cipher = $details !== '' ? aes_encrypt($details) : null;

if ($action === 'create') {
    $stmt = $conn->prepare("INSERT INTO tbl_health_records (resident_id, record_type_enc, item_name_enc, event_date_enc, details_enc, created_by, updated_by) VALUES (?,?,?,?,?,?,?)");
    if (!$stmt) healthResponse(false, 'Could not save health record.');
    $stmt->bind_param('issssii', $resident_id, $type_cipher, $name_cipher, $date_cipher, $details_cipher, $user_id, $user_id);
    if (!$stmt->execute()) {
        $stmt->close();
        healthResponse(false, 'Could not save health record.');
    }
    $record_id = $conn->insert_id;
    $stmt->close();
    write_audit_log($user_id, 'CREATE_HEALTH_RECORD', "health_record:$record_id", "Resident:{$resident['resident_code']}");
    healthResponse(true, 'Health record added.', ['health_record_id' => $record_id]);
}

$existing_check = $conn->prepare("SELECT health_record_id FROM tbl_health_records WHERE health_record_id=?");
if (!$existing_check) healthResponse(false, 'Could not validate the health record.');
$existing_check->bind_param('i', $record_id);
$existing_check->execute();
$existing = (bool)$existing_check->get_result()->fetch_assoc();
$existing_check->close();
if (!$existing) healthResponse(false, 'Health record was not found.');

$stmt = $conn->prepare("UPDATE tbl_health_records SET resident_id=?, record_type_enc=?, item_name_enc=?, event_date_enc=?, details_enc=?, updated_by=? WHERE health_record_id=?");
if (!$stmt) healthResponse(false, 'Could not update health record.');
$stmt->bind_param('issssii', $resident_id, $type_cipher, $name_cipher, $date_cipher, $details_cipher, $user_id, $record_id);
if (!$stmt->execute()) {
    $stmt->close();
    healthResponse(false, 'Could not update health record.');
}
$stmt->close();
write_audit_log($user_id, 'UPDATE_HEALTH_RECORD', "health_record:$record_id", "Resident:{$resident['resident_code']}");
healthResponse(true, 'Health record updated.', ['health_record_id' => $record_id]);
