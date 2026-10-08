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
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token.']); exit;
}

$action = $_GET['action'] ?? '';
$conn   = getDBConnection();
$uid    = (int)$_SESSION['user_id'];

if ($action === 'release_document') {
    if (!api_can('approve_document')) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
    }
    $request_id = (int)($_POST['request_id'] ?? 0);
    $received_by = trim(strip_tags((string)($_POST['received_by'] ?? '')));
    $released_at_input = trim((string)($_POST['released_at'] ?? ''));
    if (!$request_id || $received_by === '' || mb_strlen($received_by, 'UTF-8') > 150) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Request, receiving person, and valid release time are required.']); exit;
    }
    $released_at_date = DateTime::createFromFormat('!Y-m-d\\TH:i', $released_at_input);
    $date_errors = DateTime::getLastErrors();
    if (!$released_at_date || ($date_errors && ($date_errors['warning_count'] || $date_errors['error_count'])) || $released_at_date->format('Y-m-d\\TH:i') !== $released_at_input) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Enter a valid release date and time.']); exit;
    }
    $released_at = $released_at_date->format('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "SELECT request_id, request_code, status, issued_by_user_id, drawer_location, preferred_pickup_date, processed_at
         FROM tbl_document_requests WHERE request_id=?"
    );
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$request) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Document request not found.']); exit;
    }
    $is_admin_user = ($_SESSION['role_name'] ?? '') === 'System Administrator';
    if (!$is_admin_user && $request['issued_by_user_id'] !== null && (int)$request['issued_by_user_id'] !== $uid) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied for this document request.']); exit;
    }
    if ($request['status'] !== 'STORED') {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'This document is no longer awaiting pickup.']); exit;
    }
    $release_timestamp = $released_at_date->getTimestamp();
    if ($release_timestamp > time()) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Release date and time cannot be in the future.']); exit;
    }
    if (!empty($request['processed_at']) && substr($released_at, 0, 16) < date('Y-m-d H:i', strtotime($request['processed_at']))) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Release time cannot be earlier than when the document was stored.']); exit;
    }
    if (!empty($request['preferred_pickup_date']) && date('Y-m-d', $release_timestamp) < $request['preferred_pickup_date']) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'This document cannot be released before its scheduled pickup date.']); exit;
    }
    $entry_check = $conn->prepare(
        "SELECT e.entry_id, d.drawer_name FROM tbl_storage_entries e
         JOIN tbl_storage_drawers d ON d.drawer_id=e.drawer_id
         WHERE e.request_id=? LIMIT 1"
    );
    $entry_check->bind_param('i', $request_id);
    $entry_check->execute();
    $drawer_entry = $entry_check->get_result()->fetch_assoc();
    $entry_check->close();
    if (!$drawer_entry) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'The linked drawer index entry is missing. Resolve the drawer record before releasing this document.']); exit;
    }

    $conn->begin_transaction();
    $update = $conn->prepare(
        "UPDATE tbl_document_requests
         SET status='RELEASED', received_by=?, received_at=?
         WHERE request_id=? AND status='STORED'"
    );
    if (!$update) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Could not prepare the document release.']); exit;
    }
    $update->bind_param('ssi', $received_by, $released_at, $request_id);
    $updated = $update->execute() && $update->affected_rows === 1;
    $update->close();
    if (!$updated) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'The document was already released or changed. Refresh and try again.']); exit;
    }
    $entry_update = $conn->prepare(
        "UPDATE tbl_storage_entries
         SET entry_notes=CONCAT(COALESCE(entry_notes,''), CASE WHEN entry_notes IS NULL OR entry_notes='' THEN '' ELSE ' | ' END, 'Claimed by: ', ?, '; Released at: ', ?), updated_by=?
         WHERE entry_id=?"
    );
    if (!$entry_update) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Could not update the Drawer Index history.']); exit;
    }
    $drawer_entry_id = (int)$drawer_entry['entry_id'];
    $entry_update->bind_param('ssii', $received_by, $released_at, $uid, $drawer_entry_id);
    $entry_updated = $entry_update->execute() && $entry_update->affected_rows === 1;
    $entry_update->close();
    if (!$entry_updated) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Could not update the Drawer Index history.']); exit;
    }
    $conn->commit();
    write_audit_log($uid, 'RELEASE_STORED_DOCUMENT', "request:$request_id", "Code:{$request['request_code']} Drawer:{$drawer_entry['drawer_name']} ReceivedBy:$received_by ReleasedAt:$released_at");
    echo json_encode(['success'=>true,'message'=>'Document released.']); exit;
}

