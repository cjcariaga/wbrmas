<?php
require_once __DIR__ . '/../config/security.php';
secure_session_start();
header('Content-Type: application/json; charset=utf-8');

function portalResponse($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success'=>$success,'message'=>$message], $extra));
    exit;
}
function portalText($value, $max_length) {
    $value = trim(strip_tags((string)$value));
    return mb_strlen($value, 'UTF-8') <= $max_length ? $value : null;
}
function portalDateValid($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $parts = explode('-', $date);
    return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}
function portalNormalizeName($name) {
    return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)), 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    portalResponse(false, 'Method not allowed.');
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    portalResponse(false, 'Invalid request token. Refresh and try again.');
}
$action = sanitize_input($_GET['action'] ?? '');
$conn = getDBConnection();
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (in_array($action, ['register','login'], true) && !empty($_SESSION['user_id'])) {
    http_response_code(403);
    portalResponse(false, 'Sign out of the staff system before using the resident portal.');
}

if ($action === 'register') {
    if (!check_rate_limit('resident_portal_register_'.$ip)) {
        http_response_code(429);
        portalResponse(false, 'Too many registration attempts. Please wait and try again.');
    }
    $first_name = portalText($_POST['first_name'] ?? '', 100);
    $last_name = portalText($_POST['last_name'] ?? '', 100);
    $birth_date = trim((string)($_POST['birth_date'] ?? ''));
    $username = portalText($_POST['username'] ?? '', 50);
    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if (!$first_name || !$last_name || !portalDateValid($birth_date)
        || !$username || !preg_match('/^[A-Za-z0-9_.]+$/', $username)
        || strlen($password) < 8 || strlen($password) > 100 || $password !== $confirm_password) {
        portalResponse(false, 'Complete all fields with valid values. Passwords must match and be at least 8 characters.');
    }
    $stmt = $conn->prepare("SELECT resident_id, first_name, last_name, birth_date FROM tbl_residents WHERE is_archived=0 AND record_status='Active'");
    if (!$stmt) portalResponse(false, 'Registration is temporarily unavailable.');
    $stmt->execute();
    $resident_rows = $stmt->get_result();
    $stmt->close();
    $matching_residents = [];
    while ($candidate = $resident_rows->fetch_assoc()) {
        $candidate_first = html_entity_decode(aes_decrypt($candidate['first_name']), ENT_QUOTES, 'UTF-8');
        $candidate_last = html_entity_decode(aes_decrypt($candidate['last_name']), ENT_QUOTES, 'UTF-8');
        if (portalNormalizeName($candidate_first) === portalNormalizeName($first_name)
            && portalNormalizeName($candidate_last) === portalNormalizeName($last_name)
            && aes_decrypt($candidate['birth_date']) === $birth_date) {
            $matching_residents[] = $candidate;
        }
    }
    if (count($matching_residents) !== 1) {
        write_audit_log(null, 'RESIDENT_PORTAL_REGISTER_FAILED', 'portal_identity_check', 'Exact active resident matches:'.count($matching_residents));
        portalResponse(false, count($matching_residents) > 1
            ? 'More than one resident record matches those details. Please contact the barangay office to verify your account.'
            : 'We could not verify those details against an active resident record. Check your name and birth date with the barangay office.');
    }
    $resident = $matching_residents[0];

    $exists = $conn->prepare("SELECT portal_account_id FROM tbl_resident_portal_accounts WHERE resident_id=? OR username=? LIMIT 1");
    $exists->bind_param('is', $resident['resident_id'], $username);
    $exists->execute();
    $duplicate = (bool)$exists->get_result()->fetch_assoc();
    $exists->close();
    if ($duplicate) portalResponse(false, 'That resident record may already have an account, or the username is taken.');

    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>10]);
    $create = $conn->prepare("INSERT INTO tbl_resident_portal_accounts (resident_id,username,password_hash) VALUES (?,?,?)");
    if (!$create) portalResponse(false, 'Could not create resident portal account.');
    $create->bind_param('iss', $resident['resident_id'], $username, $password_hash);
    if (!$create->execute()) {
        $duplicate = $create->errno === 1062;
        $create->close();
        portalResponse(false, $duplicate ? 'That resident record already has an account, or the username is taken.' : 'Could not create resident portal account.');
    }
    $account_id = $conn->insert_id;
    $create->close();
    write_audit_log(null, 'RESIDENT_PORTAL_REGISTER', "portal_account:$account_id", "resident_id:{$resident['resident_id']}");
    portalResponse(true, 'Registration complete. You can now sign in.');
}

