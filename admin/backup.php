<?php
$page_title  = 'Database Backup';
$active_page = 'analytics';
$breadcrumb  = ['Administration', 'Backup'];
require_once '../includes/auth_check.php';
require_role('System Administrator');

$conn = getDBConnection();
$csrf = generate_csrf_token();
$uid  = (int)$_SESSION['user_id'];

// ── Backup config ─────────────────────────────────────────────────────────────
$config = $conn->query("SELECT * FROM tbl_backup_config WHERE config_id=1 LIMIT 1")->fetch_assoc();
$schedule   = $config['schedule']    ?? 'disabled';
$backup_dir = $config['backup_path'] ?? '';

// ── Last successful backup ────────────────────────────────────────────────────
$last_backup = $conn->query(
    "SELECT * FROM tbl_backup_log WHERE status='success' ORDER BY created_at DESC LIMIT 1"
)->fetch_assoc();

// ── Backup history ────────────────────────────────────────────────────────────
$history = $conn->query(
    "SELECT b.*, u.full_name FROM tbl_backup_log b
     LEFT JOIN tbl_users u ON b.created_by=u.user_id
     ORDER BY b.created_at DESC LIMIT 20"
);

// ── Check if scheduled backup is due ─────────────────────────────────────────
if ($schedule !== 'disabled' && $backup_dir) {
    $last_ts = $last_backup ? strtotime($last_backup['created_at']) : 0;
    $now     = time();
    $due     = false;
    if ($schedule === 'daily'  && ($now - $last_ts) >= 86400)  $due = true;
    if ($schedule === 'weekly' && ($now - $last_ts) >= 604800) $due = true;
    if ($due) {
        // Auto-trigger scheduled backup (will run below if action=do_backup is called)
        // Just flag it for the UI — actual backup triggered via POST
    }
}

