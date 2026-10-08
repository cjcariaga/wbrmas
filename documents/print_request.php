<?php
require_once __DIR__ . '/../config/security.php';
secure_session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);

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
require_once __DIR__ . '/../includes/auth_check.php';
if (!user_has_permission('approve_document')) {
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Access denied. You cannot issue documents.']); exit;
}

$conn   = getDBConnection();
$uid    = (int)$_SESSION['user_id'];
$req_id = (int)($_POST['request_id'] ?? 0);
$delivery_method = sanitize_input($_POST['delivery_method'] ?? '');
$drawer_id = (int)($_POST['drawer_id'] ?? 0);
$received_by = trim(sanitize_input($_POST['received_by'] ?? ''));
$drawer_location = '';
$received_col_check = $conn->query("SHOW COLUMNS FROM tbl_document_requests LIKE 'received_by'");
$has_received_by = $received_col_check && $received_col_check->num_rows > 0;
$drawer_col_check = $conn->query("SHOW COLUMNS FROM tbl_document_requests LIKE 'drawer_location'");
$has_drawer_location = $drawer_col_check && $drawer_col_check->num_rows > 0;

if (!$req_id) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }

// Fetch full request details with IDOR check
$select_sql = "SELECT dr.request_id, dr.request_code, dr.purpose, dr.status, dr.issued_by_user_id, dr.preferred_pickup_date" . ($has_received_by ? ", dr.received_by" : "") . ",
            " . ($has_drawer_location ? "dr.drawer_location," : "") . "
            dt.type_name, dt.fee,
            r.resident_id, r.first_name, r.last_name, r.middle_name,
            r.birth_date, r.civil_status, r.address, r.is_indigent,
            r.years_of_residency
     FROM tbl_document_requests dr
     JOIN tbl_document_types dt ON dr.document_type_id = dt.type_id
     JOIN tbl_residents r ON dr.resident_id = r.resident_id
     WHERE dr.request_id = ?";
$stmt = $conn->prepare($select_sql);
$stmt->bind_param("i", $req_id); $stmt->execute();
$req = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$req) { echo json_encode(['success'=>false,'message'=>'Request not found.']); exit; }
if (!in_array($req['status'], ['APPROVED','STORED','RELEASED','PRINTED'], true)) {
  http_response_code(409);
  echo json_encode(['success'=>false,'message'=>'This request cannot be issued in its current status.']); exit;
}
if (($_SESSION['role_name'] ?? '') !== 'System Administrator' && $req['issued_by_user_id'] !== null && (int)$req['issued_by_user_id'] !== $uid) {
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Access denied for this document request.']); exit;
}
if ($req['status'] === 'APPROVED') {
  if (!empty($req['preferred_pickup_date']) && $req['preferred_pickup_date'] > date('Y-m-d') && $delivery_method !== 'drawer') {
    echo json_encode(['success'=>false,'message'=>'This request has a future pickup date and must be stored in a drawer until collection.']); exit;
  }
  if (!in_array($delivery_method, ['drawer', 'recipient'], true)) {
    echo json_encode(['success'=>false,'message'=>'Choose whether to store the document in a drawer or hand it to a person.']); exit;
  }
  if ($delivery_method === 'recipient' && !$has_received_by) {
    echo json_encode(['success'=>false,'message'=>'Recipient tracking is not available in the database.']); exit;
  }
  if ($delivery_method === 'recipient' && $received_by === '') {
    echo json_encode(['success'=>false,'message'=>'Received by is required.']); exit;
  }
  if ($delivery_method === 'recipient' && mb_strlen($received_by, 'UTF-8') > 150) {
    echo json_encode(['success'=>false,'message'=>'Recipient name is too long.']); exit;
  }
  if ($delivery_method === 'drawer' && !$has_received_by) {
    echo json_encode(['success'=>false,'message'=>'Recipient tracking is required for the drawer pickup workflow. Apply the document pickup migration first.']); exit;
  }
  if ($delivery_method === 'drawer' && !$has_drawer_location) {
    echo json_encode(['success'=>false,'message'=>'Drawer location tracking is not available in the database.']); exit;
  }
  if ($delivery_method === 'drawer') {
    if (!$drawer_id) {
      echo json_encode(['success'=>false,'message'=>'Choose a drawer for this document.']); exit;
    }
    $drawer_stmt = $conn->prepare("SELECT drawer_name FROM tbl_storage_drawers WHERE drawer_id=?");
    $drawer_stmt->bind_param('i', $drawer_id);
    $drawer_stmt->execute();
    $drawer_row = $drawer_stmt->get_result()->fetch_assoc();
    $drawer_stmt->close();
    if (!$drawer_row) {
      echo json_encode(['success'=>false,'message'=>'The selected drawer was not found. Refresh and choose an available drawer.']); exit;
    }
    $drawer_location = $drawer_row['drawer_name'];
  }
}