// Permission helper for APIs
function api_can(string $perm): bool {
    if (($_SESSION['role_name'] ?? '') === 'System Administrator') return true;
    // Reload permissions if not in session
    if (!isset($_SESSION['permissions'])) {
        $c = getDBConnection();
        $uid2 = (int)$_SESSION['user_id'];
        $res = $c->query("SELECT perm_key FROM tbl_user_permissions WHERE user_id=$uid2 AND granted=1");
        $_SESSION['permissions'] = [];
        if ($res) while ($r = $res->fetch_assoc()) $_SESSION['permissions'][] = $r['perm_key'];
    }
    return in_array($perm, $_SESSION['permissions'] ?? []);
}

// ─── CREATE REQUEST ───────────────────────────────────────────────────────────
if ($action === 'update_drawer') {
    if (!api_can('approve_document')) {
        echo json_encode(['success'=>false,'message'=>'Access denied. You cannot update document filing locations.']); exit;
    }
    $request_id = (int)($_POST['request_id'] ?? 0);
    $drawer_location = sanitize_input($_POST['drawer_location'] ?? '');
    if (!$request_id || mb_strlen($drawer_location) > 100) {
        echo json_encode(['success'=>false,'message'=>'Invalid request or drawer location.']); exit;
    }
    $check = $conn->prepare("SELECT request_code, issued_by_user_id, status FROM tbl_document_requests WHERE request_id=?");
    $check->bind_param("i", $request_id);
    $check->execute();
    $request = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$request) {
        echo json_encode(['success'=>false,'message'=>'Document request not found.']); exit;
    }
    if (in_array($request['status'], ['STORED','RELEASED'], true)) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'The drawer location is managed by the document pickup workflow.']); exit;
    }
    $is_admin_user = ($_SESSION['role_name'] ?? '') === 'System Administrator';
    if (!$is_admin_user && $request['issued_by_user_id'] !== null && (int)$request['issued_by_user_id'] !== $uid) {
        echo json_encode(['success'=>false,'message'=>'Access denied for this document request.']); exit;
    }
    $update = $conn->prepare("UPDATE tbl_document_requests SET drawer_location=? WHERE request_id=?");
    if (!$update) {
        echo json_encode(['success'=>false,'message'=>'Could not update drawer location.']); exit;
    }
    $update->bind_param("si", $drawer_location, $request_id);
    if (!$update->execute()) {
        $update->close();
        echo json_encode(['success'=>false,'message'=>'Could not update drawer location.']); exit;
    }
    $update->close();
    write_audit_log($uid, 'UPDATE_DOCUMENT_DRAWER', "req:$request_id", "Code:{$request['request_code']} Drawer:".($drawer_location ?: 'Unassigned'));
    echo json_encode(['success'=>true]);
}
elseif ($action === 'create') {
    if (!api_can('create_document')) {
        echo json_encode(['success'=>false,'message'=>'Access denied. You cannot create document requests.']); exit;
    }
    $rid        = (int)($_POST['resident_id'] ?? 0);
    $dtid       = (int)($_POST['document_type_id'] ?? 0);
    $purpose    = trim(strip_tags($_POST['purpose'] ?? ''));
    // Whitelist allowed purpose values
    $allowed_purposes = [
        'Employment',
        'Government Assistance Application',
        'School / Academic Requirements',
        'Bank / Loan Application',
        'Travel Requirements',
    ];
    if (!in_array($purpose, $allowed_purposes)) {
        // Fallback: sanitize whatever was sent
        $purpose = sanitize_input($purpose);
    }
    $is_student = (int)($_POST['is_student'] ?? 0);
    $is_loan    = (int)($_POST['is_loan']    ?? 0);

    if (!$rid || !$dtid || !$purpose) {
        echo json_encode(['success'=>false,'message'=>'All fields are required.']); exit;
    }

    // IDOR: verify resident exists
    $chk = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE resident_id=? AND is_archived=0");
    $chk->bind_param("i", $rid); $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        echo json_encode(['success'=>false,'message'=>'Resident not found.']); exit;
    }
    $chk->close();

    // Get document type details
    $dt = $conn->prepare("SELECT type_id, type_name, fee FROM tbl_document_types WHERE type_id=? AND is_active=1");
    $dt->bind_param("i", $dtid); $dt->execute();
    $doc_type = $dt->get_result()->fetch_assoc();
    $dt->close();
    if (!$doc_type) {
        echo json_encode(['success'=>false,'message'=>'Invalid document type.']); exit;
    }

    // Fee logic:
    // Student + Barangay Clearance = FREE  (detected via flag OR purpose text)
    // Loan    + Barangay Clearance = ₱100  (detected via flag OR purpose text)
    // Indigency (any purpose)     = FREE
    // Default = base fee from document type
    $base_fee     = (float)$doc_type['fee'];
    $is_clearance = stripos($doc_type['type_name'], 'clearance') !== false;
    $is_indigency = stripos($doc_type['type_name'], 'indigency') !== false
                 || stripos($doc_type['type_name'], 'indigent')  !== false;

    // Also detect from purpose text in case flags weren't sent (e.g. older clients)
    if (!$is_student) $is_student = (int)(stripos($purpose,'school')!==false || stripos($purpose,'academic')!==false);
    if (!$is_loan)    $is_loan    = (int)(stripos($purpose,'loan')  !==false);

    if ($is_indigency) {
        $final_fee = 0.00;
    } elseif ($is_student && $is_clearance) {
        $final_fee = 0.00;
        $purpose   = $purpose . ' (Student - Fee Waived)';
    } elseif ($is_loan && $is_clearance) {
        $final_fee = 100.00;
        $purpose   = $purpose . ' (Loan Application - ₱100.00)';
    } else {
        $final_fee = $base_fee;
    }

    // Generate request code
    $count = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests")->fetch_row()[0];
    $code  = 'REQ-' . date('Y') . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);

    $stmt = $conn->prepare(
        "INSERT INTO tbl_document_requests (request_code, resident_id, document_type_id, purpose, status, issued_by_user_id)
         VALUES (?, ?, ?, ?, 'PENDING', ?)"
    );
    $stmt->bind_param("siisi", $code, $rid, $dtid, $purpose, $uid);

    if ($stmt->execute()) {
        $req_id = $conn->insert_id;
        write_audit_log($uid, 'CREATE_DOC_REQUEST', "request_id:$req_id",
            "Code:$code Type:{$doc_type['type_name']} Fee:$final_fee Student:$is_student Loan:$is_loan Indigency:$is_indigency");
        echo json_encode(['success'=>true, 'code'=>$code, 'fee'=>$final_fee,
            'is_student'=>$is_student, 'is_loan'=>$is_loan, 'is_free'=>($final_fee == 0)]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Database error: '.$stmt->error]);
    }
    $stmt->close();
}

