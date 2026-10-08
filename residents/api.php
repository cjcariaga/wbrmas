<?php
require_once '../config/security.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !check_session_timeout()) {
    echo json_encode(['success'=>false,'message'=>'Session expired.']); exit;
}
if (($_SESSION['role_name'] ?? '') === 'Barangay Treasurer') {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token.']); exit;
}

$action = $_GET['action'] ?? '';
$conn   = getDBConnection();
$uid    = (int)$_SESSION['user_id'];

// ── Photo upload helper ───────────────────────────────────────────────────────
function handle_photo_upload($file_key, $old_path = null) {
    if (empty($_FILES[$file_key]['name'])) return [null, null]; // no upload

    $file = $_FILES[$file_key];

    // Size check (2 MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        return [null, 'Photo must be under 2MB.'];
    }

    // Validate via magic bytes — never trust mime from $_FILES
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg', 'image/png'];
    if (!in_array($mime, $allowed)) {
        return [null, 'Only JPG and PNG images are allowed.'];
    }

    // Check it is a real image
    $img_info = @getimagesize($file['tmp_name']);
    if ($img_info === false) {
        return [null, 'Uploaded file is not a valid image.'];
    }

    $ext      = ($mime === 'image/png') ? 'png' : 'jpg';
    $filename = 'res_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dir      = __DIR__ . '/../uploads/residents/';

    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        return [null, 'Failed to save photo.'];
    }

    // Delete old photo if exists
    if ($old_path && file_exists(__DIR__ . '/../' . $old_path)) {
        @unlink(__DIR__ . '/../' . $old_path);
    }

    return ['uploads/residents/' . $filename, null];
}

