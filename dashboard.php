<?php
$page_title = 'Dashboard';
$active_page = 'dashboard';
$breadcrumb = ['Dashboard'];
require_once 'includes/auth_check.php';
require_permission('view_dashboard');

$conn = getDBConnection();
$uid = (int)$_SESSION['user_id'];

// Stats — admins see all, staff see only their own
$uid_filter = is_admin() ? "" : "AND issued_by_user_id = $uid";
$uid_filter_created = is_admin() ? "" : "AND (issued_by_user_id = $uid OR issued_by_user_id IS NULL)";

$stats = [];
$stats['residents']  = (int)$conn->query("SELECT COUNT(*) FROM tbl_residents WHERE is_archived=0")->fetch_row()[0];
$stats['docs_today'] = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests WHERE DATE(requested_at)=CURDATE() $uid_filter")->fetch_row()[0];
$stats['blotter']    = (int)$conn->query("SELECT COUNT(*) FROM tbl_blotter WHERE resolution_status='Active'")->fetch_row()[0];
$stats['users']      = (int)$conn->query("SELECT COUNT(*) FROM tbl_users WHERE is_active=1")->fetch_row()[0];
$stats['docs_total'] = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests WHERE status='PRINTED' $uid_filter")->fetch_row()[0];
$stats['pending']    = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests WHERE status='PENDING' $uid_filter")->fetch_row()[0];

// Recent Requests — filtered per user
$recent_req = $conn->query(
    "SELECT dr.request_id, dr.request_code, dr.status, dr.requested_at,
            CONCAT(AES_DECRYPT(FROM_BASE64(r.last_name),'".AES_SECRET_KEY."'),' ',AES_DECRYPT(FROM_BASE64(r.first_name),'".AES_SECRET_KEY."')) as resident_name,
            dt.type_name
     FROM tbl_document_requests dr
     JOIN tbl_residents r ON dr.resident_id=r.resident_id
     JOIN tbl_document_types dt ON dr.document_type_id=dt.type_id
     WHERE 1=1 $uid_filter
     ORDER BY dr.requested_at DESC LIMIT 8"
);

// Recent Blotter
$recent_blot = $conn->query(
    "SELECT b.case_number, b.incident_type, b.resolution_status, b.filed_at,
            CONCAT(AES_DECRYPT(FROM_BASE64(r.last_name),'".AES_SECRET_KEY."'),' ',AES_DECRYPT(FROM_BASE64(r.first_name),'".AES_SECRET_KEY."')) as complainant
     FROM tbl_blotter b
     JOIN tbl_residents r ON b.complainant_id=r.resident_id
     ORDER BY b.filed_at DESC LIMIT 6"
);

// Audit integrity check — verify last 50 HMAC signatures
$audit_check = $conn->query("SELECT COUNT(*) FROM tbl_audit_logs");
$total_logs = $audit_check ? (int)$audit_check->fetch_row()[0] : 0;

$tampered = 0;
$verified = 0;
$integrity_sample = $conn->query(
    "SELECT user_id, action_type, affected_record, ip_address,
            DATE_FORMAT(timestamp,'%Y-%m-%d %H:%i:%s') as timestamp,
            details, hmac_signature
     FROM tbl_audit_logs ORDER BY log_id DESC LIMIT 50"
);
if ($integrity_sample) {
    while ($log = $integrity_sample->fetch_assoc()) {
        $content = "{$log['user_id']}|{$log['action_type']}|{$log['affected_record']}|{$log['ip_address']}|{$log['timestamp']}|{$log['details']}";
        $expected = hash_hmac('sha256', $content, HMAC_SECRET_KEY);
        if (hash_equals($expected, $log['hmac_signature'] ?? '')) {
            $verified++;
        } else {
            $tampered++;
        }
    }
}
$integrity_ok = ($tampered === 0);

// Last backup status
$last_backup_row = $conn->query(
    "SELECT created_at, filename FROM tbl_backup_log WHERE status='success' ORDER BY created_at DESC LIMIT 1"
)->fetch_assoc();

require_once 'includes/header.php';
?>