// Determine doc_type
$doc_type = (stripos($req['type_name'],'clearance') !== false) ? 'clearance' : 'indigency';

// Decrypt resident info
$fname = aes_decrypt($req['first_name']);
$mname = aes_decrypt($req['middle_name']);
$lname = aes_decrypt($req['last_name']);
$resident_full_name = trim($fname.' '.($mname ? $mname.' ' : '').$lname);
$bdate = aes_decrypt($req['birth_date']);
$civil = $req['civil_status'] ?? '';
$age   = $bdate ? (int)date_diff(date_create($bdate), date_create('today'))->y : 0;
$mi    = $mname ? strtoupper(substr($mname,0,1)).'.' : '';
$since = $bdate ? date('F d, Y', strtotime($bdate)) : '';

// Extract purok from address if available
$purok = '';
if (stripos($req['address'] ?? '', 'Purok') !== false || stripos($req['address'] ?? '', 'Sitio') !== false) {
    $purok = $req['address'];
}

// Purpose — clean up fee notes
$purpose = preg_replace('/\s*\([^)]*Fee[^)]*\)|\s*\(Student[^)]*\)|\s*\(Loan[^)]*\)/i', '', $req['purpose']);
$purpose = trim($purpose);

// Fee
$fee = (float)$req['fee'];
if ($doc_type === 'clearance') {
    if (stripos($req['purpose'],'Student')!==false || stripos($req['purpose'],'Academic')!==false || stripos($purpose,'School')!==false) {
        $fee = 0.00;
    } elseif (stripos($req['purpose'],'Loan')!==false) {
        $fee = 100.00;
    }
}
$fee_display = $fee > 0 ? '₱'.number_format($fee,2) : 'FREE';

// Control number
$ctrl_row = $conn->query("SELECT control_number FROM tbl_generated_documents WHERE request_id=$req_id LIMIT 1")->fetch_assoc();
$ctrl     = $ctrl_row ? $ctrl_row['control_number'] : 'CTRL-'.date('Y').'-'.str_pad($req_id,6,'0',STR_PAD_LEFT);

// If no generated doc yet — create record
if (!$ctrl_row) {
    $hash_val = strtoupper(substr(hash_hmac('sha256',"$req_id|{$req['type_name']}|$lname,$fname|".date('Y-m-d'),HMAC_SECRET_KEY),0,16));
    $ins = $conn->prepare("INSERT IGNORE INTO tbl_generated_documents (request_id,document_hash,control_number,issued_at) VALUES (?,?,?,NOW())");
    $ins->bind_param("iss",$req_id,$hash_val,$ctrl);
    $ins->execute(); $ins->close();
}

