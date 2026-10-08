<?php
$page_title = 'Drawer Index';
$active_page = 'drawers';
$breadcrumb = ['Records', 'Drawer Index'];
require_once '../includes/auth_check.php';
require_permission('view_drawer_index');

$conn = getDBConnection();
require_once '../includes/drawer_index.php';
ensure_default_drawers($conn);
$csrf = generate_csrf_token();
$can_manage = can('manage_drawer_index');
$drawer_id = max(0, (int)($_GET['drawer'] ?? 0));
$search = trim(strip_tags((string)($_GET['q'] ?? '')));

$drawers = [];
$drawer_result = $conn->query(
  "SELECT d.drawer_id, d.drawer_name, COUNT(e.entry_id) AS entry_count,
      COALESCE(SUM(dr.status='STORED'),0) AS awaiting_count,
      COALESCE(SUM(dr.status='RELEASED'),0) AS claimed_count
     FROM tbl_storage_drawers d
     LEFT JOIN tbl_storage_entries e ON e.drawer_id=d.drawer_id
   LEFT JOIN tbl_document_requests dr ON dr.request_id=e.request_id
     GROUP BY d.drawer_id, d.drawer_name
     ORDER BY d.drawer_name"
);
if ($drawer_result) while ($drawer = $drawer_result->fetch_assoc()) $drawers[] = $drawer;