<!-- ── WELCOME BANNER ────────────────────────────────────────────────────── -->
<div style="background:linear-gradient(135deg,#1a3a5c 0%,#2980b9 100%);
            border-radius:14px;padding:24px 28px;margin-bottom:22px;
            display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
  <div>
    <div style="font-size:.8rem;color:rgba(255,255,255,.7);margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;">
      <?= date('l, F j, Y') ?>
    </div>
    <h2 style="color:#fff;font-size:1.4rem;font-weight:700;margin:0 0 4px;">
      Welcome back, <?= sanitize_output($_SESSION['full_name']) ?> 👋
    </h2>
    <p style="color:rgba(255,255,255,.7);font-size:.83rem;margin:0;">
      <?= sanitize_output($_SESSION['role_name']) ?> · Barangay San Isidro Record Management System
    </p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="/BRGYMS/documents/create.php"
       style="background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.3);
              padding:9px 18px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;
              display:flex;align-items:center;gap:6px;transition:background .2s;"
       onmouseover="this.style.background='rgba(255,255,255,.25)'"
       onmouseout="this.style.background='rgba(255,255,255,.15)'">
      <i class="fas fa-plus"></i> New Request
    </a>
    <a href="/BRGYMS/residents/index.php"
       style="background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.3);
              padding:9px 18px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;
              display:flex;align-items:center;gap:6px;transition:background .2s;"
       onmouseover="this.style.background='rgba(255,255,255,.25)'"
       onmouseout="this.style.background='rgba(255,255,255,.15)'">
      <i class="fas fa-people-group"></i> Residents
    </a>
  </div>
</div>

<!-- ── STAT CARDS ─────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:22px;">

  <!-- Registered Residents -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;
              display:flex;flex-direction:column;gap:10px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:-14px;right:-14px;width:70px;height:70px;border-radius:50%;
                background:rgba(41,128,185,.08);"></div>
    <div style="width:40px;height:40px;border-radius:10px;background:#e8f4fd;
                display:flex;align-items:center;justify-content:center;">
      <i class="fas fa-people-group" style="color:#2980b9;font-size:1.1rem;"></i>
    </div>
    <div>
      <div style="font-size:1.8rem;font-weight:800;color:var(--text);line-height:1;"><?= number_format($stats['residents']) ?></div>
      <div style="font-size:.75rem;color:var(--text-muted);margin-top:3px;font-weight:500;">Registered Residents</div>
    </div>
    <a href="/BRGYMS/residents/index.php" style="font-size:.72rem;color:#2980b9;text-decoration:none;font-weight:600;">
      View all <i class="fas fa-arrow-right" style="font-size:.65rem;"></i>
    </a>
  </div>

  <!-- Documents Today -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;
              display:flex;flex-direction:column;gap:10px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:-14px;right:-14px;width:70px;height:70px;border-radius:50%;
                background:rgba(39,174,96,.08);"></div>
    <div style="width:40px;height:40px;border-radius:10px;background:#eafaf1;
                display:flex;align-items:center;justify-content:center;">
      <i class="fas fa-file-circle-check" style="color:#27ae60;font-size:1.1rem;"></i>
    </div>
    <div>
      <div style="font-size:1.8rem;font-weight:800;color:var(--text);line-height:1;"><?= number_format($stats['docs_today']) ?></div>
      <div style="font-size:.75rem;color:var(--text-muted);margin-top:3px;font-weight:500;">Documents Today</div>
    </div>
    <a href="/BRGYMS/documents/index.php" style="font-size:.72rem;color:#27ae60;text-decoration:none;font-weight:600;">
      View all <i class="fas fa-arrow-right" style="font-size:.65rem;"></i>
    </a>
  </div>

  <!-- Pending Requests -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;
              display:flex;flex-direction:column;gap:10px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:-14px;right:-14px;width:70px;height:70px;border-radius:50%;
                background:rgba(243,156,18,.08);"></div>
    <div style="width:40px;height:40px;border-radius:10px;background:#fef9e7;
                display:flex;align-items:center;justify-content:center;">
      <i class="fas fa-clock" style="color:#d68910;font-size:1.1rem;"></i>
    </div>
    <div>
      <div style="font-size:1.8rem;font-weight:800;color:var(--text);line-height:1;"><?= number_format($stats['pending']) ?></div>
      <div style="font-size:.75rem;color:var(--text-muted);margin-top:3px;font-weight:500;">
        <?= is_admin() ? 'Pending Requests' : 'My Pending' ?>
      </div>
    </div>
    <a href="/BRGYMS/documents/index.php?status=PENDING" style="font-size:.72rem;color:#d68910;text-decoration:none;font-weight:600;">
      View all <i class="fas fa-arrow-right" style="font-size:.65rem;"></i>
    </a>
  </div>

  <!-- Active Blotter -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;
              display:flex;flex-direction:column;gap:10px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:-14px;right:-14px;width:70px;height:70px;border-radius:50%;
                background:rgba(231,76,60,.08);"></div>
    <div style="width:40px;height:40px;border-radius:10px;background:#fdedec;
                display:flex;align-items:center;justify-content:center;">
      <i class="fas fa-triangle-exclamation" style="color:#e74c3c;font-size:1.1rem;"></i>
    </div>
    <div>
      <div style="font-size:1.8rem;font-weight:800;color:var(--text);line-height:1;"><?= number_format($stats['blotter']) ?></div>
      <div style="font-size:.75rem;color:var(--text-muted);margin-top:3px;font-weight:500;">Active Blotter Cases</div>
    </div>
    <a href="/BRGYMS/blotter/index.php" style="font-size:.72rem;color:#e74c3c;text-decoration:none;font-weight:600;">
      View all <i class="fas fa-arrow-right" style="font-size:.65rem;"></i>
    </a>
  </div>

  <!-- Total Issued -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;
              display:flex;flex-direction:column;gap:10px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:-14px;right:-14px;width:70px;height:70px;border-radius:50%;
                background:rgba(103,58,183,.08);"></div>
    <div style="width:40px;height:40px;border-radius:10px;background:#ede7f6;
                display:flex;align-items:center;justify-content:center;">
      <i class="fas fa-file-lines" style="color:#673ab7;font-size:1.1rem;"></i>
    </div>
    <div>
      <div style="font-size:1.8rem;font-weight:800;color:var(--text);line-height:1;"><?= number_format($stats['docs_total']) ?></div>
      <div style="font-size:.75rem;color:var(--text-muted);margin-top:3px;font-weight:500;">Total Issued Documents</div>
    </div>
    <a href="/BRGYMS/documents/index.php?status=PRINTED" style="font-size:.72rem;color:#673ab7;text-decoration:none;font-weight:600;">
      View all <i class="fas fa-arrow-right" style="font-size:.65rem;"></i>
    </a>
  </div>

  <?php if (is_admin()): ?>
  <!-- Active Staff -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;
              display:flex;flex-direction:column;gap:10px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:-14px;right:-14px;width:70px;height:70px;border-radius:50%;
                background:rgba(41,128,185,.08);"></div>
    <div style="width:40px;height:40px;border-radius:10px;background:#e8f4fd;
                display:flex;align-items:center;justify-content:center;">
      <i class="fas fa-users-cog" style="color:#2980b9;font-size:1.1rem;"></i>
    </div>
    <div>
      <div style="font-size:1.8rem;font-weight:800;color:var(--text);line-height:1;"><?= number_format($stats['users']) ?></div>
      <div style="font-size:.75rem;color:var(--text-muted);margin-top:3px;font-weight:500;">Active Staff</div>
    </div>
    <a href="/BRGYMS/admin/users.php" style="font-size:.72rem;color:#2980b9;text-decoration:none;font-weight:600;">
      Manage <i class="fas fa-arrow-right" style="font-size:.65rem;"></i>
    </a>
  </div>
  <?php endif; ?>