// Issue the approved document either into a drawer or directly to its recipient.
if ($req['status'] === 'APPROVED') {
  $conn->begin_transaction();
  if ($delivery_method === 'drawer') {
    if (!$has_received_by) {
      $conn->rollback();
      echo json_encode(['success'=>false,'message'=>'Recipient tracking is required for the drawer pickup workflow. Apply the document pickup migration first.']); exit;
    }
    $update = $conn->prepare("UPDATE tbl_document_requests SET status='STORED', processed_at=NOW(), issued_by_user_id=?, received_by=NULL, received_at=NULL, drawer_location=? WHERE request_id=? AND status='APPROVED'");
    if (!$update) {
      $conn->rollback();
      echo json_encode(['success'=>false,'message'=>'Could not prepare the drawer storage update.']); exit;
    }
    $update->bind_param('isi', $uid, $drawer_location, $req_id);
    $updated = $update->execute() && $update->affected_rows === 1;
    $update->close();
    if (!$updated) {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(['success'=>false,'message'=>'This request changed while it was being issued. Refresh and try again.']); exit;
    }

    $entry_label = mb_substr($req['type_name'].' — '.$resident_full_name, 0, 150, 'UTF-8').' — '.$req['request_code'];
    $entry_notes = 'Awaiting Pickup. Document: '.$req['type_name'].'; Resident: '.$resident_full_name.'; Request: '.$req['request_code'];
    if (!empty($req['preferred_pickup_date'])) $entry_notes .= '; Scheduled Pickup: '.$req['preferred_pickup_date'];
    $resident_id = (int)$req['resident_id'];
    $entry = $conn->prepare("INSERT INTO tbl_storage_entries (drawer_id,entry_label,entry_notes,resident_id,request_id,created_by,updated_by) VALUES (?,?,?,?,?,?,?)");
    if (!$entry) {
      $conn->rollback();
      echo json_encode(['success'=>false,'message'=>'Could not prepare the drawer index entry.']); exit;
    }
    $entry->bind_param('issiiii', $drawer_id, $entry_label, $entry_notes, $resident_id, $req_id, $uid, $uid);
    if (!$entry->execute()) {
      $entry->close();
      $conn->rollback();
      echo json_encode(['success'=>false,'message'=>'Could not add the document to the Drawer Index.']); exit;
    }
    $entry_id = $conn->insert_id;
    $entry->close();
    $conn->commit();
    $req['status'] = 'STORED';
    $req['received_by'] = null;
    $req['drawer_location'] = $drawer_location;
    write_audit_log($uid, 'STORE_DOCUMENT_IN_DRAWER', "request:$req_id", "Code:{$req['request_code']} Entry:$entry_id Drawer:$drawer_location Resident:$resident_full_name");
  } else {
    $conn->rollback();
    $update = $conn->prepare("UPDATE tbl_document_requests SET status='RELEASED', processed_at=NOW(), issued_by_user_id=?, received_by=?, received_at=NOW(), drawer_location=NULL WHERE request_id=? AND status='APPROVED'");
    if (!$update) {
      echo json_encode(['success'=>false,'message'=>'Could not prepare the document release update.']); exit;
    }
    $update->bind_param('isi', $uid, $received_by, $req_id);
    $updated = $update->execute() && $update->affected_rows === 1;
    $update->close();
    if (!$updated) {
      http_response_code(409);
      echo json_encode(['success'=>false,'message'=>'This request changed while it was being issued. Refresh and try again.']); exit;
    }
    $req['status'] = 'RELEASED';
    $req['received_by'] = $received_by;
    $req['drawer_location'] = null;
    write_audit_log($uid, 'RELEASE_DOCUMENT_TO_RECIPIENT', "request:$req_id", "Code:{$req['request_code']} ReceivedBy:$received_by Resident:$resident_full_name");
  }
}
if ($req['status'] !== 'APPROVED') {
  write_audit_log($uid,'PRINT_DOCUMENT',"req:$req_id","{$req['type_name']} | $lname,$fname | Fee:$fee | Reprint status:{$req['status']}");
}

