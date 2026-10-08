<?php
$page_title  = 'Case Reports';
$active_page = 'blotter';
$breadcrumb  = ['Barangay Case Reports'];
require_once '../includes/auth_check.php';
require_permission('view_blotter');

$conn   = getDBConnection();
$csrf   = generate_csrf_token();
$filter = sanitize_input($_GET['status'] ?? '');
$code_q = sanitize_input($_GET['q'] ?? '');

$per_page = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$where_parts = [];
if ($filter) $where_parts[] = "b.resolution_status = '" . $conn->real_escape_string($filter) . "'";
if ($code_q) $where_parts[] = "b.case_number LIKE '%" . $conn->real_escape_string($code_q) . "%'";
$where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$total       = (int)$conn->query("SELECT COUNT(*) FROM tbl_blotter b $where")->fetch_row()[0];
$total_pages = max(1, ceil($total / $per_page));

$sql = "SELECT b.*, r.first_name, r.last_name, u.full_name as filed_by_name
        FROM tbl_blotter b
  LEFT JOIN tbl_residents r ON b.complainant_id = r.resident_id
        LEFT JOIN tbl_users u ON b.filed_by = u.user_id
        $where ORDER BY b.filed_at DESC LIMIT $per_page OFFSET $offset";
$cases = $conn->query($sql);

// Status counts
$counts = [];
foreach (['Active','Under Mediation','Resolved','Referred'] as $s) {
    $esc = $conn->real_escape_string($s);
    $counts[$s] = (int)$conn->query("SELECT COUNT(*) FROM tbl_blotter WHERE resolution_status='$esc'")->fetch_row()[0];
}

// Residents for dropdown
$residents = $conn->query(
    "SELECT resident_id, first_name, last_name FROM tbl_residents WHERE is_archived=0 ORDER BY last_name"
);

require_once '../includes/header.php';
?>

<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-book-open"></i> Barangay Case Reports</h2>
    <p>Recording of complaints and disputes filed within <strong>Barangay San Isidro</strong> jurisdiction only. Does not constitute a police blotter.</p>
  </div>
  <?php if (can('add_blotter')): ?>
  <button class="btn btn-primary" onclick="openModal('modalAddCase')">
    <i class="fas fa-plus"></i> New Case Report
  </button>
  <?php endif; ?>
</div>

<!-- Disclaimer Banner -->
<div class="card mb-3" style="background:linear-gradient(135deg,#fef9e7,#fdebd0);border:1.5px solid #f9ca24;border-radius:12px;">
  <div class="card-body" style="padding:12px 20px;display:flex;align-items:center;gap:14px;">
    <i class="fas fa-triangle-exclamation" style="color:#d68910;font-size:1.4rem;flex-shrink:0;"></i>
    <div style="font-size:.85rem;color:#7d6608;">
      <strong>Barangay Jurisdiction Notice:</strong>
      This Case Report covers barangay-level complaints and disputes only (RA 7160 — Katarungang Pambarangay).
      Cases requiring law enforcement action must be referred to the Philippine National Police (PNP).
      This is <u>not</u> an official PNP Blotter Record.
    </div>
  </div>
</div>

