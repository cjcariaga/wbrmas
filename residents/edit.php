<?php
$page_title = 'Edit Resident';
$active_page = 'residents';
$breadcrumb = ['Residents', 'Edit'];
require_once '../includes/auth_check.php';

$rid = (int)($_GET['id'] ?? 0);
if (!$rid) { header('Location: index.php'); exit; }

// Only admins can edit residents
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$conn = getDBConnection();

// ── Check and acquire record lock ─────────────────────────────────────────────
// Clean expired locks first
$conn->query("DELETE FROM tbl_record_locks WHERE expires_at < NOW()");

$lock_stmt = $conn->prepare(
    "SELECT l.locked_by, u.full_name, l.expires_at
     FROM tbl_record_locks l
     JOIN tbl_users u ON l.locked_by = u.user_id
     WHERE l.resident_id = ? LIMIT 1"
);
$lock_stmt->bind_param("i", $rid);
$lock_stmt->execute();
$existing_lock = $lock_stmt->get_result()->fetch_assoc();
$lock_stmt->close();

$uid = (int)$_SESSION['user_id'];
$lock_warning = null;

if ($existing_lock && (int)$existing_lock['locked_by'] !== $uid) {
    // Locked by someone else — show warning but allow viewing (read-only)
    $lock_warning = $existing_lock['full_name'];
} else {
    // Acquire / renew lock for current user (15 min)
    $lk = $conn->prepare(
        "INSERT INTO tbl_record_locks (resident_id, locked_by, locked_at, expires_at)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE))
         ON DUPLICATE KEY UPDATE locked_by=VALUES(locked_by), locked_at=NOW(),
                                  expires_at=DATE_ADD(NOW(), INTERVAL 15 MINUTE)"
    );
    $lk->bind_param("ii", $rid, $uid);
    $lk->execute();
    $lk->close();
}
$stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id=? AND is_archived=0");
$stmt->bind_param("i", $rid); $stmt->execute();
$r = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$r) { header('Location: index.php'); exit; }

$r['first_name']    = aes_decrypt($r['first_name']);
$r['middle_name']   = aes_decrypt($r['middle_name']);
$r['last_name']     = aes_decrypt($r['last_name']);
$r['birth_date']    = aes_decrypt($r['birth_date']);
$r['contact_number']= aes_decrypt($r['contact_number']);
$csrf = generate_csrf_token();

$photo_url = $r['photo_path']
    ? '/BRGYMS/' . htmlspecialchars($r['photo_path'], ENT_QUOTES, 'UTF-8')
    : null;

require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center">
  <div>
    <h2><i class="fas fa-pen-to-square"></i> Edit Resident</h2>
    <p>Updating record for <strong><?= sanitize_output($r['last_name'].', '.$r['first_name']) ?></strong>
       — <code><?= sanitize_output($r['resident_code']) ?></code></p>
  </div>
  <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($lock_warning): ?>
<div style="background:#fff3cd;border:1.5px solid #ffc107;border-radius:10px;padding:14px 18px;
            margin-bottom:18px;display:flex;align-items:center;gap:12px;">
  <i class="fas fa-lock" style="color:#d68910;font-size:1.3rem;"></i>
  <div>
    <strong style="color:#856404;">Record Locked</strong><br>
    <span style="font-size:.85rem;color:#856404;">
      This record is currently being edited by <strong><?= sanitize_output($lock_warning) ?></strong>.
      You can view the record but saving is disabled until the lock is released.
    </span>
  </div>
</div>
<?php else: ?>
<div id="lockBanner" style="background:#eafaf1;border:1.5px solid #a9dfbf;border-radius:10px;
     padding:10px 16px;margin-bottom:18px;display:flex;align-items:center;gap:10px;font-size:.82rem;">
  <i class="fas fa-lock-open" style="color:#27ae60;"></i>
  <span style="color:#1e8449;">You have an exclusive edit lock. Auto-releases in <strong id="lockCountdown">15:00</strong>.</span>
</div>
<?php endif; ?>