// ─── UPDATE STATUS ────────────────────────────────────────────────────────────
elseif ($action === 'update_status') {
    if (!api_can('approve_document')) {
        echo json_encode(['success'=>false,'message'=>'Access denied. You cannot approve/reject documents.']); exit;
    }
    $req_id = (int)($_POST['request_id'] ?? 0);
    $status = sanitize_input($_POST['status'] ?? '');
    $allowed = ['APPROVED','REJECTED'];

    if (!$req_id || !in_array($status, $allowed)) {
        echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit;
    }

    // IDOR check — fetch request + resident + doc type in one go
    $chk = $conn->prepare(
        "SELECT dr.request_id, dr.resident_id, dr.status, dr.issued_by_user_id, dt.type_name
         FROM tbl_document_requests dr
         JOIN tbl_document_types dt ON dr.document_type_id = dt.type_id
         WHERE dr.request_id=?"
    );
    $chk->bind_param("i",$req_id); $chk->execute();
    $req_row = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$req_row) {
        echo json_encode(['success'=>false,'message'=>'Request not found.']); exit;
    }
    if (($_SESSION['role_name'] ?? '') !== 'System Administrator' && $req_row['issued_by_user_id'] !== null && (int)$req_row['issued_by_user_id'] !== $uid) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied for this document request.']); exit;
    }
    if ($req_row['status'] !== 'PENDING') {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Only pending requests can be approved or rejected.']); exit;
    }

    // ── Blotter check for Barangay Clearance approvals ────────────────────────
    $blotter_warning = null;
    $is_clearance    = stripos($req_row['type_name'], 'clearance') !== false;

    if ($is_clearance && in_array($status, ['APPROVED','PRINTED'])) {
        $res_id = (int)$req_row['resident_id'];

        // Count open/under-investigation blotter cases for this resident
        $bq = $conn->prepare(
            "SELECT COUNT(*) FROM tbl_blotter
             WHERE complainant_id = ?
               AND resolution_status IN ('Active','Under Mediation')"
        );
        $bq->bind_param("i", $res_id); $bq->execute();
        $open_count = (int)$bq->get_result()->fetch_row()[0]; $bq->close();

        if ($open_count > 0) {
            $blotter_warning = $open_count;

            // Log that staff was shown the warning (for accountability)
            write_audit_log($uid, 'CLEARANCE_BLOTTER_WARNING',
                "request_id:$req_id",
                "Resident has $open_count open blotter case(s). Staff notified before status change to $status."
            );

            // If caller sent acknowledge=1, proceed; otherwise return warning for UI to show
            $acknowledged = (int)($_POST['blotter_acknowledged'] ?? 0);
            if (!$acknowledged) {
                echo json_encode([
                    'success'          => false,
                    'blotter_warning'  => true,
                    'open_cases'       => $open_count,
                    'message'          => "⚠️ This resident has $open_count open blotter case(s). Please review before approving.",
                    'request_id'       => $req_id,
                    'status'           => $status,
                ]); exit;
            }
            // Staff acknowledged — log and proceed
            write_audit_log($uid, 'CLEARANCE_BLOTTER_ACKNOWLEDGED',
                "request_id:$req_id",
                "Staff acknowledged $open_count open blotter case(s) and proceeded with status change to $status."
            );
        }
    }

    $stmt = $conn->prepare(
        "UPDATE tbl_document_requests SET status=?, processed_at=NOW(), issued_by_user_id=? WHERE request_id=? AND status='PENDING'"
    );
    $stmt->bind_param("sii", $status, $uid, $req_id);
    if ($stmt->execute() && $stmt->affected_rows === 1) {
        $details = "Status:$status";
        if ($blotter_warning) $details .= " | BlotterWarningAcknowledged:yes";
        write_audit_log($uid, 'UPDATE_DOC_STATUS', "request_id:$req_id", $details);
        echo json_encode(['success'=>true]);
    } else {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Update failed.']);
    }
    $stmt->close();
}

