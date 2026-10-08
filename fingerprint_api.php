<?php
require_once __DIR__ . '/config/security.php';
secure_session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);

$action = sanitize_input($_GET['action'] ?? $_POST['action'] ?? '');
$biometric_actions = ['start_biometric', 'get_identification_candidates', 'identify_verify', 'fingerprint_failure'];
$has_pending_login = isset($_SESSION['pending_uid']);
if ($has_pending_login) {
    $pending_started = (int)($_SESSION['pending_started'] ?? $_SESSION['last_activity'] ?? time());
    $_SESSION['pending_started'] = $pending_started;
    $_SESSION['last_activity'] = time();
    if (time() - $pending_started > 900) {
        unset($_SESSION['pending_uid'], $_SESSION['pending_user'], $_SESSION['pending_started'], $_SESSION['fingerprint_challenge']);
        echo json_encode(['success'=>false,'message'=>'Fingerprint login session expired. Please sign in again.']); exit;
    }
} elseif (!in_array($action, $biometric_actions, true) && (!isset($_SESSION['user_id']) || !check_session_timeout())) {
    echo json_encode(['success'=>false,'message'=>'Session expired.']); exit;
}
if ($action !== 'get_identification_candidates' && !verify_csrf_token($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token.']); exit;
}

$conn  = getDBConnection();
$uid   = (int)($_SESSION['user_id'] ?? 0);

if ($action === 'start_biometric') {
    if (!check_rate_limit('staff_biometric_'.$ip)) {
        http_response_code(429);
        echo json_encode(['success'=>false,'message'=>'Too many fingerprint login attempts. Please wait and try again.']); exit;
    }
    unset($_SESSION['pending_uid'], $_SESSION['pending_user'], $_SESSION['pending_started'], $_SESSION['fingerprint_verified']);
    $_SESSION['biometric_challenge'] = bin2hex(random_bytes(32));
    $_SESSION['biometric_ticket'] = bin2hex(random_bytes(32));
    $_SESSION['biometric_started'] = time();
    $candidate_timestamp = time();
    $candidate_token = hash_hmac('sha256', $_SESSION['biometric_challenge'].'|'.$_SESSION['biometric_ticket'].'|'.$candidate_timestamp, FINGERPRINT_BRIDGE_SECRET);
    echo json_encode(['success'=>true,'challenge'=>$_SESSION['biometric_challenge'],'ticket'=>$_SESSION['biometric_ticket'],'candidate_timestamp'=>$candidate_timestamp,'candidate_token'=>$candidate_token]); exit;
}

if ($action === 'get_identification_candidates') {
    $challenge = trim((string)($_POST['challenge'] ?? ''));
    $ticket = trim((string)($_POST['ticket'] ?? ''));
    $timestamp = (int)($_POST['candidate_timestamp'] ?? 0);
    $token = strtolower(trim((string)($_POST['candidate_token'] ?? '')));
    $bridge_proof = strtolower(trim((string)($_POST['bridge_proof'] ?? '')));
    $expected_token = hash_hmac('sha256', $challenge.'|'.$ticket.'|'.$timestamp, FINGERPRINT_BRIDGE_SECRET);
    $expected_proof = hash_hmac('sha256', 'identify-candidates|'.$challenge.'|'.$ticket.'|'.$timestamp.'|'.$token, FINGERPRINT_BRIDGE_SECRET);
    if (!$challenge || !$ticket || !$timestamp || abs(time() - $timestamp) > 120
        || !hash_equals($expected_token, $token) || !hash_equals($expected_proof, $bridge_proof)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Biometric identification challenge expired or invalid.']); exit;
    }
    $result = $conn->query(
        "SELECT f.user_id,f.template_blob FROM tbl_fingerprint_templates f
         JOIN tbl_users u ON u.user_id=f.user_id
         WHERE u.is_active=1 AND f.template_blob IS NOT NULL"
    );
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $candidates[] = ['user_id'=>(int)$row['user_id'],'template'=>base64_encode($row['template_blob'])];
    }
    echo json_encode(['success'=>true,'candidates'=>$candidates]); exit;
}