// ── ADD ───────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $first   = sanitize_input($_POST['first_name']     ?? '');
    $middle  = sanitize_input($_POST['middle_name']    ?? '');
    $last    = sanitize_input($_POST['last_name']      ?? '');
    $bdate   = $_POST['birth_date']                    ?? '';
    $sex     = sanitize_input($_POST['sex']            ?? '');
    $civil   = sanitize_input($_POST['civil_status']   ?? '');
    $contact = sanitize_input($_POST['contact_number'] ?? '');
    $email   = sanitize_input($_POST['email']          ?? '');
    $address = sanitize_input($_POST['address']        ?? '');
    $purok   = sanitize_input($_POST['purok']          ?? '');
    $years   = (int)($_POST['years_of_residency']      ?? 0);
    $indigent= (int)($_POST['is_indigent']             ?? 0);
    $is_head = (int)($_POST['is_head_of_family']       ?? 0);
    $hh_id   = (int)($_POST['household_head_id']       ?? 0);

    if (!$first || !$last || !$bdate) {
        echo json_encode(['success'=>false,'message'=>'First name, last name, and birth date are required.']); exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bdate)) {
        echo json_encode(['success'=>false,'message'=>'Invalid date format.']); exit;
    }

    // Photo upload
    [$photo_path, $photo_err] = handle_photo_upload('photo');
    if ($photo_err) { echo json_encode(['success'=>false,'message'=>$photo_err]); exit; }

    // Generate resident code
    $count_r = (int)$conn->query("SELECT COUNT(*) FROM tbl_residents")->fetch_row()[0];
    $code    = 'BR-' . date('Y') . '-' . str_pad($count_r + 1, 5, '0', STR_PAD_LEFT);

    // IDOR: validate household_head_id
    if ($hh_id) {
        $hchk = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE resident_id=? AND is_archived=0 AND is_head_of_family=1");
        $hchk->bind_param("i", $hh_id); $hchk->execute();
        if (!$hchk->get_result()->fetch_assoc()) $hh_id = 0;
        $hchk->close();
    }
    if ($is_head) $hh_id = 0;
    $hh_id_val = $hh_id > 0 ? $hh_id : null;
    $photo_path = $photo_path ?? '';

    $stmt = $conn->prepare(
        "INSERT INTO tbl_residents
         (resident_code,first_name,middle_name,last_name,birth_date,sex,civil_status,
          contact_number,email,address,purok,years_of_residency,is_indigent,photo_path,
          is_head_of_family,household_head_id,record_status,registered_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending Verification',?)"
    );
    if (!$stmt) {
        echo json_encode(['success'=>false,'message'=>'DB prepare error: '.$conn->error]); exit;
    }
    $ef = aes_encrypt($first); $em = aes_encrypt($middle);
    $el = aes_encrypt($last);  $eb = aes_encrypt($bdate);
    $ec = aes_encrypt($contact);
    // 17 params: s×11 + i×2 + s + i×2 + i = sssssssssssiiisiii
    $stmt->bind_param("sssssssssssiiisiii",
        $code,$ef,$em,$el,$eb,$sex,$civil,$ec,$email,$address,$purok,
        $years,$indigent,$photo_path,$is_head,$hh_id_val,$uid
    );

    if ($stmt->execute()) {
        $rid = $conn->insert_id;
        if ($hh_id_val === 0) {
            $clr = $conn->prepare("UPDATE tbl_residents SET household_head_id=NULL WHERE resident_id=?");
            if ($clr) { $clr->bind_param("i", $rid); $clr->execute(); $clr->close(); }
        }
        write_audit_log($uid, 'CREATE_RESIDENT', "resident_id:$rid", "Code:$code Name:$last,$first");
        echo json_encode(['success'=>true,'message'=>'Resident added.','id'=>$rid,'code'=>$code]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Database error: '.$stmt->error]);
    }
    $stmt->close();
}

// ── UPDATE ────────────────────────────────────────────────────────────────────
elseif ($action === 'update') {
    // Only admins can update residents
    if (!isset($_SESSION['role_name']) || $_SESSION['role_name'] !== 'System Administrator') {
        echo json_encode(['success'=>false,'message'=>'Access denied. Only administrators can edit residents.']); exit;
    }
    $rid     = (int)($_POST['resident_id']             ?? 0);
    $first   = sanitize_input($_POST['first_name']     ?? '');
    $middle  = sanitize_input($_POST['middle_name']    ?? '');
    $last    = sanitize_input($_POST['last_name']      ?? '');
    $bdate   = $_POST['birth_date']                    ?? '';
    $sex     = sanitize_input($_POST['sex']            ?? '');
    $civil   = sanitize_input($_POST['civil_status']   ?? '');
    $contact = sanitize_input($_POST['contact_number'] ?? '');
    $email   = sanitize_input($_POST['email']          ?? '');
    $address = sanitize_input($_POST['address']        ?? '');
    $purok   = sanitize_input($_POST['purok']          ?? '');
    $years   = (int)($_POST['years_of_residency']      ?? 0);
    $indigent= (int)($_POST['is_indigent']             ?? 0);
    $is_head = (int)($_POST['is_head_of_family']       ?? 0);
    $hh_id   = (int)($_POST['household_head_id']       ?? 0);

    if (!$rid || !$first || !$last) {
        echo json_encode(['success'=>false,'message'=>'Required fields missing.']); exit;
    }
    if (!preg_match("/^[\p{L} .'-]+$/u", $first) || ($middle !== '' && !preg_match("/^[\p{L} .'-]+$/u", $middle)) || !preg_match("/^[\p{L} .'-]+$/u", $last)) {
        echo json_encode(['success'=>false,'message'=>'Names may contain letters, spaces, apostrophes, periods, and hyphens only.']); exit;
    }
    if (!preg_match('/^\d+$/', (string)($_POST['years_of_residency'] ?? '0')) || $years < 0 || $years > 200) {
        echo json_encode(['success'=>false,'message'=>'Years of residency must be a number from 0 to 200.']); exit;
    }
    if ($contact !== '' && !preg_match('/^[0-9+() .-]+$/', $contact)) {
        echo json_encode(['success'=>false,'message'=>'Contact number may contain numbers and phone characters only.']); exit;
    }

    // IDOR check
    $chk = $conn->prepare("SELECT resident_id, photo_path FROM tbl_residents WHERE resident_id=? AND is_archived=0");
    $chk->bind_param("i", $rid); $chk->execute();
    $existing = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$existing) {
        echo json_encode(['success'=>false,'message'=>'Record not found.']); exit;
    }

    // Photo upload (pass old path so it gets deleted if replaced)
    [$photo_path, $photo_err] = handle_photo_upload('photo', $existing['photo_path']);
    if ($photo_err) { echo json_encode(['success'=>false,'message'=>$photo_err]); exit; }
    // If no new photo uploaded, keep existing path
    if ($photo_path === null) $photo_path = $existing['photo_path'];

    // IDOR: validate household_head_id
    if ($hh_id && $hh_id !== $rid) {
        $hchk = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE resident_id=? AND is_archived=0 AND is_head_of_family=1");
        $hchk->bind_param("i",$hh_id); $hchk->execute();
        if (!$hchk->get_result()->fetch_assoc()) $hh_id = 0;
        $hchk->close();
    } else { $hh_id = 0; }
    if ($is_head) $hh_id = 0;
    $hh_id_val = $hh_id ?: null;

    $stmt = $conn->prepare(
        "UPDATE tbl_residents
         SET first_name=?,middle_name=?,last_name=?,birth_date=?,sex=?,civil_status=?,
             contact_number=?,email=?,address=?,purok=?,years_of_residency=?,is_indigent=?,
             photo_path=?,is_head_of_family=?,household_head_id=?
         WHERE resident_id=?"
    );
    $ef=aes_encrypt($first); $em=aes_encrypt($middle); $el=aes_encrypt($last);
    $eb=aes_encrypt($bdate); $ec=aes_encrypt($contact);
    // 16 params: s×10 + i×2 + s + i×2 + i = ssssssssssiisiii
    $stmt->bind_param("ssssssssssiisiii",
        $ef,$em,$el,$eb,$sex,$civil,$ec,$email,$address,$purok,$years,$indigent,
        $photo_path,$is_head,$hh_id_val,$rid
    );
    if ($stmt->execute()) {
        write_audit_log($uid, 'UPDATE_RESIDENT', "resident_id:$rid", "Name:$last,$first");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Update failed: '.$stmt->error]);
    }
    $stmt->close();
}