// Date
$day   = (int)date('j'); $month = date('F'); $year = date('Y');
if      ($day%100>=11&&$day%100<=13) $sfx='th';
elseif  ($day%10==1) $sfx='st';
elseif  ($day%10==2) $sfx='nd';
elseif  ($day%10==3) $sfx='rd';
else    $sfx='th';
$day_ord = $day.$sfx;

// Images
function img64p($path, $mime = 'image/png') {
    return file_exists($path) ? 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path)) : '';
}
$header_b64 = img64p(__DIR__.'/../assets/header.png') ?: img64p(__DIR__.'/../assets/img/header.png');
$footer_b64 = img64p(__DIR__.'/../assets/footer.png') ?: img64p(__DIR__.'/../assets/img/footer.png');
$header_markup = $header_b64
  ? '<div class="hdr"><img src="'.$header_b64.'" alt="Barangay San Isidro Official Header"></div>'
  : '<header class="official-header"><strong>REPUBLIC OF THE PHILIPPINES</strong><strong>PROVINCE OF ISABELA · CITY OF ILAGAN</strong><strong>BARANGAY SAN ISIDRO</strong><span>Barangay Hall, San Isidro, City of Ilagan, Isabela</span></header>';
$footer_markup = $footer_b64
  ? '<div class="foot"><img src="'.$footer_b64.'" alt="Barangay San Isidro Footer"></div>'
  : '<footer class="official-footer">Barangay San Isidro · City of Ilagan, Isabela</footer>';