if ($action === 'identify_verify') {
    $challenge = trim((string)($_POST['challenge'] ?? ''));
    $ticket = trim((string)($_POST['ticket'] ?? ''));
    $target_uid = (int)($_POST['user_id'] ?? 0);
    $score = (int)($_POST['score'] ?? 0);
    $signature = strtolower(trim((string)($_POST['signature'] ?? '')));
    $expected = hash_hmac('sha256', $challenge.'|'.$target_uid.'|'.$score.'|identify', FINGERPRINT_BRIDGE_SECRET);
    $started = (int)($_SESSION['biometric_started'] ?? 0);
    if (!$target_uid || !$score || time() - $started > 120
        || !hash_equals((string)($_SESSION['biometric_challenge'] ?? ''), $challenge)
        || !hash_equals((string)($_SESSION['biometric_ticket'] ?? ''), $ticket)
        || !hash_equals($expected, $signature)) {
        write_audit_log(null, 'FINGERPRINT_VERIFY_FAILED', 'staff_biometric_login', 'Invalid identification proof');
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Fingerprint identification proof was rejected.']); exit;
    }
    $stmt = $conn->prepare(
        "SELECT u.user_id,u.username,u.full_name FROM tbl_users u
         JOIN tbl_fingerprint_templates f ON f.user_id=u.user_id
         WHERE u.user_id=? AND u.is_active=1 AND f.template_blob IS NOT NULL"
    );
    $stmt->bind_param('i', $target_uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'No active account is enrolled for this fingerprint.']); exit;
    }
    $_SESSION['pending_uid'] = (int)$user['user_id'];
    $_SESSION['pending_user'] = $user['full_name'];
    $_SESSION['pending_started'] = time();
    $_SESSION['pending_login_method'] = 'fingerprint';
    $_SESSION['fingerprint_verified'] = true;
    unset($_SESSION['biometric_challenge'], $_SESSION['biometric_ticket'], $_SESSION['biometric_started']);
    write_audit_log($user['user_id'], 'FINGERPRINT_VERIFY_SUCCESS', $user['username'], 'Standalone ZKTeco biometric login');
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'save_template') {
    if (($_SESSION['role_name'] ?? '') !== 'System Administrator') {
        echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
    }
    $target_uid = (int)($_POST['user_id'] ?? 0);
    $template_b64 = trim((string)($_POST['template'] ?? ''));
    $device_info = sanitize_input($_POST['device_info'] ?? 'ZKTeco ZK9500');
    $template = base64_decode($template_b64, true);

    if (!$target_uid || $template === false || strlen($template) < 100 || strlen($template) > 4096) {
        echo json_encode(['success'=>false,'message'=>'Invalid fingerprint template.']); exit;
    }
    $check = $conn->prepare("SELECT user_id FROM tbl_users WHERE user_id=? AND is_active=1");
    $check->bind_param('i', $target_uid); $check->execute();
    if (!$check->get_result()->fetch_assoc()) {
        $check->close(); echo json_encode(['success'=>false,'message'=>'User not found or inactive.']); exit;
    }
    $check->close();

    $hash = hash_hmac('sha256', $template, HMAC_SECRET_KEY, true);
    $stmt = $conn->prepare(
        "INSERT INTO tbl_fingerprint_templates (user_id, biometric_hash, template_blob, device_info)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE biometric_hash=VALUES(biometric_hash), template_blob=VALUES(template_blob), device_info=VALUES(device_info), enrolled_at=NOW()"
    );
    $stmt->bind_param('isss', $target_uid, $hash, $template, $device_info);
    $ok = $stmt->execute(); $stmt->close();
    if (!$ok) { echo json_encode(['success'=>false,'message'=>'Failed to save fingerprint template.']); exit; }
    write_audit_log($uid, 'FINGERPRINT_ENROLL', "user_id:$target_uid", "Device:$device_info");
    echo json_encode(['success'=>true,'message'=>'Fingerprint enrolled successfully.']); exit;
}

