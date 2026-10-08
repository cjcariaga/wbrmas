<?php
require_once __DIR__ . '/../config/security.php';
secure_session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);

// ── Auth checks ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !check_session_timeout()) {
    echo json_encode(['success' => false, 'message' => 'Session expired.']); exit;
}
if (($_SESSION['role_name'] ?? '') === 'Barangay Treasurer') {
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']); exit;
}
require_once __DIR__ . '/../includes/auth_check.php';
if (!user_has_permission('create_document')) {
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Access denied. You cannot issue documents.']); exit;
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$conn     = getDBConnection();
$uid      = (int)$_SESSION['user_id'];
$doc_type = sanitize_input($_POST['doc_type']      ?? '');
$rid      = (int)($_POST['resident_id']            ?? 0);
$purpose  = sanitize_input($_POST['purpose_final'] ?? ($_POST['purpose'] ?? ''));
$purok    = sanitize_input($_POST['purok']         ?? '');
$ctc_no   = sanitize_input($_POST['ctc_no']        ?? '');
$or_no    = sanitize_input($_POST['or_no']         ?? '');
$received_by = sanitize_input($_POST['received_by'] ?? '');
$received_col_check = $conn->query("SHOW COLUMNS FROM tbl_document_requests LIKE 'received_by'");
$has_received_by = $received_col_check && $received_col_check->num_rows > 0;

if (!$rid)                                        { echo json_encode(['success'=>false,'message'=>'No resident selected.']); exit; }
if (!$doc_type)                                   { echo json_encode(['success'=>false,'message'=>'No document type.']); exit; }
if (!$purpose)                                    { echo json_encode(['success'=>false,'message'=>'Purpose is required.']); exit; }
if (!$has_received_by || !$received_by)             { echo json_encode(['success'=>false,'message'=>'Received by is required.']); exit; }
if (!in_array($doc_type,['clearance','indigency'])){ echo json_encode(['success'=>false,'message'=>'Invalid document type.']); exit; }

// ── Fetch resident ────────────────────────────────────────────────────────────
$st = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id=? AND is_archived=0");
$st->bind_param("i",$rid); $st->execute();
$r = $st->get_result()->fetch_assoc(); $st->close();
if (!$r) { echo json_encode(['success'=>false,'message'=>'Resident not found.']); exit; }

$fname = aes_decrypt($r['first_name']);
$mname = aes_decrypt($r['middle_name']);
$lname = aes_decrypt($r['last_name']);
$bdate = aes_decrypt($r['birth_date']);
$civil = $r['civil_status'] ?? '';
$age   = $bdate ? (int)date_diff(date_create($bdate), date_create('today'))->y : 0;
$mi    = $mname ? strtoupper(substr($mname,0,1)).'.' : '';
$since = $bdate ? date('F d, Y', strtotime($bdate)) : '';

// ── Fee ───────────────────────────────────────────────────────────────────────
if ($doc_type === 'clearance') {
    $type_id   = 1;
    $type_name = 'Barangay Clearance';
    if (stripos($purpose,'School')!==false || stripos($purpose,'Academic')!==false) {
        $fee = 0.00; $fee_note = ' (Student - Free)';
    } elseif (stripos($purpose,'Loan')!==false) {
        $fee = 100.00; $fee_note = ' (Loan - P100)';
    } else {
        $fee = 50.00; $fee_note = '';
    }
} else {
    $type_id   = 2;
    $type_name = 'Certificate of Indigency';
    $fee = 0.00; $fee_note = '';
}
$fee_display   = $fee > 0 ? 'P'.number_format($fee,2) : 'FREE';
$purpose_saved = $purpose . $fee_note;

// ── Save to DB ────────────────────────────────────────────────────────────────
$cnt = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests")->fetch_row()[0];
$req_code = 'REQ-'.date('Y').'-'.str_pad($cnt+1,5,'0',STR_PAD_LEFT);

if ($has_received_by) {
    $st2 = $conn->prepare("INSERT INTO tbl_document_requests (request_code,resident_id,document_type_id,purpose,status,issued_by_user_id,received_by,received_at,drawer_location,processed_at) VALUES (?, ?, ?, ?, 'RELEASED', ?, ?, NOW(), NULL, NOW())");
    if (!$st2) { echo json_encode(['success'=>false,'message'=>'Could not prepare document issuance.']); exit; }
    $st2->bind_param("siisis", $req_code, $rid, $type_id, $purpose_saved, $uid, $received_by);
}
$st2->execute();
$req_id = $conn->insert_id;
$st2->close();

$ctrl   = 'CTRL-'.date('Y').'-'.str_pad($req_id,6,'0',STR_PAD_LEFT);
$hash   = strtoupper(substr(hash_hmac('sha256',$req_id.'|'.$type_name.'|'.$lname.','.$fname.'|'.date('Y-m-d'),HMAC_SECRET_KEY),0,16));

$st3 = $conn->prepare("INSERT INTO tbl_generated_documents (request_id,document_hash,control_number,issued_at) VALUES (?,?,?,NOW())");
$st3->bind_param("iss",$req_id,$hash,$ctrl);
$st3->execute(); $st3->close();

write_audit_log($uid,'GENERATE_DOCUMENT',"req:$req_id","$type_name | $lname,$fname | Fee:$fee | ReleasedTo:$received_by");

// ── Date ──────────────────────────────────────────────────────────────────────
$day   = (int)date('j');
$month = date('F');
$year  = date('Y');
if      ($day%100>=11 && $day%100<=13) $sfx='th';
elseif  ($day%10==1)  $sfx='st';
elseif  ($day%10==2)  $sfx='nd';
elseif  ($day%10==3)  $sfx='rd';
else    $sfx='th';
$day_ord = $day.$sfx;

// ── Images (base64) ───────────────────────────────────────────────────────────
function img64($path, $mime = 'image/png') {
    return file_exists($path) ? 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path)) : '';
}
$header_b64 = img64(__DIR__.'/../assets/header.png') ?: img64(__DIR__.'/../assets/img/header.png');
$footer_b64 = img64(__DIR__.'/../assets/footer.png') ?: img64(__DIR__.'/../assets/img/footer.png');
$header_markup = $header_b64
  ? '<div class="hdr"><img src="'.$header_b64.'" alt="Barangay San Isidro Official Header"></div>'
  : '<header class="official-header"><strong>REPUBLIC OF THE PHILIPPINES</strong><strong>PROVINCE OF ISABELA · CITY OF ILAGAN</strong><strong>BARANGAY SAN ISIDRO</strong><span>Barangay Hall, San Isidro, City of Ilagan, Isabela</span></header>';