<!-- Status Tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <a href="?" class="btn <?= !$filter?'btn-primary':'btn-secondary' ?>">
    All <span class="badge badge-secondary" style="margin-left:4px;"><?= $total ?></span>
  </a>
  <?php
  $tab_colors = ['Active'=>'btn-danger','Under Mediation'=>'btn-warning','Resolved'=>'btn-success','Referred'=>'btn-primary'];
  foreach ($counts as $s => $c):
  ?>
  <a href="?status=<?= urlencode($s) ?>" class="btn <?= $filter===$s?$tab_colors[$s]:'btn-secondary' ?>">
    <?= sanitize_output($s) ?>
    <span class="badge badge-secondary" style="margin-left:4px;"><?= $c ?></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">
    <h3><i class="fas fa-list"></i> Case List</h3>
    <div class="search-box" style="max-width:260px;">
      <i class="fas fa-search"></i>
            <input type="text" id="blotterSearch" placeholder="Search complainant or respondent..."
             class="form-control" style="padding-left:34px;"
              oninput="filterBlotterParties(this.value)">
    </div>
  </div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrapper">
      <table id="blotterTable">
        <thead>
          <tr>
            <th>#</th><th>Case No.</th><th>Complainant</th><th>Respondent</th>
            <th>Case Type</th><th>Case Date</th><th>Hearing Date</th>
            <th>Status</th><th>Filed</th><th>Filed By</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($cases && $cases->num_rows > 0):
          $i = $offset + 1;
          while ($b = $cases->fetch_assoc()):
            $complainant_name = $b['complainant_id'] !== null
              ? aes_decrypt($b['last_name']) . ', ' . aes_decrypt($b['first_name'])
              : aes_decrypt($b['complainant_name'] ?? '');
            $complainant_contact = $b['complainant_id'] === null
              ? aes_decrypt($b['complainant_contact'] ?? '')
              : '';
            $sc = ['Active'=>'danger','Under Mediation'=>'warning','Resolved'=>'success','Referred'=>'info'];
            $st = $b['resolution_status'];
        ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><code style="font-size:.8rem;"><?= sanitize_output($b['case_number']) ?></code></td>
            <td><?= sanitize_output($complainant_name) ?></td>
            <td><?= sanitize_output($b['respondent_name'] ?: '—') ?></td>
            <td>
              <span class="badge badge-secondary"><?= sanitize_output($b['case_type']) ?></span>
            </td>
            <td><?= date('M d, Y', strtotime($b['case_date'])) ?></td>
            <td>
              <?= $b['hearing_date'] ? date('M d, Y', strtotime($b['hearing_date'])) : '<span style="color:var(--text-muted)">—</span>' ?>
            </td>
            <td>
              <span class="badge badge-<?= $sc[$st] ?? 'secondary' ?>">
                <?php
                $icons = ['Active'=>'fa-circle-dot','Under Mediation'=>'fa-handshake','Resolved'=>'fa-circle-check','Referred'=>'fa-arrow-up-right-from-square'];
                ?>
                <i class="fas <?= $icons[$st] ?? 'fa-circle' ?>"></i>
                <?= sanitize_output($st) ?>
              </span>
            </td>
            <td style="font-size:.8rem;"><?= date('M d, Y', strtotime($b['filed_at'])) ?></td>
            <td style="font-size:.8rem;">
              <?php if ($b['filed_by_name']): ?>
                <span style="display:flex;align-items:center;gap:4px;">
                  <i class="fas fa-user-tie" style="color:var(--primary);font-size:.75rem;"></i>
                  <?= sanitize_output($b['filed_by_name']) ?>
                </span>
              <?php else: ?>
                <span style="color:var(--text-muted);">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-2">
                <button class="btn btn-icon btn-sm" title="View Details"
                  onclick='viewCase(<?= json_encode([
                    "case_number"  => $b['case_number'],
                    "complainant"  => $complainant_name,
                    "complainant_address" => $b['complainant_address'] ?? '',
                    "complainant_contact" => $complainant_contact,
                    "respondent"   => $b['respondent_name'],
                    "case_type"    => $b['case_type'],
                    "description"  => $b['case_description'],
                    "case_date"    => $b['case_date'],
                    "location"     => $b['case_location'],
                    "status"       => $b['resolution_status'],
                    "notes"        => $b['resolution_notes'],
                    "referred_to"  => $b['referred_to'],
                    "hearing_date" => $b['hearing_date'],
                    "filed"        => $b['filed_at'],
                    "filed_by"     => $b['filed_by_name']
                  ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                  <i class="fas fa-eye"></i>
                </button>
                <?php if (can('update_blotter')): ?>
                <button class="btn btn-icon btn-sm" title="Update Status"
                  onclick="updateCase(<?= $b['case_id'] ?>,'<?= sanitize_output($st) ?>','<?= sanitize_output($b['referred_to'] ?? '') ?>','<?= sanitize_output($b['hearing_date'] ?? '') ?>')">
                  <i class="fas fa-pen-to-square"></i>
                </button>
                <?php endif; ?>
                <button class="btn btn-icon btn-sm" title="Print Case Report"
                  onclick="printCase(<?= $b['case_id'] ?>)">
                  <i class="fas fa-print"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="11">
            <div class="empty-state">
              <i class="fas fa-folder-open"></i>
              <p>No case reports found. <?= can('add_blotter') ? 'Click "New Case Report" to add one.' : '' ?></p>
            </div>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination" style="padding:16px 22px;">
      <?php for ($p=1; $p<=$total_pages; $p++): ?>
        <a href="?status=<?= urlencode($filter) ?>&page=<?= $p ?>"
           class="page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── ADD CASE MODAL ───────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalAddCase">
  <div class="modal" style="max-width:700px;">
    <div class="modal-header">
      <h4><i class="fas fa-plus"></i> New Barangay Case Report</h4>
      <button class="modal-close" onclick="closeModal('modalAddCase')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div style="background:#fef9e7;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.82rem;color:#7d6608;">
        <i class="fas fa-triangle-exclamation"></i>
        <strong>Note:</strong> This is a Barangay-level Case Report only. For criminal cases requiring police action, refer to PNP.
      </div>
      <form id="addCaseForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="form-grid">

          <div class="form-group" style="grid-column:1/-1;">
            <label>Complainant Type <span class="req">*</span></label>
            <select name="complainant_type" id="complainantType" class="form-control" onchange="toggleComplainantType()">
              <option value="resident">Registered Resident</option>
              <option value="non_resident">Non-Resident</option>
            </select>
          </div>

          <div class="form-group" id="residentComplainantFields" style="grid-column:1/-1;">
            <label>Complainant (Resident) <span class="req">*</span></label>
            <div class="blotter-resident-picker">
              <input type="text" id="complainantSearch" class="form-control"
                     placeholder="Search complainant by name or resident code..." autocomplete="off"
                     oninput="filterBlotterResidents('complainant', this.value)"
                     onfocus="filterBlotterResidents('complainant', this.value)">
              <input type="hidden" name="complainant_id" id="complainantId" required>
              <div id="complainantResults" class="blotter-resident-results"></div>
            </div>
            <small id="complainantHint" class="blotter-picker-hint">Select the resident who filed the complaint.</small>
          </div>

          <div id="nonResidentComplainantFields" class="form-grid" style="grid-column:1/-1;display:none;">
            <div class="form-group">
              <label>Complainant Name <span class="req">*</span></label>
              <input type="text" name="complainant_name" id="nonResidentName" class="form-control" maxlength="200" disabled>
            </div>
            <div class="form-group">
              <label>Contact Number <span class="req">*</span></label>
              <input type="text" name="complainant_contact" id="nonResidentContact" class="form-control" maxlength="50" inputmode="tel" disabled>
            </div>
            <div class="form-group" style="grid-column:1/-1;">
              <label>Address <span class="req">*</span></label>
              <textarea name="complainant_address" id="nonResidentAddress" class="form-control" rows="2" maxlength="500" disabled></textarea>
            </div>
          </div>

          <div class="form-group" style="grid-column:1/-1;">
            <label>Respondent (Person Being Complained Against) <span class="req">*</span></label>
            <div class="blotter-resident-picker">
              <input type="text" id="respondentSearch" class="form-control"
                placeholder="Search respondent by name or resident code..." autocomplete="off"
                     oninput="filterBlotterResidents('respondent', this.value)"
                     onfocus="filterBlotterResidents('respondent', this.value)">
              <input type="hidden" name="respondent_resident_id" id="respondentResidentId" required>
              <input type="hidden" name="respondent_name" id="respondentName" required>
              <div id="respondentResults" class="blotter-resident-results"></div>
            </div>
            <small id="respondentHint" class="blotter-picker-hint">The respondent must be selected from an active resident record.</small>
          </div>

          <div class="form-group">
            <label>Case Type <span class="req">*</span></label>
            <select name="case_type" class="form-control" required>
              <option value="">-- Select Type --</option>
              <optgroup label="Disputes">
                <option>Land / Property Dispute</option>
                <option>Neighborhood Dispute</option>
                <option>Family / Domestic Dispute</option>
                <option>Debt / Money Dispute</option>
              </optgroup>
              <optgroup label="Complaints">
                <option>Noise / Disturbance Complaint</option>
                <option>Trespassing Complaint</option>
                <option>Verbal Abuse / Threat</option>
                <option>Physical Altercation</option>
              </optgroup>
              <optgroup label="Community Concerns">
                <option>Vandalism / Property Damage</option>
                <option>Illegal Structures / Encroachment</option>
                <option>Stray Animals / Sanitation Issue</option>
                <option>Other Barangay Concern</option>
              </optgroup>
            </select>
          </div>

          <div class="form-group">
            <label>Date of Incident <span class="req">*</span></label>
            <input type="date" name="case_date" class="form-control" required max="<?= date('Y-m-d') ?>">
          </div>

          <div class="form-group" style="grid-column:1/-1;">
            <label>Location of Incident <span class="req">*</span></label>
            <input type="text" name="case_location" class="form-control" required maxlength="255"
                   placeholder="e.g. Purok 3, Barangay San Isidro">
          </div>

          <div class="form-group" style="grid-column:1/-1;">
            <label>Description / Narrative <span class="req">*</span></label>
            <textarea name="case_description" class="form-control" required rows="4"
                      placeholder="Detailed account of the complaint or dispute as stated by the complainant..."></textarea>
          </div>

          <div class="form-group">
            <label>Scheduled Hearing Date</label>
            <input type="date" name="hearing_date" class="form-control" min="<?= date('Y-m-d') ?>">
          </div>

          <div class="form-group">
            <label>Initial Status</label>
            <select name="resolution_status" class="form-control">
              <option value="Active">Active — Newly Filed</option>
              <option value="Under Mediation">Under Mediation</option>
            </select>
          </div>

        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalAddCase')">Cancel</button>
      <button class="btn btn-primary" onclick="submitCase()">
        <i class="fas fa-save"></i> File Case Report
      </button>
    </div>
  </div>