// ─── GENERATE / PRINT DOCUMENT ────────────────────────────────────────────────
elseif ($action === 'generate' || $action === 'reprint') {
    if (!api_can('approve_document')) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
    }
    $req_id = (int)($_POST['request_id'] ?? 0);
    if (!$req_id) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }

    // Get full request details with IDOR check
    $stmt = $conn->prepare(
        "SELECT dr.*, dt.type_name, dt.fee,
                r.first_name, r.last_name, r.middle_name, r.address, r.civil_status, r.birth_date,
                r.is_indigent, r.years_of_residency
         FROM tbl_document_requests dr
         JOIN tbl_document_types dt ON dr.document_type_id = dt.type_id
         JOIN tbl_residents r ON dr.resident_id = r.resident_id
         WHERE dr.request_id = ?"
    );
    $stmt->bind_param("i", $req_id); $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) { echo json_encode(['success'=>false,'message'=>'Request not found.']); exit; }
    if (($_SESSION['role_name'] ?? '') !== 'System Administrator' && $req['issued_by_user_id'] !== null && (int)$req['issued_by_user_id'] !== $uid) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied for this document request.']); exit;
    }
    if ($action === 'generate' || !in_array($req['status'], ['PRINTED','STORED','RELEASED'], true)) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Use the Document Destination workflow to issue this request.']); exit;
    }

    $fname  = aes_decrypt($req['first_name']);
    $mname  = aes_decrypt($req['middle_name']);
    $lname  = aes_decrypt($req['last_name']);
    $bdate  = aes_decrypt($req['birth_date']);
    $addr   = $req['address'] ?: 'Barangay San Isidro, City of Ilagan, Isabela';
    $fullname = strtoupper(trim("$fname " . ($mname ? "$mname " : "") . "$lname"));

    // Determine fee from purpose tag
    $is_student   = stripos($req['purpose'], 'Student') !== false;
    $is_loan      = stripos($req['purpose'], 'Loan Application') !== false;
    $is_clearance = stripos($req['type_name'], 'clearance') !== false;

    if ($is_student && $is_clearance) {
        $fee = 0.00;
    } elseif ($is_loan && $is_clearance) {
        $fee = 100.00;
    } else {
        $fee = (float)$req['fee'];
    }
    $fee_display = $fee == 0 ? 'FREE' : '₱'.number_format($fee, 2);

    // Control number
    $ctrl = 'CTRL-' . date('Y') . '-' . str_pad($req_id, 6, '0', STR_PAD_LEFT);
    $date_issued = date('F d, Y');
    $valid_until = date('F d, Y', strtotime('+6 months'));

    // Document hash for integrity
    $hash_data = "$req_id|{$req['type_name']}|$fullname|$date_issued";
    $doc_hash  = hash_hmac('sha256', $hash_data, HMAC_SECRET_KEY);
    $short_hash = strtoupper(substr($doc_hash, 0, 16));

    // Build HTML document
    $official_name = $req['type_name'];
    $purpose_clean = str_replace(' (Student - Fee Waived)', '', $req['purpose']);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>{$official_name} - {$fullname}</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Times+New+Roman:ital,wght@0,400;0,700;1,400&display=swap');
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Times New Roman', serif; font-size: 13pt; color: #000; background: #fff; }
  .page { width: 8.5in; min-height: 11in; margin: 0 auto; padding: 0.8in 1in; position: relative; }
  .header { text-align: center; border-bottom: 3px double #000; padding-bottom: 12px; margin-bottom: 20px; }
  .header .republic { font-size: 10pt; letter-spacing: 1px; }
  .header .brgy { font-size: 16pt; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; margin: 6px 0; }
  .header .address { font-size: 10pt; color: #333; }
  .doc-title { text-align: center; margin: 24px 0 20px; }
  .doc-title h2 { font-size: 18pt; font-weight: bold; text-transform: uppercase; letter-spacing: 3px; text-decoration: underline; }
  .ctrl-box { display: flex; justify-content: space-between; font-size: 10pt; margin-bottom: 20px; color: #555; }
  .body-text { line-height: 2; text-align: justify; font-size: 13pt; margin: 16px 0; }
  .body-text .name { text-transform: uppercase; font-weight: bold; text-decoration: underline; }
  .fee-box { margin: 16px 0; padding: 10px 16px; border: 1.5px solid #000; display: inline-block; }
  .purpose-line { margin: 12px 0; }
  .validity { font-size: 10pt; color: #555; margin-top: 8px; font-style: italic; }
  .sig-section { margin-top: 48px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
  .sig-box { text-align: center; }
  .sig-line { border-top: 1.5px solid #000; margin-top: 48px; padding-top: 6px; }
  .sig-name { font-weight: bold; text-transform: uppercase; }
  .sig-title { font-size: 10pt; font-style: italic; }
  .footer { position: absolute; bottom: 0.5in; left: 1in; right: 1in; border-top: 1.5px solid #000; padding-top: 8px; display: flex; justify-content: space-between; font-size: 9pt; color: #666; }
  .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-45deg); font-size: 72pt; color: rgba(0,0,0,0.04); font-weight: bold; white-space: nowrap; pointer-events: none; }
  .student-badge { background: #eafaf1; border: 1.5px solid #27ae60; color: #1e8449; padding: 6px 14px; border-radius: 6px; font-size: 11pt; display: inline-block; margin: 8px 0; }
  @media print { body { -webkit-print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="page">
  <div class="watermark">OFFICIAL</div>

  <div class="header">
    <div class="republic">Republic of the Philippines</div>
    <div class="republic">Province of Isabela • City of Ilagan</div>
    <div class="brgy">Barangay San Isidro</div>
    <div class="address">Barangay San Isidro, City of Ilagan, Isabela</div>
  </div>

  <div class="ctrl-box">
    <span>Control No.: <strong>{$ctrl}</strong></span>
    <span>Date: <strong>{$date_issued}</strong></span>
  </div>

  <div class="doc-title">
    <h2>{$official_name}</h2>
  </div>

  <div class="body-text">
    <p>TO WHOM IT MAY CONCERN:</p><br>
    <p>This is to certify that <span class="name">{$fullname}</span>,
HTML;

    if ($bdate) {
        $age = date_diff(date_create($bdate), date_create('today'))->y;
        $html .= " {$age} years of age,";
    }

    $html .= " of legal age,";

    if ($req['civil_status']) {
        $html .= " {$req['civil_status']},";
    }

    $html .= " is a <em>bonafide resident</em> of <strong>{$addr}</strong>";

    if ($req['years_of_residency'] > 0) {
        $html .= ", for {$req['years_of_residency']} year(s)";
    }

    $html .= ".</p><br>";

    if ($req['type_name'] === 'Barangay Clearance') {
        $html .= "<p>Further, this certifies that as of this date, <span class='name'>{$fullname}</span>
        has <strong>NO DEROGATORY RECORD</strong> on file in this Barangay and is known to be a person
        of good moral character and law-abiding citizen in the community.</p><br>";
    } elseif (stripos($req['type_name'], 'indigency') !== false) {
        $html .= "<p>Further, this certifies that the above-named person belongs to an
        <strong>INDIGENT FAMILY</strong> in this barangay, and is financially incapable of
        shouldering expenses for their needs.</p><br>";
    }

    $html .= <<<HTML2
    <div class="purpose-line">
      This certification is issued upon the request of the above-named person for the purpose of
      <strong>{$purpose_clean}</strong> and for whatever legal purpose it may serve.
    </div>
  </div>

  <div class="fee-box">
    <strong>Documentary Fee:</strong> {$fee_display}
HTML;

if ($is_student && $is_clearance) {
    $html .= '<br><span class="student-badge">🎓 Student — Fee Waived</span>';
} elseif ($is_loan && $is_clearance) {
    $html .= '<br><span class="student-badge" style="background:#fef3e2;border-color:#d35400;color:#d35400;">🏦 Loan Application Rate</span>';
}

$html .= <<<HTML2
  </div>

  <div class="validity">Valid until: {$valid_until}</div>

  <div class="sig-section">
    <div class="sig-box">
      <div class="sig-line">
        <div class="sig-name">Barangay Secretary</div>
        <div class="sig-title">Issued by</div>
      </div>
    </div>
    <div class="sig-box">
      <div class="sig-line">
        <div class="sig-name">Punong Barangay</div>
        <div class="sig-title">Barangay San Isidro</div>
      </div>
    </div>
  </div>

  <div class="footer">
    <span>Doc Hash: {$short_hash}</span>
    <span>WBRMAS — Barangay San Isidro Digital Records System</span>
    <span>Printed: {$date_issued}</span>
  </div>
</div>
<script>window.onload=function(){window.print();}</script>
</body>
</html>
HTML2;

    // Save to DB and mark as PRINTED
    $stmt2 = $conn->prepare(
        "INSERT INTO tbl_generated_documents (request_id, document_hash, control_number, issued_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE document_hash=VALUES(document_hash), issued_at=NOW()"
    );
    $stmt2->bind_param("iss", $req_id, $doc_hash, $ctrl);
    $stmt2->execute();
    $stmt2->close();

    write_audit_log($uid, 'PRINT_DOCUMENT', "request_id:$req_id", "Type:{$req['type_name']} Fee:$fee_display Hash:$short_hash");

    echo json_encode(['success'=>true, 'html'=>$html]);
}

else {
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
}
?>
