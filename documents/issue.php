<?php
$page_title  = 'Issue Document';
$active_page = 'documents';
$breadcrumb  = ['Documents', 'Issue Document'];
require_once '../includes/auth_check.php';
require_permission('create_document');

$conn = getDBConnection();
$csrf = generate_csrf_token();

// Pre-selected resident from URL
$preselect_rid = (int)($_GET['resident_id'] ?? 0);
$resident = null;

if ($preselect_rid) {
    $stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id=? AND is_archived=0");
    $stmt->bind_param("i", $preselect_rid); $stmt->execute();
    $resident = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($resident) {
        $resident['first_name']    = aes_decrypt($resident['first_name']);
        $resident['middle_name']   = aes_decrypt($resident['middle_name']);
        $resident['last_name']     = aes_decrypt($resident['last_name']);
        $resident['birth_date']    = aes_decrypt($resident['birth_date']);
        $resident['contact_number']= aes_decrypt($resident['contact_number']);
    }
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex justify-between align-center">
  <div>
    <h2><i class="fas fa-file-plus"></i> Issue Document</h2>
    <p>Select a document type and fill in the required details</p>
  </div>
  <a href="/BRGYMS/documents/index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<!-- Step 1: Select Purok then Search Resident (if none preselected) -->
<?php if (!$resident): ?>
<div class="card mb-3" style="max-width:600px;">
  <div class="card-header"><h3><i class="fas fa-map-marker-alt"></i> Step 1 — Select Purok & Find Resident</h3></div>
  <div class="card-body">
    <!-- Purok selector -->
    <div class="form-group mb-3">
      <label style="font-weight:600;font-size:.85rem;">Select Purok <span style="color:var(--danger);">*</span></label>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
        <?php foreach(['Purok 1','Purok 2','Purok 3','Purok 4'] as $pk):
          $colors = ['Purok 1'=>'#2980b9','Purok 2'=>'#27ae60','Purok 3'=>'#d68910','Purok 4'=>'#e74c3c'];
          $c = $colors[$pk];
        ?>
        <button type="button" class="purok-btn" data-purok="<?= $pk ?>"
          onclick="selectPurok('<?= $pk ?>')"
          style="padding:10px 20px;border-radius:10px;border:2px solid <?= $c ?>;background:#fff;color:<?= $c ?>;font-weight:700;cursor:pointer;transition:all .2s;font-size:.9rem;">
          <i class="fas fa-folder"></i> <?= $pk ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Search (shown after purok selected) -->
    <div id="searchSection" style="display:none;">
      <hr style="margin:16px 0;border-color:var(--border);">
      <label style="font-weight:600;font-size:.85rem;">Search Resident in <span id="selectedPurokLabel" style="color:var(--primary);"></span></label>
      <div class="d-flex gap-2 mt-2">
        <div class="search-box" style="flex:1;">
          <i class="fas fa-search"></i>
          <input type="text" id="searchName" placeholder="Type last name or first name..."
                 class="form-control" style="padding-left:36px;">
        </div>
        <button type="button" class="btn btn-primary" onclick="searchResident()">
          <i class="fas fa-search"></i> Search
        </button>
      </div>
      <div id="searchResults" style="margin-top:14px;"></div>
    </div>
  </div>
</div>
<?php else: ?>

<!-- Resident Info Banner -->
<div class="card mb-3" style="background:linear-gradient(135deg,#e8f4fd,#d6eaf8);border:1.5px solid #2980b9;max-width:800px;">
  <div class="card-body" style="padding:16px 22px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#1a3a5c,#2980b9);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i class="fas fa-user" style="color:#fff;font-size:1.2rem;"></i>
    </div>
    <div style="flex:1;">
      <div style="font-weight:700;font-size:1rem;color:#1a3a5c;">
        <?= sanitize_output($resident['last_name'].', '.$resident['first_name'].($resident['middle_name']?' '.$resident['middle_name']:'')) ?>
      </div>
      <div style="font-size:.82rem;color:#1a6fa8;margin-top:2px;">
        Code: <strong><?= sanitize_output($resident['resident_code']) ?></strong> &nbsp;|&nbsp;
        Sex: <strong><?= sanitize_output($resident['sex'] ?? '—') ?></strong> &nbsp;|&nbsp;
        Civil Status: <strong><?= sanitize_output($resident['civil_status'] ?? '—') ?></strong>
        <?= $resident['is_indigent'] ? ' &nbsp;|&nbsp; <span class="badge badge-warning">Indigent</span>' : '' ?>
      </div>
    </div>
    <a href="/BRGYMS/documents/issue.php" class="btn btn-secondary btn-sm">
      <i class="fas fa-arrow-rotate-left"></i> Change Resident
    </a>
  </div>
</div>

<!-- Step 2: Choose Document Type -->
<div class="page-header" style="margin-bottom:14px;">
  <h3 style="font-size:1rem;color:var(--text-muted);font-weight:500;">Step 2 — Select Document Type</h3>
</div>

<div class="d-flex gap-2 mb-3 flex-wrap" style="max-width:800px;">
  <!-- BARANGAY CLEARANCE -->
  <div class="doc-choice-card" id="cardClearance" onclick="selectDoc('clearance')"
       style="flex:1;min-width:240px;">
    <div class="doc-choice-inner">
      <div style="font-size:2rem;margin-bottom:8px;">📄</div>
      <div class="doc-choice-name">Barangay Clearance</div>
      <div class="doc-choice-fee" id="feeClearance">
        <?php
          $fee_row = $conn->query("SELECT fee FROM tbl_document_types WHERE type_name LIKE '%Clearance%' LIMIT 1")->fetch_assoc();
          echo '₱'.number_format($fee_row['fee'] ?? 50, 2);
        ?>
      </div>
      <div class="doc-choice-req">Valid ID, 1 Passport Size Photo</div>
    </div>
  </div>

  <!-- CERTIFICATE OF INDIGENCY -->
  <div class="doc-choice-card" id="cardIndigency" onclick="selectDoc('indigency')"
       style="flex:1;min-width:240px;">
    <div class="doc-choice-inner">
      <div style="font-size:2rem;margin-bottom:8px;">📋</div>
      <div class="doc-choice-name">Certificate of Indigency</div>
      <div class="doc-choice-fee" style="color:var(--success);">FREE</div>
      <div class="doc-choice-req">Valid ID, Proof of Residency</div>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- ── CLEARANCE FORM MODAL ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalClearance">
  <div class="modal" style="max-width:580px;">
    <div class="modal-header">
      <h4><i class="fas fa-file-lines"></i> Barangay Clearance — Details</h4>
      <button class="modal-close" onclick="closeModal('modalClearance')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <form id="clearanceForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="resident_id" value="<?= $preselect_rid ?>">
        <input type="hidden" name="doc_type" value="clearance">

        <div class="form-grid">
          <div class="form-group">
            <label>Purok / Sitio <span class="req">*</span></label>
            <input type="text" name="purok" class="form-control" required maxlength="100"
                   placeholder="e.g. Purok 3">
          </div>
          <div class="form-group">
            <label>CTC No. <small style="color:var(--text-muted)">(Community Tax Cert)</small></label>
            <input type="text" name="ctc_no" class="form-control" maxlength="50"
                   placeholder="Optional">
          </div>
          <div class="form-group">
            <label>Official Receipt No.</label>
            <input type="text" name="or_no" class="form-control" maxlength="50"
                   placeholder="Optional">
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label>Purpose <span class="req">*</span></label>
            <select name="purpose" id="clearancePurpose" class="form-control" required onchange="updateClearanceFee(this.value)">
              <option value="">-- Select Purpose --</option>
              <option value="Employment">Employment</option>
              <option value="School / Academic Requirements">School / Academic Requirements — FREE 🎓</option>
              <option value="Government Assistance Application">Government Assistance Application</option>
              <option value="Bank / Loan Application">Bank / Loan Application — ₱100.00 🏦</option>
              <option value="Travel Requirements">Travel Requirements</option>
            </select>
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label>Received By <span class="req">*</span></label>
            <input type="text" name="received_by" class="form-control" required maxlength="150"
                   placeholder="Name of person who claimed the document">
          </div>
        </div>

        <!-- Fee Badge -->
        <div id="clearanceFeeBox" style="margin-top:12px;padding:14px 16px;border-radius:10px;background:#fef9e7;border:1.5px solid #f9ca24;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="color:#555;font-size:.9rem;">Documentary Fee:</span>
            <strong id="clearanceFeeAmt" style="font-size:1.4rem;color:#d35400;">₱50.00</strong>
          </div>
          <div id="clearanceFeeNote" style="font-size:.82rem;margin-top:6px;font-weight:600;"></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalClearance')">Cancel</button>
      <button class="btn btn-primary" onclick="generateDocument('clearance')">
        <i class="fas fa-print"></i> Generate & Print
      </button>
    </div>
  </div>
</div>

<!-- ── INDIGENCY FORM MODAL ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalIndigency">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <h4><i class="fas fa-file-lines"></i> Certificate of Indigency — Details</h4>
      <button class="modal-close" onclick="closeModal('modalIndigency')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <form id="indigencyForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="resident_id" value="<?= $preselect_rid ?>">
        <input type="hidden" name="doc_type" value="indigency">
        <div class="form-group">
          <label>Purpose <span class="req">*</span></label>
          <select name="purpose" id="indigencyPurpose" class="form-control" required onchange="handleIndigencyPurpose()">
            <option value="">-- Select Purpose --</option>
            <option value="Medical Assistance">Medical Assistance</option>
            <option value="Educational Assistance">Educational Assistance</option>
            <option value="Government Assistance Application">Government Assistance Application</option>
            <option value="Hospital / PhilHealth Requirements">Hospital / PhilHealth Requirements</option>
            <option value="DSWD / 4Ps Requirements">DSWD / 4Ps Requirements</option>
            <option value="Other">Other (specify)</option>
          </select>
        </div>
        <div class="form-group mt-2" id="indigencyOtherGrp" style="display:none;">
          <label>Specify Purpose <span class="req">*</span></label>
          <input type="text" name="purpose_other" id="indigencyOtherPurpose" class="form-control" maxlength="255">
        </div>
        <div class="form-group mt-2">
          <label>Received By <span class="req">*</span></label>
          <input type="text" name="received_by" class="form-control" required maxlength="150"
                 placeholder="Name of person who claimed the document">
        </div>
        <div style="margin-top:14px;padding:12px 16px;border-radius:8px;background:#eafaf1;border:1.5px solid #a9dfbf;font-size:.9rem;display:flex;justify-content:space-between;align-items:center;">
          <span>Documentary Fee:</span>
          <strong style="font-size:1.1rem;color:var(--success);">FREE</strong>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalIndigency')">Cancel</button>
      <button class="btn btn-primary" onclick="generateDocument('indigency')">
        <i class="fas fa-print"></i> Generate & Print
      </button>
    </div>
  </div>
</div>

<style>
.doc-choice-card { cursor:pointer; }
.doc-choice-inner {
  border:2.5px solid var(--border);border-radius:14px;padding:24px 20px;
  text-align:center;transition:all .2s;background:#fff;
}
.doc-choice-card:hover .doc-choice-inner,
.doc-choice-card.selected .doc-choice-inner {
  border-color:var(--primary-light);background:var(--primary-xlight);
  box-shadow:0 4px 16px rgba(41,128,185,.15);transform:translateY(-2px);
}
.doc-choice-name { font-weight:700;color:var(--primary);font-size:1rem;margin-bottom:6px; }
.doc-choice-fee  { font-size:1.3rem;font-weight:700;color:var(--success);margin-bottom:6px; }
.doc-choice-req  { font-size:.75rem;color:var(--text-muted); }
</style>

<script>
// ── Purok Selection ───────────────────────────────────────────────────────
let selectedPurok = '';
function selectPurok(purok) {
  selectedPurok = purok;
  // Update button styles
  document.querySelectorAll('.purok-btn').forEach(btn => {
    const isActive = btn.dataset.purok === purok;
    btn.style.background = isActive ? btn.style.borderColor : '#fff';
    btn.style.color      = isActive ? '#fff' : btn.style.borderColor;
    if (isActive) {
      const c = {'Purok 1':'#2980b9','Purok 2':'#27ae60','Purok 3':'#d68910','Purok 4':'#e74c3c'}[purok];
      btn.style.background = c;
      btn.style.color = '#fff';
    }
  });
  // Show search section
  document.getElementById('searchSection').style.display = 'block';
  document.getElementById('selectedPurokLabel').textContent = purok;
  document.getElementById('searchName').focus();
  document.getElementById('searchResults').innerHTML = '';
}

// ── Resident Search ────────────────────────────────────────────────────────
async function searchResident() {
  const q = document.getElementById('searchName')?.value?.trim();
  if (!q) { showToast('error','Please enter a name to search.'); return; }
  const res = await apiRequest('/BRGYMS/documents/search_resident.php', {
    q: q, purok: selectedPurok, csrf_token: '<?= $csrf ?>'
  });
  const box = document.getElementById('searchResults');
  if (!res.success || !res.residents.length) {
    box.innerHTML = '<div class="empty-state" style="padding:20px;"><i class="fas fa-user-slash"></i><p>No residents found for "'+q+'" in '+selectedPurok+'</p></div>';
    return;
  }
  let html = '<div class="table-wrapper"><table><thead><tr><th>Code</th><th>Name</th><th>Sex</th><th>Select</th></tr></thead><tbody>';
  res.residents.forEach(r => {
    html += `<tr>
      <td><code>${r.code}</code></td>
      <td><strong>${r.name}</strong></td>
      <td>${r.sex||'—'}</td>
      <td><a href="/BRGYMS/documents/issue.php?resident_id=${r.id}" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Select</a></td>
    </tr>`;
  });
  html += '</tbody></table></div>';
  box.innerHTML = html;
}
document.getElementById('searchName')?.addEventListener('keypress', e => { if(e.key==='Enter'){e.preventDefault();searchResident();} });

// ── Doc Type Selection ────────────────────────────────────────────────────
function selectDoc(type) {
  document.querySelectorAll('.doc-choice-card').forEach(c => c.classList.remove('selected'));
  if (type === 'clearance') {
    document.getElementById('cardClearance').classList.add('selected');
    // Auto-detect student
    openModal('modalClearance');
  } else {
    document.getElementById('cardIndigency').classList.add('selected');
    openModal('modalIndigency');
  }
}

// ── Fee Update Function ───────────────────────────────────────────────────
function updateClearanceFee(sel) {
  var amt       = document.getElementById('clearanceFeeAmt');
  var box       = document.getElementById('clearanceFeeBox');
  var note      = document.getElementById('clearanceFeeNote');
  var cardFee   = document.getElementById('feeClearance'); // card on main page

  if (sel === 'School / Academic Requirements') {
    amt.textContent       = 'FREE';
    amt.style.color       = '#27ae60';
    box.style.background  = '#eafaf1';
    box.style.borderColor = '#a9dfbf';
    note.style.color      = '#27ae60';
    note.textContent      = '🎓 Student — Barangay Clearance is FREE';
    if (cardFee) { cardFee.textContent = 'FREE'; cardFee.style.color = 'var(--success)'; }
  } else if (sel === 'Bank / Loan Application') {
    amt.textContent       = '₱100.00';
    amt.style.color       = '#d35400';
    box.style.background  = '#fff3e0';
    box.style.borderColor = '#f39c12';
    note.style.color      = '#d35400';
    note.textContent      = '🏦 Loan Application Rate';
    if (cardFee) { cardFee.textContent = '₱100.00'; cardFee.style.color = '#d35400'; }
  } else if (sel) {
    amt.textContent       = '₱50.00';
    amt.style.color       = '#d35400';
    box.style.background  = '#fef9e7';
    box.style.borderColor = '#f9ca24';
    note.style.color      = '#888';
    note.textContent      = '📄 Regular Fee';
    if (cardFee) { cardFee.textContent = '₱50.00'; cardFee.style.color = ''; }
  } else {
    amt.textContent       = '₱50.00';
    amt.style.color       = '#d35400';
    box.style.background  = '#fef9e7';
    box.style.borderColor = '#f9ca24';
    note.textContent      = '';
    if (cardFee) { cardFee.textContent = '₱50.00'; cardFee.style.color = ''; }
  }
}

function handleIndigencyPurpose() {
  const sel = document.getElementById('indigencyPurpose').value;
  const og  = document.getElementById('indigencyOtherGrp');
  og.style.display = sel === 'Other' ? 'flex' : 'none';
  og.style.flexDirection = 'column';
  document.getElementById('indigencyOtherPurpose').required = sel === 'Other';
}

// ── Generate & Print ──────────────────────────────────────────────────────
async function generateDocument(type) {
  const formId = type === 'clearance' ? 'clearanceForm' : 'indigencyForm';
  const form   = document.getElementById(formId);
  const data   = Object.fromEntries(new FormData(form));

  // Validate
  if (!data.resident_id || data.resident_id == '0') {
    showToast('error','No resident selected.'); return;
  }
  const purposeVal = data.purpose === 'Other'
    ? data.purpose_other
    : data.purpose.replace(/\s*—\s*(FREE|₱\d+\.?\d*\s*🎓|🎓|🏦)[^]*/gi,'').trim();
  if (!purposeVal) { showToast('error','Please select a purpose.'); return; }
  data.purpose_final = purposeVal;

  showToast('info','Generating document...');
  const res = await apiRequest('/BRGYMS/documents/generate_api.php', data);

  if (res.success) {
    closeModal('modal' + (type === 'clearance' ? 'Clearance' : 'Indigency'));
    showToast('success','Document generated! Opening print window...');
    // Open print window
    const pw = window.open('', '_blank', 'width=900,height=700,scrollbars=yes');
    pw.document.write(res.html);
    pw.document.close();
    setTimeout(() => { pw.focus(); pw.print(); }, 800);
    // Reload after 3s to reflect new request
    setTimeout(() => window.location.href = '/BRGYMS/documents/index.php', 3000);
  } else {
    showToast('error', res.message || 'Failed to generate document.');
  }
}
</script>
<?php require_once '../includes/footer.php'; ?>
