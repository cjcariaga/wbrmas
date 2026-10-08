<?php
$page_title  = 'Audit Trail';
$active_page = 'audit';
$breadcrumb  = ['Administration', 'Audit Trail'];
require_once '../includes/auth_check.php';
require_role('System Administrator');

$conn = getDBConnection();
$csrf = generate_csrf_token();

$per_page = 20;
$page     = max(1,(int)($_GET['page'] ?? 1));
$offset   = ($page-1)*$per_page;
$filter_action = sanitize_input($_GET['action_type'] ?? '');
$filter_user   = sanitize_input($_GET['user_id']     ?? '');
$filter_date   = sanitize_input($_GET['date']        ?? 'all');
$filter_from   = sanitize_input($_GET['date_from']   ?? '');
$filter_to     = sanitize_input($_GET['date_to']     ?? '');
$search        = sanitize_input($_GET['q']           ?? '');
if (!in_array($filter_date, ['all', 'today'], true)) $filter_date = 'all';
foreach (['filter_from', 'filter_to'] as $date_filter) {
  if ($$date_filter !== '') {
    $date_parts = explode('-', $$date_filter);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$date_filter)
        || count($date_parts) !== 3
        || !checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) $$date_filter = '';
  }
}

$where = "WHERE 1=1";
if ($filter_action) $where .= " AND a.action_type = '".$conn->real_escape_string($filter_action)."'";
if ($filter_user)   $where .= " AND a.user_id = ".(int)$filter_user;
if ($filter_date === 'today') $where .= " AND a.timestamp >= CURDATE() AND a.timestamp < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
if ($filter_from) $where .= " AND a.timestamp >= '".$conn->real_escape_string($filter_from)." 00:00:00'";
if ($filter_to)   $where .= " AND a.timestamp < DATE_ADD('".$conn->real_escape_string($filter_to)."', INTERVAL 1 DAY)";
if ($search)        $where .= " AND (a.action_type LIKE '%".$conn->real_escape_string($search)."%' OR a.affected_record LIKE '%".$conn->real_escape_string($search)."%' OR a.details LIKE '%".$conn->real_escape_string($search)."%')";

$total = (int)$conn->query("SELECT COUNT(*) FROM tbl_audit_logs a $where")->fetch_row()[0];
$total_pages = max(1,ceil($total/$per_page));

$logs = $conn->query(
    "SELECT a.log_id, a.action_type, a.affected_record, a.ip_address,
            a.timestamp, a.details, a.hmac_signature,
            COALESCE(a.user_id, 0) as user_id_raw,
            u.username, u.full_name
     FROM tbl_audit_logs a
     LEFT JOIN tbl_users u ON a.user_id = u.user_id
     $where ORDER BY a.timestamp DESC LIMIT $per_page OFFSET $offset"
);

// HMAC integrity check
$integrity_issues = 0;
$all_logs_check = $conn->query("SELECT log_id, user_id, action_type, affected_record, ip_address, timestamp, details, hmac_signature FROM tbl_audit_logs ORDER BY log_id DESC LIMIT 100");
$integrity_checked = $all_logs_check ? $all_logs_check->num_rows : 0;
while ($lc = $all_logs_check->fetch_assoc()) {
    $uid_val = $lc['user_id'] ?? '';
    $data = "$uid_val|{$lc['action_type']}|{$lc['affected_record']}|{$lc['ip_address']}|{$lc['timestamp']}|{$lc['details']}";
    if (!hmac_verify($data, $lc['hmac_signature'])) $integrity_issues++;
}
$integrity_ok = ($integrity_issues === 0);

// Action types for filter
$action_types = $conn->query("SELECT DISTINCT action_type FROM tbl_audit_logs ORDER BY action_type");
// Users for filter
$all_users = $conn->query("SELECT user_id, username, full_name FROM tbl_users ORDER BY full_name");

require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-clipboard-list"></i> Audit Trail</h2>
    <p>Cryptographically verified transaction log — HMAC-SHA256 protected</p>
  </div>
  <div class="d-flex gap-2">
    <a href="audit_export.php?<?= http_build_query($_GET) ?>" class="btn btn-secondary">
      <i class="fas fa-file-export"></i> Export CSV
    </a>
  </div>
</div>