<div class="card" style="max-width:780px;">
  <div class="card-header"><h3><i class="fas fa-id-card"></i> Resident Information</h3></div>
  <div class="card-body">
    <!-- NOTE: enctype MUST be multipart/form-data for file upload -->
    <form id="editForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="resident_id" value="<?= $rid ?>">

      <!-- ── Photo Section ─────────────────────────────────────────────── -->
      <div style="display:flex;align-items:flex-start;gap:24px;margin-bottom:24px;padding:18px;background:var(--primary-xlight);border-radius:10px;border:1.5px solid var(--border);">
        <!-- Current photo preview -->
        <div style="flex-shrink:0;text-align:center;">
          <div id="photoPreview" style="width:100px;height:100px;border-radius:10px;overflow:hidden;border:2px solid var(--border);background:#f0f0f0;display:flex;align-items:center;justify-content:center;">
            <?php if ($photo_url): ?>
              <img id="photoImg" src="<?= $photo_url ?>" alt="Resident photo"
                   style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
              <i class="fas fa-user" id="photoPlaceholder" style="font-size:2.5rem;color:#ccc;"></i>
            <?php endif; ?>
          </div>
          <div style="font-size:.72rem;color:var(--text-muted);margin-top:6px;">
            <?= $photo_url ? 'Current Photo' : 'No Photo' ?>
          </div>
        </div>

        <!-- Upload control -->
        <div style="flex:1;">
          <label style="font-size:.85rem;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">
            <i class="fas fa-camera"></i> Resident Photo
            <span style="font-size:.75rem;color:var(--text-muted);font-weight:400;"> (JPG/PNG, max 2MB, ID-style 2×2)</span>
          </label>
          <input type="file" name="photo" id="photoInput" accept="image/jpeg,image/png"
                 class="form-control" onchange="previewPhoto(this)" style="margin-bottom:8px;">
          <div id="photoError" style="color:var(--danger);font-size:.8rem;display:none;"></div>
          <div style="font-size:.78rem;color:var(--text-muted);">
            Leave blank to keep the current photo. Upload a new file to replace it.
          </div>
        </div>
      </div>

      <!-- ── Personal Info ─────────────────────────────────────────────── -->
      <div class="form-grid">
        <div class="form-group">
          <label>First Name <span class="req">*</span></label>
             <input type="text" name="first_name" class="form-control name-input"
               value="<?= sanitize_output($r['first_name']) ?>" required maxlength="100"
               pattern="[A-Za-zÀ-ÿ .'-]+" title="Letters, spaces, apostrophes, periods, and hyphens only">
        </div>
        <div class="form-group">
          <label>Middle Name</label>
             <input type="text" name="middle_name" class="form-control name-input"
               value="<?= sanitize_output($r['middle_name']) ?>" maxlength="100"
               pattern="[A-Za-zÀ-ÿ .'-]+" title="Letters, spaces, apostrophes, periods, and hyphens only">
        </div>
        <div class="form-group">
          <label>Last Name <span class="req">*</span></label>
             <input type="text" name="last_name" class="form-control name-input"
               value="<?= sanitize_output($r['last_name']) ?>" required maxlength="100"
               pattern="[A-Za-zÀ-ÿ .'-]+" title="Letters, spaces, apostrophes, periods, and hyphens only">
        </div>
        <div class="form-group">
          <label>Date of Birth <span class="req">*</span></label>
          <input type="date" name="birth_date" class="form-control"
                 value="<?= sanitize_output($r['birth_date']) ?>" required>
        </div>
        <div class="form-group">
          <label>Sex</label>
          <select name="sex" class="form-control">
            <option <?= $r['sex']==='Male'   ?'selected':'' ?>>Male</option>
            <option <?= $r['sex']==='Female' ?'selected':'' ?>>Female</option>
          </select>
        </div>
        <div class="form-group">
          <label>Civil Status</label>
          <select name="civil_status" class="form-control">
            <?php foreach(['Single','Married','Widowed','Separated'] as $cs): ?>
            <option <?= $r['civil_status']===$cs?'selected':'' ?>><?= $cs ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Contact Number</label>
             <input type="text" name="contact_number" class="form-control numeric-input"
               value="<?= sanitize_output($r['contact_number']) ?>" maxlength="20"
               inputmode="tel" pattern="[0-9+() .-]+" title="Numbers and phone characters only">
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" class="form-control"
                 value="<?= sanitize_output($r['email'] ?? '') ?>" maxlength="150">
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label>Address</label>
          <textarea name="address" class="form-control" rows="2"><?= sanitize_output($r['address'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label>Purok</label>
          <select name="purok" class="form-control">
            <option value="">-- Select Purok --</option>
            <?php foreach(['Purok 1','Purok 2','Purok 3','Purok 4'] as $p): ?>
            <option <?= ($r['purok'] ?? '')===$p ? 'selected' : '' ?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Drawer Location</label>
          <input type="text" name="drawer_location" class="form-control" maxlength="100"
                 value="<?= sanitize_output($r['drawer_location'] ?? '') ?>" placeholder="e.g. Drawer 2">
        </div>
        <div class="form-group">
          <label>Years of Residency</label>
             <input type="text" name="years_of_residency" class="form-control numeric-input" inputmode="numeric"
               value="<?= (int)$r['years_of_residency'] ?>" pattern="[0-9]+" min="0" max="200" title="Numbers only">
        </div>
        <div class="form-group" style="justify-content:flex-end;">
          <label>&nbsp;</label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;margin-top:8px;">
            <input type="checkbox" name="is_indigent" value="1"
                   <?= $r['is_indigent']?'checked':'' ?> style="width:16px;height:16px;">
            Mark as Indigent
          </label>
        </div>
      </div>

      <!-- ── Household Section ──────────────────────────────────────────── -->
      <div style="border:1.5px solid var(--border);border-radius:10px;padding:18px 20px;margin-top:4px;background:var(--primary-xlight);">
        <div style="font-weight:700;font-size:.88rem;color:var(--primary);margin-bottom:14px;">
          <i class="fas fa-house-user"></i> Household Information
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
              <input type="checkbox" name="is_head_of_family" id="isHeadChk" value="1"
                     <?= $r['is_head_of_family']?'checked':'' ?> style="width:16px;height:16px;"
                     onchange="toggleHouseholdHead()">
              Mark as Head of Family
            </label>
            <small style="color:var(--text-muted);font-size:.75rem;margin-top:4px;display:block;">
              Check if this resident is the head/representative of their household.
            </small>
          </div>
          <div class="form-group" id="householdHeadGroup" style="<?= $r['is_head_of_family']?'display:none;':'' ?>">
            <label>Household Head <small style="color:var(--text-muted);font-weight:400;">(if not head)</small></label>
            <!-- Searchable autocomplete for household head -->
            <div style="position:relative;">
              <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;z-index:1;"></i>
              <input type="text" id="hhSearchInput" placeholder="Search household head by name..."
                     class="form-control" style="padding-left:34px;" autocomplete="off"
                     oninput="filterHHOptions(this.value)" onfocus="showHHDropdown()">
              <input type="hidden" name="household_head_id" id="hhHiddenInput">
              <div id="hhDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;
                   background:#fff;border:1.5px solid var(--primary-light);border-top:none;
                   border-radius:0 0 8px 8px;max-height:200px;overflow-y:auto;z-index:200;
                   box-shadow:0 8px 20px rgba(0,0,0,.12);"></div>
            </div>
            <small id="hhHint" style="color:var(--text-muted);font-size:.75rem;margin-top:4px;display:block;">
              Type a name to search among registered household heads.
            </small>
          </div>
        </div>
        <!-- Household members count (shown if head) -->
        <?php
        $member_count = 0;
        if ($r['is_head_of_family']) {
            $mc = $conn->prepare("SELECT COUNT(*) FROM tbl_residents WHERE household_head_id=? AND is_archived=0");
            $mc->bind_param("i", $rid); $mc->execute();
            $member_count = (int)$mc->get_result()->fetch_row()[0]; $mc->close();
        }
        ?>
        <?php if ($r['is_head_of_family']): ?>
        <div style="margin-top:10px;padding:10px 14px;background:#e8f4fd;border-radius:8px;font-size:.83rem;color:#1a3a5c;">
          <i class="fas fa-people-group"></i>
          This resident is a <strong>Head of Family</strong> with
          <strong><?= $member_count ?></strong> household member<?= $member_count!==1?'s':'' ?> linked.
        </div>
        <?php endif; ?>
      </div>

      <div class="d-flex gap-2 mt-3" style="justify-content:flex-end;">
        <a href="index.php" class="btn btn-secondary">Cancel</a>
        <button type="button" class="btn btn-primary" onclick="submitEdit()"
                id="saveBtn" <?= $lock_warning ? 'disabled title="Record is locked by '.$lock_warning.'"' : '' ?>>
          <i class="fas fa-save"></i> Update Resident
        </button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.name-input').forEach(function (input) {
  input.addEventListener('input', function () { this.value = this.value.replace(/[\d]/g, ''); });
});
document.querySelectorAll('.numeric-input').forEach(function (input) {
  input.addEventListener('input', function () {
    this.value = this.name === 'years_of_residency'
      ? this.value.replace(/\D/g, '')
      : this.value.replace(/[^0-9+() .-]/g, '');
  });
});

