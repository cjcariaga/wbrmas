<?php
require_once __DIR__ . '/../config/security.php';
secure_session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !check_session_timeout()) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Session expired. Sign in again.']);
    exit;
}
require_once __DIR__ . '/../includes/auth_check.php';
if (!user_has_permission('view_visitor_logbook')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access denied.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed.']);
    exit;
}
$csrf_token = $_POST['csrf_token'] ?? '';
if (!is_string($csrf_token) || !verify_csrf_token($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token. Refresh the page and try again.']);
    exit;
}
$visitor_id_input = $_POST['visitor_id'] ?? null;
$visitor_id = is_string($visitor_id_input) || is_int($visitor_id_input)
    ? filter_var($visitor_id_input, FILTER_VALIDATE_INT)
    : false;
if (!$visitor_id || $visitor_id < 1) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Invalid visitor entry.']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare(
    "UPDATE tbl_visitor_logbook SET time_out=NOW()
     WHERE visitor_id=? AND time_out IS NULL"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Could not prepare the time-out update.']);
    exit;
}
$stmt->bind_param('i', $visitor_id);
$executed = $stmt->execute();
$updated = $stmt->affected_rows === 1;
$stmt->close();

if (!$executed) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Could not record the time out.']);
    exit;
}
if (!$updated) {
    $check = $conn->prepare("SELECT time_out FROM tbl_visitor_logbook WHERE visitor_id=?");
    if (!$check) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Could not verify the visitor entry.']);
        exit;
    }
    $check->bind_param('i', $visitor_id);
    $check->execute();
    $entry = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$entry) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Visitor entry not found.']);
        exit;
    }
    http_response_code(409);
    echo json_encode(['success'=>false,'message'=>'Time out was already recorded.']);
    exit;
}

write_audit_log(current_user_id(), 'VISITOR_TIME_OUT', "visitor_id:$visitor_id", 'Time out recorded.');
echo json_encode(['success'=>true,'message'=>'Time out recorded.']);
