<?php
$page_title = 'New Document Request';
$active_page = 'documents';
$breadcrumb = ['Documents', 'New Request'];
require_once '../includes/auth_check.php';

$conn = getDBConnection();
$csrf = generate_csrf_token();
$preselect_resident = (int)($_GET['resident_id'] ?? 0);

// Fetch document types
$doc_types = $conn->query("SELECT * FROM tbl_document_types WHERE is_active=1");

// Fetch residents grouped by purok for filtered dropdown
$residents_all = $conn->query(
    "SELECT resident_id, resident_code, first_name, last_name, purok
     FROM tbl_residents WHERE is_archived=0 ORDER BY purok, last_name"
);
$residents_by_purok = ['Purok 1'=>[],'Purok 2'=>[],'Purok 3'=>[],'Purok 4'=>[],'Unassigned'=>[]];
while ($res = $residents_all->fetch_assoc()) {
    $res['first_name'] = aes_decrypt($res['first_name']);
    $res['last_name']  = aes_decrypt($res['last_name']);
    $key = (isset($residents_by_purok[$res['purok']]) && $res['purok']) ? $res['purok'] : 'Unassigned';
    $residents_by_purok[$key][] = $res;
}

require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center">
  <div>
    <h2><i class="fas fa-file-plus"></i> New Document Request</h2>
    <p>Create a new document issuance request for a resident</p>
  </div>
  <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="card" style="max-width:700px;">
  <div class="card-header"><h3><i class="fas fa-pen-to-square"></i> Request Details</h3></div>
  <div class="card-body">
    <form id="createDocForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1;">
          <label>Purok <span class="req">*</span></label>
          <select id="purokFilter" class="form-control" onchange="filterResidentsByPurok(this.value)" required>
            <option value="">-- Select Purok First --</option>
            <option>Purok 1</option>
            <option>Purok 2</option>
            <option>Purok 3</option>
            <option>Purok 4</option>
            <option value="Unassigned">Unassigned</option>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label>Resident <span class="req">*</span></label>

          <!-- Autocomplete search input -->
          <div style="position:relative;">
            <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;z-index:1;"></i>
            <input type="text" id="residentSearchInput"
                   placeholder="Type resident name or code..."
                   class="form-control" style="padding-left:36px;"
                   oninput="searchResidentLive(this.value)"
                   onfocus="searchResidentLive(this.value)"
                   autocomplete="off">
            <!-- Hidden actual value -->
            <input type="hidden" name="resident_id" id="residentSelect" required>
            <!-- Dropdown results -->
            <div id="residentDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1.5px solid var(--primary-light);border-top:none;border-radius:0 0 8px 8px;max-height:220px;overflow-y:auto;z-index:200;box-shadow:0 8px 20px rgba(0,0,0,.12);"></div>
          </div>
          <small id="residentHint" style="color:var(--text-muted);font-size:.78rem;margin-top:4px;display:block;">
            <?php if ($preselect_resident): ?>Select Purok above then type name to search.<?php else: ?>Select Purok above then type resident name to search.<?php endif; ?>
          </small>
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label>Document Type <span class="req">*</span></label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" id="docTypeGrid">
            <?php $doc_types->data_seek(0); while($dt=$doc_types->fetch_assoc()): ?>
            <label class="doc-type-card" for="dt_<?= $dt['type_id'] ?>">
              <input type="radio" name="document_type_id" id="dt_<?= $dt['type_id'] ?>"
                     value="<?= $dt['type_id'] ?>"
                     data-base-fee="<?= number_format($dt['fee'],2) ?>"
                     data-name="<?= sanitize_output($dt['type_name']) ?>"
                     required>
              <div class="dtc-inner">
                <div class="dtc-name"><?= sanitize_output($dt['type_name']) ?></div>
                <div class="dtc-fee" id="card-fee-<?= $dt['type_id'] ?>">₱<?= number_format($dt['fee'],2) ?></div>
                <div class="dtc-req"><?= sanitize_output($dt['requirements']) ?></div>
              </div>
            </label>
            <?php endwhile; ?>
          </div>
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label>Purpose <span class="req">*</span></label>
          <select name="purpose_select" id="purposeSelect" class="form-control" onchange="handlePurpose()">
            <option value="">-- Select Purpose --</option>
            <option value="Employment">Employment</option>
            <option value="Government Assistance Application">Government Assistance Application</option>
            <option value="School / Academic Requirements">School / Academic Requirements — FREE 🎓</option>
            <option value="Bank / Loan Application">Bank / Loan Application — ₱100.00 🏦</option>
            <option value="Travel Requirements">Travel Requirements</option>
          </select>
        </div>
      </div>

      <!-- Dynamic Fee Preview -->
      <div id="feePreview" style="display:none;margin:12px 0;padding:14px 16px;border-radius:10px;background:#fef9e7;border:1.5px solid #f9ca24;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span style="color:#555;font-size:.9rem;">Documentary Fee:</span>
          <strong id="feePreviewAmt" style="font-size:1.3rem;color:#d35400;">₱50.00</strong>
        </div>
        <div id="feePreviewNote" style="font-size:.82rem;margin-top:4px;font-weight:600;"></div>
      </div>

      <div class="d-flex gap-2 mt-3" style="justify-content:flex-end;">
        <a href="index.php" class="btn btn-secondary">Cancel</a>
        <button type="button" class="btn btn-primary" onclick="submitRequest()">
          <i class="fas fa-paper-plane"></i> Submit Request
        </button>
      </div>
    </form>
  </div>