// ── Live photo preview ────────────────────────────────────────────────────────
function previewPhoto(input) {
  const errEl = document.getElementById('photoError');
  errEl.style.display = 'none';

  if (!input.files || !input.files[0]) return;
  const file = input.files[0];

  // Client-side size check (server enforces too)
  if (file.size > 2 * 1024 * 1024) {
    errEl.textContent = 'Photo must be under 2MB.';
    errEl.style.display = 'block';
    input.value = '';
    return;
  }
  // Client-side type check
  if (!['image/jpeg','image/png'].includes(file.type)) {
    errEl.textContent = 'Only JPG and PNG images are allowed.';
    errEl.style.display = 'block';
    input.value = '';
    return;
  }

  const reader = new FileReader();
  reader.onload = e => {
    const preview = document.getElementById('photoPreview');
    preview.innerHTML = `<img src="${e.target.result}"
      style="width:100%;height:100%;object-fit:cover;border-radius:8px;" alt="Preview">`;
  };
  reader.readAsDataURL(file);
}

// ── Record Lock JS ───────────────────────────────────────────────────────────
<?php if (!$lock_warning): ?>
const LOCK_RID  = <?= $rid ?>;
const LOCK_CSRF = '<?= $csrf ?>';
let lockSeconds = 15 * 60; // 15 minutes

