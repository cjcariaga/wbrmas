<?php
$page_title  = 'Resident Profile';
$active_page = 'residents';
$breadcrumb  = ['Residents', 'Profile'];
require_once '../includes/auth_check.php';
require_permission('view_residents');
require_once '../includes/resident_age.php';

$rid = (int)($_GET['id'] ?? 0);
if (!$rid) { header('Location: index.php'); exit; }

$conn = getDBConnection();

// ── Fetch resident (IDOR: must exist + not archived, unless admin viewing archived) ──
$stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id=?");
$stmt->bind_param("i", $rid); $stmt->execute();
$r = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$r) { header('Location: index.php'); exit; }

// Decrypt PII
$r['first_name']     = aes_decrypt($r['first_name']);
$r['middle_name']    = aes_decrypt($r['middle_name']);
$r['last_name']      = aes_decrypt($r['last_name']);
$r['birth_date']     = aes_decrypt($r['birth_date']);
$r['contact_number'] = aes_decrypt($r['contact_number']);
$full_name = trim($r['last_name'].', '.$r['first_name'].($r['middle_name'] ? ' '.$r['middle_name'] : ''));
$age = resident_age_from_birth_date($r['birth_date']);
$age_group = resident_age_group_from_age($age);

// ── Document requests ────────────────────────────────────────────────────────
$doc_stmt = $conn->prepare(
    "SELECT dr.request_code, dr.status, dr.purpose, dr.requested_at, dr.processed_at,
            dt.type_name, dt.fee, u.full_name AS issued_by
     FROM tbl_document_requests dr
     JOIN tbl_document_types dt ON dr.document_type_id = dt.type_id
     LEFT JOIN tbl_users u ON dr.issued_by_user_id = u.user_id
     WHERE dr.resident_id = ?
     ORDER BY dr.requested_at DESC"
);
$doc_stmt->bind_param("i", $rid); $doc_stmt->execute();
$doc_records = $doc_stmt->get_result(); $doc_stmt->close();

// Health details are shown only to authorized module users.
$health_role_allowed = in_array($_SESSION['role_name'] ?? '', ['System Administrator', 'Barangay Staff'], true);
$can_view_health = $health_role_allowed && can('view_health_records');
$can_manage_health = $health_role_allowed && can('manage_health_records');
$health_records = [];
if ($can_view_health) {
  $health_stmt = $conn->prepare(
    "SELECT health_record_id, record_type_enc, item_name_enc, event_date_enc, details_enc
     FROM tbl_health_records WHERE resident_id=? ORDER BY updated_at DESC, health_record_id DESC"
  );
  $health_stmt->bind_param("i", $rid);
  $health_stmt->execute();
  $health_result = $health_stmt->get_result();
  while ($health = $health_result->fetch_assoc()) {
    $health['record_type'] = aes_decrypt($health['record_type_enc']);
    $health['item_name'] = aes_decrypt($health['item_name_enc']);
    $health['event_date'] = aes_decrypt($health['event_date_enc']);
    $health['details'] = aes_decrypt($health['details_enc']);
    $health_records[] = $health;
  }
  $health_stmt->close();
}

// ── Blotter involvement (complainant OR respondent by name match) ─────────────
// Complainant: direct FK
$blot_stmt = $conn->prepare(
    "SELECT b.case_number, b.case_type, b.case_date, b.case_location,
            b.resolution_status, b.filed_at, u.full_name AS filed_by,
            'Complainant' AS involvement
     FROM tbl_blotter b
     LEFT JOIN tbl_users u ON b.filed_by = u.user_id
     WHERE b.complainant_id = ?
     ORDER BY b.filed_at DESC"
);
$blot_stmt->bind_param("i", $rid); $blot_stmt->execute();
$blot_complainant = $blot_stmt->get_result(); $blot_stmt->close();

// ── Archive info (if archived) ────────────────────────────────────────────────
$arch_user = null;
if ($r['is_archived'] && $r['archived_by']) {
    $au = $conn->prepare("SELECT full_name FROM tbl_users WHERE user_id=?");
    $au->bind_param("i", $r['archived_by']); $au->execute();
    $arch_user = $au->get_result()->fetch_assoc()['full_name'] ?? null;
    $au->close();
}

