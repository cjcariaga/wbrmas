<?php
require_once __DIR__ . '/../config/security.php';
secure_session_start();

function resident_portal_clear_session() {
    unset(
        $_SESSION['resident_portal_account_id'],
        $_SESSION['resident_portal_resident_id'],
        $_SESSION['resident_portal_username'],
        $_SESSION['resident_portal_last_activity']
    );
}

function resident_portal_current_account($json_response = false) {
    if (isset($_SESSION['user_id']) || empty($_SESSION['resident_portal_account_id'])) {
        if ($json_response) {
            http_response_code(401);
            echo json_encode(['success'=>false,'message'=>'Resident portal session required.']);
            exit;
        }
        header('Location: /portal/login.php');
        exit;
    }

    $last_activity = (int)($_SESSION['resident_portal_last_activity'] ?? time());
    if (time() - $last_activity > SESSION_TIMEOUT) {
        resident_portal_clear_session();
        if ($json_response) {
            http_response_code(401);
            echo json_encode(['success'=>false,'message'=>'Resident portal session expired.']);
            exit;
        }
        header('Location: /portal/login.php?timeout=1');
        exit;
    }
    $_SESSION['resident_portal_last_activity'] = time();

    $conn = getDBConnection();
    $account_id = (int)$_SESSION['resident_portal_account_id'];
    $stmt = $conn->prepare(
        "SELECT a.portal_account_id, a.resident_id, a.username,
                r.resident_code, r.first_name, r.middle_name, r.last_name,
                r.birth_date, r.sex, r.civil_status, r.contact_number, r.address
         FROM tbl_resident_portal_accounts a
         JOIN tbl_residents r ON r.resident_id=a.resident_id
         WHERE a.portal_account_id=? AND a.is_active=1 AND r.is_archived=0 AND r.record_status='Active'"
    );
    if (!$stmt) {
        http_response_code(500);
        exit('Portal account lookup failed.');
    }
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account) {
        resident_portal_clear_session();
        if ($json_response) {
            http_response_code(401);
            echo json_encode(['success'=>false,'message'=>'Resident portal account is unavailable.']);
            exit;
        }
        header('Location: /portal/login.php');
        exit;
    }
    return $account;
}
?>
