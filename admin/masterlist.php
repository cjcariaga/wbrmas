<?php
// TEMPORARY DEBUG — remove after diagnosing the 500
ini_set('display_errors', 1);
error_reporting(E_ALL);
$page_title  = 'Master List Report';
$active_page = 'analytics';
$breadcrumb  = ['Administration', 'Master List'];
require_once '../includes/auth_check.php';
require_role('System Administrator');
require_once '../includes/resident_age.php';

$conn = getDBConnection();
$csrf = generate_csrf_token();

// ── Filters ───────────────────────────────────────────────────────────────────
$f_purok  = sanitize_input($_GET['purok']  ?? '');
$f_status = sanitize_input($_GET['status'] ?? '');
$f_age    = sanitize_input($_GET['age']    ?? '');
$f_sex    = sanitize_input($_GET['sex']    ?? '');
$f_drawer = sanitize_input($_GET['drawer'] ?? '');
$export   = sanitize_input($_GET['export'] ?? ''); // 'csv'

$valid_puroks   = ['','Purok 1','Purok 2','Purok 3','Purok 4','Unassigned'];
$valid_statuses = ['','Active','Pending Verification'];
$valid_ages     = ['','Infant','Children','Youth','Young Adult','Adult','Senior Citizen'];
$valid_sexes    = ['','Male','Female'];

if (!in_array($f_purok,  $valid_puroks))   $f_purok  = '';
if (!in_array($f_status, $valid_statuses)) $f_status = '';
if (!in_array($f_age,    $valid_ages))     $f_age    = '';
if (!in_array($f_sex,    $valid_sexes))    $f_sex    = '';

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where = "WHERE is_archived=0";
if ($f_status) $where .= " AND record_status='".$conn->real_escape_string($f_status)."'";
if ($f_sex)    $where .= " AND sex='".$conn->real_escape_string($f_sex)."'";
if ($f_purok === 'Unassigned') $where .= " AND (purok IS NULL OR purok='')";
elseif ($f_purok) $where .= " AND purok='".$conn->real_escape_string($f_purok)."'";
if ($f_drawer) $where .= " AND drawer_location LIKE '%".$conn->real_escape_string($f_drawer)."%'";

// ── Fetch all residents ───────────────────────────────────────────────────────
$result = $conn->query(
    "SELECT resident_id, resident_code, first_name, middle_name, last_name,
            birth_date, sex, civil_status, contact_number, email, address,
            purok, drawer_location, is_indigent, record_status, years_of_residency, created_at
     FROM tbl_residents $where
     ORDER BY FIELD(purok,'Purok 1','Purok 2','Purok 3','Purok 4'), last_name"
);

// ── Decrypt and filter by age group ──────────────────────────────────────────
$residents = [];
while ($r = $result->fetch_assoc()) {
    $r['first_name']     = aes_decrypt($r['first_name']);
    $r['middle_name']    = aes_decrypt($r['middle_name']);
    $r['last_name']      = aes_decrypt($r['last_name']);
    $r['birth_date']     = aes_decrypt($r['birth_date']);
    $r['contact_number'] = aes_decrypt($r['contact_number']);
    $r['age'] = resident_age_from_birth_date($r['birth_date']);
    $r['age_group'] = resident_age_group_from_age($r['age']);

    // Apply age group filter in PHP (cannot do in SQL — birth_date is encrypted)
    if ($f_age && $r['age_group'] !== $f_age) continue;

    $residents[] = $r;
}
$total = count($residents);

