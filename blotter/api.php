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

// ── ADD CASE REPORT ───────────────────────────────────────────────────────────
if ($action === 'add') {
    // Check permission properly
    $is_admin = $_SESSION['role_name'] === 'System Administrator';
    $has_perm = in_array('add_blotter', $_SESSION['permissions'] ?? []);
    if (!$is_admin && !$has_perm) {
        echo json_encode(['success'=>false,'message'=>'Access denied. You do not have permission to file case reports.']); exit;
    }

    $complainant_type  = sanitize_input($_POST['complainant_type'] ?? 'resident');
    $complainant_id    = (int)($_POST['complainant_id'] ?? 0);
    $complainant_name  = trim(strip_tags((string)($_POST['complainant_name'] ?? '')));
    $complainant_address = trim(strip_tags((string)($_POST['complainant_address'] ?? '')));
    $complainant_contact = trim(strip_tags((string)($_POST['complainant_contact'] ?? '')));
    $respondent_resident_id = (int)($_POST['respondent_resident_id'] ?? 0);
    $respondent_name   = sanitize_input($_POST['respondent_name']   ?? '');
    $case_type         = sanitize_input($_POST['case_type']         ?? '');
    $case_date         = $_POST['case_date']         ?? '';
    $case_location     = sanitize_input($_POST['case_location']     ?? '');
    $case_description  = sanitize_input($_POST['case_description']  ?? '');
    $hearing_date      = $_POST['hearing_date']      ?? null;
    $resolution_status = sanitize_input($_POST['resolution_status'] ?? 'Active');

    $is_non_resident = $complainant_type === 'non_resident';
    if (!in_array($complainant_type, ['resident', 'non_resident'], true)
      || (!$is_non_resident && !$complainant_id)
      || ($is_non_resident && (!$complainant_name || !$complainant_address || !$complainant_contact))
      || !$respondent_resident_id || !$respondent_name || !$case_type || !$case_date || !$case_description || !$case_location) {
        echo json_encode(['success'=>false,'message'=>'All required fields must be filled.']); exit;
    }
    if ($is_non_resident && !preg_match('/^[0-9+() .-]+$/', $complainant_contact)) {
      echo json_encode(['success'=>false,'message'=>'Complainant contact number contains invalid characters.']); exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $case_date)) {
        echo json_encode(['success'=>false,'message'=>'Invalid case date format.']); exit;
    }

    $respondent_check = $conn->prepare(
      "SELECT resident_id, first_name, last_name FROM tbl_residents WHERE resident_id=? AND is_archived=0"
    );
    $respondent_check->bind_param('i', $respondent_resident_id);
    $respondent_check->execute();
    $respondent = $respondent_check->get_result()->fetch_assoc();
    $respondent_check->close();
    if (!$respondent) {
      http_response_code(400);
      echo json_encode(['success'=>false,'message'=>'Respondent must be selected from an active barangay resident record.']); exit;
    }
    $verified_respondent_name = trim(aes_decrypt($respondent['last_name']).', '.aes_decrypt($respondent['first_name']));
    if (mb_strtolower(trim($respondent_name), 'UTF-8') !== mb_strtolower($verified_respondent_name, 'UTF-8')) {
      http_response_code(400);
      echo json_encode(['success'=>false,'message'=>'Selected respondent does not match the resident record. Select the respondent again.']); exit;
    }
    $respondent_name = $verified_respondent_name;

    // IDOR: verify complainant exists
    if (!$is_non_resident) {
      $chk = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE resident_id=? AND is_archived=0");
      $chk->bind_param("i",$complainant_id); $chk->execute();
      if (!$chk->get_result()->fetch_assoc()) {
        echo json_encode(['success'=>false,'message'=>'Resident not found.']); exit;
      }
      $chk->close();
      $complainant_name = $complainant_address = $complainant_contact = '';
    } else {
      $complainant_id = null;
      $complainant_name = aes_encrypt($complainant_name);
      $complainant_contact = aes_encrypt($complainant_contact);
    }

    // Generate case number: BCR-YYYY-#####
    $count = (int)$conn->query("SELECT COUNT(*) FROM tbl_blotter")->fetch_row()[0];
    $case_number = 'BCR-' . date('Y') . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);

    $hdate = ($hearing_date && $hearing_date !== '') ? $hearing_date : null;

    $stmt = $conn->prepare(
        "INSERT INTO tbl_blotter
         (case_number, complainant_id, complainant_name, complainant_address, complainant_contact, respondent_resident_id,
          respondent_name, case_type, case_description,
          case_date, case_location, resolution_status, hearing_date, filed_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
      $stmt->bind_param("sisssisssssssi",
        $case_number, $complainant_id, $complainant_name, $complainant_address, $complainant_contact, $respondent_resident_id,
        $respondent_name, $case_type,
        $case_description, $case_date, $case_location,
        $resolution_status, $hdate, $uid
    );

    if ($stmt->execute()) {
        $case_id = $conn->insert_id;
        write_audit_log($uid,'FILE_CASE_REPORT',"case_id:$case_id","CaseNo:$case_number Type:$case_type");
        echo json_encode(['success'=>true,'case_number'=>$case_number,'case_id'=>$case_id]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Database error: '.$stmt->error]);
    }
    $stmt->close();
}

// ── UPDATE STATUS ─────────────────────────────────────────────────────────────
elseif ($action === 'update_status') {
    $case_id           = (int)($_POST['case_id']           ?? 0);
    $resolution_status = sanitize_input($_POST['resolution_status'] ?? '');
    $resolution_notes  = sanitize_input($_POST['resolution_notes']  ?? '');
    $referred_to       = sanitize_input($_POST['referred_to']       ?? '');
    $hearing_date      = $_POST['hearing_date'] ?? null;

    $allowed = ['Active','Under Mediation','Resolved','Referred'];
    if (!$case_id || !in_array($resolution_status, $allowed)) {
        echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit;
    }
    if ($resolution_status === 'Referred' && !$referred_to) {
        echo json_encode(['success'=>false,'message'=>'Please specify who the case is referred to.']); exit;
    }

    // IDOR check
    $chk = $conn->prepare("SELECT case_id FROM tbl_blotter WHERE case_id=?");
    $chk->bind_param("i",$case_id); $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        echo json_encode(['success'=>false,'message'=>'Case not found.']); exit;
    }
    $chk->close();

    $hdate = ($hearing_date && $hearing_date !== '') ? $hearing_date : null;

    $stmt = $conn->prepare(
        "UPDATE tbl_blotter SET resolution_status=?, resolution_notes=?,
         referred_to=?, hearing_date=?, updated_at=NOW()
         WHERE case_id=?"
    );
    $stmt->bind_param("ssssi", $resolution_status, $resolution_notes, $referred_to, $hdate, $case_id);

    if ($stmt->execute()) {
        write_audit_log($uid,'UPDATE_CASE_STATUS',"case_id:$case_id","Status:$resolution_status");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Update failed.']);
    }
    $stmt->close();
}

// ── PRINT CASE REPORT ─────────────────────────────────────────────────────────
elseif ($action === 'print') {
    $case_id = (int)($_POST['case_id'] ?? 0);
    if (!$case_id) { echo json_encode(['success'=>false,'message'=>'Invalid case.']); exit; }

    $stmt = $conn->prepare(
        "SELECT b.*, r.first_name, r.last_name, u.full_name as filed_by_name
         FROM tbl_blotter b
         LEFT JOIN tbl_residents r ON b.complainant_id = r.resident_id
         LEFT JOIN tbl_users u ON b.filed_by = u.user_id
         WHERE b.case_id = ?"
    );
    $stmt->bind_param("i",$case_id); $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$c) { echo json_encode(['success'=>false,'message'=>'Case not found.']); exit; }

    $complainant = $c['complainant_id'] !== null
      ? aes_decrypt($c['last_name']) . ', ' . aes_decrypt($c['first_name'])
      : aes_decrypt($c['complainant_name'] ?? '');
    $complainant_html = htmlspecialchars($complainant, ENT_QUOTES, 'UTF-8');
    $complainant_extra_html = '';
    if ($c['complainant_id'] === null) {
      $complainant_address_html = htmlspecialchars($c['complainant_address'] ?? '', ENT_QUOTES, 'UTF-8');
      $complainant_contact_html = htmlspecialchars(aes_decrypt($c['complainant_contact'] ?? ''), ENT_QUOTES, 'UTF-8');
      $complainant_extra_html = '<span class="lbl">Address:</span><span>'.$complainant_address_html.'</span>'
        . '<span class="lbl">Contact:</span><span>'.$complainant_contact_html.'</span>';
    }
    $status_color = ['Active'=>'#e74c3c','Under Mediation'=>'#d68910','Resolved'=>'#27ae60','Referred'=>'#2980b9'];
    $sc = $status_color[$c['resolution_status']] ?? '#333';
    $print_date = date('F d, Y \a\t h:i A');
    $case_date  = date('F d, Y', strtotime($c['case_date']));
    $filed_date = date('F d, Y', strtotime($c['filed_at']));
    $hearing    = $c['hearing_date'] ? date('F d, Y', strtotime($c['hearing_date'])) : 'Not yet scheduled';

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Barangay Case Report — {$c['case_number']}</title>
<style>
  @page { size:A4 portrait; margin:16mm 18mm 20mm; @bottom-center { content:"Page " counter(page) " of " counter(pages); font:9pt "Times New Roman",serif; color:#555; } }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Times New Roman', serif; font-size:12pt; color:#000; background:#fff; }
  .page { width:8.5in; min-height:11in; margin:0 auto; padding:0.75in 1in; position:relative; }
  .header { text-align:center; border-bottom:3px double #000; padding-bottom:14px; margin-bottom:18px; }
  .republic { font-size:10pt; letter-spacing:1px; }
  .brgy { font-size:16pt; font-weight:bold; text-transform:uppercase; letter-spacing:2px; margin:6px 0; }
  .doc-title { text-align:center; margin:20px 0; }
  .doc-title h2 { font-size:16pt; font-weight:bold; text-transform:uppercase; letter-spacing:2px; text-decoration:underline; }
  .doc-title .subtitle { font-size:10pt; color:#555; margin-top:4px; font-style:italic; }
  .meta-row { display:flex; justify-content:space-between; margin-bottom:16px; font-size:10pt; }
  .section { margin:16px 0; }
  .section-title { font-weight:bold; font-size:11pt; text-transform:uppercase; border-bottom:1px solid #999; padding-bottom:4px; margin-bottom:10px; letter-spacing:.5px; }
  .info-grid { display:grid; grid-template-columns:160px 1fr; gap:6px 12px; font-size:11pt; }
  .info-grid .lbl { font-weight:bold; color:#333; }
  .narrative { background:#f9f9f9; border:1px solid #ddd; border-radius:4px; padding:12px 16px; font-size:11pt; line-height:1.8; margin-top:8px; }
  .status-badge { display:inline-block; padding:4px 14px; border-radius:4px; font-size:11pt; font-weight:bold; color:#fff; background:{$sc}; }
  .sig-section { margin-top:50px; display:grid; grid-template-columns:1fr 1fr; gap:40px; }
  .sig-box { text-align:center; }
  .sig-line { border-top:1.5px solid #000; margin-top:46px; padding-top:6px; }
  .sig-name { font-weight:bold; text-transform:uppercase; font-size:11pt; }
  .sig-title { font-size:10pt; font-style:italic; }
  .disclaimer { margin-top:24px; padding:10px 14px; border:1.5px solid #d68910; border-radius:4px; background:#fef9e7; font-size:9pt; color:#7d6608; line-height:1.6; }
  .footer { position:fixed; bottom:0.28in; left:0.55in; right:0.55in; border-top:1px solid #ccc; padding-top:8px; display:flex; justify-content:space-between; font-size:8.5pt; color:#666; background:#fff; }
  @media print { body { -webkit-print-color-adjust:exact; print-color-adjust:exact; } .section-title{break-after:avoid} .info-grid,.narrative,.sig-section,.disclaimer{break-inside:avoid} }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <div class="republic">Republic of the Philippines</div>
    <div class="republic">Province of Isabela &bull; City of Ilagan</div>
    <div class="brgy">Barangay San Isidro</div>
    <div class="republic" style="font-size:9pt;color:#555;">Barangay Hall, Barangay San Isidro, City of Ilagan, Isabela</div>
  </div>

  <div class="doc-title">
    <h2>Barangay Case Report</h2>
    <div class="subtitle">Katarungang Pambarangay — Republic Act No. 7160<br>
    <em>This is a Barangay-level Case Report only. Not a PNP Blotter Record.</em></div>
  </div>

  <div class="meta-row">
    <div><strong>Case No.:</strong> {$c['case_number']}</div>
    <div><strong>Date Filed:</strong> {$filed_date}</div>
    <div><strong>Status:</strong> <span class="status-badge">{$c['resolution_status']}</span></div>
  </div>

  <div class="section">
    <div class="section-title">Parties Involved</div>
    <div class="info-grid">
      <span class="lbl">Complainant:</span><span>{$complainant_html}</span>
      {$complainant_extra_html}
      <span class="lbl">Respondent:</span><span>{$c['respondent_name']}</span>
    </div>
  </div>

  <div class="section">
    <div class="section-title">Case Details</div>
    <div class="info-grid">
      <span class="lbl">Case Type:</span><span>{$c['case_type']}</span>
      <span class="lbl">Date of Incident:</span><span>{$case_date}</span>
      <span class="lbl">Location:</span><span>{$c['case_location']}</span>
      <span class="lbl">Hearing Date:</span><span>{$hearing}</span>
      <span class="lbl">Filed By:</span><span>{$c['filed_by_name']}</span>
    </div>
  </div>

  <div class="section">
    <div class="section-title">Narrative / Description</div>
    <div class="narrative">{$c['case_description']}</div>
  </div>

HTML;

    if ($c['resolution_notes']) {
        $html .= <<<HTML2
  <div class="section">
    <div class="section-title">Resolution Notes</div>
    <div class="narrative">{$c['resolution_notes']}</div>
  </div>
HTML2;
    }

    if ($c['referred_to']) {
        $html .= <<<HTML3
  <div class="section">
    <div class="section-title">Referral Information</div>
    <div class="info-grid">
      <span class="lbl">Referred To:</span><span>{$c['referred_to']}</span>
    </div>
  </div>
HTML3;
    }

    $html .= <<<HTML4
  <div class="sig-section">
    <div class="sig-box">
      <div class="sig-line">
        <div class="sig-name">Barangay Secretary</div>
        <div class="sig-title">Recorded by</div>
      </div>
    </div>
    <div class="sig-box">
      <div class="sig-line">
        <div class="sig-name">Punong Barangay</div>
        <div class="sig-title">Barangay San Isidro</div>
      </div>
    </div>
  </div>

  <div class="disclaimer">
    <strong>⚠ Important Notice:</strong> This document is a <strong>Barangay Case Report</strong> filed under
    the Katarungang Pambarangay Law (Republic Act No. 7160). It records complaints and disputes within
    the jurisdiction of <strong>Barangay San Isidro, City of Ilagan, Isabela</strong> only.
    This is <u>NOT</u> an official Philippine National Police (PNP) Blotter Record.
    Cases requiring law enforcement action are referred to the appropriate authorities.
  </div>

  <div class="footer">
    <span>Case No: {$c['case_number']}</span>
    <span>WBRMAS — Barangay San Isidro Digital Records System</span>
    <span>Printed: {$print_date}</span>
  </div>
</div>
<script>window.onload=function(){window.print();}</script>
</body>
</html>
HTML4;

    write_audit_log($uid,'PRINT_CASE_REPORT',"case_id:$case_id","CaseNo:{$c['case_number']}");
    echo json_encode(['success'=>true,'html'=>$html]);
}

else {
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
}
?>