if ($action === 'login') {
    $username = portalText($_POST['username'] ?? '', 50);
    $password = (string)($_POST['password'] ?? '');
    if (!$username || $password === '' || !check_rate_limit('resident_portal_login_'.$ip)) {
        http_response_code(429);
        portalResponse(false, 'Invalid credentials or too many attempts. Try again later.');
    }
    $stmt = $conn->prepare(
        "SELECT a.portal_account_id,a.resident_id,a.username,a.password_hash,a.failed_attempts,a.locked_until
         FROM tbl_resident_portal_accounts a
         JOIN tbl_residents r ON r.resident_id=a.resident_id
         WHERE a.username=? AND a.is_active=1 AND r.is_archived=0 AND r.record_status='Active'"
    );
    if (!$stmt) portalResponse(false, 'Login is temporarily unavailable.');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account || ($account['locked_until'] && strtotime($account['locked_until']) > time()) || !password_verify($password, $account['password_hash'])) {
        if ($account) {
            $attempts = (int)$account['failed_attempts'] + 1;
            $lock = $attempts >= MAX_LOGIN_ATTEMPTS;
            $update = $conn->prepare($lock
                ? "UPDATE tbl_resident_portal_accounts SET failed_attempts=?,locked_until=DATE_ADD(NOW(), INTERVAL ".LOCKOUT_TIME." SECOND) WHERE portal_account_id=?"
                : "UPDATE tbl_resident_portal_accounts SET failed_attempts=? WHERE portal_account_id=?"
            );
            $update->bind_param('ii', $attempts, $account['portal_account_id']);
            $update->execute();
            $update->close();
        }
        write_audit_log(null, 'RESIDENT_PORTAL_LOGIN_FAILED', $username, 'Invalid credentials or locked account');
        portalResponse(false, 'Invalid credentials or account temporarily locked.');
    }

    $update = $conn->prepare("UPDATE tbl_resident_portal_accounts SET failed_attempts=0,locked_until=NULL,last_login=NOW() WHERE portal_account_id=?");
    $update->bind_param('i', $account['portal_account_id']);
    $update->execute();
    $update->close();

    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['resident_portal_account_id'] = (int)$account['portal_account_id'];
    $_SESSION['resident_portal_resident_id'] = (int)$account['resident_id'];
    $_SESSION['resident_portal_username'] = $account['username'];
    $_SESSION['resident_portal_last_activity'] = time();
    $_SESSION['last_activity'] = time();
    $new_csrf = generate_csrf_token();
    write_audit_log(null, 'RESIDENT_PORTAL_LOGIN_SUCCESS', "portal_account:{$account['portal_account_id']}", "resident_id:{$account['resident_id']}");
    portalResponse(true, 'Signed in.', ['csrf_token'=>$new_csrf]);
}

if ($action === 'create_request') {
    require_once __DIR__ . '/../includes/resident_portal_auth.php';
    $account = resident_portal_current_account(true);
    if (!check_rate_limit('resident_portal_request_'.$account['portal_account_id'])) {
        http_response_code(429);
        portalResponse(false, 'Too many requests were submitted. Please wait and try again.');
    }
    $document_type_id = (int)($_POST['document_type_id'] ?? 0);
    $purpose = portalText($_POST['purpose'] ?? '', 500);
    $pickup_date = trim((string)($_POST['preferred_pickup_date'] ?? ''));
    if (!$document_type_id || !$purpose || $purpose === '') portalResponse(false, 'Select a document and enter its purpose.');
    if ($pickup_date !== '' && (!portalDateValid($pickup_date) || $pickup_date <= date('Y-m-d'))) {
        portalResponse(false, 'Preferred pickup date must be a valid future date.');
    }

    $type_stmt = $conn->prepare("SELECT type_id,type_name FROM tbl_document_types WHERE type_id=? AND is_active=1");
    $type_stmt->bind_param('i', $document_type_id);
    $type_stmt->execute();
    $document_type = $type_stmt->get_result()->fetch_assoc();
    $type_stmt->close();
    if (!$document_type) portalResponse(false, 'Selected document type is unavailable.');

    $request_code = '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $request_code = 'REQ-'.date('Y').'-P'.strtoupper(bin2hex(random_bytes(3)));
        $check = $conn->prepare("SELECT request_id FROM tbl_document_requests WHERE request_code=?");
        $check->bind_param('s', $request_code);
        $check->execute();
        $taken = (bool)$check->get_result()->fetch_assoc();
        $check->close();
        if (!$taken) break;
        $request_code = '';
    }
    if ($request_code === '') portalResponse(false, 'Could not generate a request code. Please try again.');

    $resident_id = (int)$account['resident_id'];
    $portal_account_id = (int)$account['portal_account_id'];
    $pickup_value = $pickup_date !== '' ? $pickup_date : null;
    $stmt = $conn->prepare(
        "INSERT INTO tbl_document_requests
         (request_code,resident_id,document_type_id,purpose,status,requested_at,portal_account_id,preferred_pickup_date)
         VALUES (?,?,?,?,'PENDING',NOW(),?,?)"
    );
    if (!$stmt) portalResponse(false, 'Could not submit document request.');
    $stmt->bind_param('siisis', $request_code, $resident_id, $document_type_id, $purpose, $portal_account_id, $pickup_value);
    if (!$stmt->execute()) {
        $stmt->close();
        portalResponse(false, 'Could not submit document request. Please try again.');
    }
    $request_id = $conn->insert_id;
    $stmt->close();
    write_audit_log(null, 'RESIDENT_PORTAL_CREATE_DOC_REQUEST', "request_id:$request_id", "portal_account:$portal_account_id Code:$request_code Pickup:".($pickup_date ?: 'Immediate'));
    portalResponse(true, 'Document request submitted.', ['request_code'=>$request_code]);
}

http_response_code(400);
portalResponse(false, 'Unknown portal action.');