// ── CSV Export ────────────────────────────────────────────────────────────────
if ($export === 'csv') {
    $filename = 'MasterList_Brgy_SanIsidro_'.date('Ymd_His').'.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        '#','Resident Code','Last Name','First Name','Middle Name',
        'Birth Date','Age','Age Group','Sex','Civil Status',
        'Purok','Drawer Location','Contact Number','Email','Address',
        'Years of Residency','Indigent','Record Status','Registered Date'
    ]);
    foreach ($residents as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['resident_code'],
            $r['last_name'],
            $r['first_name'],
            $r['middle_name'],
            $r['birth_date'],
            $r['age'] ?? '',
            $r['age_group'],
            $r['sex'],
            $r['civil_status'],
            $r['purok'] ?: 'Unassigned',
            $r['drawer_location'] ?: '',
            $r['contact_number'],
            $r['email'],
            $r['address'],
            $r['years_of_residency'],
            $r['is_indigent'] ? 'Yes' : 'No',
            $r['record_status'],
            date('M d, Y', strtotime($r['created_at'])),
        ]);
    }
    fclose($out);
    write_audit_log((int)$_SESSION['user_id'], 'EXPORT_MASTERLIST', 'masterlist',
        "CSV export | Filters: purok=$f_purok status=$f_status age=$f_age sex=$f_sex drawer=$f_drawer | Rows=$total");
    exit;
}

write_audit_log((int)$_SESSION['user_id'], 'VIEW_MASTERLIST', 'masterlist',
    "Filters: purok=$f_purok status=$f_status age=$f_age sex=$f_sex drawer=$f_drawer | Rows=$total");

require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-list-ol"></i> Resident Master List</h2>
    <p>Barangay San Isidro — City of Ilagan, Isabela &nbsp;|&nbsp;
       Generated: <strong><?= date('F d, Y h:i A') ?></strong></p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-secondary btn-sm" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn btn-success btn-sm">
      <i class="fas fa-file-csv"></i> Export CSV
    </a>
  </div>
</div>

<!-- Filters -->
<form method="GET" class="card mb-3">
  <div class="card-body" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;padding:16px 20px;">
    <div class="form-group" style="margin:0;min-width:140px;">
      <label style="font-size:.8rem;font-weight:600;">Purok</label>
      <select name="purok" class="form-control" style="font-size:.83rem;">
        <option value="">All Puroks</option>
        <?php foreach(['Purok 1','Purok 2','Purok 3','Purok 4','Unassigned'] as $p): ?>
        <option <?= $f_purok===$p?'selected':'' ?>><?= $p ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:160px;">
      <label style="font-size:.8rem;font-weight:600;">Record Status</label>
      <select name="status" class="form-control" style="font-size:.83rem;">
        <option value="">All Statuses</option>
        <option value="Active"               <?= $f_status==='Active'?'selected':'' ?>>Active</option>
        <option value="Pending Verification" <?= $f_status==='Pending Verification'?'selected':'' ?>>Pending Verification</option>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:160px;">
      <label style="font-size:.8rem;font-weight:600;">Age Group</label>
      <select name="age" class="form-control" style="font-size:.83rem;">
        <option value="">All Age Groups</option>
        <?php foreach(['Infant','Children','Youth','Young Adult','Adult','Senior Citizen'] as $ag): ?>
        <option <?= $f_age===$ag?'selected':'' ?>><?= $ag ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:120px;">
      <label style="font-size:.8rem;font-weight:600;">Sex</label>
      <select name="sex" class="form-control" style="font-size:.83rem;">
        <option value="">All</option>
        <option <?= $f_sex==='Male'?'selected':'' ?>>Male</option>
        <option <?= $f_sex==='Female'?'selected':'' ?>>Female</option>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:170px;">
      <label style="font-size:.8rem;font-weight:600;">Drawer Location</label>
      <input type="text" name="drawer" class="form-control" style="font-size:.83rem;"
             value="<?= sanitize_output($f_drawer) ?>" maxlength="100" placeholder="e.g. Drawer 2">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">
      <i class="fas fa-filter"></i> Apply Filters
    </button>
    <a href="?" class="btn btn-secondary btn-sm">
      <i class="fas fa-xmark"></i> Clear
    </a>
    <span style="margin-left:auto;font-size:.83rem;color:var(--text-muted);align-self:center;">
      <strong><?= number_format($total) ?></strong> resident<?= $total!==1?'s':'' ?> found
    </span>
  </div>
</form>