// ── Backup function ───────────────────────────────────────────────────────────
function run_backup($conn, $uid, $dir, $triggered_by) {
    if (!$dir) return ['success'=>false,'message'=>'Backup directory not configured.'];

    // Resolve absolute path
    if (!str_starts_with($dir, '/') && !preg_match('/^[A-Za-z]:/', $dir)) {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . $dir;
    }

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            return ['success'=>false,'message'=>'Cannot create backup directory: '.$dir];
        }
    }
    if (!is_writable($dir)) {
        return ['success'=>false,'message'=>'Backup directory is not writable: '.$dir];
    }

    $filename   = 'wbrmas_backup_'.date('Ymd_His').'.sql';
    $filepath   = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    $mysql_path = 'c:\\xampp\\mysql\\bin\\mysqldump.exe';

    // Build command safely
    $cmd = sprintf(
        '"%s" -u %s --password=%s %s > "%s" 2>&1',
        $mysql_path,
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_NAME),
        $filepath
    );

    exec($cmd, $output, $return_code);

    if ($return_code !== 0 || !file_exists($filepath) || filesize($filepath) < 100) {
        $notes = implode("\n", $output);
        $conn->prepare(
            "INSERT INTO tbl_backup_log (filename,file_path,file_size,triggered_by,created_by,status,notes)
             VALUES (?,?,0,?,?,'failed',?)"
        )->execute();
        // Use direct insert
        $stmt = $conn->prepare(
            "INSERT INTO tbl_backup_log (filename,file_path,file_size,triggered_by,created_by,status,notes)
             VALUES (?,?,0,?,?,'failed',?)"
        );
        $stmt->bind_param("sssss", $filename, $filepath, $triggered_by, $uid, $notes);
        $stmt->execute(); $stmt->close();
        return ['success'=>false,'message'=>'mysqldump failed. '.($notes?substr($notes,0,200):'')];
    }

    $size = filesize($filepath);
    $stmt = $conn->prepare(
        "INSERT INTO tbl_backup_log (filename,file_path,file_size,triggered_by,created_by,status)
         VALUES (?,?,?,?,?,'success')"
    );
    $stmt->bind_param("sssii", $filename, $filepath, $size, $triggered_by, $uid);
    $stmt->execute(); $stmt->close();

    write_audit_log($uid, 'DATABASE_BACKUP', 'backup', "File:$filename Size:$size Triggered:$triggered_by");
    return ['success'=>true,'filename'=>$filename,'size'=>$size,'path'=>$filepath];
}

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die(json_encode(['success'=>false,'message'=>'Invalid CSRF token.']));
    }
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    if ($action === 'backup_now') {
        $result = run_backup($conn, $uid, $backup_dir, 'manual');
        echo json_encode($result); exit;
    }

    if ($action === 'save_config') {
        $new_schedule = sanitize_input($_POST['schedule']    ?? 'disabled');
        $new_path     = sanitize_input($_POST['backup_path'] ?? '');

        $allowed = ['disabled','daily','weekly'];
        if (!in_array($new_schedule, $allowed)) $new_schedule = 'disabled';

        $stmt = $conn->prepare(
            "UPDATE tbl_backup_config SET schedule=?, backup_path=?, updated_by=? WHERE config_id=1"
        );
        $stmt->bind_param("ssi", $new_schedule, $new_path, $uid);
        $stmt->execute(); $stmt->close();

        write_audit_log($uid, 'UPDATE_BACKUP_CONFIG', 'backup_config',
            "Schedule:$new_schedule Path:$new_path");
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'scheduled_backup') {
        // Called via session trigger for auto-backup
        $result = run_backup($conn, $uid, $backup_dir, 'scheduled');
        echo json_encode($result); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

require_once '../includes/header.php';

// ── Check if scheduled backup is due and show prompt ─────────────────────────
$backup_due = false;
if ($schedule !== 'disabled' && $backup_dir) {
    $last_ts = $last_backup ? strtotime($last_backup['created_at']) : 0;
    $now     = time();
    if ($schedule === 'daily'  && ($now - $last_ts) >= 86400)  $backup_due = true;
    if ($schedule === 'weekly' && ($now - $last_ts) >= 604800) $backup_due = true;
}
?>

<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-database"></i> Database Backup</h2>
    <p>Manage local backups of the WBRMAS database</p>
  </div>
</div>

<!-- Scheduled backup due alert -->
<?php if ($backup_due): ?>
<div style="background:#fff3cd;border:1.5px solid #ffc107;border-radius:10px;padding:14px 18px;
            margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
  <div style="display:flex;align-items:center;gap:10px;">
    <i class="fas fa-triangle-exclamation" style="color:#d68910;font-size:1.2rem;"></i>
    <div>
      <strong style="color:#856404;">Scheduled Backup Due</strong><br>
      <span style="font-size:.83rem;color:#856404;">
        Your <?= $schedule ?> backup is overdue. Last backup:
        <?= $last_backup ? date('M d, Y h:i A', strtotime($last_backup['created_at'])) : 'Never' ?>
      </span>
    </div>
  </div>
  <button class="btn btn-warning btn-sm" onclick="runBackup()">
    <i class="fas fa-play"></i> Run Backup Now
  </button>
</div>
<?php endif; ?>

<div class="d-flex gap-3 flex-wrap" style="align-items:flex-start;">

  <!-- Left col: Backup now + config -->
  <div style="flex:0 0 340px;min-width:280px;">

    <!-- Backup Now -->
    <div class="card mb-3">
      <div class="card-header"><h3><i class="fas fa-play-circle"></i> Manual Backup</h3></div>
      <div class="card-body" style="padding:20px;">
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px;">
          Creates an immediate full backup of the <code>wbrmas_db</code> database to the configured directory.
        </p>
        <?php if ($last_backup): ?>
        <div style="background:var(--primary-xlight);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem;">
          <i class="fas fa-circle-check" style="color:var(--success);"></i>
          <strong>Last backup:</strong> <?= date('M d, Y h:i A', strtotime($last_backup['created_at'])) ?><br>
          <span style="color:var(--text-muted);">
            <?= sanitize_output($last_backup['filename']) ?>
            (<?= number_format($last_backup['file_size'] / 1024, 1) ?> KB)
          </span>
        </div>
        <?php else: ?>
        <div style="background:#fff3cd;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem;color:#856404;">
          <i class="fas fa-circle-exclamation"></i> No backups yet.
        </div>
        <?php endif; ?>
        <button class="btn btn-primary w-100" onclick="runBackup()" id="backupBtn">
          <i class="fas fa-database"></i> Backup Now
        </button>
        <div id="backupStatus" style="margin-top:10px;font-size:.82rem;display:none;"></div>
      </div>
    </div>

    <!-- Config -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-gear"></i> Backup Configuration</h3></div>
      <div class="card-body" style="padding:20px;">
        <form id="configForm">
          <div class="form-group mb-3">
            <label style="font-size:.85rem;font-weight:600;">Schedule</label>
            <select name="schedule" id="scheduleSelect" class="form-control">
              <option value="disabled" <?= $schedule==='disabled'?'selected':'' ?>>Disabled</option>
              <option value="daily"    <?= $schedule==='daily'   ?'selected':'' ?>>Daily</option>
              <option value="weekly"   <?= $schedule==='weekly'  ?'selected':'' ?>>Weekly</option>
            </select>
            <small style="color:var(--text-muted);font-size:.75rem;">
              Note: "Daily/Weekly" triggers a backup the next time an admin opens this page after the interval.
            </small>
          </div>
          <div class="form-group mb-3">
            <label style="font-size:.85rem;font-weight:600;">Backup Directory</label>
            <input type="text" name="backup_path" class="form-control"
                   value="<?= sanitize_output($backup_dir) ?>"
                   placeholder="e.g. C:\backups\wbrmas or backups/wbrmas">
            <small style="color:var(--text-muted);font-size:.75rem;">
              Absolute path or relative to BRGYMS root. Directory will be created if it doesn't exist.
            </small>
          </div>
          <button type="button" class="btn btn-primary btn-sm w-100" onclick="saveConfig()">
            <i class="fas fa-save"></i> Save Configuration
          </button>
        </form>
      </div>
    </div>

  </div>

  <!-- Right col: Backup history -->
  <div style="flex:1;min-width:300px;">
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-history"></i> Backup History</h3></div>
      <div class="card-body" style="padding:0;">
        <?php if ($history && $history->num_rows > 0): ?>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th><th>Filename</th><th>Size</th><th>Triggered By</th>
                <th>Created By</th><th>Status</th><th>Date</th>
              </tr>
            </thead>
            <tbody>
            <?php $i=1; while ($h = $history->fetch_assoc()): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td style="font-size:.78rem;"><code><?= sanitize_output($h['filename']) ?></code></td>
                <td style="font-size:.78rem;"><?= $h['file_size'] > 0 ? number_format($h['file_size']/1024,1).' KB' : '—' ?></td>
                <td>
                  <span class="badge <?= $h['triggered_by']==='manual'?'badge-info':'badge-secondary' ?>">
                    <?= $h['triggered_by'] ?>
                  </span>
                </td>
                <td style="font-size:.78rem;"><?= sanitize_output($h['full_name'] ?? '—') ?></td>
                <td>
                  <span class="badge <?= $h['status']==='success'?'badge-success':'badge-danger' ?>">
                    <?= $h['status'] ?>
                  </span>
                  <?php if ($h['status']==='failed' && $h['notes']): ?>
                  <span title="<?= sanitize_output($h['notes']) ?>" style="cursor:help;color:var(--danger);">
                    <i class="fas fa-circle-info" style="font-size:.75rem;"></i>
                  </span>
                  <?php endif; ?>
                </td>
                <td style="font-size:.78rem;"><?= date('M d, Y h:i A', strtotime($h['created_at'])) ?></td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:32px;">
          <svg width="52" height="52" viewBox="0 0 80 80" fill="none"><rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/></svg>
          <h4>No backups yet</h4>
          <p>Click "Backup Now" to create your first backup.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script>
const BACKUP_CSRF = '<?= $csrf ?>';

async function runBackup() {
  const btn    = document.getElementById('backupBtn');
  const status = document.getElementById('backupStatus');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Backing up...';
  status.style.display = 'none';

  const fd = new FormData();
  fd.append('csrf_token', BACKUP_CSRF);
  fd.append('action',     'backup_now');

  try {
    const resp = await fetch('/admin/backup.php', { method:'POST', body:fd });
    const res  = await resp.json();
    status.style.display = 'block';
    if (res.success) {
      status.style.color   = 'var(--success)';
      status.innerHTML = `<i class="fas fa-circle-check"></i> Backup created: <strong>${res.filename}</strong> (${(res.size/1024).toFixed(1)} KB)`;
      showToast('success', 'Backup created successfully!');
      setTimeout(() => location.reload(), 2000);
    } else {
      status.style.color = 'var(--danger)';
      status.innerHTML = `<i class="fas fa-circle-xmark"></i> ${res.message}`;
      showToast('error', res.message || 'Backup failed.');
    }
  } catch(e) {
    status.style.display = 'block';
    status.style.color   = 'var(--danger)';
    status.textContent   = 'Network error.';
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-database"></i> Backup Now';
}

async function saveConfig() {
  const form = document.getElementById('configForm');
  const fd   = new FormData(form);
  fd.append('csrf_token', BACKUP_CSRF);
  fd.append('action',     'save_config');

  const resp = await fetch('/admin/backup.php', { method:'POST', body:fd });
  const res  = await resp.json();
  if (res.success) {
    showToast('success', 'Backup configuration saved!');
  } else {
    showToast('error', res.message || 'Failed to save.');
  }
}
</script>
<?php require_once '../includes/footer.php'; ?>