</div>

<!-- ── VIEW CASE MODAL ──────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalViewCase">
  <div class="modal" style="max-width:640px;">
    <div class="modal-header">
      <h4><i class="fas fa-eye"></i> Case Report Details</h4>
      <button class="modal-close" onclick="closeModal('modalViewCase')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="viewCaseContent"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalViewCase')">Close</button>
    </div>
  </div>
</div>

<!-- ── UPDATE STATUS MODAL ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalUpdateCase">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <h4><i class="fas fa-pen-to-square"></i> Update Case Status</h4>
      <button class="modal-close" onclick="closeModal('modalUpdateCase')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <form id="updateCaseForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="case_id" id="updateCaseId">
        <div class="form-group">
          <label>Status <span class="req">*</span></label>
          <select name="resolution_status" id="updateStatus" class="form-control" onchange="toggleReferral()">
            <option value="Active">Active — Ongoing</option>
            <option value="Under Mediation">Under Mediation — Hearing Scheduled</option>
            <option value="Resolved">Resolved — Case Closed</option>
            <option value="Referred">Referred — Escalated to PNP/Court</option>
          </select>
        </div>
        <div class="form-group mt-2" id="referralGroup" style="display:none;">
          <label>Referred To <span class="req">*</span></label>
          <input type="text" name="referred_to" id="referredTo" class="form-control"
                 placeholder="e.g. Ilagan City Police Station">
        </div>
        <div class="form-group mt-2">
          <label>Hearing Date</label>
          <input type="date" name="hearing_date" id="updateHearingDate" class="form-control">
        </div>
        <div class="form-group mt-2">
          <label>Resolution Notes</label>
          <textarea name="resolution_notes" class="form-control" rows="3"
                    placeholder="Summary of outcome, agreement reached, or reason for referral..."></textarea>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalUpdateCase')">Cancel</button>
      <button class="btn btn-primary" onclick="submitUpdate()">
        <i class="fas fa-save"></i> Save Update
      </button>
    </div>
  </div>