// ── ARCHIVE ───────────────────────────────────────────────────────────────────
elseif ($action === 'archive') {
    if (!isset($_SESSION['role_name']) || $_SESSION['role_name'] !== 'System Administrator') {
        echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
    }
    $rid    = (int)($_POST['resident_id']   ?? 0);   // ← bug fix: was missing
    $reason = sanitize_input($_POST['archive_reason'] ?? '');

    if (!$rid) {
        echo json_encode(['success'=>false,'message'=>'Invalid resident.']); exit;
    }
    if (!$reason) {
        echo json_encode(['success'=>false,'message'=>'Archive reason is required.']); exit;
    }

    $allowed_reasons = ['Transferred','Deceased','Duplicate Record','Other'];
    $reason_type = sanitize_input($_POST['reason_type'] ?? '');
    if (!in_array($reason_type, $allowed_reasons)) {
        echo json_encode(['success'=>false,'message'=>'Invalid archive reason selected.']); exit;
    }
    $final_reason = ($reason_type === 'Other') ? 'Other: ' . $reason : $reason_type;

    // IDOR check
    $chk = $conn->prepare(
        "SELECT resident_id, first_name, last_name FROM tbl_residents WHERE resident_id=? AND is_archived=0"
    );
    $chk->bind_param("i", $rid); $chk->execute();
    $res = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$res) { echo json_encode(['success'=>false,'message'=>'Resident not found.']); exit; }

    $fname = aes_decrypt($res['first_name']);
    $lname = aes_decrypt($res['last_name']);

    // Also release any lock on this record
    $conn->query("DELETE FROM tbl_record_locks WHERE resident_id=$rid");

    $stmt = $conn->prepare(
        "UPDATE tbl_residents
         SET is_archived=1, record_status='Archived', archive_reason=?, archived_at=NOW(), archived_by=?
         WHERE resident_id=?"
    );
    $stmt->bind_param("sii", $final_reason, $uid, $rid);
    if ($stmt->execute()) {
        write_audit_log($uid, 'ARCHIVE_RESIDENT', "resident_id:$rid",
            "Name:$lname,$fname | Reason:$final_reason");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Archive failed.']);
    }
    $stmt->close();
}

