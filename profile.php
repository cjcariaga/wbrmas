<?php
$page_title  = 'My Profile';
$active_page = 'profile';
$breadcrumb  = ['Account', 'My Profile'];
require_once 'includes/auth_check.php';

$conn = getDBConnection();
$csrf = generate_csrf_token();
$uid  = current_user_id();

// Fetch current user data
$stmt = $conn->prepare(
    "SELECT u.*, r.role_name FROM tbl_users u
     JOIN tbl_roles r ON u.role_id = r.role_id
     WHERE u.user_id = ?"
);
$stmt->bind_param("i", $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch this user's permissions
$perms_res = $conn->query("SELECT perm_key FROM tbl_user_permissions WHERE user_id=$uid AND granted=1");
$my_perms = [];
while ($p = $perms_res->fetch_assoc()) $my_perms[] = $p['perm_key'];

// Fetch recent activity (last 10 logs)
$activity = $conn->query(
    "SELECT action_type, affected_record, ip_address, timestamp, details
     FROM tbl_audit_logs WHERE user_id=$uid
     ORDER BY timestamp DESC LIMIT 10"
);

// Stats for this user
$docs_issued = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests WHERE issued_by_user_id=$uid AND status='PRINTED'")->fetch_row()[0];
$blotter_filed = (int)$conn->query("SELECT COUNT(*) FROM tbl_blotter WHERE filed_by=$uid")->fetch_row()[0];
$residents_added = (int)$conn->query("SELECT COUNT(*) FROM tbl_residents WHERE registered_by=$uid AND is_archived=0")->fetch_row()[0];
$total_actions = (int)$conn->query("SELECT COUNT(*) FROM tbl_audit_logs WHERE user_id=$uid")->fetch_row()[0];

require_once 'includes/header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-user-circle"></i> My Profile</h2>
  <p>Manage your account information and change your password</p>
</div>

<div class="d-flex gap-2 flex-wrap" style="align-items:flex-start;">

  <!-- LEFT: Profile Card -->
  <div style="flex:1;min-width:280px;max-width:340px;">
    <div class="card mb-3">
      <div class="card-body" style="text-align:center;padding:32px 24px;">
        <!-- Avatar -->
        <div style="width:90px;height:90px;border-radius:50%;background:linear-gradient(135deg,#1a3a5c,#2980b9);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
          <i class="fas fa-user" style="font-size:2.5rem;color:#fff;"></i>
        </div>
        <h3 style="font-size:1.1rem;font-weight:700;color:var(--primary);">
          <?= sanitize_output($user['full_name']) ?>
        </h3>
        <div style="margin:8px 0;">
          <span class="badge <?= $user['role_name']==='System Administrator'?'badge-danger':'badge-info' ?>" style="font-size:.8rem;">
            <i class="fas fa-shield-halved"></i> <?= sanitize_output($user['role_name']) ?>
          </span>
        </div>
        <div style="color:var(--text-muted);font-size:.85rem;margin-top:8px;">
          <i class="fas fa-at"></i> <?= sanitize_output($user['username']) ?>
        </div>
        <div style="color:var(--text-muted);font-size:.78rem;margin-top:6px;">
          <i class="fas fa-calendar"></i> Member since <?= date('M d, Y', strtotime($user['created_at'])) ?>
        </div>
        <div style="color:var(--text-muted);font-size:.78rem;margin-top:6px;">
          <i class="fas fa-right-to-bracket" style="color:var(--success);"></i>
          Last login:
          <?= $user['last_login'] ? '<strong style="color:var(--success);">'.date('M d, Y h:i A', strtotime($user['last_login'])).'</strong>' : '<em>No record yet</em>' ?>
        </div>
        <hr style="margin:16px 0;border-color:var(--border);">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;text-align:center;">
          <div style="background:#e8f4fd;border-radius:8px;padding:10px;">
            <div style="font-size:1.3rem;font-weight:700;color:#2980b9;"><?= $docs_issued ?></div>
            <div style="font-size:.7rem;color:var(--text-muted);">Docs Issued</div>
          </div>
          <div style="background:#eafaf1;border-radius:8px;padding:10px;">
            <div style="font-size:1.3rem;font-weight:700;color:#27ae60;"><?= $residents_added ?></div>
            <div style="font-size:.7rem;color:var(--text-muted);">Residents Added</div>
          </div>
          <div style="background:#fef9e7;border-radius:8px;padding:10px;">
            <div style="font-size:1.3rem;font-weight:700;color:#d68910;"><?= $blotter_filed ?></div>
            <div style="font-size:.7rem;color:var(--text-muted);">Blotter Filed</div>
          </div>
          <div style="background:#f4f0fb;border-radius:8px;padding:10px;">
            <div style="font-size:1.3rem;font-weight:700;color:#8e44ad;"><?= $total_actions ?></div>
            <div style="font-size:.7rem;color:var(--text-muted);">Total Actions</div>
          </div>
        </div>
      </div>
    </div>

    <!-- My Permissions -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-shield-halved"></i> My Permissions</h3></div>
      <div class="card-body" style="padding:16px;">
        <?php if (is_admin()): ?>
          <div style="background:#fdedec;border-radius:8px;padding:10px 14px;font-size:.82rem;color:#c0392b;font-weight:600;">
            <i class="fas fa-crown"></i> System Administrator — Full Access
          </div>
        <?php elseif (empty($my_perms)): ?>
          <div style="background:#fef9e7;border-radius:8px;padding:10px 14px;font-size:.82rem;color:#d68910;">
            <i class="fas fa-exclamation-triangle"></i> No permissions assigned yet. Contact administrator.
          </div>
        <?php else: ?>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($my_perms as $pk): ?>
              <span class="badge badge-info" style="font-size:.72rem;"><?= sanitize_output($pk) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- RIGHT: Edit & Password -->
  <div style="flex:2;min-width:300px;">

    <!-- Edit Profile -->
    <div class="card mb-3">
      <div class="card-header"><h3><i class="fas fa-pen-to-square"></i> Edit Profile Information</h3></div>
      <div class="card-body">
        <div id="profileAlert"></div>
        <form id="profileForm">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <div class="form-grid">
            <div class="form-group">
              <label>Last Name <span class="req">*</span></label>
              <input type="text" name="last_name" class="form-control" required maxlength="80"
                value="<?= sanitize_output($user['last_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>First Name <span class="req">*</span></label>
              <input type="text" name="first_name" class="form-control" required maxlength="80"
                value="<?= sanitize_output($user['first_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Middle Name</label>
              <input type="text" name="middle_name" class="form-control" maxlength="80"
                value="<?= sanitize_output($user['middle_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Username</label>
              <input type="text" class="form-control" value="<?= sanitize_output($user['username']) ?>" disabled
                style="background:#f8f9fa;color:var(--text-muted);">
              <small style="color:var(--text-muted);">Username cannot be changed. Contact admin.</small>
            </div>
          </div>
          <div class="d-flex gap-2 mt-3" style="justify-content:flex-end;">
            <button type="button" class="btn btn-primary" onclick="saveProfile()">
              <i class="fas fa-save"></i> Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Change Password -->
    <div class="card mb-3">
      <div class="card-header"><h3><i class="fas fa-key"></i> Change Password</h3></div>
      <div class="card-body">
        <div id="pwAlert"></div>
        <form id="pwForm">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1;">
              <label>Current Password <span class="req">*</span></label>
              <div style="position:relative;">
                <input type="password" name="current_password" id="curPw" class="form-control" required maxlength="100" placeholder="Enter your current password" style="padding-right:44px;">
                <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;color:#aaa;" onclick="toggleField('curPw','eyeCur')">
                  <i class="fas fa-eye" id="eyeCur"></i>
                </span>
              </div>
            </div>
            <div class="form-group">
              <label>New Password <span class="req">*</span></label>
              <div style="position:relative;">
                <input type="password" name="new_password" id="newPw" class="form-control" required minlength="8" maxlength="100" placeholder="Min. 8 characters" style="padding-right:44px;" oninput="checkPwStrength(this.value)">
                <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;color:#aaa;" onclick="toggleField('newPw','eyeNew')">
                  <i class="fas fa-eye" id="eyeNew"></i>
                </span>
              </div>
              <div id="strengthBar" style="margin-top:6px;"></div>
            </div>
            <div class="form-group">
              <label>Confirm New Password <span class="req">*</span></label>
              <div style="position:relative;">
                <input type="password" name="confirm_password" id="conPw" class="form-control" required maxlength="100" placeholder="Re-enter new password" style="padding-right:44px;">
                <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;color:#aaa;" onclick="toggleField('conPw','eyeCon')">
                  <i class="fas fa-eye" id="eyeCon"></i>
                </span>
              </div>
            </div>
          </div>
          <div style="background:#f8f9fa;border-radius:8px;padding:12px 14px;font-size:.8rem;color:var(--text-muted);margin-top:12px;">
            <strong>Password requirements:</strong>
            <ul style="margin-top:6px;padding-left:18px;line-height:2;">
              <li id="req_len" style="color:#ccc;">At least 8 characters</li>
              <li id="req_upper" style="color:#ccc;">At least one uppercase letter (A-Z)</li>
              <li id="req_num" style="color:#ccc;">At least one number (0-9)</li>
              <li id="req_special" style="color:#ccc;">At least one special character (!@#$...)</li>
            </ul>
          </div>
          <div class="d-flex gap-2 mt-3" style="justify-content:flex-end;">
            <button type="button" class="btn btn-danger" onclick="changePassword()">
              <i class="fas fa-key"></i> Update Password
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Recent Activity -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-clock-rotate-left"></i> My Recent Activity</h3></div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
          <table>
            <thead><tr><th>Action</th><th>Record</th><th>IP</th><th>Time</th></tr></thead>
            <tbody>
            <?php if ($activity && $activity->num_rows > 0):
              while ($log = $activity->fetch_assoc()):
                $ac = ['LOGIN_SUCCESS'=>'success','LOGIN_FAILED'=>'danger','LOGOUT'=>'secondary','CREATE_RESIDENT'=>'info','UPDATE_RESIDENT'=>'info','CREATE_DOC_REQUEST'=>'info','PRINT_DOCUMENT'=>'success'];
            ?>
              <tr>
                <td><span class="badge badge-<?= $ac[$log['action_type']] ?? 'secondary' ?>"><?= sanitize_output($log['action_type']) ?></span></td>
                <td style="font-size:.8rem;"><?= sanitize_output($log['affected_record'] ?: '—') ?></td>
                <td style="font-size:.78rem;font-family:monospace;"><?= sanitize_output($log['ip_address'] ?: '—') ?></td>
                <td style="font-size:.78rem;white-space:nowrap;"><?= date('M d, h:i A', strtotime($log['timestamp'])) ?></td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="4"><div class="empty-state"><p>No activity yet.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- right col -->
</div>

<script>
function toggleField(id, iconId) {
  const f = document.getElementById(id), i = document.getElementById(iconId);
  f.type = f.type === 'password' ? 'text' : 'password';
  i.className = f.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}

function checkPwStrength(v) {
  const reqs = [
    { id:'req_len',     ok: v.length >= 8 },
    { id:'req_upper',   ok: /[A-Z]/.test(v) },
    { id:'req_num',     ok: /[0-9]/.test(v) },
    { id:'req_special', ok: /[^A-Za-z0-9]/.test(v) }
  ];
  let score = 0;
  reqs.forEach(r => {
    const el = document.getElementById(r.id);
    el.style.color = r.ok ? '#27ae60' : '#ccc';
    el.innerHTML = (r.ok ? '✅ ' : '○ ') + el.innerHTML.replace(/^[✅○]\s*/,'');
    if (r.ok) score++;
  });
  const bars = ['','<div style="height:6px;border-radius:3px;background:#e74c3c;width:25%"></div>','<div style="height:6px;border-radius:3px;background:#f39c12;width:50%"></div>','<div style="height:6px;border-radius:3px;background:#2980b9;width:75%"></div>','<div style="height:6px;border-radius:3px;background:#27ae60;width:100%"></div>'];
  document.getElementById('strengthBar').innerHTML = v ? `<div style="background:#eee;border-radius:3px;">${bars[score]||''}</div>` : '';
}

function showAlert(elId, type, msg) {
  const colors = {success:'#eafaf1;border:1px solid #a9dfbf;color:#1e8449', error:'#fdedec;border:1px solid #f1948a;color:#c0392b'};
  document.getElementById(elId).innerHTML = `<div style="padding:10px 14px;border-radius:8px;font-size:.85rem;margin-bottom:14px;background:${colors[type]};display:flex;align-items:center;gap:8px;"><i class="fas fa-${type==='success'?'circle-check':'circle-xmark'}"></i>${msg}</div>`;
}

async function saveProfile() {
  const data = Object.fromEntries(new FormData(document.getElementById('profileForm')));
  if (!data.first_name || !data.last_name) { showAlert('profileAlert','error','First name and last name are required.'); return; }
  const res = await apiRequest('/profile_api.php?action=update_profile', data);
  if (res.success) {
    showAlert('profileAlert','success','Profile updated successfully!');
    showToast('success','Profile saved!');
    setTimeout(()=>location.reload(),1500);
  } else showAlert('profileAlert','error', res.message||'Update failed.');
}

async function changePassword() {
  const form = document.getElementById('pwForm');
  const data = Object.fromEntries(new FormData(form));
  if (!data.current_password)  { showAlert('pwAlert','error','Please enter your current password.'); return; }
  if (data.new_password.length < 8) { showAlert('pwAlert','error','New password must be at least 8 characters.'); return; }
  if (data.new_password !== data.confirm_password) { showAlert('pwAlert','error','New passwords do not match.'); return; }
  if (data.current_password === data.new_password) { showAlert('pwAlert','error','New password must be different from current password.'); return; }
  const res = await apiRequest('/profile_api.php?action=change_password', data);
  if (res.success) {
    showAlert('pwAlert','success','Password changed successfully! Please remember your new password.');
    showToast('success','Password updated!');
    form.reset();
    document.getElementById('strengthBar').innerHTML = '';
  } else showAlert('pwAlert','error', res.message||'Failed to change password.');
}
</script>
<?php require_once 'includes/footer.php'; ?>