</div>

<?php if (is_admin()): ?>
<!-- ── SYSTEM HEALTH BAR ───────────────────────────────────────────────────── -->
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;
            padding:12px 20px;margin-bottom:22px;display:flex;align-items:center;
            gap:16px;flex-wrap:wrap;font-size:.78rem;">
  <span style="font-weight:700;color:var(--text-muted);display:flex;align-items:center;gap:6px;">
    <i class="fas fa-heart-pulse" style="color:#e74c3c;"></i> System Health
  </span>
  <div style="width:1px;height:18px;background:var(--border);"></div>
  <span style="color:<?= $integrity_ok?'#27ae60':'#e74c3c' ?>;display:flex;align-items:center;gap:5px;">
    <i class="fas <?= $integrity_ok?'fa-shield-halved':'fa-triangle-exclamation' ?>"></i>
    Audit <?= $integrity_ok?'Verified':'TAMPERED' ?> (<?= $verified ?>/<?= $verified+$tampered ?> logs)
  </span>
  <div style="width:1px;height:18px;background:var(--border);"></div>
  <?php if ($last_backup_row): ?>
  <span style="color:#27ae60;display:flex;align-items:center;gap:5px;">
    <i class="fas fa-database"></i>
    Last backup: <?= date('M d, Y g:i A', strtotime($last_backup_row['created_at'])) ?>
  </span>
  <?php else: ?>
  <span style="color:#e74c3c;display:flex;align-items:center;gap:5px;">
    <i class="fas fa-database"></i> No backup yet
  </span>
  <?php endif; ?>
  <a href="/BRGYMS/admin/backup.php"
     style="margin-left:auto;color:var(--primary);font-size:.75rem;font-weight:600;text-decoration:none;
            display:flex;align-items:center;gap:4px;">
    <i class="fas fa-gear"></i> Manage Backup
  </a>