$selected_drawer = null;
$entries = [];
$search_results = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare(
        "SELECT e.entry_id, e.drawer_id, e.entry_label, e.entry_notes, e.resident_id, e.request_id,
          d.drawer_name, r.resident_code, dr.request_code, dr.status AS request_status, dr.received_by
         FROM tbl_storage_entries e
         JOIN tbl_storage_drawers d ON d.drawer_id=e.drawer_id
         LEFT JOIN tbl_residents r ON r.resident_id=e.resident_id
         LEFT JOIN tbl_document_requests dr ON dr.request_id=e.request_id
         WHERE e.entry_label LIKE ? OR e.entry_notes LIKE ?
         ORDER BY d.drawer_name, e.entry_label"
    );
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $search_results[] = $row;
    $stmt->close();
} elseif ($drawer_id > 0) {
    $stmt = $conn->prepare("SELECT drawer_id, drawer_name FROM tbl_storage_drawers WHERE drawer_id=?");
    $stmt->bind_param('i', $drawer_id);
    $stmt->execute();
    $selected_drawer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($selected_drawer) {
        $stmt = $conn->prepare(
                "SELECT e.entry_id, e.entry_label, e.entry_notes, e.resident_id, e.request_id,
                  r.resident_code, dr.request_code, dr.status AS request_status, dr.received_by
             FROM tbl_storage_entries e
             LEFT JOIN tbl_residents r ON r.resident_id=e.resident_id
             LEFT JOIN tbl_document_requests dr ON dr.request_id=e.request_id
             WHERE e.drawer_id=?
             ORDER BY e.entry_label"
        );
        $stmt->bind_param('i', $drawer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $entries[] = $row;
        $stmt->close();
    }
}

  function drawerEntryStatus($status) {
    if ($status === 'STORED') return ['Awaiting Pickup', 'warning'];
    if ($status === 'RELEASED') return ['Claimed', 'success'];
    if ($status === 'PRINTED') return ['Printed', 'info'];
    return ['Indexed', 'secondary'];
  }

$resident_options = $conn->query("SELECT resident_id, resident_code FROM tbl_residents ORDER BY resident_code");
$request_options = $conn->query("SELECT request_id, request_code FROM tbl_document_requests ORDER BY request_id DESC");
require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-boxes-stacked"></i> Drawer Index</h2>
    <p>Physical filing cabinet reference</p>
  </div>
  <?php if ($can_manage): ?>
  <button type="button" class="btn btn-primary" onclick="openDrawerModal()">
    <i class="fas fa-plus"></i> Add Drawer
  </button>
  <?php endif; ?>
</div>

<form method="GET" class="d-flex gap-2 align-center mb-3 flex-wrap">
  <?php if ($selected_drawer && $search === ''): ?>
  <input type="hidden" name="drawer" value="<?= (int)$drawer_id ?>">
  <?php endif; ?>
  <div class="search-box" style="flex:1;min-width:260px;max-width:560px;">
    <i class="fas fa-search"></i>
    <input type="search" name="q" class="form-control" style="padding-left:36px;"
           value="<?= sanitize_output($search) ?>" maxlength="120"
           placeholder="Search entries across all drawers..." aria-label="Search all drawers">
  </div>
  <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i> Search</button>
  <?php if ($search !== ''): ?>
  <a href="<?= $selected_drawer ? '?drawer='.(int)$drawer_id : 'index.php' ?>" class="btn btn-secondary">Clear</a>
  <?php endif; ?>
</form>

<?php if ($search !== ''): ?>
<div class="card mb-3">
  <div class="card-header"><h3><i class="fas fa-magnifying-glass"></i> Search Results</h3><span class="badge badge-secondary"><?= count($search_results) ?></span></div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Entry</th><th>Pickup Status</th><th>Received By</th><th>Notes</th><th>Drawer</th><th>Linked Record</th><th></th></tr></thead>
        <tbody>
        <?php if ($search_results): foreach ($search_results as $entry): ?>
          <?php [$entry_status_label, $entry_status_class] = drawerEntryStatus($entry['request_status'] ?? ''); ?>
          <tr>
            <td><strong><?= sanitize_output($entry['entry_label']) ?></strong></td>
            <td><span class="badge badge-<?= $entry_status_class ?>"><?= sanitize_output($entry_status_label) ?></span></td>
            <td><?= sanitize_output($entry['received_by'] ?: '—') ?></td>
            <td><?= nl2br(sanitize_output($entry['entry_notes'] ?: '—')) ?></td>
            <td><a href="?drawer=<?= (int)$entry['drawer_id'] ?>"><?= sanitize_output($entry['drawer_name']) ?></a></td>
            <td><?= sanitize_output($entry['resident_code'] ?: $entry['request_code'] ?: '—') ?></td>
            <td><?php if ($can_manage && !in_array($entry['request_status'] ?? '', ['STORED','RELEASED'], true)): ?><button type="button" class="btn btn-icon btn-sm" title="Edit entry" onclick='editEntry(<?= json_encode(['entry_id'=>(int)$entry['entry_id'],'drawer_id'=>(int)$entry['drawer_id'],'entry_label'=>$entry['entry_label'],'entry_notes'=>$entry['entry_notes'],'resident_id'=>$entry['resident_id'],'request_id'=>$entry['request_id']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button><?php elseif (in_array($entry['request_status'] ?? '', ['STORED','RELEASED'], true)): ?><span class="text-muted">Linked document</span><?php endif; ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7"><div class="empty-state"><i class="fas fa-folder-open"></i><p>No matching filing entries.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php else: ?>
<div class="d-flex justify-between align-center mb-2 flex-wrap gap-2">
  <h3 style="margin:0;"><?= $selected_drawer ? sanitize_output($selected_drawer['drawer_name']) : 'Drawers' ?></h3>
  <?php if ($selected_drawer): ?><a class="btn btn-secondary btn-sm" href="index.php"><i class="fas fa-arrow-left"></i> All Drawers</a><?php endif; ?>
</div>
<?php if (!$selected_drawer): ?>
  <?php if ($drawers): ?>
  <div class="card">
    <div class="card-body" style="padding:0;">
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Drawer Folder</th><th>Index Entries</th><th>Awaiting Pickup</th><th>Claimed</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($drawers as $drawer): ?>
            <tr>
              <td><a href="?drawer=<?= (int)$drawer['drawer_id'] ?>"><i class="fas fa-folder" style="margin-right:8px;color:#c18a24;"></i><strong><?= sanitize_output($drawer['drawer_name']) ?></strong></a></td>
              <td><?= number_format((int)$drawer['entry_count']) ?></td>
              <td><span class="badge badge-warning"><?= number_format((int)$drawer['awaiting_count']) ?></span></td>
              <td><span class="badge badge-success"><?= number_format((int)$drawer['claimed_count']) ?></span></td>
              <td><?php if ($can_manage): ?><button type="button" class="btn btn-icon btn-sm" title="Rename drawer" onclick='renameDrawer(<?= (int)$drawer['drawer_id'] ?>, <?= json_encode($drawer['drawer_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="empty-state"><i class="fas fa-boxes-stacked"></i><h4>No drawers yet</h4><p>Create a drawer folder to start the physical filing index.</p></div>
  <?php endif; ?>
<?php elseif ($selected_drawer): ?>
<div class="card">
  <div class="card-header">
    <h3><i class="fas fa-folder-open" style="color:#c18a24;"></i> Filing Index</h3>
    <?php if ($can_manage): ?><button type="button" class="btn btn-primary btn-sm" onclick="openEntryModal()"><i class="fas fa-plus"></i> Add Index Entry</button><?php endif; ?>
  </div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Folder / Item</th><th>Pickup Status</th><th>Received By</th><th>Notes</th><th>Linked Record</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if ($entries): foreach ($entries as $entry): ?>
          <?php [$entry_status_label, $entry_status_class] = drawerEntryStatus($entry['request_status'] ?? ''); ?>
          <tr>
            <td><strong><?= sanitize_output($entry['entry_label']) ?></strong></td>
            <td><span class="badge badge-<?= $entry_status_class ?>"><?= sanitize_output($entry_status_label) ?></span></td>
            <td><?= sanitize_output($entry['received_by'] ?: '—') ?></td>
            <td><?= nl2br(sanitize_output($entry['entry_notes'] ?: '—')) ?></td>
            <td><?= sanitize_output($entry['resident_code'] ?: $entry['request_code'] ?: '—') ?></td>
            <td><?php if ($can_manage && !in_array($entry['request_status'] ?? '', ['STORED','RELEASED'], true)): ?><div class="d-flex gap-2"><button type="button" class="btn btn-icon btn-sm" title="Edit entry" onclick='editEntry(<?= json_encode(['entry_id'=>(int)$entry['entry_id'],'drawer_id'=>(int)$drawer_id,'entry_label'=>$entry['entry_label'],'entry_notes'=>$entry['entry_notes'],'resident_id'=>$entry['resident_id'],'request_id'=>$entry['request_id']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button><button type="button" class="btn btn-icon btn-sm" title="Remove entry" onclick="deleteEntry(<?= (int)$entry['entry_id'] ?>)"><i class="fas fa-trash"></i></button></div><?php elseif (in_array($entry['request_status'] ?? '', ['STORED','RELEASED'], true)): ?><span class="text-muted">Linked document</span><?php endif; ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="6"><div class="empty-state"><i class="fas fa-folder-open"></i><p>No index entries in this drawer yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($can_manage): ?>
<div class="modal-overlay" id="drawerModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><h4 id="drawerModalTitle">Add Drawer</h4><button type="button" class="modal-close" onclick="closeModal('drawerModal')"><i class="fas fa-xmark"></i></button></div>
    <div class="modal-body"><input type="hidden" id="drawerEditId"><label for="drawerName">Drawer Name</label><input id="drawerName" class="form-control" maxlength="100" placeholder="e.g. Drawer 1" required></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('drawerModal')">Cancel</button><button type="button" class="btn btn-primary" onclick="saveDrawer()"><i class="fas fa-save"></i> Save</button></div>
  </div>
</div>

<div class="modal-overlay" id="entryModal">
  <div class="modal" style="max-width:600px;">
    <div class="modal-header"><h4 id="entryModalTitle">Add Index Entry</h4><button type="button" class="modal-close" onclick="closeModal('entryModal')"><i class="fas fa-xmark"></i></button></div>
    <div class="modal-body">
      <input type="hidden" id="entryEditId"><input type="hidden" id="entryDrawerId" value="<?= (int)$drawer_id ?>">
      <div class="form-group"><label for="entryLabel">Folder / Item Label <span class="req">*</span></label><input id="entryLabel" class="form-control" maxlength="200" required placeholder="e.g. Barangay Reports 2024"></div>
      <div class="form-group"><label for="entryNotes">Notes</label><textarea id="entryNotes" class="form-control" rows="3" maxlength="2000" placeholder="Optional date range or location details"></textarea></div>
      <div class="form-group"><label for="entryResident">Link to Resident Record <small>(optional)</small></label><select id="entryResident" class="form-control"><option value="">No resident link</option><?php if ($resident_options): while ($option = $resident_options->fetch_assoc()): ?><option value="<?= (int)$option['resident_id'] ?>"><?= sanitize_output($option['resident_code']) ?></option><?php endwhile; endif; ?></select></div>
      <div class="form-group"><label for="entryRequest">Link to Document Request <small>(optional)</small></label><select id="entryRequest" class="form-control"><option value="">No document link</option><?php if ($request_options): while ($option = $request_options->fetch_assoc()): ?><option value="<?= (int)$option['request_id'] ?>"><?= sanitize_output($option['request_code']) ?></option><?php endwhile; endif; ?></select></div>
      <small style="color:var(--text-muted);">Choose at most one digital record link. Physical-only entries need no link.</small>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('entryModal')">Cancel</button><button type="button" class="btn btn-primary" onclick="saveEntry()"><i class="fas fa-save"></i> Save Entry</button></div>
  </div>
</div>
<script>
const DRAWER_CSRF = <?= json_encode($csrf) ?>;
const DRAWER_CURRENT_ID = <?= (int)$drawer_id ?>;
const DRAWER_CURRENT_SEARCH = <?= json_encode($search) ?>;
const DRAWER_API = '/drawers/api.php?action=';

function openDrawerModal() {
  document.getElementById('drawerEditId').value = '';
  document.getElementById('drawerName').value = '';
  document.getElementById('drawerModalTitle').textContent = 'Add Drawer';
  openModal('drawerModal');
}
function renameDrawer(id, name) {
  document.getElementById('drawerEditId').value = id;
  document.getElementById('drawerName').value = name;
  document.getElementById('drawerModalTitle').textContent = 'Rename Drawer';
  openModal('drawerModal');
}
async function saveDrawer() {
  const drawerName = document.getElementById('drawerName').value.trim();
  if (!drawerName) { showToast('error', 'Enter a drawer name.'); return; }
  const editId = document.getElementById('drawerEditId').value;
  const data = { csrf_token: DRAWER_CSRF, drawer_name: drawerName };
  const action = editId ? 'rename_drawer' : 'create_drawer';
  if (editId) data.drawer_id = editId;
  const result = await apiRequest(DRAWER_API + action, data);
  if (!result.success) { showToast('error', result.message || 'Could not save drawer.'); return; }
  showToast('success', editId ? 'Drawer renamed.' : 'Drawer created.');
  window.location.href = editId && DRAWER_CURRENT_ID ? `?drawer=${DRAWER_CURRENT_ID}` : 'index.php';
}
function openEntryModal() {
  document.getElementById('entryEditId').value = '';
  document.getElementById('entryDrawerId').value = DRAWER_CURRENT_ID;
  document.getElementById('entryLabel').value = '';
  document.getElementById('entryNotes').value = '';
  document.getElementById('entryResident').value = '';
  document.getElementById('entryRequest').value = '';
  document.getElementById('entryModalTitle').textContent = 'Add Index Entry';
  openModal('entryModal');
}
function editEntry(entry) {
  document.getElementById('entryEditId').value = entry.entry_id;
  document.getElementById('entryDrawerId').value = entry.drawer_id;
  document.getElementById('entryLabel').value = entry.entry_label || '';
  document.getElementById('entryNotes').value = entry.entry_notes || '';
  document.getElementById('entryResident').value = entry.resident_id || '';
  document.getElementById('entryRequest').value = entry.request_id || '';
  document.getElementById('entryModalTitle').textContent = 'Edit Index Entry';
  openModal('entryModal');
}
async function saveEntry() {
  const label = document.getElementById('entryLabel').value.trim();
  if (!label) { showToast('error', 'Enter an entry label.'); return; }
  const entryId = document.getElementById('entryEditId').value;
  const residentId = document.getElementById('entryResident').value;
  const requestId = document.getElementById('entryRequest').value;
  if (residentId && requestId) { showToast('error', 'Choose only one linked record.'); return; }
  const data = {
    csrf_token: DRAWER_CSRF,
    drawer_id: document.getElementById('entryDrawerId').value,
    entry_label: label,
    entry_notes: document.getElementById('entryNotes').value.trim(),
    resident_id: residentId,
    request_id: requestId
  };
  const action = entryId ? 'update_entry' : 'create_entry';
  if (entryId) data.entry_id = entryId;
  const result = await apiRequest(DRAWER_API + action, data);
  if (!result.success) { showToast('error', result.message || 'Could not save entry.'); return; }
  showToast('success', entryId ? 'Entry updated.' : 'Entry added.');
  window.location.href = `?drawer=${encodeURIComponent(data.drawer_id)}`;
}
async function deleteEntry(entryId) {
  if (!confirm('Remove this filing index entry?')) return;
  const result = await apiRequest(DRAWER_API + 'delete_entry', { csrf_token: DRAWER_CSRF, entry_id: entryId });
  if (!result.success) { showToast('error', result.message || 'Could not remove entry.'); return; }
  showToast('success', 'Entry removed.');
  window.location.reload();
}
</script>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
