<?php
require_once '../config/security.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !check_session_timeout()) {
    echo json_encode(['success'=>false,'message'=>'Session expired.']); exit;
}
// Admin only
if (($_SESSION['role_name'] ?? '') !== 'System Administrator') {
    echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
}
if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token.']); exit;
}

$target_uid = (int)($_GET['uid'] ?? 0);
if (!$target_uid) {
    echo json_encode(['success'=>false,'message'=>'Invalid user.']); exit;
}

$conn = getDBConnection();

// IDOR: verify the target user exists
$chk = $conn->prepare("SELECT user_id FROM tbl_users WHERE user_id=?");
$chk->bind_param("i", $target_uid); $chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
    echo json_encode(['success'=>false,'message'=>'User not found.']); exit;
}
$chk->close();

// Fetch actions — exclude login/logout events
$excluded = "('LOGIN_SUCCESS','LOGIN_FAILED','LOGOUT','SESSION_EXPIRED')";

$stmt = $conn->prepare(
    "SELECT action_type, affected_record, details,
            DATE_FORMAT(timestamp, '%b %d, %Y %h:%i %p') AS timestamp
     FROM tbl_audit_logs
     WHERE user_id = ?
       AND action_type NOT IN $excluded
     ORDER BY timestamp DESC
     LIMIT 50"
);
$stmt->bind_param("i", $target_uid);
$stmt->execute();
$rows = $stmt->get_result();
$stmt->close();

$logs = [];
while ($r = $rows->fetch_assoc()) {
    $logs[] = [
        'action_type'     => $r['action_type'],
        'affected_record' => $r['affected_record'],
        'details'         => $r['details'],
        'timestamp'       => $r['timestamp'],
    ];
}

echo json_encode(['success'=>true,'logs'=>$logs]);
?>