// ── Status colors ─────────────────────────────────────────────────────────────
$status_cfg = [
    'Active'               => ['color'=>'#27ae60','bg'=>'#eafaf1','label'=>'Active'],
    'Pending Verification' => ['color'=>'#d68910','bg'=>'#fef9e7','label'=>'Pending Verification'],
    'Archived'             => ['color'=>'#7f8c8d','bg'=>'#f2f3f4','label'=>'Archived'],
];
$sc = $status_cfg[$r['record_status'] ?? 'Pending Verification'] ?? $status_cfg['Pending Verification'];

$csrf = generate_csrf_token();
require_once '../includes/header.php';
?>

<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-id-card"></i> Resident 360° Profile</h2>
    <p>Full record view — <strong><?= sanitize_output($full_name) ?></strong> · <code><?= sanitize_output($r['resident_code']) ?></code></p>
  </div>
  <div class="d-flex gap-2">
    <?php if (is_admin() && !$r['is_archived']): ?>
    <a href="/residents/edit.php?id=<?= $rid ?>" class="btn btn-secondary btn-sm">
      <i class="fas fa-pen-to-square"></i> Edit
    </a>
    <?php endif; ?>
    <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<div class="d-flex gap-3 flex-wrap" style="align-items:flex-start;">

  <!-- ── LEFT: Profile Card ──────────────────────────────────────────────── -->
  <div style="flex:0 0 260px;min-width:220px;">
    <div class="card mb-3">
      <div class="card-body" style="text-align:center;padding:28px 20px;">
        <!-- Photo -->
        <div style="width:96px;height:96px;border-radius:50%;overflow:hidden;margin:0 auto 14px;
                    border:3px solid var(--border);background:#f0f0f0;display:flex;align-items:center;justify-content:center;">
          <?php if ($r['photo_path']): ?>
            <img src="/<?= htmlspecialchars($r['photo_path'],ENT_QUOTES) ?>"
                 style="width:100%;height:100%;object-fit:cover;" alt="Photo">
          <?php else: ?>
            <i class="fas fa-user" style="font-size:2.5rem;color:#ccc;"></i>
          <?php endif; ?>
        </div>

        <h3 style="font-size:1rem;font-weight:700;color:var(--primary);margin-bottom:4px;">
          <?= sanitize_output($r['first_name'].' '.($r['middle_name']?$r['middle_name'].' ':'').$r['last_name']) ?>
        </h3>
        <code style="font-size:.78rem;color:var(--text-muted);"><?= sanitize_output($r['resident_code']) ?></code>

        <!-- Record Status badge -->
        <div style="margin:12px 0 4px;">
          <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border:1.5px solid <?= $sc['color'] ?>;
                       padding:4px 14px;border-radius:20px;font-size:.78rem;font-weight:700;">
            <?= $sc['label'] ?>
          </span>
        </div>

        <?php if ($r['is_indigent']): ?>
        <div style="margin-top:6px;">
          <span class="badge badge-warning"><i class="fas fa-hand-holding-heart"></i> Indigent</span>
        </div>
        <?php endif; ?>

        <!-- Quick facts -->
        <div style="margin-top:16px;text-align:left;font-size:.82rem;line-height:2.1;border-top:1px solid var(--border);padding-top:12px;">
          <div><i class="fas fa-map-marker-alt" style="width:16px;color:var(--primary-light);"></i>
            <strong>Purok:</strong> <?= sanitize_output($r['purok'] ?: '—') ?></div>
          <div><i class="fas fa-box-archive" style="width:16px;color:var(--primary-light);"></i>
            <strong>Drawer Location:</strong> <?= sanitize_output($r['drawer_location'] ?? '—') ?: '—' ?></div>
          <div><i class="fas fa-cake-candles" style="width:16px;color:var(--primary-light);"></i>
            <strong>Age:</strong> <?= $age !== null ? $age.' yrs' : '—' ?></div>
          <div><i class="fas fa-people-group" style="width:16px;color:var(--primary-light);"></i>
            <strong>Age Group:</strong> <?= sanitize_output($age_group) ?></div>
          <div><i class="fas fa-venus-mars" style="width:16px;color:var(--primary-light);"></i>
            <strong>Sex:</strong> <?= sanitize_output($r['sex'] ?: '—') ?></div>
          <div><i class="fas fa-ring" style="width:16px;color:var(--primary-light);"></i>
            <strong>Civil Status:</strong> <?= sanitize_output($r['civil_status'] ?: '—') ?></div>
          <div><i class="fas fa-phone" style="width:16px;color:var(--primary-light);"></i>
            <strong>Contact:</strong> <?= sanitize_output($r['contact_number'] ?: '—') ?></div>
          <div><i class="fas fa-envelope" style="width:16px;color:var(--primary-light);"></i>
            <strong>Email:</strong> <?= sanitize_output($r['email'] ?: '—') ?></div>
          <div><i class="fas fa-house" style="width:16px;color:var(--primary-light);"></i>
            <strong>Address:</strong> <?= sanitize_output($r['address'] ?: '—') ?></div>
          <div><i class="fas fa-calendar" style="width:16px;color:var(--primary-light);"></i>
            <strong>Registered:</strong> <?= date('M d, Y', strtotime($r['created_at'])) ?></div>
        </div>
      </div>
    </div>

    <!-- Archive info card (if archived) -->
    <?php if ($r['is_archived']): ?>
    <div class="card" style="border-left:4px solid #e74c3c;">
      <div class="card-body" style="padding:14px 16px;font-size:.82rem;">
        <div style="font-weight:700;color:#e74c3c;margin-bottom:8px;">
          <i class="fas fa-box-archive"></i> Archived Record
        </div>
        <div><strong>Reason:</strong> <?= sanitize_output($r['archive_reason'] ?: '—') ?></div>
        <div><strong>Date:</strong> <?= $r['archived_at'] ? date('M d, Y h:i A', strtotime($r['archived_at'])) : '—' ?></div>
        <div><strong>By:</strong> <?= sanitize_output($arch_user ?: '—') ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Change Status (admin only, non-archived) -->
    <?php if (is_admin() && !$r['is_archived']): ?>
    <div class="card mt-3">
      <div class="card-header"><h3 style="font-size:.85rem;"><i class="fas fa-toggle-on"></i> Record Status</h3></div>
      <div class="card-body" style="padding:14px 16px;">
        <select id="statusChanger" class="form-control" style="font-size:.83rem;margin-bottom:10px;">
          <?php foreach(['Active','Pending Verification'] as $st): ?>
          <option value="<?= $st ?>" <?= ($r['record_status']===$st)?'selected':'' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary btn-sm w-100" onclick="changeStatus()">
          <i class="fas fa-save"></i> Update Status
        </button>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── RIGHT: History ───────────────────────────────────────────────────── -->
  <div style="flex:1;min-width:300px;">

    <!-- Document Requests -->
    <div class="card mb-3">
      <div class="card-header">
        <h3><i class="fas fa-file-lines"></i> Document Requests</h3>
        <?php if (!$r['is_archived']): ?>
        <a href="/documents/create.php?resident_id=<?= $rid ?>" class="btn btn-primary btn-sm">
          <i class="fas fa-plus"></i> New Request
        </a>
        <?php endif; ?>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if ($doc_records && $doc_records->num_rows > 0): ?>
        <div class="table-wrapper">
          <table>
            <thead><tr><th>Code</th><th>Type</th><th>Purpose</th><th>Fee</th><th>Status</th><th>Date</th><th>Issued By</th></tr></thead>
            <tbody>
            <?php while ($d = $doc_records->fetch_assoc()):
              $is_clr = stripos($d['type_name'],'clearance') !== false;
              $is_ind = stripos($d['type_name'],'indigency') !== false || stripos($d['type_name'],'indigent') !== false;
              $sc2 = ['PENDING'=>'warning','APPROVED'=>'info','PRINTED'=>'success','REJECTED'=>'danger'];
            ?>
            <tr>
              <td><code style="font-size:.75rem;"><?= sanitize_output($d['request_code']) ?></code></td>
              <td style="font-size:.83rem;"><?= sanitize_output($d['type_name']) ?></td>
              <td style="font-size:.8rem;color:var(--text-muted);"><?= sanitize_output($d['purpose'] ?: '—') ?></td>
              <td style="font-size:.82rem;"><?php
                if ($is_ind) echo '<span style="color:var(--success);font-weight:600;">FREE</span>';
                elseif ($is_clr) {
                    if (stripos($d['purpose'],'Student')!==false||stripos($d['purpose'],'Academic')!==false||stripos($d['purpose'],'School')!==false)
                        echo '<span style="color:var(--success);font-weight:600;">FREE</span>';
                    elseif (stripos($d['purpose'],'Loan')!==false)
                        echo '<span style="font-weight:600;">₱100.00</span>';
                    else echo '₱'.number_format($d['fee'],2);
                } else echo '₱'.number_format($d['fee'],2);
              ?></td>
              <td><span class="badge badge-<?= $sc2[$d['status']] ?? 'secondary' ?>"><?= $d['status'] ?></span></td>
              <td style="font-size:.78rem;white-space:nowrap;"><?= date('M d, Y', strtotime($d['requested_at'])) ?></td>
              <td style="font-size:.78rem;color:var(--text-muted);"><?= sanitize_output($d['issued_by'] ?: '—') ?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:28px;">
          <svg width="48" height="48" viewBox="0 0 80 80" fill="none"><rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/><line x1="22" y1="36" x2="58" y2="36" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity=".2"/></svg>
          <h4>No document requests</h4>
          <p>No documents have been requested for this resident yet.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($can_view_health): ?>
    <div class="card mb-3">
      <div class="card-header">
        <h3><i class="fas fa-heart-pulse"></i> Health Records</h3>
        <div class="d-flex gap-2">
          <a href="/health/index.php?resident_id=<?= $rid ?>" class="btn btn-secondary btn-sm">Open List</a>
          <?php if ($can_manage_health && !$r['is_archived']): ?>
          <a href="/health/index.php?resident_id=<?= $rid ?>&add=1" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if ($health_records): ?>
        <div class="table-wrapper">
          <table>
            <thead><tr><th>Type</th><th>Item</th><th>Date</th><th>Notes</th></tr></thead>
            <tbody>
            <?php $health_types = ['vaccination'=>'Vaccination','condition'=>'Medical Condition','allergy'=>'Allergy','program'=>'Health Program']; foreach ($health_records as $health): ?>
              <tr>
                <td><?= sanitize_output($health_types[$health['record_type']] ?? 'Health Record') ?></td>
                <td><strong><?= sanitize_output($health['item_name']) ?></strong></td>
                <td><?= sanitize_output($health['event_date'] ?: '—') ?></td>
                <td><?= nl2br(sanitize_output($health['details'] ?: '—')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:24px;"><i class="fas fa-heart-pulse"></i><p>No health records recorded for this resident.</p></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Blotter Involvement -->
    <div class="card mb-3">
      <div class="card-header">
        <h3><i class="fas fa-book-open"></i> Blotter Involvement</h3>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if ($blot_complainant && $blot_complainant->num_rows > 0): ?>
        <div class="table-wrapper">
          <table>
            <thead><tr><th>Case No.</th><th>Type</th><th>Role</th><th>Date</th><th>Status</th><th>Filed By</th></tr></thead>
            <tbody>
            <?php
            $bs = ['Active'=>'danger','Under Mediation'=>'warning','Resolved'=>'success','Referred'=>'info'];
            while ($b = $blot_complainant->fetch_assoc()): ?>
            <tr>
              <td><code style="font-size:.75rem;"><?= sanitize_output($b['case_number']) ?></code></td>
              <td style="font-size:.82rem;"><?= sanitize_output($b['case_type'] ?? $b['incident_type'] ?? '—') ?></td>
              <td><span class="badge badge-info" style="font-size:.72rem;"><?= $b['involvement'] ?></span></td>
              <td style="font-size:.78rem;"><?= $b['case_date'] ? date('M d, Y', strtotime($b['case_date'])) : date('M d, Y', strtotime($b['filed_at'])) ?></td>
              <td><span class="badge badge-<?= $bs[$b['resolution_status']] ?? 'secondary' ?>" style="font-size:.72rem;"><?= sanitize_output($b['resolution_status']) ?></span></td>
              <td style="font-size:.78rem;color:var(--text-muted);"><?= sanitize_output($b['filed_by'] ?: '—') ?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:28px;">
          <svg width="48" height="48" viewBox="0 0 80 80" fill="none"><circle cx="40" cy="45" r="16" stroke="currentColor" stroke-width="2.5" opacity=".15"/><line x1="40" y1="38" x2="40" y2="46" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity=".25"/></svg>
          <h4>No blotter records</h4>
          <p>This resident has no blotter involvement on record.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- right col -->
</div>

<script>
async function changeStatus() {
  const status = document.getElementById('statusChanger').value;
  const fd = new FormData();
  fd.append('csrf_token',   '<?= $csrf ?>');
  fd.append('resident_id',  '<?= $rid ?>');
  fd.append('record_status', status);
  const resp = await fetch('/residents/api.php?action=change_status', { method:'POST', body:fd });
  const res  = await resp.json();
  if (res.success) {
    showToast('success', 'Record status updated to: ' + status);
    setTimeout(() => location.reload(), 1200);
  } else {
    showToast('error', res.message || 'Update failed.');
  }
}
</script>
<?php require_once '../includes/footer.php'; ?>
