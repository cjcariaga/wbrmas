<?php
$page_title  = 'User Accounts';
$active_page = 'users';
$breadcrumb  = ['Administration', 'User Accounts'];
require_once '../includes/auth_check.php';
require_role('System Administrator');

$conn = getDBConnection();
$csrf = generate_csrf_token();

$users = $conn->query(
        "SELECT u.user_id, u.username, u.full_name, u.first_name, u.middle_name, u.last_name,
          u.is_active, u.failed_attempts, u.locked_until, u.created_at, r.role_name, r.role_id,
          ft.template_id AS fingerprint_id
         FROM tbl_users u JOIN tbl_roles r ON u.role_id=r.role_id
         LEFT JOIN tbl_fingerprint_templates ft ON ft.user_id=u.user_id
     ORDER BY u.created_at DESC"
);
$roles = $conn->query("SELECT * FROM tbl_roles ORDER BY role_id");

// Get all permissions grouped
$perms_raw = $conn->query("SELECT * FROM tbl_permissions ORDER BY perm_group, perm_label");
$perm_groups = [];
while ($p = $perms_raw->fetch_assoc()) {
    $perm_groups[$p['perm_group']][] = $p;
}

require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-users-cog"></i> User Accounts & Permissions</h2>
    <p>Admin registers users and assigns specific access permissions (RBAC)</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('modalAddUser')">
    <i class="fas fa-user-plus"></i> Register New User
  </button>
</div>

<div class="card">
  <div class="card-header"><h3><i class="fas fa-list"></i> Staff Accounts</h3></div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>#</th><th>Full Name</th><th>Username</th><th>Role</th><th>Status</th><th>Locked</th><th>Registered</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if ($users && $users->num_rows > 0): $i=1; while ($u=$users->fetch_assoc()): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td>
              <strong><?= sanitize_output($u['last_name'] ? "{$u['last_name']}, {$u['first_name']}" . ($u['middle_name'] ? " {$u['middle_name']}" : '') : $u['full_name']) ?></strong>
            </td>
            <td><code><?= sanitize_output($u['username']) ?></code></td>
            <td><span class="badge <?= $u['role_name']==='System Administrator'?'badge-danger':'badge-info' ?>"><?= sanitize_output($u['role_name']) ?></span></td>
            <td><?= $u['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>' ?></td>
            <td>
              <?php if ($u['locked_until'] && strtotime($u['locked_until'])>time()): ?>
                <span class="badge badge-danger"><i class="fas fa-lock"></i> Locked</span>
              <?php elseif ($u['failed_attempts']>0): ?>
                <span class="badge badge-warning"><?= (int)$u['failed_attempts'] ?> failed</span>
              <?php else: echo '<span style="color:var(--text-muted)">—</span>'; endif; ?>
            </td>
            <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
            <td>
              <div class="d-flex gap-2">
                <button class="btn btn-icon btn-sm" title="Manage Permissions"
                  onclick="managePermissions(<?= $u['user_id'] ?>,'<?= sanitize_output($u['full_name']) ?>')">
                  <i class="fas fa-shield-halved"></i>
                </button>
                <button class="btn btn-icon btn-sm" title="<?= $u['fingerprint_id'] ? 'Replace' : 'Enroll' ?> fingerprint"
                  style="color:<?= $u['fingerprint_id'] ? 'var(--success)' : 'var(--primary-light)' ?>;"
                  onclick='enrollFingerprint(<?= $u['user_id'] ?>, <?= htmlspecialchars(json_encode($u['full_name']), ENT_QUOTES, "UTF-8") ?>)'>
                  <i class="fas fa-fingerprint"></i>
                </button>
                <button class="btn btn-icon btn-sm" title="Edit"
                  onclick='editUser(<?= json_encode([
                    "id"=>$u['user_id'],
                    "username"=>$u['username'],
                    "first_name"=>$u['first_name'] ?? '',
                    "middle_name"=>$u['middle_name'] ?? '',
                    "last_name"=>$u['last_name'] ?? '',
                    "role_id"=>$u['role_id'],
                    "is_active"=>$u['is_active']
                  ]) ?>)'>
                  <i class="fas fa-pen-to-square"></i>
                </button>
                <button class="btn btn-icon btn-sm" title="Reset Password" onclick="resetPassword(<?= $u['user_id'] ?>,'<?= sanitize_output($u['username']) ?>')">
                  <i class="fas fa-key"></i>
                </button>
                <?php if ($u['failed_attempts']>0 || ($u['locked_until'] && strtotime($u['locked_until'])>time())): ?>
                <button class="btn btn-icon btn-sm" title="Unlock" style="color:var(--success);" onclick="unlockUser(<?= $u['user_id'] ?>)">
                  <i class="fas fa-lock-open"></i>
                </button>
                <?php endif; ?>
                <?php if ($u['user_id']!=(int)$_SESSION['user_id']): ?>
                <button class="btn btn-icon btn-sm" style="color:<?= $u['is_active']?'var(--danger)':'var(--success)' ?>;"
                  title="<?= $u['is_active']?'Deactivate':'Activate' ?>"
                  onclick="toggleUser(<?= $u['user_id'] ?>,<?= $u['is_active']?0:1 ?>,'<?= sanitize_output($u['full_name']) ?>')">
                  <i class="fas fa-<?= $u['is_active']?'user-slash':'user-check' ?>"></i>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="8"><div class="empty-state"><i class="fas fa-users"></i><p>No users found.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ADD USER MODAL -->