$footer_markup = $footer_b64
  ? '<div class="foot"><img src="'.$footer_b64.'" alt="Barangay San Isidro Footer"></div>'
  : '<footer class="official-footer">Barangay San Isidro · City of Ilagan, Isabela</footer>';

// ═════════════════════════════════════════════════════════════════════════════
// BUILD HTML
// ═════════════════════════════════════════════════════════════════════════════
$hn       = htmlspecialchars($lname, ENT_QUOTES, 'UTF-8');
$hf       = htmlspecialchars($fname, ENT_QUOTES, 'UTF-8');
$hmi      = htmlspecialchars($mi,    ENT_QUOTES, 'UTF-8');
$hcivil   = htmlspecialchars(strtoupper($civil), ENT_QUOTES, 'UTF-8');
$hpurok   = htmlspecialchars($purok ?: '', ENT_QUOTES, 'UTF-8');
$hpurpose = htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8');
$hctc     = htmlspecialchars($ctc_no,  ENT_QUOTES, 'UTF-8');
$hor      = htmlspecialchars($or_no,   ENT_QUOTES, 'UTF-8');
$hfullname = htmlspecialchars($fname.($mname ? ' '.$mname.' ' : ' ').$lname, ENT_QUOTES, 'UTF-8');
$hage     = $age ?: '';
$hsince   = htmlspecialchars($since ?: '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($type_name); ?></title>
<style>
@page { size:A4 portrait; margin:0; }
* { margin:0; padding:0; box-sizing:border-box; }
body {
  font-family:"Times New Roman", Times, serif;
  font-size:11pt;
  color:#000;
  background:#fff;
}
.page {
  width:210mm;
  min-height:297mm;
  position:relative;
  display:flex;
  flex-direction:column;
}

/* ── Header / Footer images ── */
.hdr { width:calc(100% - 0.4in); margin:0 0.2in; line-height:0; display:block; }
.hdr img { width:100%; display:block; }
.foot { width:calc(100% - 0.4in); margin:20px 0.2in 0; line-height:0; display:block; margin-top:auto; }
.foot img { width:100%; display:block; }

/* ── Body ── */
.body-wrap {
  padding: 12px 1.35in 0;
}

/* ── Title ── */
.doc-title {
  text-align:center;
  margin-bottom:15px;
}
.doc-title h2 {
  font-size:17pt;
  font-weight:bold;
  text-transform:uppercase;
}

/* ── Control row ── */
.ctrl-row {
  display:flex;
  justify-content:space-between;
  font-size:10pt;
  margin-bottom:14px;
}

/* ── Paragraphs ── */
.para {
  text-align:justify;
  line-height:1.75;
  margin-bottom:13px;
}
.indent { text-indent:0.5in; }

/* ── Underline / blank lines ── */
.ul  { text-decoration:underline; }
.ul2 { text-decoration:underline; font-weight:bold; }

/* inline underline blanks */
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
.official-header{text-align:center;border-bottom:3px double #000;padding:0 0 10px;margin:0 0 14px;font-size:9pt;line-height:1.5}
.official-header strong,.official-header span{display:block}
.official-header strong:nth-child(3){font-size:14pt;letter-spacing:.4px}
.official-header span{font-size:8.5pt}
.official-footer{text-align:center;margin-top:auto;padding:8px 0 4px;border-top:1px solid #777;font-size:8pt;color:#444}

/* sub-labels */
.lbl {
  font-size:8pt;
  display:block;
  text-align:center;
}

/* ── Bottom table (clearance) ── */
.btbl {
  width:100%;
  border-collapse:collapse;
  margin-top:18px;
  font-size:9.5pt;
}
.btbl td {
  border:1px solid #000;
  padding:6px 8px;
  vertical-align:top;
}
.btbl .shdr {
  font-weight:bold;
  text-align:center;
  text-transform:uppercase;
  font-size:9.5pt;
  padding:7px 8px;
}
.sig-line {
  border-top:1px solid #000;
  margin-top:52px;
  text-align:center;
  font-size:8pt;
  padding-top:4px;
  font-style:italic;
}
.thumb-box {
  border:1px solid #000;
  width:70px;
  height:86px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:7.5pt;
  text-align:center;
  line-height:1.4;
  margin:4px auto 0;
}

@media print {
  @page{size:A4 portrait;margin:12mm 0.2in 16mm;@bottom-center{content:"Page " counter(page) " of " counter(pages);font:8pt "Times New Roman",serif;color:#555}}
  body { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .btbl thead{display:table-header-group}
  tr{break-inside:avoid}
}
</style>
</head>
<body>
<div class="page">

  <!-- HEADER IMAGE (you supply assets/header.png) -->
  <?php echo $header_markup; ?>

  <div class="body-wrap">

    <!-- Title -->
    <div class="doc-title">
      <h2><?php echo htmlspecialchars($type_name); ?></h2>
    </div>

    <!-- Control / Date row -->
    <div class="ctrl-row">
      <span>Control No.: <strong><?php echo $ctrl; ?></strong></span>
      <span>Date: <strong><?php echo "$day_ord day of $month, $year"; ?></strong></span>
    </div>

    <p class="para"><strong>TO WHOM IT MAY CONCERN:</strong></p>

<?php if ($doc_type === 'clearance'): ?>

    <!-- Paragraph 1 — name line -->
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

    <!-- Paragraph 2 — age / civil status / purok / since -->
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

    <!-- Paragraph 3 — good moral -->
    <p class="para indent">
      This is to further certify that the aforementioned person whose thumb mark,
      signature and other personal circumstances is of
      <strong>GOOD MORAL CHARACTER</strong> and has <strong>NO DEROGATORY RECORD</strong>
      in this office linking to any subversive acts as of the date of issuance.
    </p>

    <!-- Paragraph 4 — purpose -->
    <p class="para indent">
      This certification is issued upon the request of the aforementioned for
      <span class="field f-purpose"><?php echo $hpurpose; ?></span>,
      and for whatever legal purposes it may serve.
    </p>

    <!-- Issued line -->
    <p class="para">
      Issued this <span class="field f-day"><?php echo $day_ord; ?></span> day of
      <span class="field f-month"><?php echo $month; ?></span>
      <?php echo $year; ?> at the Office of the <span class="ul">Punong Barangay</span>.
    </p>

    <!-- Bottom table -->
    <table class="btbl">
      <tr>
        <td style="width:30%;">CTC No.&nbsp;&nbsp;<strong><?php echo $hctc; ?></strong></td>
        <td style="width:26%;"></td>
        <td rowspan="2" style="width:44%;vertical-align:top;">
          <strong>NOT VALID WITHOUT OFFICIAL SEAL</strong><br>
          <em style="color:#c00;font-size:9pt;">(Please affix the official seal here)</em>
        </td>
      </tr>
      <tr>
        <td>Official Receipt No.&nbsp;&nbsp;<strong><?php echo $hor; ?></strong></td>
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

    <!-- Paragraph 1 -->
    <p class="para indent">
      This is to certify that
      <span class="field f-full"><?php echo $hfullname; ?></span>,
      <span class="field f-age"><?php echo $hage; ?></span>
      of age, is a <span class="ul">bona fide</span> resident of this <span class="ul">barangay</span>.
    </p>

    <!-- Paragraph 2 -->
    <p class="para indent">
      This is to further certify that the aforementioned is an identified
      <strong>INDIGENT INDIVIDUAL</strong> belonging to a household living below the poverty threshold.
    </p>

    <!-- Paragraph 3 -->
    <p class="para indent">
      This certification is issued upon the request of the aforementioned for whatever
      legal purposes it may serve.
    </p>

    <!-- Certified line -->
    <p class="para indent">
      Certified this <span class="field f-day"><?php echo $day_ord; ?></span> of
      <span class="field f-month"><?php echo $month; ?></span>,
      <?php echo $year; ?> at the Office of the <span class="ul">Punong Barangay</span>.
    </p>

    <p style="margin-top:44px;font-size:12.5pt;">Certified and Noted:</p>

    <p style="font-weight:bold;text-transform:uppercase;font-size:13pt;margin-top:64px;">MARLON M. SAPONGAY</p>
    <p style="font-style:italic;font-size:12pt;">Punong Barangay</p>

    <div style="margin-top:54px;">
      <p style="font-size:10pt;font-weight:bold;">/NOT VALID WITHOUT OFFICIAL SEAL</p>
      <p style="font-size:9.5pt;font-style:italic;">(Please affix the official seal here)</p>
    </div>

<?php endif; ?>

  </div><!-- .body-wrap -->

  <!-- FOOTER IMAGE (you supply assets/footer.png) -->
  <?php echo $footer_markup; ?>

</div><!-- .page -->
<script>window.onload = function(){ window.print(); };</script>
</body>
</html>
<?php
$html = ob_get_clean();
echo json_encode(['success' => true, 'html' => $html, 'req_code' => $req_code, 'fee' => $fee]);
?>