<!-- Master List Table -->
<div class="card" id="printArea">
  <!-- Print header (hidden on screen) -->
  <div style="display:none;" class="print-only" id="printHeader">
    <div style="text-align:center;padding:16px 0 8px;border-bottom:2px solid #000;margin-bottom:14px;">
      <h2 style="font-size:14pt;font-weight:bold;text-transform:uppercase;margin-bottom:4px;">
        Barangay San Isidro — Resident Master List
      </h2>
      <div style="font-size:10pt;">City of Ilagan, Isabela &nbsp;|&nbsp; Generated: <?= date('F d, Y') ?></div>
      <?php if ($f_purok || $f_status || $f_age || $f_sex || $f_drawer): ?>
      <div style="font-size:9pt;color:#555;margin-top:4px;">
        Filters: <?= implode(', ', array_filter([
          $f_purok  ? "Purok: $f_purok"   : '',
          $f_status ? "Status: $f_status" : '',
          $f_age    ? "Age: $f_age"       : '',
          $f_sex    ? "Sex: $f_sex"       : '',
          $f_drawer ? "Drawer: $f_drawer" : '',
        ])) ?>
      </div>
      <?php endif; ?>
      <div style="font-size:9pt;margin-top:4px;">Total: <strong><?= number_format($total) ?></strong> residents</div>
    </div>
  </div>

  <div class="card-body" style="padding:0;">
    <?php if (empty($residents)): ?>
    <div class="empty-state" style="padding:40px;">
      <svg width="60" height="60" viewBox="0 0 80 80" fill="none"><rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/></svg>
      <h4>No residents found</h4>
      <p>Try adjusting your filters.</p>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
      <table id="masterTable" style="font-size:.82rem;">
        <thead>
          <tr style="background:var(--primary-xlight);">
            <th>#</th>
            <th>Code</th>
            <th>Full Name</th>
            <th>Birth Date</th>
            <th>Age</th>
            <th>Age Group</th>
            <th>Sex</th>
            <th>Civil Status</th>
            <th>Drawer Location</th>
            <th>Purok</th>
            <th>Indigent</th>
            <th>Status</th>
            <th>Registered</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $rs_colors = [
            'Active'               => '#27ae60',
            'Pending Verification' => '#d68910',
        ];
        foreach ($residents as $i => $r):
        ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><code style="font-size:.75rem;"><?= sanitize_output($r['resident_code']) ?></code></td>
            <td><strong><?= sanitize_output($r['last_name'].', '.$r['first_name'].($r['middle_name']?' '.$r['middle_name']:'')) ?></strong></td>
            <td><?= sanitize_output($r['birth_date'] ? date('M d, Y', strtotime($r['birth_date'])) : '—') ?></td>
            <td><?= $r['age'] !== null ? $r['age'].' yrs' : '—' ?></td>
            <td><?= sanitize_output($r['age_group']) ?></td>
            <td><?= sanitize_output($r['sex'] ?: '—') ?></td>
            <td><?= sanitize_output($r['civil_status'] ?: '—') ?></td>
            <td><?= sanitize_output($r['drawer_location'] ?: '—') ?></td>
            <td><?= sanitize_output($r['purok'] ?: 'Unassigned') ?></td>
            <td><?= $r['is_indigent'] ? '<span style="color:#d68910;font-weight:700;">Yes</span>' : 'No' ?></td>
            <td>
              <span style="color:<?= $rs_colors[$r['record_status']] ?? '#7f8c8d' ?>;font-weight:600;font-size:.75rem;">
                <?= sanitize_output($r['record_status']) ?>
              </span>
            </td>
            <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:var(--primary-xlight);font-weight:700;">
            <td colspan="2">TOTAL</td>
            <td colspan="11"><?= number_format($total) ?> Resident<?= $total!==1?'s':'' ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<style>
