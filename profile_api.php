<?php
require_once __DIR__ . '/config/security.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !check_session_timeout()) {
    echo json_encode(['success'=>false,'message'=>'Session expired.']); exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token.']); exit;
}

$action = $_GET['action'] ?? '';
$conn   = getDBConnection();
$uid    = (int)$_SESSION['user_id'];

// ── UPDATE PROFILE ─────────────────────────────────────────────────────────
if ($action === 'update_profile') {
    $first_name  = sanitize_input($_POST['first_name']  ?? '');
    $middle_name = sanitize_input($_POST['middle_name'] ?? '');
    $last_name   = sanitize_input($_POST['last_name']   ?? '');

    if (!$first_name || !$last_name) {
        echo json_encode(['success'=>false,'message'=>'First name and last name are required.']); exit;
    }

    $full_name = trim("$last_name, $first_name" . ($middle_name ? " $middle_name" : ''));

    $stmt = $conn->prepare(
        "UPDATE tbl_users SET first_name=?, middle_name=?, last_name=?, full_name=? WHERE user_id=?"
    );
    $stmt->bind_param("ssssi", $first_name, $middle_name, $last_name, $full_name, $uid);

    if ($stmt->execute()) {
        // Update session
        $_SESSION['full_name'] = $full_name;
        write_audit_log($uid, 'UPDATE_PROFILE', "user_id:$uid", "Name updated to: $full_name");
        echo json_encode(['success'=>true, 'full_name'=>$full_name]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Update failed: '.$stmt->error]);
    }
    $stmt->close();
}

// ── CHANGE PASSWORD ─────────────────────────────────────────────────────────
elseif ($action === 'change_password') {
    $current_pw  = $_POST['current_password']  ?? '';
    $new_pw      = $_POST['new_password']      ?? '';
    $confirm_pw  = $_POST['confirm_password']  ?? '';

    // Validate inputs
    if (!$current_pw || !$new_pw || !$confirm_pw) {
        echo json_encode(['success'=>false,'message'=>'All fields are required.']); exit;
    }
    if (strlen($new_pw) < 8) {
        echo json_encode(['success'=>false,'message'=>'New password must be at least 8 characters.']); exit;
    }
    if ($new_pw !== $confirm_pw) {
        echo json_encode(['success'=>false,'message'=>'New passwords do not match.']); exit;
    }
    if ($current_pw === $new_pw) {
        echo json_encode(['success'=>false,'message'=>'New password must be different from current.']); exit;
    }

    // Fetch current hash
    $stmt = $conn->prepare("SELECT password_hash FROM tbl_users WHERE user_id=?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current_pw, $row['password_hash'])) {
        write_audit_log($uid, 'CHANGE_PASSWORD_FAILED', "user_id:$uid", 'Wrong current password');
        echo json_encode(['success'=>false,'message'=>'Current password is incorrect.']); exit;
    }

    // Hash new password
    $new_hash = password_hash($new_pw, PASSWORD_BCRYPT, ['cost'=>10]);

    $stmt2 = $conn->prepare(
        "UPDATE tbl_users SET password_hash=?, failed_attempts=0, locked_until=NULL WHERE user_id=?"
    );
    $stmt2->bind_param("si", $new_hash, $uid);

    if ($stmt2->execute()) {
        write_audit_log($uid, 'CHANGE_PASSWORD_SUCCESS', "user_id:$uid", 'Password changed by user');
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Failed to update password.']);
    }
    $stmt2->close();
}

else {
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
}
?>