<!-- Integrity Banner -->
<div class="card mb-3" style="background:<?= $integrity_ok ? 'linear-gradient(135deg,#1e8449,#27ae60)' : 'linear-gradient(135deg,#c0392b,#e74c3c)' ?>;border:none;">
  <div class="card-body" style="padding:14px 22px;display:flex;align-items:center;gap:16px;color:#fff;flex-wrap:wrap;">
    <i class="fas fa-<?= $integrity_ok ? 'shield-halved' : 'triangle-exclamation' ?>" style="font-size:1.5rem;"></i>
    <div>
      <strong>HMAC-SHA256 Integrity Check (Last 100 records):</strong>
      <?php if ($integrity_ok): ?>
        <span style="margin-left:8px;">✅ All <?= number_format($integrity_checked) ?> records verified — No tampering detected</span>
      <?php else: ?>
        <span style="margin-left:8px;">⚠️ <?= $integrity_issues ?> record(s) failed integrity check — Possible tampering detected!</span>
      <?php endif; ?>
    </div>
    <div style="margin-left:auto;font-size:.8rem;opacity:.85;">Matching Log Entries: <?= number_format($total) ?></div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body" style="padding:16px 22px;">
    <form method="GET" class="d-flex gap-2 flex-wrap align-center">
      <div class="search-box" style="flex:1;min-width:180px;">
        <i class="fas fa-search"></i>
        <input type="text" name="q" placeholder="Search logs..." value="<?= sanitize_output($search) ?>">
      </div>
      <select name="action_type" class="form-control" style="width:200px;">
        <option value="">All Actions</option>
        <?php while ($at = $action_types->fetch_assoc()): ?>
        <option value="<?= sanitize_output($at['action_type']) ?>" <?= $filter_action===$at['action_type']?'selected':'' ?>>
          <?= sanitize_output($at['action_type']) ?>
        </option>
        <?php endwhile; ?>
      </select>
      <select name="user_id" class="form-control" style="width:180px;">
        <option value="">All Users</option>
        <?php while ($usr = $all_users->fetch_assoc()): ?>
        <option value="<?= $usr['user_id'] ?>" <?= $filter_user==$usr['user_id']?'selected':'' ?>>
          <?= sanitize_output($usr['username']) ?>
        </option>
        <?php endwhile; ?>
      </select>
      <select name="date" class="form-control" style="width:150px;" aria-label="Filter by date">
        <option value="all" <?= $filter_date==='all'?'selected':'' ?>>All Dates</option>
        <option value="today" <?= $filter_date==='today'?'selected':'' ?>>Today</option>
      </select>
      <label class="d-flex align-center gap-1" style="font-size:.8rem;">From <input type="date" name="date_from" class="form-control" value="<?= sanitize_output($filter_from) ?>"></label>
      <label class="d-flex align-center gap-1" style="font-size:.8rem;">To <input type="date" name="date_to" class="form-control" value="<?= sanitize_output($filter_to) ?>"></label>
      <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
      <a href="audit.php" class="btn btn-secondary"><i class="fas fa-rotate-left"></i> Reset</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3><i class="fas fa-list"></i> Log Entries
      <span class="badge badge-secondary" style="margin-left:8px;"><?= number_format($total) ?></span>
    </h3>
  </div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>#</th><th>Timestamp</th><th>User</th><th>Action</th><th>Record</th><th>IP Address</th><th>Details</th><th>Integrity</th></tr>
        </thead>
        <tbody>
        <?php if ($logs && $logs->num_rows > 0):
          $i = $offset + 1;
          while ($log = $logs->fetch_assoc()):
            // user_id can be NULL — use COALESCE value from query
            $uid_check = ($log['user_id_raw'] ?? 0) ?: '';
            $log_data2 = "$uid_check|{$log['action_type']}|{$log['affected_record']}|{$log['ip_address']}|{$log['timestamp']}|{$log['details']}";
            $valid = hmac_verify($log_data2, $log['hmac_signature']);

            $action_colors = [
              'LOGIN_SUCCESS'=>'success','LOGIN_FAILED'=>'danger','LOGOUT'=>'secondary',
              'CREATE_RESIDENT'=>'info','UPDATE_RESIDENT'=>'info','ARCHIVE_RESIDENT'=>'warning',
              'CREATE_DOC_REQUEST'=>'info','UPDATE_DOC_STATUS'=>'info','PRINT_DOCUMENT'=>'success',
              'CREATE_USER'=>'purple','UPDATE_USER'=>'purple','RESET_PASSWORD'=>'warning',
              'UNLOCK_USER'=>'success','TOGGLE_USER'=>'warning',
            ];
            $acolor = $action_colors[$log['action_type']] ?? 'secondary';
        ?>
          <tr>
            <td><?= $i++ ?></td>
            <td style="white-space:nowrap;font-size:.8rem;">
              <?= date('M d, Y', strtotime($log['timestamp'])) ?><br>
              <span style="color:var(--text-muted);"><?= date('h:i:s A', strtotime($log['timestamp'])) ?></span>
            </td>
            <td>
              <?php if ($log['username']): ?>
                <strong><?= sanitize_output($log['username']) ?></strong><br>
                <span style="font-size:.75rem;color:var(--text-muted);"><?= sanitize_output($log['full_name']) ?></span>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.8rem;">System</span>
              <?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $acolor ?>"><?= sanitize_output($log['action_type']) ?></span></td>
            <td style="font-size:.82rem;max-width:150px;word-break:break-all;"><?= sanitize_output($log['affected_record'] ?: '—') ?></td>
            <td style="font-size:.8rem;font-family:monospace;"><?= sanitize_output($log['ip_address'] ?: '—') ?></td>
            <td style="font-size:.8rem;max-width:200px;word-break:break-word;"><?= sanitize_output($log['details'] ?: '—') ?></td>
            <td>
              <?php if ($valid): ?>
                <span class="badge badge-success" title="HMAC verified"><i class="fas fa-shield-halved"></i> OK</span>
              <?php else: ?>
                <span class="badge badge-danger" title="HMAC mismatch!"><i class="fas fa-triangle-exclamation"></i> FAIL</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="8"><div class="empty-state"><i class="fas fa-clipboard-list"></i><p>No log entries found.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="pagination" style="padding:16px 22px;">
      <?php for ($p = 1; $p <= min($total_pages,10); $p++): ?>
        <a href="?page=<?= $p ?>&q=<?= urlencode($search) ?>&action_type=<?= urlencode($filter_action) ?>&user_id=<?= urlencode($filter_user) ?>&date=<?= urlencode($filter_date) ?>&date_from=<?= urlencode($filter_from) ?>&date_to=<?= urlencode($filter_to) ?>"
           class="page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($total_pages > 10): ?>
        <span class="page-btn">...</span>
        <a href="?page=<?= $total_pages ?>" class="page-btn"><?= $total_pages ?></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