<div class="modal-overlay" id="modalAddUser">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header">
      <h4><i class="fas fa-user-plus"></i> Register New User</h4>
      <button class="modal-close" onclick="closeModal('modalAddUser')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <form id="addUserForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="form-grid">
          <div class="form-group">
            <label>Last Name <span class="req">*</span></label>
            <input type="text" name="last_name" class="form-control" required maxlength="80" placeholder="Dela Cruz">
          </div>
          <div class="form-group">
            <label>First Name <span class="req">*</span></label>
            <input type="text" name="first_name" class="form-control" required maxlength="80" placeholder="Juan">
          </div>
          <div class="form-group">
            <label>Middle Name</label>
            <input type="text" name="middle_name" class="form-control" maxlength="80" placeholder="Santos (optional)">
          </div>
          <div class="form-group">
            <label>Username <span class="req">*</span></label>
            <input type="text" name="username" class="form-control" required maxlength="50" autocomplete="off" placeholder="e.g. jdelacruz">
          </div>
          <div class="form-group">
            <label>Role <span class="req">*</span></label>
            <select name="role_id" class="form-control" required>
              <?php $roles->data_seek(0); while ($r=$roles->fetch_assoc()): ?>
              <option value="<?= $r['role_id'] ?>"><?= sanitize_output($r['role_name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Password <span class="req">*</span></label>
            <input type="password" name="password" id="addPw" class="form-control" required minlength="8" maxlength="100" autocomplete="new-password" placeholder="Min. 8 characters">
          </div>
          <div class="form-group">
            <label>Confirm Password <span class="req">*</span></label>
            <input type="password" name="confirm_password" class="form-control" required maxlength="100" autocomplete="new-password" placeholder="Re-enter password">
          </div>
        </div>
        <div id="pwStrength" style="font-size:.8rem;margin-top:4px;"></div>
        <p style="font-size:.8rem;color:var(--text-muted);margin-top:12px;">
          <i class="fas fa-info-circle"></i> After creating, use <strong>🛡️ Manage Permissions</strong> to assign access rights.
        </p>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalAddUser')">Cancel</button>
      <button class="btn btn-primary" onclick="submitAddUser()"><i class="fas fa-save"></i> Create Account</button>
    </div>
  </div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal-overlay" id="modalEditUser">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header">
      <h4><i class="fas fa-pen-to-square"></i> Edit User</h4>
      <button class="modal-close" onclick="closeModal('modalEditUser')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <form id="editUserForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="form-grid">
          <div class="form-group">
            <label>Last Name <span class="req">*</span></label>
            <input type="text" name="last_name" id="editLastName" class="form-control" required maxlength="80">
          </div>
          <div class="form-group">
            <label>First Name <span class="req">*</span></label>
            <input type="text" name="first_name" id="editFirstName" class="form-control" required maxlength="80">
          </div>
          <div class="form-group">
            <label>Middle Name</label>
            <input type="text" name="middle_name" id="editMiddleName" class="form-control" maxlength="80">
          </div>
          <div class="form-group">
            <label>Username <span class="req">*</span></label>
            <input type="text" name="username" id="editUsername" class="form-control" required maxlength="50">
          </div>
          <div class="form-group">
            <label>Role</label>
            <select name="role_id" id="editRoleId" class="form-control">
              <?php $roles->data_seek(0); while ($r=$roles->fetch_assoc()): ?>
              <option value="<?= $r['role_id'] ?>"><?= sanitize_output($r['role_name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="is_active" id="editIsActive" class="form-control">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalEditUser')">Cancel</button>
      <button class="btn btn-primary" onclick="submitEditUser()"><i class="fas fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- PERMISSIONS MODAL -->
<div class="modal-overlay" id="modalPermissions">
  <div class="modal" style="max-width:680px;">
    <div class="modal-header">
      <h4><i class="fas fa-shield-halved"></i> Manage Permissions — <span id="permUserName"></span></h4>
      <button class="modal-close" onclick="closeModal('modalPermissions')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div style="background:#e8f4fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#1a6fa8;">
        <i class="fas fa-info-circle"></i> Check the permissions this user is allowed to access. Unchecked = blocked.
      </div>
      <form id="permForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="user_id" id="permUserId">
        <div id="permCheckboxes">
          <?php foreach ($perm_groups as $group => $perms): ?>
          <div style="margin-bottom:18px;">
            <div style="font-weight:700;color:var(--primary);font-size:.85rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;">
              <span><i class="fas fa-folder"></i> <?= sanitize_output($group) ?></span>
              <label style="font-weight:500;font-size:.78rem;cursor:pointer;">
                <input type="checkbox" class="grp-toggle" data-group="<?= sanitize_output($group) ?>" onchange="toggleGroup(this,'<?= sanitize_output($group) ?>')"> Select All
              </label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <?php foreach ($perms as $p): ?>
              <label style="display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;transition:all .15s;" class="perm-card" data-group="<?= sanitize_output($group) ?>">
                <input type="checkbox" name="perms[]" value="<?= sanitize_output($p['perm_key']) ?>"
                  class="perm-cb" data-group="<?= sanitize_output($group) ?>"
                  style="width:16px;height:16px;flex-shrink:0;margin-top:2px;">
                <div>
                  <div style="font-size:.85rem;font-weight:500;"><?= sanitize_output($p['perm_label']) ?></div>
                  <div style="font-size:.72rem;color:var(--text-muted);"><?= sanitize_output($p['perm_key']) ?></div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalPermissions')">Cancel</button>
      <button class="btn btn-success" onclick="savePermissions()"><i class="fas fa-shield-halved"></i> Save Permissions</button>
    </div>
  </div>
</div>

<!-- RESET PW MODAL -->
<div class="modal-overlay" id="modalResetPw">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h4><i class="fas fa-key"></i> Reset Password</h4>
      <button class="modal-close" onclick="closeModal('modalResetPw')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p id="resetPwLabel" style="margin-bottom:14px;font-size:.88rem;color:var(--text-muted);"></p>
      <form id="resetPwForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="user_id" id="resetUserId">
        <div class="form-group">
          <label>New Password <span class="req">*</span></label>
          <input type="password" name="new_password" class="form-control" required minlength="8" maxlength="100">
        </div>
        <div class="form-group mt-2">
          <label>Confirm <span class="req">*</span></label>
          <input type="password" name="confirm_new_password" class="form-control" required maxlength="100">
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalResetPw')">Cancel</button>
      <button class="btn btn-danger" onclick="submitResetPw()"><i class="fas fa-key"></i> Reset</button>
    </div>
  </div>
</div>

<script>
// Password strength
document.getElementById('addPw')?.addEventListener('input', function(){
  const v=this.value, el=document.getElementById('pwStrength');
  let s=0;
  if(v.length>=8)s++; if(/[A-Z]/.test(v))s++; if(/[0-9]/.test(v))s++; if(/[^A-Za-z0-9]/.test(v))s++;
  const lvl=['','<span style="color:#e74c3c">Weak</span>','<span style="color:#f39c12">Fair</span>','<span style="color:#2980b9">Good</span>','<span style="color:#27ae60">Strong ✓</span>'];
  el.innerHTML = v ? 'Password strength: '+lvl[s] : '';
});

// Toggle all checkboxes in a group
function toggleGroup(el, group) {
  document.querySelectorAll('.perm-cb[data-group="'+group+'"]').forEach(cb => {
    cb.checked = el.checked;
    cb.closest('.perm-card').style.borderColor = el.checked ? 'var(--primary-light)' : 'var(--border)';
    cb.closest('.perm-card').style.background  = el.checked ? 'var(--primary-xlight)' : '#fff';
  });
}

// Highlight card when checked
document.addEventListener('change', function(e){
  if (e.target.classList.contains('perm-cb')) {
    const card = e.target.closest('.perm-card');
    card.style.borderColor = e.target.checked ? 'var(--primary-light)' : 'var(--border)';
    card.style.background  = e.target.checked ? 'var(--primary-xlight)' : '#fff';
  }
});

async function managePermissions(uid, name) {
  document.getElementById('permUserId').value = uid;
  document.getElementById('permUserName').textContent = name;
  // Reset all
  document.querySelectorAll('.perm-cb').forEach(cb => {
    cb.checked = false;
    cb.closest('.perm-card').style.borderColor = 'var(--border)';
    cb.closest('.perm-card').style.background  = '#fff';
  });
  // Load current perms
  const res = await apiRequest('/admin/users_api.php?action=get_perms', {user_id:uid, csrf_token:'<?= $csrf ?>'});
  if (res.success) {
    res.perms.forEach(pk => {
      const cb = document.querySelector('.perm-cb[value="'+pk+'"]');
      if (cb) {
        cb.checked = true;
        cb.closest('.perm-card').style.borderColor = 'var(--primary-light)';
        cb.closest('.perm-card').style.background  = 'var(--primary-xlight)';
      }
    });
  }
  openModal('modalPermissions');
}

async function savePermissions() {
  const uid   = document.getElementById('permUserId').value;
  const perms = [...document.querySelectorAll('.perm-cb:checked')].map(cb=>cb.value);
  const res = await apiRequest('/admin/users_api.php?action=save_perms', {
    user_id: uid, perms: JSON.stringify(perms), csrf_token: '<?= $csrf ?>'
  });
  if (res.success) { showToast('success','Permissions saved!'); closeModal('modalPermissions'); }
  else showToast('error', res.message||'Failed to save permissions.');
}

async function submitAddUser() {
  const form = document.getElementById('addUserForm');
  const data = Object.fromEntries(new FormData(form));
  if (data.password !== data.confirm_password) { showToast('error','Passwords do not match.'); return; }
  if (data.password.length < 8) { showToast('error','Password must be at least 8 characters.'); return; }
  const res = await apiRequest('/admin/users_api.php?action=add', data);
  if (res.success) {
    showToast('success','User created! Now assign permissions via the 🛡️ button.');
    closeModal('modalAddUser');
    setTimeout(()=>location.reload(),1500);
  } else showToast('error', res.message||'Failed.');
}

async function enrollFingerprint(userId, userName) {
  if (!confirm(`Enroll or replace fingerprint for ${userName}?`)) return;
  try {
    const bridge = await fetch('http://127.0.0.1:8765/health');
    if (!bridge.ok) throw new Error('Fingerprint bridge is unavailable.');
    const health = await bridge.json();
    if (!health.success) throw new Error(health.message || 'Fingerprint bridge is unavailable.');

    showToast('info', 'Place the same finger on the ZK9500 three times.');
    const enroll = await fetch('http://127.0.0.1:8765/enroll', { method:'POST' });
    const result = await enroll.json();
    if (!result.success || !result.template) {
      showToast('error', result.message || 'Fingerprint enrollment failed.');
      return;
    }

    const saved = await apiRequest('/fingerprint_api.php?action=save_template', {
      csrf_token: '<?= $csrf ?>', user_id: userId, template: result.template, device_info: 'ZKTeco ZK9500'
    });
    if (saved.success) {
      showToast('success', 'Fingerprint enrolled successfully.');
      setTimeout(() => location.reload(), 1000);
    } else showToast('error', saved.message || 'Failed to save fingerprint.');
  } catch (error) {
    showToast('error', error.message || 'Fingerprint bridge is unavailable.');
  }
}

function editUser(d) {
  document.getElementById('editUserId').value     = d.id;
  document.getElementById('editLastName').value   = d.last_name;
  document.getElementById('editFirstName').value  = d.first_name;
  document.getElementById('editMiddleName').value = d.middle_name;
  document.getElementById('editUsername').value   = d.username;
  document.getElementById('editRoleId').value     = d.role_id;
  document.getElementById('editIsActive').value   = d.is_active;
  openModal('modalEditUser');
}

async function submitEditUser() {
  const data = Object.fromEntries(new FormData(document.getElementById('editUserForm')));
  const res = await apiRequest('/admin/users_api.php?action=update', data);
  if (res.success) { showToast('success','User updated!'); closeModal('modalEditUser'); setTimeout(()=>location.reload(),1200); }
  else showToast('error', res.message||'Failed.');
}

function resetPassword(id, username) {
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetPwLabel').textContent = 'Resetting password for: ' + username;
  document.getElementById('resetPwForm').reset();
  document.getElementById('resetUserId').value = id;
  openModal('modalResetPw');
}

async function submitResetPw() {
  const data = Object.fromEntries(new FormData(document.getElementById('resetPwForm')));
  if (data.new_password !== data.confirm_new_password) { showToast('error','Passwords do not match.'); return; }
  const res = await apiRequest('/admin/users_api.php?action=reset_password', data);
  if (res.success) { showToast('success','Password reset!'); closeModal('modalResetPw'); }
  else showToast('error', res.message||'Failed.');
}

async function unlockUser(id) {
  const res = await apiRequest('/admin/users_api.php?action=unlock',{user_id:id,csrf_token:'<?= $csrf ?>'});
  if (res.success) { showToast('success','Account unlocked!'); setTimeout(()=>location.reload(),1000); }
  else showToast('error','Failed.');
}

async function toggleUser(id, status, name) {
  if (!confirm((status?'Activate':'Deactivate')+' account for '+name+'?')) return;
  const res = await apiRequest('/admin/users_api.php?action=toggle',{user_id:id,is_active:status,csrf_token:'<?= $csrf ?>'});
  if (res.success) { showToast('success','Done!'); setTimeout(()=>location.reload(),1000); }
  else showToast('error','Failed.');
}
</script>
<?php require_once '../includes/footer.php'; ?>