</div>

<style>
.blotter-resident-picker { position:relative; }
.blotter-resident-results { display:none;position:absolute;top:100%;left:0;right:0;z-index:200;background:#fff;
  border:1px solid var(--primary-light);border-top:none;border-radius:0 0 8px 8px;max-height:210px;
  overflow-y:auto;box-shadow:0 8px 20px rgba(0,0,0,.12); }
.blotter-resident-option { display:block;width:100%;padding:10px 12px;border:0;border-bottom:1px solid var(--border);
  background:#fff;text-align:left;cursor:pointer;color:var(--text);font-size:.84rem; }
.blotter-resident-option:hover { background:var(--primary-xlight); }
.blotter-resident-option small { display:block;color:var(--text-muted);margin-top:3px;font-size:.74rem; }
.blotter-resident-empty { padding:12px;color:var(--text-muted);font-size:.8rem; }
.blotter-picker-hint { display:block;margin-top:4px;color:var(--text-muted);font-size:.75rem; }
</style>
<script>
const blotterResidents = <?php
  $resident_options = [];
  $residents->data_seek(0);
  while ($res = $residents->fetch_assoc()) {
      $resident_options[] = [
          'id' => (int)$res['resident_id'],
          'code' => $res['resident_code'] ?? '',
          'name' => aes_decrypt($res['last_name']).', '.aes_decrypt($res['first_name'])
      ];
  }
  echo json_encode($resident_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

function escapeBlotterText(value) {
  return String(value || '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
}

function filterBlotterResidents(type, query) {
  const q = (query || '').trim().toLowerCase();
  const resultBox = document.getElementById(type + 'Results');
  const matches = blotterResidents.filter(r =>
    `${r.name} ${r.code}`.toLowerCase().includes(q)
  ).slice(0, 30);
  if (!matches.length) {
    resultBox.innerHTML = q
      ? '<div class="blotter-resident-empty">No active resident found. The respondent must have a barangay resident record.</div>'
      : '<div class="blotter-resident-empty">No residents available.</div>';
    resultBox.style.display = 'block';
    return;
  }
  resultBox.innerHTML = matches.map(r => `<button type="button" class="blotter-resident-option"
    onclick="selectBlotterResident('${type}', ${r.id})">
    <strong>${escapeBlotterText(r.name)}</strong><small>${escapeBlotterText(r.code)}</small>
  </button>`).join('');
  resultBox.style.display = 'block';
}

function selectBlotterResident(type, id) {
  const resident = blotterResidents.find(r => r.id === Number(id));
  if (!resident) return;
  document.getElementById(type + 'Search').value = `[${resident.code}] ${resident.name}`;
  if (type === 'complainant') document.getElementById('complainantId').value = resident.id;
  else {
    document.getElementById('respondentResidentId').value = resident.id;
    document.getElementById('respondentName').value = resident.name;
  }
  document.getElementById(type + 'Results').style.display = 'none';
  document.getElementById(type + 'Hint').textContent = 'Selected: ' + resident.name;
}

function toggleComplainantType() {
  const nonResident = document.getElementById('complainantType').value === 'non_resident';
  const residentFields = document.getElementById('residentComplainantFields');
  const manualFields = document.getElementById('nonResidentComplainantFields');
  const residentId = document.getElementById('complainantId');
  residentFields.style.display = nonResident ? 'none' : '';
  manualFields.style.display = nonResident ? 'grid' : 'none';
  residentId.required = !nonResident;
  if (nonResident) residentId.value = '';
  ['nonResidentName', 'nonResidentContact', 'nonResidentAddress'].forEach(id => {
    const input = document.getElementById(id);
    input.disabled = !nonResident;
    input.required = nonResident;
  });
}

document.getElementById('respondentSearch')?.addEventListener('input', function () {
  document.getElementById('respondentResidentId').value = '';
  document.getElementById('respondentName').value = '';
  document.getElementById('respondentHint').textContent = 'Select a matching active resident from the results.';
});
document.addEventListener('click', event => {
  ['complainant', 'respondent'].forEach(type => {
    const results = document.getElementById(type + 'Results');
    const input = document.getElementById(type + 'Search');
    if (results && input && !results.contains(event.target) && event.target !== input) results.style.display = 'none';
  });
});

function filterBlotterParties(query) {
  const q = (query || '').toLowerCase().trim();
  document.querySelectorAll('#blotterTable tbody tr').forEach(row => {
    const complainant = row.cells[2]?.textContent.toLowerCase() || '';
    const respondent = row.cells[3]?.textContent.toLowerCase() || '';
    row.style.display = !q || complainant.includes(q) || respondent.includes(q) ? '' : 'none';
  });
}

// ── Add Case ────────────────────────────────────────────────────────────────
async function submitCase() {
  const form = document.getElementById('addCaseForm');
  const data = Object.fromEntries(new FormData(form));
  const hasComplainant = data.complainant_type === 'non_resident'
    ? data.complainant_name && data.complainant_address && data.complainant_contact
    : data.complainant_id;
  if (!hasComplainant || !data.respondent_resident_id || !data.respondent_name || !data.case_type || !data.case_date || !data.case_description || !data.case_location) {
    showToast('error','Please fill in all required fields.'); return;
  }
  const res = await apiRequest('/BRGYMS/blotter/api.php?action=add', data);
  if (res.success) {
    showToast('success','Case Report filed! Case No: ' + res.case_number);
    closeModal('modalAddCase');
    setTimeout(() => location.reload(), 1500);
  } else showToast('error', res.message || 'Failed to file case.');
}

// ── View Case ───────────────────────────────────────────────────────────────
function viewCase(d) {
  const sc = {Active:'danger','Under Mediation':'warning',Resolved:'success',Referred:'info'};

  // Status stepper — 4 stages
  const stages = ['Active','Under Mediation','Resolved','Referred'];
  const stageIcons = {
    'Active':          'fa-circle-dot',
    'Under Mediation': 'fa-handshake',
    'Resolved':        'fa-circle-check',
    'Referred':        'fa-arrow-up-right-from-square'
  };
  const stageColors = {
    'Active':          '#e74c3c',
    'Under Mediation': '#f39c12',
    'Resolved':        '#27ae60',
    'Referred':        '#2980b9'
  };
  const currentIdx = stages.indexOf(d.status);

  let stepperHtml = `<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding:16px;background:#f8f9fa;border-radius:10px;overflow-x:auto;">`;
  stages.forEach((stage, idx) => {
    const isDone    = idx < currentIdx;
    const isCurrent = idx === currentIdx;
    const color     = isCurrent ? stageColors[stage] : (isDone ? '#27ae60' : '#ccc');
    const bgColor   = isCurrent ? `${stageColors[stage]}18` : (isDone ? '#eafaf1' : '#fff');
    const border    = isCurrent ? `2px solid ${stageColors[stage]}` : (isDone ? '2px solid #27ae60' : '2px solid #ddd');

    stepperHtml += `
      <div style="display:flex;flex-direction:column;align-items:center;gap:6px;flex:1;min-width:80px;">
        <div style="width:42px;height:42px;border-radius:50%;background:${bgColor};border:${border};
                    display:flex;align-items:center;justify-content:center;position:relative;">
          <i class="fas ${isDone ? 'fa-check' : stageIcons[stage]}" style="color:${color};font-size:1rem;"></i>
          ${isCurrent ? `<span style="position:absolute;top:-6px;right:-6px;width:12px;height:12px;background:${stageColors[stage]};border-radius:50%;border:2px solid #fff;"></span>` : ''}
        </div>
        <span style="font-size:.7rem;font-weight:${isCurrent?'700':'400'};color:${color};text-align:center;line-height:1.3;">${stage}</span>
      </div>`;

    // Connector line between steps
    if (idx < stages.length - 1) {
      const lineColor = idx < currentIdx ? '#27ae60' : '#ddd';
      stepperHtml += `<div style="flex:0 0 24px;height:2px;background:${lineColor};margin-bottom:20px;"></div>`;
    }
  });
  stepperHtml += `</div>`;

  document.getElementById('viewCaseContent').innerHTML = `
    ${stepperHtml}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
      <div><strong>Case Number</strong><br><code>${d.case_number}</code></div>
      <div><strong>Status</strong><br><span class="badge badge-${sc[d.status]||'secondary'}">${d.status}</span></div>
      <div><strong>Complainant</strong><br>${escapeBlotterText(d.complainant)||'—'}</div>
      ${d.complainant_address ? `<div><strong>Complainant Address</strong><br>${escapeBlotterText(d.complainant_address)}</div>` : ''}
      ${d.complainant_contact ? `<div><strong>Complainant Contact</strong><br>${escapeBlotterText(d.complainant_contact)}</div>` : ''}
      <div><strong>Respondent</strong><br>${d.respondent||'—'}</div>
      <div><strong>Case Type</strong><br>${d.case_type||'—'}</div>
      <div><strong>Date of Incident</strong><br>${d.case_date||'—'}</div>
      <div style="grid-column:1/-1;"><strong>Location</strong><br>${d.location||'—'}</div>
      <div style="grid-column:1/-1;"><strong>Description / Narrative</strong><br>
        <div style="background:#f8f9fa;padding:10px 14px;border-radius:8px;margin-top:4px;font-size:.88rem;line-height:1.7;">${d.description||'—'}</div>
      </div>
      ${d.hearing_date ? `<div><strong>Hearing Date</strong><br>${d.hearing_date}</div>` : ''}
      ${d.referred_to  ? `<div><strong>Referred To</strong><br><span class="badge badge-info">${d.referred_to}</span></div>` : ''}
      ${d.notes ? `<div style="grid-column:1/-1;"><strong>Resolution Notes</strong><br>
        <div style="background:#eafaf1;padding:10px 14px;border-radius:8px;margin-top:4px;font-size:.88rem;">${d.notes}</div></div>` : ''}
      <div><strong>Filed By</strong><br>${d.filed_by||'—'}</div>
      <div><strong>Filed At</strong><br>${d.filed||'—'}</div>
    </div>
    <div style="margin-top:14px;padding:10px 14px;background:#fef9e7;border-radius:8px;font-size:.78rem;color:#7d6608;">
      <i class="fas fa-info-circle"></i> This is a <strong>Barangay Case Report</strong> under Katarungang Pambarangay (RA 7160). Not a PNP Blotter.
    </div>`;
  openModal('modalViewCase');
}

// ── Update Status ───────────────────────────────────────────────────────────
function updateCase(id, status, referredTo, hearingDate) {
  document.getElementById('updateCaseId').value      = id;
  document.getElementById('updateStatus').value      = status;
  document.getElementById('referredTo').value        = referredTo || '';
  document.getElementById('updateHearingDate').value = hearingDate || '';
  toggleReferral();
  openModal('modalUpdateCase');
}

function toggleReferral() {
  const st = document.getElementById('updateStatus').value;
  document.getElementById('referralGroup').style.display = st === 'Referred' ? 'block' : 'none';
  document.getElementById('referredTo').required = st === 'Referred';
}

async function submitUpdate() {
  const data = Object.fromEntries(new FormData(document.getElementById('updateCaseForm')));
  if (data.resolution_status === 'Referred' && !data.referred_to) {
    showToast('error','Please specify who the case is referred to.'); return;
  }
  const res = await apiRequest('/BRGYMS/blotter/api.php?action=update_status', data);
  if (res.success) {
    showToast('success','Case status updated!');
    closeModal('modalUpdateCase');
    setTimeout(() => location.reload(), 1200);
  } else showToast('error', res.message || 'Update failed.');
}

// ── Print Case Report ───────────────────────────────────────────────────────
async function printCase(id) {
  const res = await apiRequest('/BRGYMS/blotter/api.php?action=print', {
    case_id: id, csrf_token: '<?= $csrf ?>'
  });
  if (res.success) printDocument(res.html);
  else showToast('error', 'Failed to generate report.');
}

// ── Live resident search ─────────────────────────────────────────────────────
function filterTable(query, tableId, colIndex) {
  const q = query.toLowerCase().trim();
  const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
  let visible = 0;
  rows.forEach(row => {
    const cell = row.cells[colIndex];
    if (!cell) return;
    const match = !q || cell.textContent.toLowerCase().includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
}
</script>

<?php require_once '../includes/footer.php'; ?>