// ── CHANGE RECORD STATUS ──────────────────────────────────────────────────────
elseif ($action === 'change_status') {
    if (!isset($_SESSION['role_name']) || $_SESSION['role_name'] !== 'System Administrator') {
        echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
    }
    $rid    = (int)($_POST['resident_id'] ?? 0);
    $status = sanitize_input($_POST['record_status'] ?? '');

    $allowed_statuses = ['Active','Pending Verification'];
    if (!$rid || !in_array($status, $allowed_statuses)) {
        echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit;
    }

    // IDOR check — only non-archived residents
    $chk = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE resident_id=? AND is_archived=0");
    $chk->bind_param("i",$rid); $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        echo json_encode(['success'=>false,'message'=>'Resident not found.']); exit;
    }
    $chk->close();

    $stmt = $conn->prepare("UPDATE tbl_residents SET record_status=? WHERE resident_id=?");
    $stmt->bind_param("si", $status, $rid);
    if ($stmt->execute()) {
        write_audit_log($uid, 'CHANGE_RECORD_STATUS', "resident_id:$rid", "Status set to: $status");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Update failed.']);
    }
    $stmt->close();
}

// ── ACCEPT ALL PENDING RESIDENTS ────────────────────────────────────────────
elseif ($action === 'accept_all_pending') {
    if (!isset($_SESSION['role_name']) || $_SESSION['role_name'] !== 'System Administrator') {
        echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
    }

    $conn->begin_transaction();
    $stmt = $conn->prepare(
        "UPDATE tbl_residents
         SET record_status='Active'
         WHERE is_archived=0 AND record_status='Pending Verification'"
    );
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>'Failed to accept pending records.']); exit;
    }
    $updated = $stmt->affected_rows;
    $stmt->close();
    write_audit_log($uid, 'ACCEPT_ALL_PENDING_RESIDENTS', 'bulk_resident_status', "Records accepted:$updated");
    $conn->commit();
    echo json_encode(['success'=>true,'updated'=>$updated]);
}

// ── LOCK ─────────────────────────────────────────────────────────────────────
elseif ($action === 'lock') {
    $rid = (int)($_POST['resident_id'] ?? 0);
    if (!$rid) { echo json_encode(['success'=>false,'message'=>'Invalid resident.']); exit; }

    // IDOR check
    $chk = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE resident_id=? AND is_archived=0");
    $chk->bind_param("i",$rid); $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        echo json_encode(['success'=>false,'message'=>'Resident not found.']); exit;
    }
    $chk->close();

    // Clean expired locks first
    $conn->query("DELETE FROM tbl_record_locks WHERE expires_at < NOW()");

    // Check if already locked by someone else
    $lk = $conn->query("SELECT l.locked_by, u.full_name, l.expires_at
                         FROM tbl_record_locks l
                         JOIN tbl_users u ON l.locked_by=u.user_id
                         WHERE l.resident_id=$rid LIMIT 1")->fetch_assoc();
    if ($lk && (int)$lk['locked_by'] !== $uid) {
        echo json_encode([
            'success'   => false,
            'locked'    => true,
            'locked_by' => $lk['full_name'],
            'expires_at'=> $lk['expires_at'],
            'message'   => 'This record is currently being edited by '.$lk['full_name'].'.'
        ]); exit;
    }

    // Upsert lock (15 min expiry)
    $stmt = $conn->prepare(
        "INSERT INTO tbl_record_locks (resident_id, locked_by, locked_at, expires_at)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE))
         ON DUPLICATE KEY UPDATE locked_by=VALUES(locked_by), locked_at=NOW(),
                                  expires_at=DATE_ADD(NOW(), INTERVAL 15 MINUTE)"
    );
    $stmt->bind_param("ii", $rid, $uid); $stmt->execute(); $stmt->close();
    echo json_encode(['success'=>true,'locked'=>false]);
}