@media print {
  .topbar, .sidebar, .page-header .btn, form.card, .btn,
  .breadcrumb-bar, nav, .sidebar-brand, #sigEditor { display:none !important; }
  .print-only { display:block !important; }
  body, .page-content, .main-content { margin:0; padding:0; }
  .card { box-shadow:none; border:none; }
  table { font-size:9pt; }
  th { background:#f0f0f0 !important; color:#000 !important; }
  .sig-block { display:block !important; }
}
.sig-block { display:none; }
</style>

<!-- Prepared By signature block — shown only on print -->
<div class="sig-block" id="sigBlock" style="margin-top:40px;padding:20px 0 0;border-top:2px solid #000;display:none;">
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:40px;margin-top:24px;">
    <div style="text-align:center;">
      <div style="border-top:1px solid #000;padding-top:6px;margin-top:50px;">
        <strong id="preparedByName" style="font-size:10pt;text-transform:uppercase;"></strong><br>
        <span id="preparedByPosition" style="font-size:9pt;font-style:italic;"></span>
        <div style="font-size:9pt;color:#555;margin-top:2px;">Prepared by</div>
      </div>
    </div>
    <div style="text-align:center;">
      <div style="border-top:1px solid #000;padding-top:6px;margin-top:50px;">
        <span style="font-size:9pt;font-style:italic;" id="datePrepared"></span>
        <div style="font-size:9pt;color:#555;margin-top:2px;">Date Prepared</div>
      </div>
    </div>
    <div style="text-align:center;">
      <div style="border-top:1px solid #000;padding-top:6px;margin-top:50px;">
        <strong style="font-size:10pt;text-transform:uppercase;">MARLON M. SAPONGAY</strong><br>
        <span style="font-size:9pt;font-style:italic;">Punong Barangay</span>
        <div style="font-size:9pt;color:#555;margin-top:2px;">Noted by</div>
      </div>
    </div>
  </div>
</div>

<!-- Signature block editor (screen only, hidden on print) -->
<div class="card mt-3" style="max-width:600px;" id="sigEditor">
  <div class="card-header"><h3><i class="fas fa-signature"></i> Signature Block (for printing)</h3></div>
  <div class="card-body">
    <div class="form-grid" style="grid-template-columns:1fr 1fr;">
      <div class="form-group">
        <label style="font-size:.83rem;font-weight:600;">Prepared By (Name)</label>
        <input type="text" id="inputPreparedBy" class="form-control"
               style="font-size:.83rem;"
               value="<?= sanitize_output($_SESSION['full_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label style="font-size:.83rem;font-weight:600;">Position / Title</label>
        <input type="text" id="inputPosition" class="form-control"
               style="font-size:.83rem;"
               value="<?= sanitize_output($_SESSION['role_name'] ?? 'Barangay Staff') ?>">
      </div>
      <div class="form-group">
        <label style="font-size:.83rem;font-weight:600;">Date Prepared</label>
        <input type="text" id="inputDatePrepared" class="form-control"
               style="font-size:.83rem;"
               value="<?= date('F d, Y') ?>">
      </div>
    </div>
    <div class="d-flex gap-2 mt-2">
      <button class="btn btn-primary btn-sm" onclick="applyAndPrint()">
        <i class="fas fa-print"></i> Apply & Print
      </button>
      <button class="btn btn-secondary btn-sm" onclick="previewSig()">
        <i class="fas fa-eye"></i> Preview Signature Block
      </button>
    </div>
  </div>
</div>

<script>
function applyAndPrint() {
  applySigValues();
  window.print();
}

function previewSig() {
  applySigValues();
  const blk = document.getElementById('sigBlock');
  blk.style.display = blk.style.display === 'none' ? 'block' : 'none';
}

function applySigValues() {
  document.getElementById('preparedByName').textContent     = document.getElementById('inputPreparedBy').value;
  document.getElementById('preparedByPosition').textContent = document.getElementById('inputPosition').value;
  document.getElementById('datePrepared').textContent       = document.getElementById('inputDatePrepared').value;
  // Make visible for print
  document.getElementById('sigBlock').style.display = 'block';
}
</script>
<?php require_once '../includes/footer.php'; ?>