// Escaped HTML vars
$hn       = htmlspecialchars($lname);
$hf       = htmlspecialchars($fname);
$hmi      = htmlspecialchars($mi);
$hcivil   = htmlspecialchars(strtoupper($civil));
$hpurok   = htmlspecialchars($purok ?: '');
$hpurpose = htmlspecialchars($purpose);
$hage     = $age ?: '';
$hfull    = htmlspecialchars($fname.($mname ? ' '.$mname.' ' : ' ').$lname);
$hsince   = htmlspecialchars($since ?: '');

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($req['type_name']); ?> — <?php echo htmlspecialchars("$lname, $fname"); ?></title>
<style>
@page { size:A4 portrait; margin:12mm 0.2in 16mm; @bottom-center { content:"Page " counter(page) " of " counter(pages); font:8pt "Times New Roman",serif; color:#555; } }
* { margin:0; padding:0; box-sizing:border-box; }
body {
  font-family:"Times New Roman", Times, serif;
  font-size:11pt;
  color:#000;
  background:#fff;
}
.page {
  width:100%;
  min-height:269mm;
  position:relative;
  display:flex;
  flex-direction:column;
}
.hdr { width:calc(100% - 0.4in); margin:0 0.2in; line-height:0; display:block; }
.hdr img { width:100%; display:block; }
.foot { width:calc(100% - 0.4in); margin:20px 0.2in 0; line-height:0; display:block; margin-top:auto; }
.foot img { width:100%; display:block; }
.body-wrap { flex:1; padding:8mm 18mm 0; }
.doc-title { text-align:center; margin-bottom:15px; }
.doc-title h2 { font-size:17pt; font-weight:bold; text-transform:uppercase; }
.ctrl-row { display:flex; justify-content:space-between; font-size:10pt; margin-bottom:14px; }
.para { text-align:justify; line-height:1.75; margin-bottom:13px; }
.indent { text-indent:0.5in; }
.ul { text-decoration:underline; }
.field {
  display:inline-block;
  border-bottom:1px solid #000;
  min-width:30px;
  text-align:center;
  vertical-align:bottom;
  line-height:1.1;
  padding:0 4px;
  font-weight:normal;
}
.f-name  { min-width:180px; }
.f-fname { min-width:140px; }
.f-mi    { min-width:36px; }
.f-age   { min-width:36px; }
.f-civil { min-width:130px; }
.f-purok { min-width:130px; }
.f-since { min-width:150px; }
.f-purpose { min-width:280px; }
.f-day   { min-width:40px; }
.f-month { min-width:110px; }
.f-full  { min-width:260px; }
.official-header{text-align:center;border-bottom:3px double #000;padding:0 0 10px;margin:0 0 14px;font-size:9pt;line-height:1.5}.official-header strong,.official-header span{display:block}.official-header strong:nth-child(3){font-size:14pt;letter-spacing:.4px}.official-header span{font-size:8.5pt}.official-footer{text-align:center;margin-top:auto;padding:8px 0 4px;border-top:1px solid #777;font-size:8pt;color:#444}
.indigency-signoff{display:flex;justify-content:flex-end;min-height:52mm;margin-top:23mm;break-inside:avoid}
.indigency-signoff-content{width:74mm;text-align:center}
.indigency-certified{margin-bottom:14mm;font-size:12pt;text-align:left}
.indigency-signatory{font-size:12pt;font-weight:bold;text-transform:uppercase}
.indigency-title{margin-top:3px;font-size:10pt;font-style:italic}
.indigency-seal{margin-top:16mm;text-align:left;font-size:9pt;font-weight:bold}
.indigency-seal-note{margin-top:3px;text-align:left;font-size:8.5pt;font-style:italic}
.btbl { width:100%; border-collapse:collapse; margin-top:18px; font-size:9.5pt; }
.btbl td { border:1px solid #000; padding:6px 8px; vertical-align:top; }
.btbl .shdr { font-weight:bold; text-align:center; text-transform:uppercase; font-size:9.5pt; padding:7px 8px; }
.sig-line { border-top:1px solid #000; margin-top:52px; text-align:center; font-size:8pt; padding-top:4px; font-style:italic; }
.thumb-box { border:1px solid #000; width:70px; height:86px; display:flex; align-items:center; justify-content:center; font-size:7.5pt; text-align:center; line-height:1.4; margin:4px auto 0; }
@media print { body { -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style>
</head>
<body>
<div class="page">

  <!-- HEADER IMAGE -->
  <?php echo $header_markup; ?>

  <div class="body-wrap">

    <div class="doc-title">
      <h2><?php echo htmlspecialchars($req['type_name']); ?></h2>
    </div>

    <div class="ctrl-row">
      <span>Control No.: <strong><?php echo $ctrl; ?></strong></span>
      <span>Date: <strong><?php echo "$day_ord day of $month, $year"; ?></strong></span>
    </div>

    <p class="para"><strong>TO WHOM IT MAY CONCERN:</strong></p>

<?php if ($doc_type === 'clearance'): ?>

    <p class="para indent">
      This is to certify that
      <span class="field f-name"><?php echo $hn; ?></span>
      <span class="field f-fname"><?php echo $hf; ?></span>
      <span class="field f-mi"><?php echo $hmi; ?></span><br>
      <span style="padding-left:0.5in;font-size:8pt;">
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(Last name)
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(First name)
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(M.I)
      </span>
    </p>

    <p class="para">
      <span class="field f-age"><?php echo $hage; ?></span>
      <span style="font-size:8pt;"> (Age)</span>
      &nbsp;of age,&nbsp;
      <span class="field f-civil"><?php echo $hcivil; ?></span><span style="font-size:8pt;"> (Civil Status)</span>,
      is a <span class="ul">bona fide</span> resident of
      <span class="field f-purok"><?php echo $hpurok; ?></span><span style="font-size:8pt;"> (Purok)</span>,<br>
      of this <span class="ul">barangay</span> since
      <span class="field f-since"><?php echo $hsince; ?></span>
      to the date of issuance.<br>
      <span style="padding-left:2.05in;font-size:8pt;">(date/since Birth)</span>
    </p>

    <p class="para indent">
      This is to further certify that the aforementioned person whose thumb mark,
      signature and other personal circumstances is of
      <strong>GOOD MORAL CHARACTER</strong> and has <strong>NO DEROGATORY RECORD</strong>
      in this office linking to any subversive acts as of the date of issuance.
    </p>

    <p class="para indent">
      This certification is issued upon the request of the aforementioned for
      <span class="field f-purpose"><?php echo $hpurpose; ?></span>,
      and for whatever legal purposes it may serve.
    </p>

    <p class="para">
      Issued this <span class="field f-day"><?php echo $day_ord; ?></span> day of
      <span class="field f-month"><?php echo $month; ?></span>
      <?php echo $year; ?> at the Office of the <span class="ul">Punong Barangay</span>.
    </p>

    <table class="btbl">
      <tr>
        <td style="width:30%;">CTC No.</td>
        <td style="width:26%;"></td>
        <td rowspan="2" style="width:44%;vertical-align:top;">
          <strong>NOT VALID WITHOUT OFFICIAL SEAL</strong><br>
          <em style="color:#c00;font-size:9pt;">(Please affix the official seal here)</em>
        </td>
      </tr>
      <tr>
        <td>Official Receipt No.</td>
        <td></td>
      </tr>
      <tr>
        <td colspan="2" class="shdr">Attestation of the Applicant</td>
        <td class="shdr">Certified and Approved By:</td>
      </tr>
      <tr>
        <td style="font-size:9pt;font-style:italic;vertical-align:top;padding-top:8px;">
          I hereby certify that the above listed information is true and correct.
        </td>
        <td style="text-align:center;vertical-align:bottom;padding-bottom:8px;">
          <div class="thumb-box">Right<br>Thumbmark</div>
        </td>
        <td style="text-align:center;vertical-align:bottom;padding-bottom:12px;">
          <br><br><br>
          <strong style="font-size:11pt;">MARLON M. SAPONGAY</strong><br>
          <span style="font-size:10pt;">PUNONG BARANGAY</span>
        </td>
      </tr>
      <tr>
        <td colspan="2">
          <div class="sig-line">Signature of the Applicant</div>
        </td>
        <td></td>
      </tr>
    </table>

<?php else: /* INDIGENCY */ ?>

    <p class="para indent">
      This is to certify that
      <span class="field f-full"><?php echo $hfull; ?></span>,
      <span class="field f-age"><?php echo $hage; ?></span>
      of age, is a <span class="ul">bona fide</span> resident of this <span class="ul">barangay</span>.
    </p>

    <p class="para indent">
      This is to further certify that the aforementioned is an identified
      <strong>INDIGENT INDIVIDUAL</strong> belonging to a household living below the poverty threshold.
    </p>

    <p class="para indent">
      This certification is issued upon the request of the aforementioned for whatever
      legal purposes it may serve.
    </p>

    <p class="para indent">
      Certified this <span class="field f-day"><?php echo $day_ord; ?></span> of
      <span class="field f-month"><?php echo $month; ?></span>,
      <?php echo $year; ?> at the Office of the <span class="ul">Punong Barangay</span>.
    </p>

    <div class="indigency-signoff">
      <div class="indigency-signoff-content">
        <p class="indigency-certified">Certified and Noted:</p>
        <p class="indigency-signatory">MARLON M. SAPONGAY</p>
        <p class="indigency-title">Punong Barangay</p>
        <p class="indigency-seal">/ NOT VALID WITHOUT OFFICIAL SEAL</p>
        <p class="indigency-seal-note">(Please affix the official seal here)</p>
      </div>
    </div>

<?php endif; ?>

  </div><!-- .body-wrap -->

  <!-- FOOTER IMAGE -->
  <?php echo $footer_markup; ?>

</div><!-- .page -->
<script>window.onload = function(){ window.print(); };</script>
</body>
</html>
<?php
$html = ob_get_clean();
echo json_encode(['success'=>true,'html'=>$html,'req_code'=>$req['request_code'],'fee'=>$fee]);
?>