if ($action === 'get_pending_template') {
    $pending_uid = (int)($_SESSION['pending_uid'] ?? 0);
    if (!$pending_uid) { echo json_encode(['success'=>false,'message'=>'No pending login.']); exit; }
    $stmt = $conn->prepare("SELECT template_blob, device_info FROM tbl_fingerprint_templates WHERE user_id=?");
    $stmt->bind_param('i', $pending_uid); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $_SESSION['fingerprint_challenge'] = bin2hex(random_bytes(32));
    if (!$row || !$row['template_blob']) {
        echo json_encode(['success'=>true,'enrollment_required'=>true,'challenge'=>$_SESSION['fingerprint_challenge']]); exit;
    }
    echo json_encode([
        'success'=>true,
        'template'=>base64_encode($row['template_blob']),
        'device_info'=>$row['device_info'],
        'challenge'=>$_SESSION['fingerprint_challenge']
    ]); exit;
}

if ($action === 'save_pending_template') {
    $target_uid = (int)($_SESSION['pending_uid'] ?? 0);
    $template_b64 = trim((string)($_POST['template'] ?? ''));
    $template = base64_decode($template_b64, true);
    if (!$target_uid || $template === false || strlen($template) < 100 || strlen($template) > 4096) {
        echo json_encode(['success'=>false,'message'=>'Invalid fingerprint template.']); exit;
    }
    $check = $conn->prepare("SELECT user_id FROM tbl_users WHERE user_id=? AND is_active=1");
    $check->bind_param('i', $target_uid); $check->execute();
    if (!$check->get_result()->fetch_assoc()) {
        $check->close(); echo json_encode(['success'=>false,'message'=>'Account not found or inactive.']); exit;
    }
    $check->close();
    $hash = hash_hmac('sha256', $template, HMAC_SECRET_KEY, true);
    $stmt = $conn->prepare(
        "INSERT INTO tbl_fingerprint_templates (user_id, biometric_hash, template_blob, device_info)
         VALUES (?,?,?,'ZKTeco ZK9500')
         ON DUPLICATE KEY UPDATE biometric_hash=VALUES(biometric_hash), template_blob=VALUES(template_blob), device_info=VALUES(device_info), enrolled_at=NOW()"
    );
    $stmt->bind_param('iss', $target_uid, $hash, $template);
    $ok = $stmt->execute(); $stmt->close();
    if (!$ok) { echo json_encode(['success'=>false,'message'=>'Failed to save fingerprint template.']); exit; }
    $_SESSION['fingerprint_verified'] = true;
    unset($_SESSION['fingerprint_challenge']);
    write_audit_log($target_uid, 'FINGERPRINT_ENROLL', "user_id:$target_uid", 'Device:ZKTeco ZK9500 | Bootstrap enrollment');
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'bridge_verify') {
    $challenge = sanitize_input($_POST['challenge'] ?? '');
    $score = (int)($_POST['score'] ?? 0);
    $signature = strtolower(trim((string)($_POST['signature'] ?? '')));
    $expected = hash_hmac('sha256', $challenge.'|'.$score, FINGERPRINT_BRIDGE_SECRET);
    if (!$challenge || !hash_equals((string)($_SESSION['fingerprint_challenge'] ?? ''), $challenge) ||
        !hash_equals($expected, $signature)) {
        write_audit_log(
            (int)($_SESSION['pending_uid'] ?? 0) ?: null,
            'FINGERPRINT_VERIFY_FAILED',
            'pending_login',
            'Invalid or mismatched bridge verification proof'
        );
        echo json_encode(['success'=>false,'message'=>'Invalid fingerprint verification proof.']); exit;
    }
    $_SESSION['fingerprint_verified'] = true;
    unset($_SESSION['fingerprint_challenge']);
    write_audit_log(
        (int)($_SESSION['pending_uid'] ?? 0) ?: null,
        'FINGERPRINT_VERIFY_SUCCESS',
        'pending_login',
        'ZKTeco ZK9500 bridge proof accepted; score:'.$score
    );
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'fingerprint_failure') {
    $message = sanitize_input($_POST['message'] ?? 'Fingerprint verification failed.');
    $message = substr($message, 0, 255);
    write_audit_log(
        (int)($_SESSION['pending_uid'] ?? 0) ?: null,
        'FINGERPRINT_VERIFY_FAILED',
        'pending_login',
        $message
    );
    echo json_encode(['success'=>true]); exit;
}

echo json_encode(['success'=>false,'message'=>'Unknown action.']);
?>