</div>

<style>
.doc-type-card{display:block;cursor:pointer;}
.doc-type-card input{display:none;}
.dtc-inner{
  border:2px solid var(--border);border-radius:10px;padding:16px;
  transition:all .2s;background:#fff;
}
.doc-type-card input:checked + .dtc-inner{
  border-color:var(--primary-light);background:var(--primary-xlight);
}
.dtc-name{font-weight:600;color:var(--primary);margin-bottom:4px;}
.dtc-fee{font-size:1.1rem;font-weight:700;color:var(--success);}
.dtc-req{font-size:.75rem;color:var(--text-muted);margin-top:6px;}
</style>

<script>
const residentsByPurok = <?php
  $js = [];
  foreach ($residents_by_purok as $purok => $list) {
      $js[$purok] = array_map(fn($r) => [
          'id'   => $r['resident_id'],
          'code' => $r['resident_code'],
          'name' => $r['last_name'].', '.$r['first_name']
      ], $list);
  }
  echo json_encode($js, JSON_HEX_TAG);
?>;

const preselectId = <?= $preselect_resident ?>;
let currentPurokResidents = [];
let selectedResidentName  = '';

function filterResidentsByPurok(purok) {
  const inp   = document.getElementById('residentSearchInput');
  const hidden= document.getElementById('residentSelect');
  const hint  = document.getElementById('residentHint');
  const dd    = document.getElementById('residentDropdown');

  // Reset
  inp.value = ''; hidden.value = '';
  dd.style.display = 'none';
  selectedResidentName = '';

  if (!purok) {
    currentPurokResidents = [];
    inp.disabled = true;
    inp.placeholder = 'Select Purok first...';
    hint.textContent = 'Select a Purok above to load residents.';
    return;
  }

  currentPurokResidents = residentsByPurok[purok] || [];
  inp.disabled = false;
  inp.placeholder = 'Type name or code to search in ' + purok + '...';
  inp.focus();
  hint.textContent = currentPurokResidents.length + ' resident(s) in ' + purok + '. Type to search.';

  // Auto-select if preselected
  <?php if ($preselect_resident): ?>
  const pre = currentPurokResidents.find(r => r.id == <?= $preselect_resident ?>);
  if (pre) selectResident(pre.id, '[' + pre.code + '] ' + pre.name);
  <?php endif; ?>
}

function searchResidentLive(query) {
  const dd   = document.getElementById('residentDropdown');
  const q    = query.trim().toLowerCase();

  if (!currentPurokResidents.length) {
    dd.style.display = 'none'; return;
  }

  const matches = q
    ? currentPurokResidents.filter(r =>
        r.name.toLowerCase().includes(q) || r.code.toLowerCase().includes(q))
    : currentPurokResidents;

  if (!matches.length) {
    dd.innerHTML = '<div style="padding:12px 16px;color:var(--text-muted);font-size:.85rem;">No residents found for "' + query + '"</div>';
    dd.style.display = 'block';
    return;
  }

  dd.innerHTML = matches.map(r =>
    `<div class="resident-option" onclick="selectResident(${r.id}, '[${r.code}] ${r.name}')"
       style="padding:10px 16px;cursor:pointer;font-size:.88rem;border-bottom:1px solid var(--border);transition:background .15s;"
       onmouseover="this.style.background='var(--primary-xlight)'"
       onmouseout="this.style.background=''">
      <strong style="color:var(--primary);">${r.name}</strong>
      <span style="color:var(--text-muted);font-size:.78rem;margin-left:8px;">${r.code}</span>
    </div>`
  ).join('');
  dd.style.display = 'block';
}

function selectResident(id, label) {
  document.getElementById('residentSelect').value = id;
  document.getElementById('residentSearchInput').value = label;
  document.getElementById('residentDropdown').style.display = 'none';
  document.getElementById('residentHint').textContent = '✓ Selected: ' + label;
  document.getElementById('residentHint').style.color = 'var(--success)';
  selectedResidentName = label;
}

// Close dropdown on outside click
document.addEventListener('click', e => {
  if (!e.target.closest('[id="residentSearchInput"]') &&
      !e.target.closest('[id="residentDropdown"]')) {
    const dd = document.getElementById('residentDropdown');
    if (dd) dd.style.display = 'none';
    // Restore selected name if user typed but didn't pick
    if (selectedResidentName)
      document.getElementById('residentSearchInput').value = selectedResidentName;
  }
});

// Disable search input until purok selected
document.getElementById('residentSearchInput').disabled = true;