// Countdown display
const countdownEl = document.getElementById('lockCountdown');
const lockTimer = setInterval(() => {
  lockSeconds--;
  if (lockSeconds <= 0) {
    clearInterval(lockTimer);
    if (countdownEl) countdownEl.textContent = 'EXPIRED';
    const banner = document.getElementById('lockBanner');
    if (banner) banner.style.background = '#fff3cd';
    const saveBtn = document.getElementById('saveBtn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.title = 'Lock expired — please reload.'; }
    return;
  }
  const m = Math.floor(lockSeconds / 60);
  const s = lockSeconds % 60;
  if (countdownEl) countdownEl.textContent = m + ':' + String(s).padStart(2,'0');
}, 1000);

// Heartbeat every 4 minutes to renew lock
setInterval(async () => {
  const fd = new FormData();
  fd.append('csrf_token',  LOCK_CSRF);
  fd.append('resident_id', LOCK_RID);
  await fetch('/BRGYMS/residents/api.php?action=heartbeat', { method:'POST', body:fd });
  lockSeconds = 15 * 60; // reset countdown
}, 4 * 60 * 1000);

// Release lock on page leave (save, cancel, or close tab)
async function releaseLock() {
  const fd = new FormData();
  fd.append('csrf_token',  LOCK_CSRF);
  fd.append('resident_id', LOCK_RID);
  navigator.sendBeacon('/BRGYMS/residents/api.php?action=unlock', fd);
}
window.addEventListener('beforeunload', releaseLock);
window.addEventListener('pagehide',     releaseLock);

// Override submitEdit to release lock after save
const _origSubmitEdit = submitEdit;
window.submitEdit = async function() {
  window.removeEventListener('beforeunload', releaseLock);
  await _origSubmitEdit();
  releaseLock();
};
<?php endif; ?>
// ── Household Head helpers ────────────────────────────────────────────────────
const CURRENT_RID = <?= $rid ?>;
const CURRENT_HH  = <?= (int)($r['household_head_id'] ?? 0) ?>;

async function loadHouseholdHeads() {
  const sel = document.getElementById('householdHeadSelect');
  if (!sel) return;
  try {
    const resp = await fetch('/BRGYMS/residents/api.php?action=get_heads&csrf_token=<?= $csrf ?>');
    const data = await resp.json();
    if (!data.success) return;
    while (sel.options.length > 1) sel.remove(1);
    data.heads.forEach(h => {
      if (h.id === CURRENT_RID) return;
      const opt = new Option(`[${h.code}] ${h.name}${h.purok?' — '+h.purok:''}`, h.id);
      if (h.id === CURRENT_HH) opt.selected = true;
      sel.add(opt);
    });
  } catch(e) {}
}

function toggleHouseholdHead() {
  const isHead = document.getElementById('isHeadChk').checked;
  const grp    = document.getElementById('householdHeadGroup');
  if (grp) grp.style.display = isHead ? 'none' : 'block';
}

document.addEventListener('DOMContentLoaded', () => {
  const isHead = document.getElementById('isHeadChk')?.checked;
  if (!isHead) loadHouseholdHeads();
});

async function submitEdit() {
  const form = document.getElementById('editForm');

  // Client-side required check
  const fn = form.querySelector('[name=first_name]').value.trim();
  const ln = form.querySelector('[name=last_name]').value.trim();
  if (!fn || !ln) { showToast('error', 'First name and last name are required.'); return; }

  const fd = new FormData(form);
  fd.set('is_indigent',        form.querySelector('[name=is_indigent]')?.checked       ? '1' : '0');
  fd.set('is_head_of_family',  form.querySelector('[name=is_head_of_family]')?.checked ? '1' : '0');
  const hhSel = form.querySelector('[name=household_head_id]');
  fd.set('household_head_id', hhSel ? (hhSel.value || '0') : '0');

  try {
    const resp = await fetch('/BRGYMS/residents/api.php?action=update', {
      method: 'POST',
      body: fd   // no Content-Type header — browser sets multipart boundary automatically
    });
    const res = await resp.json();
    if (res.success) {
      showToast('success', 'Resident updated successfully!');
      setTimeout(() => window.location.href = 'index.php', 1500);
    } else {
      showToast('error', res.message || 'Update failed.');
    }
  } catch(e) {
    showToast('error', 'Network error. Please try again.');
  }
}
</script>
<?php require_once '../includes/footer.php'; ?>
