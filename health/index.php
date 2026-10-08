<?php
$page_title = 'Health Records';
$active_page = 'health';
$breadcrumb = ['Residents', 'Health Records'];
require_once '../includes/auth_check.php';
$allowed_roles = ['System Administrator', 'Barangay Staff'];
if (!in_array($_SESSION['role_name'] ?? '', $allowed_roles, true)) {
    http_response_code(403);
    exit('Access denied.');
}
require_permission('view_health_records');

$conn = getDBConnection();
$csrf = generate_csrf_token();
$can_manage = can('manage_health_records');
$search = trim(strip_tags((string)($_GET['q'] ?? '')));
$resident_filter = max(0, (int)($_GET['resident_id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 40;

$records = [];
$result = $conn->query(
    "SELECT h.health_record_id, h.resident_id, h.record_type_enc, h.item_name_enc,
            h.event_date_enc, h.details_enc, h.created_at, r.resident_code
     FROM tbl_health_records h
     JOIN tbl_residents r ON r.resident_id=h.resident_id
     ORDER BY h.updated_at DESC, h.health_record_id DESC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['record_type'] = aes_decrypt($row['record_type_enc']);
        $row['item_name'] = aes_decrypt($row['item_name_enc']);
        $row['event_date'] = aes_decrypt($row['event_date_enc']);
        $row['details'] = aes_decrypt($row['details_enc']);
        if ($resident_filter && (int)$row['resident_id'] !== $resident_filter) continue;
        $haystack = mb_strtolower(implode(' ', [
            $row['resident_code'], $row['record_type'], $row['item_name'], $row['event_date'], $row['details']
        ]), 'UTF-8');
        if ($search !== '' && mb_strpos($haystack, mb_strtolower($search, 'UTF-8'), 0, 'UTF-8') === false) continue;
        $records[] = $row;
    }
}
$total_records = count($records);
$total_pages = max(1, (int)ceil($total_records / $per_page));
$page = min($page, $total_pages);
$page_records = array_slice($records, ($page - 1) * $per_page, $per_page);

$type_labels = [
    'vaccination' => 'Vaccination',
    'condition' => 'Medical Condition',
    'allergy' => 'Allergy',
    'program' => 'Health Program',
];
$resident_options = $conn->query("SELECT resident_id, resident_code, is_archived FROM tbl_residents ORDER BY resident_code");
require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-heart-pulse"></i> Health Records</h2>
    <p>Resident-linked health history and program enrollment</p>
  </div>
  <div class="d-flex gap-2">
    <a href="analytics.php" class="btn btn-secondary"><i class="fas fa-chart-column"></i> Analytics</a>
    <?php if ($can_manage): ?>
    <button type="button" class="btn btn-primary" onclick="openHealthModal()"><i class="fas fa-plus"></i> Add Record</button>
    <?php endif; ?>
  </div>
</div>

<form method="GET" class="d-flex gap-2 align-center mb-3 flex-wrap">
  <?php if ($resident_filter): ?><input type="hidden" name="resident_id" value="<?= $resident_filter ?>"><?php endif; ?>
  <div class="search-box" style="flex:1;min-width:240px;max-width:600px;">
    <i class="fas fa-search"></i>
    <input type="search" name="q" class="form-control" style="padding-left:36px;" maxlength="120"
           value="<?= sanitize_output($search) ?>" placeholder="Search resident code or health record text...">
  </div>
  <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i> Search</button>
  <?php if ($search !== '' || $resident_filter): ?><a href="index.php" class="btn btn-secondary">Clear</a><?php endif; ?>
</form>

<?php if ($resident_filter): ?>
<div class="mb-3"><a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> All Residents</a></div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h3><i class="fas fa-list"></i> Records</h3>
    <span class="badge badge-secondary"><?= number_format($total_records) ?> result<?= $total_records === 1 ? '' : 's' ?></span>
  </div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Resident</th><th>Record Type</th><th>Item</th><th>Date</th><th>Details</th><?php if ($can_manage): ?><th>Actions</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if ($page_records): foreach ($page_records as $record): ?>
          <tr>
            <td><a href="/BRGYMS/residents/view.php?id=<?= (int)$record['resident_id'] ?>"><code><?= sanitize_output($record['resident_code']) ?></code></a></td>
            <td><?= sanitize_output($type_labels[$record['record_type']] ?? 'Health Record') ?></td>
            <td><strong><?= sanitize_output($record['item_name']) ?></strong></td>
            <td><?= sanitize_output($record['event_date'] ?: '—') ?></td>
            <td><?= nl2br(sanitize_output($record['details'] ?: '—')) ?></td>
            <?php if ($can_manage): ?>
            <td><button type="button" class="btn btn-icon btn-sm" title="Edit health record" onclick='editHealthRecord(<?= json_encode(['health_record_id'=>(int)$record['health_record_id'],'resident_id'=>(int)$record['resident_id'],'record_type'=>$record['record_type'],'item_name'=>$record['item_name'],'event_date'=>$record['event_date'],'details'=>$record['details']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="<?= $can_manage ? 6 : 5 ?>"><div class="empty-state"><i class="fas fa-heart-pulse"></i><h4>No health records found</h4><p>Add resident-disclosed vaccination, condition, allergy, or program information as needed.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="pagination" style="padding:16px 22px;">
      <?php for ($p = 1; $p <= $total_pages; $p++): ?>
      <a class="page-btn <?= $p === $page ? 'active' : '' ?>" href="?<?= http_build_query(array_filter(['resident_id'=>$resident_filter ?: null,'q'=>$search ?: null,'page'=>$p])) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($can_manage): ?>
<div class="modal-overlay" id="healthRecordModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header"><h4 id="healthModalTitle">Add Health Record</h4><button type="button" class="modal-close" onclick="closeHealthModal()"><i class="fas fa-xmark"></i></button></div>
    <div class="modal-body">
      <input type="hidden" id="healthRecordId">
      <div class="form-grid">
        <div class="form-group">
          <label for="healthResident">Resident <span class="req">*</span></label>
          <select id="healthResident" class="form-control" required><option value="">Select resident</option><?php if ($resident_options): while ($resident = $resident_options->fetch_assoc()): ?><option value="<?= (int)$resident['resident_id'] ?>"><?= sanitize_output($resident['resident_code']) ?><?= $resident['is_archived'] ? ' (Archived)' : '' ?></option><?php endwhile; endif; ?></select>
        </div>
        <div class="form-group">
          <label for="healthType">Record Type <span class="req">*</span></label>
          <select id="healthType" class="form-control" required>
            <option value="vaccination">Vaccination</option><option value="condition">Medical Condition</option><option value="allergy">Allergy</option><option value="program">Health Program</option>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label for="healthItem">Vaccine, condition, allergy, or program <span class="req">*</span></label>
          <input id="healthItem" class="form-control" maxlength="150" required placeholder="e.g. Influenza vaccine or Senior Citizen Health Program">
        </div>
        <div class="form-group">
          <label for="healthDate">Date <small>(optional)</small></label>
          <input id="healthDate" type="date" class="form-control">
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label for="healthDetails">Notes <small>(optional, resident-disclosed)</small></label>
          <textarea id="healthDetails" class="form-control" rows="3" maxlength="2000" placeholder="Dose, allergy details, or program compliance notes"></textarea>
        </div>
      </div>
      <small style="color:var(--text-muted);">Health information is stored encrypted and is visible only to authorized staff.</small>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeHealthModal()">Cancel</button><button type="button" class="btn btn-primary" onclick="saveHealthRecord()"><i class="fas fa-lock"></i> Save Encrypted Record</button></div>
  </div>
</div>
<script>
const HEALTH_CSRF = <?= json_encode($csrf) ?>;
const HEALTH_FILTER_RESIDENT = <?= $resident_filter ?>;
const HEALTH_FILTER_QUERY = <?= json_encode($search) ?>;
function closeHealthModal() {
  const modal = document.getElementById('healthRecordModal');
  modal.classList.remove('show');
  modal.style.visibility = 'hidden';
  modal.style.opacity = '0';
  modal.querySelector('.modal').style.transform = 'scale(.95)';
  document.body.style.overflow = '';
}
function showHealthModal() {
  const modal = document.getElementById('healthRecordModal');
  modal.classList.add('show');
  modal.style.visibility = 'visible';
  modal.style.opacity = '1';
  modal.querySelector('.modal').style.transform = 'scale(1)';
  document.body.style.overflow = 'hidden';
}
document.getElementById('healthRecordModal').addEventListener('click', event => {
  if (event.target.id === 'healthRecordModal') closeHealthModal();
});
function openHealthModal() {
  document.getElementById('healthRecordId').value = '';
  document.getElementById('healthResident').value = HEALTH_FILTER_RESIDENT || '';
  document.getElementById('healthType').value = 'vaccination';
  document.getElementById('healthItem').value = '';
  document.getElementById('healthDate').value = '';
  document.getElementById('healthDetails').value = '';
  document.getElementById('healthModalTitle').textContent = 'Add Health Record';
  showHealthModal();
}
function editHealthRecord(record) {
  document.getElementById('healthRecordId').value = record.health_record_id;
  document.getElementById('healthResident').value = record.resident_id;
  document.getElementById('healthType').value = record.record_type;
  document.getElementById('healthItem').value = record.item_name || '';
  document.getElementById('healthDate').value = record.event_date || '';
  document.getElementById('healthDetails').value = record.details || '';
  document.getElementById('healthModalTitle').textContent = 'Edit Health Record';
  showHealthModal();
}
async function saveHealthRecord() {
  const recordId = document.getElementById('healthRecordId').value;
  const data = {
    csrf_token: HEALTH_CSRF,
    resident_id: document.getElementById('healthResident').value,
    record_type: document.getElementById('healthType').value,
    item_name: document.getElementById('healthItem').value.trim(),
    event_date: document.getElementById('healthDate').value,
    details: document.getElementById('healthDetails').value.trim()
  };
  if (!data.resident_id || !data.item_name) { showToast('error', 'Select a resident and enter the item.'); return; }
  const action = recordId ? 'update' : 'create';
  if (recordId) data.health_record_id = recordId;
  const result = await apiRequest('/BRGYMS/health/api.php?action=' + action, data);
  if (!result.success) { showToast('error', result.message || 'Could not save health record.'); return; }
  showToast('success', result.message || 'Health record saved.');
  const params = new URLSearchParams();
  if (HEALTH_FILTER_RESIDENT) params.set('resident_id', HEALTH_FILTER_RESIDENT);
  if (HEALTH_FILTER_QUERY) params.set('q', HEALTH_FILTER_QUERY);
  window.location.href = 'index.php' + (params.toString() ? '?' + params.toString() : '');
}
<?php if ($resident_filter && (int)($_GET['add'] ?? 0) === 1): ?>
openHealthModal();
<?php endif; ?>
</script>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