// Auto-select purok if preselected via URL
<?php if ($preselect_resident): ?>
(function(){
  for (const [purok, list] of Object.entries(residentsByPurok)) {
    if (list.some(r => r.id == <?= $preselect_resident ?>)) {
      document.getElementById('purokFilter').value = purok;
      filterResidentsByPurok(purok);
      break;
    }
  }
})();
<?php endif; ?>
function handlePurpose(){
  const sel = document.getElementById('purposeSelect').value;
  const dtid = document.querySelector('[name=document_type_id]:checked')?.value;
  updateFeePreview(sel, dtid);
  updateCardFees(sel);
}

// Listen for doc type changes to update fee preview
document.addEventListener('change', function(e){
  if(e.target.name==='document_type_id'){
    const sel = document.getElementById('purposeSelect').value;
    updateFeePreview(sel, e.target.value);
    updateCardFees(sel);
  }
});

function updateCardFees(purpose) {
  // Update each card's fee display based on selected purpose
  document.querySelectorAll('[name=document_type_id]').forEach(radio => {
    const typeId   = radio.value;
    const typeName = radio.dataset.name || '';
    const baseFee  = radio.dataset.baseFee || '50.00';
    const feeEl    = document.getElementById('card-fee-' + typeId);
    if (!feeEl) return;

    const isClearance = /clearance/i.test(typeName);
    const isIndigency = /indigency|indigent/i.test(typeName);

    if (isIndigency) {
      feeEl.textContent = 'FREE';
      feeEl.style.color = '#27ae60';
    } else if (isClearance && purpose) {
      if (/School|Academic/i.test(purpose)) {
        feeEl.textContent = 'FREE';
        feeEl.style.color = '#27ae60';
      } else if (/Loan/i.test(purpose)) {
        feeEl.textContent = '₱100.00';
        feeEl.style.color = '#d35400';
      } else {
        feeEl.textContent = '₱' + baseFee;
        feeEl.style.color = '';
      }
    } else {
      feeEl.textContent = '₱' + baseFee;
      feeEl.style.color = '';
    }
  });
}

function updateFeePreview(purpose, dtid) {
  const box  = document.getElementById('feePreview');
  const amt  = document.getElementById('feePreviewAmt');
  const note = document.getElementById('feePreviewNote');

  if (!dtid || !purpose) { box.style.display='none'; return; }
  box.style.display='block';

  const selectedLabel = document.querySelector(`[for="dt_${dtid}"] .dtc-name`)?.textContent||'';
  const isClearance = /clearance/i.test(selectedLabel);
  const isIndigency = /indigency|indigent/i.test(selectedLabel);

  if (isIndigency) {
    amt.textContent='FREE'; amt.style.color='#27ae60';
    box.style.background='#eafaf1'; box.style.borderColor='#a9dfbf';
    note.style.color='#27ae60'; note.textContent='Certificate of Indigency is FREE for all residents';
  } else if (isClearance) {
    if (/School|Academic/i.test(purpose)) {
      amt.textContent='FREE'; amt.style.color='#27ae60';
      box.style.background='#eafaf1'; box.style.borderColor='#a9dfbf';
      note.style.color='#27ae60'; note.textContent='🎓 Student — Barangay Clearance is FREE';
    } else if (/Loan/i.test(purpose)) {
      amt.textContent='₱100.00'; amt.style.color='#d35400';
      box.style.background='#fff3e0'; box.style.borderColor='#f39c12';
      note.style.color='#d35400'; note.textContent='🏦 Loan Application Rate';
    } else {
      amt.textContent='₱50.00'; amt.style.color='#d35400';
      box.style.background='#fef9e7'; box.style.borderColor='#f9ca24';
      note.style.color='#888'; note.textContent='📄 Regular Fee';
    }
  } else {
    box.style.display='none';
  }
}

async function submitRequest(){
  const form  = document.getElementById('createDocForm');
  const rid   = document.getElementById('residentSelect').value;
  const dtid  = form.querySelector('[name=document_type_id]:checked')?.value;
  const sel   = document.getElementById('purposeSelect').value;

  if (!rid)    { showToast('error','Please search and select a resident.'); return; }
  if (!dtid)   { showToast('error','Please select a document type.'); return; }
  if (!sel)    { showToast('error','Please select the purpose.');     return; }

  const is_student = (/School|Academic/i.test(sel)) ? 1 : 0;
  const is_loan    = (/Loan/i.test(sel)) ? 1 : 0;

  // Debug — remove after confirming purpose saves correctly
  console.log('Submitting purpose:', sel, '| is_student:', is_student, '| is_loan:', is_loan);

  const res = await apiRequest('/documents/api.php?action=create', {
    resident_id: rid, document_type_id: dtid, purpose: sel,
    is_student, is_loan, csrf_token: '<?= $csrf ?>'
  });
  if (res.success) {
    const feeLabel = res.is_free ? 'FREE' : '₱' + parseFloat(res.fee).toFixed(2);
    showToast('success', 'Request submitted! Code: ' + res.code + ' | Fee: ' + feeLabel);
    setTimeout(() => window.location.href = 'index.php', 1800);
  } else {
    showToast('error', res.message || 'Failed to submit.');
  }
}
</script>
<?php require_once '../includes/footer.php'; ?>