</div>
<?php endif; ?>

<!-- ── RECENT ACTIVITY TABLES ─────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1.6fr 1fr;gap:16px;" class="dashboard-tables">

  <!-- Recent Document Requests -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);
                display:flex;align-items:center;justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:34px;height:34px;border-radius:8px;background:#e8f4fd;
                    display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-file-lines" style="color:#2980b9;font-size:.95rem;"></i>
        </div>
        <div>
          <div style="font-weight:700;font-size:.9rem;color:var(--text);">Recent Document Requests</div>
          <div style="font-size:.72rem;color:var(--text-muted);">Latest transactions</div>
        </div>
      </div>
      <a href="/BRGYMS/documents/index.php" class="btn btn-primary btn-sm">View All</a>
    </div>
    <div class="table-wrapper" style="margin:0;">
      <table style="font-size:.82rem;">
        <thead>
          <tr>
            <th>Code</th>
            <th>Resident</th>
            <th>Document</th>
            <th>Status</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($recent_req && $recent_req->num_rows > 0):
          while ($row = $recent_req->fetch_assoc()):
            $sc = ['PENDING'=>'warning','APPROVED'=>'info','PRINTED'=>'success','REJECTED'=>'danger'];
            $s  = $row['status'];
        ?>
          <tr>
            <td><code style="font-size:.72rem;"><?= sanitize_output($row['request_code']) ?></code></td>
            <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= sanitize_output($row['resident_name']) ?>
            </td>
            <td><?= sanitize_output($row['type_name']) ?></td>
            <td><span class="badge badge-<?= $sc[$s] ?? 'secondary' ?>"><?= $s ?></span></td>
            <td style="color:var(--text-muted);font-size:.75rem;white-space:nowrap;">
              <?= date('M d, g:i a', strtotime($row['requested_at'])) ?>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="5">
            <div class="empty-state" style="padding:32px;">
              <svg width="52" height="52" viewBox="0 0 80 80" fill="none"><rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/><line x1="22" y1="36" x2="58" y2="36" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity=".2"/></svg>
              <h4>No requests yet</h4>
              <p>Document requests will appear here once filed.</p>
            </div>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Blotter -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);
                display:flex;align-items:center;justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:34px;height:34px;border-radius:8px;background:#fdedec;
                    display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-book-open" style="color:#e74c3c;font-size:.95rem;"></i>
        </div>
        <div>
          <div style="font-weight:700;font-size:.9rem;color:var(--text);">Recent Blotter</div>
          <div style="font-size:.72rem;color:var(--text-muted);">Active case reports</div>
        </div>
      </div>
      <a href="/BRGYMS/blotter/index.php" class="btn btn-primary btn-sm">View All</a>
    </div>
    <div class="table-wrapper" style="margin:0;">
      <table style="font-size:.82rem;">
        <thead>
          <tr><th>Case No.</th><th>Type</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if ($recent_blot && $recent_blot->num_rows > 0):
          while ($b = $recent_blot->fetch_assoc()):
            $bs = ['Active'=>'danger','Under Mediation'=>'warning','Resolved'=>'success','Referred'=>'info'];
        ?>
          <tr>
            <td><code style="font-size:.72rem;"><?= sanitize_output($b['case_number']) ?></code></td>
            <td style="font-size:.78rem;"><?= sanitize_output($b['incident_type']) ?></td>
            <td>
              <span class="badge badge-<?= $bs[$b['resolution_status']] ?? 'secondary' ?>" style="font-size:.68rem;">
                <?= sanitize_output($b['resolution_status']) ?>
              </span>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="3">
            <div class="empty-state" style="padding:32px;">
              <svg width="52" height="52" viewBox="0 0 80 80" fill="none"><circle cx="40" cy="45" r="16" stroke="currentColor" stroke-width="2.5" opacity=".15"/><line x1="40" y1="38" x2="40" y2="46" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity=".25"/></svg>
              <h4>No blotter records</h4>
              <p>Case reports will appear here once filed.</p>
            </div>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<style>
@media (max-width: 700px) {
  .dashboard-tables { grid-template-columns: 1fr !important; }
}
</style>

<?php require_once 'includes/footer.php'; ?>