// ── UNLOCK ────────────────────────────────────────────────────────────────────
elseif ($action === 'unlock') {
    $rid = (int)($_POST['resident_id'] ?? $_GET['resident_id'] ?? 0);
    if ($rid) {
        // Only the lock owner (or admin) can unlock
        $conn->prepare(
            $_SESSION['role_name']==='System Administrator'
                ? "DELETE FROM tbl_record_locks WHERE resident_id=?"
                : "DELETE FROM tbl_record_locks WHERE resident_id=? AND locked_by=?"
        );
        // simplified: use direct query with bound params
        if ($_SESSION['role_name'] === 'System Administrator') {
            $s = $conn->prepare("DELETE FROM tbl_record_locks WHERE resident_id=?");
            $s->bind_param("i",$rid);
        } else {
            $s = $conn->prepare("DELETE FROM tbl_record_locks WHERE resident_id=? AND locked_by=?");
            $s->bind_param("ii",$rid,$uid);
        }
        $s->execute(); $s->close();
    }
    echo json_encode(['success'=>true]);
}

// ── LOCK HEARTBEAT (renew expiry) ─────────────────────────────────────────────
elseif ($action === 'heartbeat') {
    $rid = (int)($_POST['resident_id'] ?? 0);
    if ($rid) {
        $conn->query(
            "UPDATE tbl_record_locks SET expires_at=DATE_ADD(NOW(), INTERVAL 15 MINUTE)
             WHERE resident_id=$rid AND locked_by=$uid"
        );
    }
    echo json_encode(['success'=>true]);
}

// ── GET ───────────────────────────────────────────────────────────────────────
elseif ($action === 'get') {
    $rid = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id=? AND is_archived=0");
    $stmt->bind_param("i", $rid); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$r) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    $r['first_name']     = aes_decrypt($r['first_name']);
    $r['middle_name']    = aes_decrypt($r['middle_name']);
    $r['last_name']      = aes_decrypt($r['last_name']);
    $r['birth_date']     = aes_decrypt($r['birth_date']);
    $r['contact_number'] = aes_decrypt($r['contact_number']);
    // photo_path is plain text — safe to return as-is
    echo json_encode(['success'=>true,'data'=>$r]);
}

// ── GET HOUSEHOLD HEADS ───────────────────────────────────────────────────────
elseif ($action === 'get_heads') {
    // Returns all active heads of family for household dropdown
    $rows = $conn->query(
        "SELECT resident_id, resident_code, first_name, last_name, purok
         FROM tbl_residents
         WHERE is_archived=0 AND is_head_of_family=1
         ORDER BY last_name"
    );
    $heads = [];
    while ($h = $rows->fetch_assoc()) {
        $heads[] = [
            'id'    => $h['resident_id'],
            'code'  => $h['resident_code'],
            'name'  => aes_decrypt($h['last_name']).', '.aes_decrypt($h['first_name']),
            'purok' => $h['purok'],
        ];
    }
    echo json_encode(['success'=>true,'heads'=>$heads]);
}

else {
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
}
?>
