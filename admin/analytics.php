<?php
$page_title  = 'Analytics';
$active_page = 'analytics';
$breadcrumb  = ['Administration', 'Analytics & Reports'];
require_once '../includes/auth_check.php';
require_permission('view_analytics');
require_once '../includes/resident_age.php';

$conn = getDBConnection();
$today = date('Y-m-d');
$default_from = (new DateTimeImmutable('first day of -5 months'))->format('Y-m-d');
$filter_from = trim((string)($_GET['from'] ?? $default_from));
$filter_to = trim((string)($_GET['to'] ?? $today));
$filter_date_valid = static function ($date) {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
  $parts = explode('-', $date);
  return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
};
if (!$filter_date_valid($filter_from)) $filter_from = $default_from;
if (!$filter_date_valid($filter_to)) $filter_to = $today;
if ($filter_from > $filter_to) { $swap = $filter_from; $filter_from = $filter_to; $filter_to = $swap; }
$filter_user_id = max(0, (int)($_GET['user_id'] ?? 0));
$filter_status = sanitize_input($_GET['status'] ?? 'all');
$analytics_statuses = ['all','STORED','RELEASED','PRINTED'];
if (!in_array($filter_status, $analytics_statuses, true)) $filter_status = 'all';
$filter_action = sanitize_input($_GET['action'] ?? 'all');
$action_result = $conn->query("SELECT DISTINCT action_type FROM tbl_audit_logs WHERE action_type<>'' ORDER BY action_type");
$analytics_actions = [];
if ($action_result) while ($action_row = $action_result->fetch_assoc()) $analytics_actions[] = $action_row['action_type'];
if ($filter_action !== 'all' && !in_array($filter_action, $analytics_actions, true)) $filter_action = 'all';
$analytics_users = $conn->query("SELECT user_id, full_name FROM tbl_users WHERE is_active=1 ORDER BY full_name");

$doc_conditions = [
  "COALESCE(dr.processed_at, dr.requested_at) >= '".$conn->real_escape_string($filter_from)." 00:00:00'",
  "COALESCE(dr.processed_at, dr.requested_at) < DATE_ADD('".$conn->real_escape_string($filter_to)."', INTERVAL 1 DAY)"
];
if ($filter_user_id > 0) $doc_conditions[] = 'dr.issued_by_user_id='.(int)$filter_user_id;
if ($filter_status !== 'all') $doc_conditions[] = "dr.status='".$conn->real_escape_string($filter_status)."'";
$doc_where = implode(' AND ', $doc_conditions);
$issued_statuses = "('PRINTED','STORED','RELEASED')";
$audit_conditions = [
  "a.timestamp >= '".$conn->real_escape_string($filter_from)." 00:00:00'",
  "a.timestamp < DATE_ADD('".$conn->real_escape_string($filter_to)."', INTERVAL 1 DAY)"
];
if ($filter_user_id > 0) $audit_conditions[] = 'a.user_id='.(int)$filter_user_id;
if ($filter_action !== 'all') $audit_conditions[] = "a.action_type='".$conn->real_escape_string($filter_action)."'";
$audit_where = implode(' AND ', $audit_conditions);

// ── Key Stats ─────────────────────────────────────────────────────────────────
$stats = [
    'total_residents' => (int)$conn->query("SELECT COUNT(*) FROM tbl_residents WHERE is_archived=0")->fetch_row()[0],
  'total_docs'      => (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests dr WHERE $doc_where AND dr.status IN $issued_statuses")->fetch_row()[0],
  'docs_today'      => (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests dr WHERE $doc_where AND dr.status IN $issued_statuses AND DATE(COALESCE(dr.processed_at,dr.requested_at))=CURDATE()")->fetch_row()[0],
  'docs_month'      => (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests dr WHERE $doc_where AND dr.status IN $issued_statuses AND MONTH(COALESCE(dr.processed_at,dr.requested_at))=MONTH(NOW()) AND YEAR(COALESCE(dr.processed_at,dr.requested_at))=YEAR(NOW())")->fetch_row()[0],
    'active_blotter'  => (int)$conn->query("SELECT COUNT(*) FROM tbl_blotter WHERE resolution_status='Active'")->fetch_row()[0],
    'resolved_blotter'=> (int)$conn->query("SELECT COUNT(*) FROM tbl_blotter WHERE resolution_status='Resolved'")->fetch_row()[0],
    'total_users'     => (int)$conn->query("SELECT COUNT(*) FROM tbl_users WHERE is_active=1")->fetch_row()[0],
    'audit_logs'      => (int)$conn->query("SELECT COUNT(*) FROM tbl_audit_logs")->fetch_row()[0],
];

// ── Residents by Sex ──────────────────────────────────────────────────────────
$sex_data = $conn->query("SELECT sex, COUNT(*) as cnt FROM tbl_residents WHERE is_archived=0 GROUP BY sex");
$sex_labels = []; $sex_counts = [];
if ($sex_data) while ($r = $sex_data->fetch_assoc()) {
    $sex_labels[] = $r['sex'] ?: 'Not Specified';
    $sex_counts[] = (int)$r['cnt'];
}

// ── Residents by Civil Status ─────────────────────────────────────────────────
$civil_data = $conn->query("SELECT civil_status, COUNT(*) as cnt FROM tbl_residents WHERE is_archived=0 GROUP BY civil_status ORDER BY cnt DESC");
$civil_labels = []; $civil_counts = [];
if ($civil_data) while ($r = $civil_data->fetch_assoc()) {
    $civil_labels[] = $r['civil_status'] ?: 'Not Specified';
    $civil_counts[] = (int)$r['cnt'];
}

// ── Age Group Distribution ────────────────────────────────────────────────────
$age_groups = ['Infant'=>0,'Children'=>0,'Youth'=>0,'Young Adult'=>0,'Adult'=>0,'Senior Citizen'=>0,'Unknown'=>0];
$age_res = $conn->query("SELECT birth_date FROM tbl_residents WHERE is_archived=0");
while ($r = $age_res->fetch_assoc()) {
    $bd = aes_decrypt($r['birth_date']);
  $age = resident_age_from_birth_date($bd);
  $group = resident_age_group_from_age($age);
  $age_groups[$group]++;
}

// ── Documents by Type ─────────────────────────────────────────────────────────
$doctype_data = $conn->query("SELECT dt.type_name, COUNT(dr.request_id) as cnt FROM tbl_document_requests dr JOIN tbl_document_types dt ON dr.document_type_id=dt.type_id WHERE $doc_where AND dr.status IN $issued_statuses GROUP BY dt.type_name");
$doctype_labels = []; $doctype_counts = [];
while ($r = $doctype_data->fetch_assoc()) {
    $doctype_labels[] = $r['type_name'];
    $doctype_counts[] = (int)$r['cnt'];
}

// ── Documents by Purpose ─────────────────────────────────────────────────────
// Normalize fee annotations so the same user-selected purpose stays one category.
$purpose_rows = [];
$purpose_data = $conn->query(
  "SELECT dr.purpose, dt.type_name, COUNT(*) AS cnt
   FROM tbl_document_requests dr
   JOIN tbl_document_types dt ON dr.document_type_id=dt.type_id
  WHERE $doc_where AND dr.status IN $issued_statuses AND dr.purpose IS NOT NULL AND dr.purpose <> ''
   GROUP BY dr.purpose, dt.type_name"
);
if ($purpose_data) {
  while ($r = $purpose_data->fetch_assoc()) {
    $purpose = preg_replace('/\s*\((?:Student - (?:Fee Waived|Free)|Loan(?: Application)? - [^)]*)\)\s*/i', '', $r['purpose']);
    $purpose = trim($purpose);
    if ($purpose === '' || $purpose === '0') $purpose = 'Unspecified';
    if ($purpose === '') $purpose = 'Unspecified';
    $key = $purpose . '|' . $r['type_name'];
    if (!isset($purpose_rows[$key])) {
      $purpose_rows[$key] = [
        'purpose' => $purpose,
        'type_name' => $r['type_name'],
        'cnt' => 0,
      ];
    }
    $purpose_rows[$key]['cnt'] += (int)$r['cnt'];
  }
}
usort($purpose_rows, function ($a, $b) { return $b['cnt'] <=> $a['cnt']; });
$purpose_labels = [];
$purpose_counts = [];
$purpose_total = 0;
foreach ($purpose_rows as $row) {
  $purpose_labels[] = $row['purpose'] . ' - ' . $row['type_name'];
  $purpose_counts[] = $row['cnt'];
  $purpose_total += $row['cnt'];
}

// ── Monthly Documents (last 6 months) ────────────────────────────────────────
$monthly = $conn->query("SELECT DATE_FORMAT(COALESCE(dr.processed_at,dr.requested_at),'%b %Y') as mo, COUNT(*) as cnt FROM tbl_document_requests dr WHERE $doc_where AND dr.status IN $issued_statuses GROUP BY DATE_FORMAT(COALESCE(dr.processed_at,dr.requested_at),'%Y-%m') ORDER BY MIN(COALESCE(dr.processed_at,dr.requested_at))");
$monthly_labels = []; $monthly_counts = [];
while ($r = $monthly->fetch_assoc()) {
    $monthly_labels[] = $r['mo'];
    $monthly_counts[] = (int)$r['cnt'];
}

// ── Issued documents by type and calendar month (six-month report) ────────────
$report_periods = [];
$report_period_start = (new DateTimeImmutable($filter_from))->modify('first day of this month');
$report_period_end = (new DateTimeImmutable($filter_to))->modify('first day of next month');
for ($period = $report_period_start; $period < $report_period_end; $period = $period->modify('+1 month')) {
  $report_periods[$period->format('Y-m')] = $period->format('M Y');
}
$report_types = [];
$report_issue_matrix = array_fill_keys(array_keys($report_periods), []);
$issue_frequency = $conn->query(
  "SELECT DATE_FORMAT(COALESCE(dr.processed_at, dr.requested_at), '%Y-%m') AS period_key,
      dt.type_name, COUNT(*) AS issued_count
   FROM tbl_document_requests dr
   JOIN tbl_document_types dt ON dr.document_type_id = dt.type_id
  WHERE $doc_where AND dr.status IN $issued_statuses
     AND COALESCE(dr.processed_at, dr.requested_at) >= '".$conn->real_escape_string($report_period_start->format('Y-m-d'))."'
       AND COALESCE(dr.processed_at, dr.requested_at) < '".$conn->real_escape_string($report_period_end->format('Y-m-d'))."'
   GROUP BY period_key, dt.type_id, dt.type_name
   ORDER BY dt.type_name, period_key"
);
if ($issue_frequency) {
  while ($row = $issue_frequency->fetch_assoc()) {
    $type_name = $row['type_name'];
    $period_key = $row['period_key'];
    if (!in_array($type_name, $report_types, true)) $report_types[] = $type_name;
    if (isset($report_issue_matrix[$period_key])) {
      $report_issue_matrix[$period_key][$type_name] = (int)$row['issued_count'];
    }
  }
}

// ── Blotter by Status ─────────────────────────────────────────────────────────
$blotter_data = $conn->query("SELECT resolution_status, COUNT(*) as cnt FROM tbl_blotter GROUP BY resolution_status");
$blot_labels = []; $blot_counts = [];
if ($blotter_data) {
    while ($r = $blotter_data->fetch_assoc()) {
        $blot_labels[] = $r['resolution_status'];
        $blot_counts[] = (int)$r['cnt'];
    }
}

// ── Case Reports by Type (was incident_type, now case_type) ──────────────────
$incident_data = $conn->query("SELECT case_type, COUNT(*) as cnt FROM tbl_blotter GROUP BY case_type ORDER BY cnt DESC LIMIT 8");
$inc_labels = []; $inc_counts = [];
if ($incident_data) {
    while ($r = $incident_data->fetch_assoc()) {
        $inc_labels[] = $r['case_type'];
        $inc_counts[] = (int)$r['cnt'];
    }
}

// ── Top Staff Activity ────────────────────────────────────────────────────────
$staff_activity = $conn->query("SELECT u.full_name, COUNT(a.log_id) as actions FROM tbl_audit_logs a JOIN tbl_users u ON a.user_id=u.user_id WHERE $audit_where GROUP BY a.user_id ORDER BY actions DESC LIMIT 5");

// ── Staff Document Issuance Ranking ──────────────────────────────────────────
$staff_docs = $conn->query(
    "SELECT u.user_id, u.full_name, u.username, r.role_name,
            COUNT(dr.request_id)  AS total_issued,
            SUM(CASE WHEN dt.type_name LIKE '%Clearance%'  THEN 1 ELSE 0 END) AS clearance_count,
            SUM(CASE WHEN dt.type_name LIKE '%Indigency%' OR dt.type_name LIKE '%Indigent%' THEN 1 ELSE 0 END) AS indigency_count,
            MAX(dr.processed_at) AS last_issued
     FROM tbl_document_requests dr
     JOIN tbl_users u  ON dr.issued_by_user_id = u.user_id
     JOIN tbl_document_types dt ON dr.document_type_id = dt.type_id
     JOIN tbl_roles r ON u.role_id = r.role_id
    WHERE $doc_where AND dr.status IN $issued_statuses
     GROUP BY dr.issued_by_user_id
     ORDER BY total_issued DESC"
);

// ── Household Head Summary ────────────────────────────────────────────────────
$total_households = (int)$conn->query(
    "SELECT COUNT(*) FROM tbl_residents WHERE is_archived=0 AND is_head_of_family=1"
)->fetch_row()[0];

$household_heads = $conn->query(
    "SELECT r.resident_id, r.resident_code, r.first_name, r.last_name, r.purok,
            (SELECT COUNT(*) FROM tbl_residents m
             WHERE m.household_head_id = r.resident_id AND m.is_archived=0) AS member_count
     FROM tbl_residents r
     WHERE r.is_archived=0 AND r.is_head_of_family=1
     ORDER BY member_count DESC, r.last_name"
);
$puroks = ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4'];
$purok_counts = [];
foreach ($puroks as $p) {
    $esc = $conn->real_escape_string($p);
    $purok_counts[] = (int)$conn->query("SELECT COUNT(*) FROM tbl_residents WHERE is_archived=0 AND purok='$esc'")->fetch_row()[0];
}
$purok_unassigned = (int)$conn->query("SELECT COUNT(*) FROM tbl_residents WHERE is_archived=0 AND (purok IS NULL OR purok='')")->fetch_row()[0];

// ── Documents Issued per Purok ────────────────────────────────────────────────
$doc_purok_counts = [];
foreach ($puroks as $p) {
    $esc = $conn->real_escape_string($p);
    $doc_purok_counts[] = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests dr JOIN tbl_residents r ON dr.resident_id=r.resident_id WHERE $doc_where AND dr.status IN $issued_statuses AND r.purok='$esc'")->fetch_row()[0];
}
  $doc_purok_unassigned = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests dr JOIN tbl_residents r ON dr.resident_id=r.resident_id WHERE $doc_where AND dr.status IN $issued_statuses AND (r.purok IS NULL OR r.purok='')")->fetch_row()[0];

require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-chart-pie"></i> Analytics & Reports</h2>
    <p>Document activity filters apply to issuance summaries; demographic totals remain all-resident counts.</p>
  </div>
  <button type="button" class="btn btn-secondary" onclick="printAnalyticsReport()">
    <i class="fas fa-print"></i> Print Report
  </button>
</div>

<form method="GET" class="card mb-3 no-print">
  <div class="card-body d-flex gap-2 align-center flex-wrap" style="padding:14px 18px;">
    <label class="d-flex align-center gap-2">From <input type="date" name="from" class="form-control" required value="<?= sanitize_output($filter_from) ?>"></label>
    <label class="d-flex align-center gap-2">To <input type="date" name="to" class="form-control" required value="<?= sanitize_output($filter_to) ?>"></label>
    <label class="d-flex align-center gap-2">Staff
      <select name="user_id" class="form-control"><option value="0">All staff</option><?php if ($analytics_users): while ($analytics_user = $analytics_users->fetch_assoc()): ?><option value="<?= (int)$analytics_user['user_id'] ?>" <?= $filter_user_id === (int)$analytics_user['user_id'] ? 'selected' : '' ?>><?= sanitize_output($analytics_user['full_name']) ?></option><?php endwhile; endif; ?></select>
    </label>
    <label class="d-flex align-center gap-2">Request Status
      <select name="status" class="form-control"><?php foreach ($analytics_statuses as $analytics_status): ?><option value="<?= sanitize_output($analytics_status) ?>" <?= $filter_status === $analytics_status ? 'selected' : '' ?>><?= $analytics_status === 'all' ? 'All issued statuses' : sanitize_output($analytics_status) ?></option><?php endforeach; ?></select>
    </label>
    <label class="d-flex align-center gap-2">Audit Action
      <select name="action" class="form-control"><option value="all">All actions</option><?php foreach ($analytics_actions as $analytics_action): ?><option value="<?= sanitize_output($analytics_action) ?>" <?= $filter_action === $analytics_action ? 'selected' : '' ?>><?= sanitize_output($analytics_action) ?></option><?php endforeach; ?></select>
    </label>
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
    <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-secondary">This Month</a>
    <a href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-secondary">Year to Date</a>
  </div>
</form>

<!-- Key Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:28px;">
  <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-people-group"></i></div><div><div class="stat-value"><?= number_format($stats['total_residents']) ?></div><div class="stat-label">Total Residents</div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-file-circle-check"></i></div><div><div class="stat-value"><?= number_format($stats['total_docs']) ?></div><div class="stat-label">Docs Issued</div></div></div>
  <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-calendar-day"></i></div><div><div class="stat-value"><?= number_format($stats['docs_today']) ?></div><div class="stat-label">Docs Today</div></div></div>
  <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div><div><div class="stat-value"><?= number_format($stats['docs_month']) ?></div><div class="stat-label">Docs This Month</div></div></div>
  <div class="stat-card"><div class="stat-icon red"><i class="fas fa-book-open"></i></div><div><div class="stat-value"><?= number_format($stats['active_blotter']) ?></div><div class="stat-label">Active Cases</div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-check-circle"></i></div><div><div class="stat-value"><?= number_format($stats['resolved_blotter']) ?></div><div class="stat-label">Resolved Cases</div></div></div>
  <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-clipboard-list"></i></div><div><div class="stat-value"><?= number_format($stats['audit_logs']) ?></div><div class="stat-label">Audit Entries</div></div></div>
  <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-users-cog"></i></div><div><div class="stat-value"><?= number_format($stats['total_users']) ?></div><div class="stat-label">Active Staff</div></div></div>
</div>

<!-- Purok Distribution -->
<div class="card mb-3">
  <div class="card-header"><h3><i class="fas fa-map-marker-alt"></i> Residents per Purok — Barangay San Isidro</h3></div>
  <div class="card-body">
    <div class="d-flex gap-3 flex-wrap" style="align-items:center;">
      <div style="flex:0 0 280px;display:flex;align-items:center;justify-content:center;">
        <canvas id="chartPurok" width="260" height="260"></canvas>
      </div>
      <div style="flex:1;min-width:220px;">
        <?php
        $purok_colors = ['#2980b9','#27ae60','#e74c3c','#f39c12'];
        foreach ($puroks as $i => $p):
          $pct = $stats['total_residents'] > 0 ? round(($purok_counts[$i] / $stats['total_residents']) * 100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
          <div style="width:14px;height:14px;border-radius:50%;background:<?= $purok_colors[$i] ?>;flex-shrink:0;"></div>
          <div style="flex:1;">
            <div style="display:flex;justify-content:space-between;font-size:.85rem;font-weight:600;margin-bottom:4px;">
              <span><?= $p ?></span>
              <span><?= number_format($purok_counts[$i]) ?> residents (<?= $pct ?>%)</span>
            </div>
            <div style="background:#eee;border-radius:20px;height:8px;">
              <div style="background:<?= $purok_colors[$i] ?>;height:8px;border-radius:20px;width:<?= $pct ?>%;transition:width .5s;"></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if ($purok_unassigned > 0): ?>
        <div style="font-size:.8rem;color:var(--text-muted);margin-top:8px;">
          <i class="fas fa-circle-info"></i> <?= $purok_unassigned ?> resident(s) with no purok assigned yet.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Documents Issued per Purok — Horizontal Bar Chart -->
<div class="card mb-3">
  <div class="card-header"><h3><i class="fas fa-file-circle-check"></i> Documents Issued per Purok</h3></div>
  <div class="card-body" style="padding:24px;">
    <!-- Summary stat boxes -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px;">
      <?php
      $doc_purok_colors = ['#8e44ad','#16a085','#d35400','#2c3e50'];
      $doc_purok_icons  = ['fa-file-lines','fa-file-medical','fa-file-contract','fa-file-shield'];
      $total_doc_issued = array_sum($doc_purok_counts) + $doc_purok_unassigned;
      foreach ($puroks as $i => $p):
        $clr = $doc_purok_colors[$i];
        $ico = $doc_purok_icons[$i];
      ?>
      <div style="background:#fff;border:1.5px solid <?= $clr ?>;border-radius:12px;padding:14px 16px;
                  box-shadow:0 2px 8px rgba(0,0,0,.05);text-align:center;">
        <i class="fas <?= $ico ?>" style="font-size:1.4rem;color:<?= $clr ?>;margin-bottom:8px;display:block;"></i>
        <div style="font-size:1.8rem;font-weight:800;color:<?= $clr ?>;line-height:1.1;"><?= number_format($doc_purok_counts[$i]) ?></div>
        <div style="font-size:.75rem;font-weight:600;color:#555;margin-top:4px;"><?= $p ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Horizontal bar chart -->
    <canvas id="chartDocPurok" height="120"></canvas>

    <?php if ($doc_purok_unassigned > 0): ?>
    <div style="font-size:.78rem;color:var(--text-muted);margin-top:10px;text-align:center;">
      <i class="fas fa-circle-info"></i> <?= $doc_purok_unassigned ?> document(s) issued to residents with no purok assigned.
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Household Heads Summary -->
<div class="card mb-3">
  <div class="card-header">
    <h3><i class="fas fa-house-user"></i> Household Heads & Members</h3>
    <span style="font-size:.82rem;color:var(--text-muted);margin-left:auto;">
      <strong><?= number_format($total_households) ?></strong> household<?= $total_households!==1?'s':'' ?> registered
    </span>
  </div>
  <div class="card-body" style="padding:0;">
    <?php
    $heads_arr = [];
    if ($household_heads) {
        while ($h = $household_heads->fetch_assoc()) {
            $h['first_name'] = aes_decrypt($h['first_name']);
            $h['last_name']  = aes_decrypt($h['last_name']);
            $heads_arr[] = $h;
        }
    }
    ?>
    <?php if (empty($heads_arr)): ?>
    <div class="empty-state" style="padding:32px;">
      <svg width="52" height="52" viewBox="0 0 80 80" fill="none"><rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/></svg>
      <h4>No household heads registered yet</h4>
      <p>Mark residents as Head of Family in their profile to see them here.</p>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
      <table style="font-size:.83rem;">
        <thead>
          <tr>
            <th>#</th>
            <th>Code</th>
            <th>Head of Family</th>
            <th>Purok</th>
            <th style="text-align:center;">Members Linked</th>
            <th>Household Size</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($heads_arr as $i => $h):
          $total_size = $h['member_count'] + 1; // head + members
          $bar_pct = $h['member_count'] > 0 ? min(100, $h['member_count'] * 20) : 5;
        ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><code style="font-size:.75rem;"><?= sanitize_output($h['resident_code']) ?></code></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:28px;height:28px;border-radius:50%;background:#e8f4fd;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                  <i class="fas fa-user-tie" style="color:#2980b9;font-size:.75rem;"></i>
                </div>
                <span style="font-weight:600;"><?= sanitize_output($h['last_name'].', '.$h['first_name']) ?></span>
              </div>
            </td>
            <td>
              <?php
              $pc = ['Purok 1'=>'#2980b9','Purok 2'=>'#27ae60','Purok 3'=>'#d68910','Purok 4'=>'#e74c3c'];
              $clr = $pc[$h['purok']] ?? '#888';
              ?>
              <span style="background:<?= $clr ?>18;color:<?= $clr ?>;border:1px solid <?= $clr ?>40;
                           padding:2px 10px;border-radius:12px;font-size:.75rem;font-weight:600;">
                <?= sanitize_output($h['purok'] ?: 'Unassigned') ?>
              </span>
            </td>
            <td style="text-align:center;">
              <span style="font-size:1.1rem;font-weight:800;color:#2980b9;"><?= number_format($h['member_count']) ?></span>
              <span style="font-size:.75rem;color:var(--text-muted);margin-left:4px;">member<?= $h['member_count']!==1?'s':'' ?></span>
            </td>
            <td style="min-width:160px;">
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="flex:1;background:#eee;border-radius:20px;height:7px;">
                  <div style="background:#2980b9;height:7px;border-radius:20px;width:<?= $bar_pct ?>%;"></div>
                </div>
                <span style="font-size:.78rem;color:var(--text-muted);white-space:nowrap;">
                  <?= $total_size ?> total
                </span>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <!-- Summary footer -->
    <div style="padding:12px 20px;border-top:1px solid var(--border);
                display:flex;gap:24px;flex-wrap:wrap;font-size:.8rem;color:var(--text-muted);">
      <span><i class="fas fa-house-user" style="color:#2980b9;"></i>
        <strong style="color:var(--text);"><?= $total_households ?></strong> Household<?= $total_households!==1?'s':'' ?>
      </span>
      <span><i class="fas fa-people-group" style="color:#27ae60;"></i>
        <strong style="color:var(--text);"><?= array_sum(array_column($heads_arr,'member_count')) ?></strong> Members linked
      </span>
      <span><i class="fas fa-user-tie" style="color:#8e44ad;"></i>
        <strong style="color:var(--text);"><?= array_sum(array_map(fn($h)=>$h['member_count']+1,$heads_arr)) ?></strong> Residents in households
      </span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Charts Row 1 --><div class="d-flex gap-2 flex-wrap mb-3">
  <div class="card" style="flex:1;min-width:280px;">
    <div class="card-header"><h3><i class="fas fa-venus-mars"></i> Residents by Sex</h3></div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center;padding:24px;">
      <canvas id="chartSex" width="260" height="260"></canvas>
    </div>
  </div>
  <div class="card" style="flex:1;min-width:280px;">
    <div class="card-header"><h3><i class="fas fa-ring"></i> Civil Status</h3></div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center;padding:24px;">
      <canvas id="chartCivil" width="260" height="260"></canvas>
    </div>
  </div>
  <div class="card" style="flex:2;min-width:320px;">
    <div class="card-header"><h3><i class="fas fa-users"></i> Age Group Distribution</h3></div>
    <div class="card-body"><canvas id="chartAge" height="200"></canvas></div>
  </div>
</div>

<!-- Documents by Purpose -->
<div class="card mb-3">
  <div class="card-header">
    <h3><i class="fas fa-bullseye"></i> Issued Documents by Purpose</h3>
    <span style="font-size:.8rem;color:var(--text-muted);margin-left:auto;">
      <?= number_format($purpose_total) ?> issued document<?= $purpose_total!==1?'s':'' ?> with purpose recorded
    </span>
  </div>
  <div class="card-body">
    <?php if (empty($purpose_rows)): ?>
      <div class="empty-state" style="padding:28px;">
        <i class="fas fa-chart-column" style="font-size:2rem;color:var(--text-muted);"></i>
        <h4>No issued-document purposes recorded yet</h4>
        <p>Newly issued documents will appear here automatically.</p>
      </div>
    <?php else: ?>
      <div class="d-flex gap-3 flex-wrap" style="align-items:flex-start;">
        <div style="flex:2;min-width:300px;">
          <canvas id="chartDocPurpose" height="170"></canvas>
        </div>
        <div style="flex:1;min-width:300px;">
          <div class="table-wrapper">
            <table style="font-size:.82rem;">
              <thead><tr><th>Purpose</th><th>Document</th><th style="text-align:right;">Issued</th><th style="text-align:right;">Share</th></tr></thead>
              <tbody>
              <?php foreach ($purpose_rows as $row):
                $share = $purpose_total > 0 ? round(($row['cnt'] / $purpose_total) * 100, 1) : 0;
              ?>
                <tr>
                  <td><strong><?= sanitize_output($row['purpose']) ?></strong></td>
                  <td><?= sanitize_output($row['type_name']) ?></td>
                  <td style="text-align:right;"><strong><?= number_format($row['cnt']) ?></strong></td>
                  <td style="text-align:right;color:var(--text-muted);"> <?= $share ?>%</td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Charts Row 2 -->
<div class="d-flex gap-2 flex-wrap mb-3">
  <div class="card" style="flex:2;min-width:300px;">
    <div class="card-header"><h3><i class="fas fa-chart-line"></i> Monthly Document Requests (Last 6 Months)</h3></div>
    <div class="card-body"><canvas id="chartMonthly" height="180"></canvas></div>
  </div>
  <div class="card" style="flex:1;min-width:260px;">
    <div class="card-header"><h3><i class="fas fa-file-lines"></i> Documents by Type</h3></div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center;padding:24px;">
      <canvas id="chartDocType" width="220" height="220"></canvas>
    </div>
  </div>
</div>

<!-- Charts Row 3 -->
<div class="d-flex gap-2 flex-wrap mb-3">
  <div class="card" style="flex:1;min-width:260px;">
    <div class="card-header"><h3><i class="fas fa-book-open"></i> Blotter by Status</h3></div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center;padding:24px;">
      <canvas id="chartBlotter" width="220" height="220"></canvas>
    </div>
  </div>
  <div class="card" style="flex:2;min-width:300px;">
    <div class="card-header"><h3><i class="fas fa-triangle-exclamation"></i> Incidents by Type</h3></div>
    <div class="card-body"><canvas id="chartIncident" height="180"></canvas></div>
  </div>
</div>

<!-- Staff Document Issuance Ranking -->
<div class="card mb-3">
  <div class="card-header"><h3><i class="fas fa-medal"></i> Staff Document Issuance Ranking</h3></div>
  <div class="card-body" style="padding:0;">
    <table>
      <thead>
        <tr>
          <th style="width:40px;">#</th>
          <th>Staff Name</th>
          <th>Role</th>
          <th>Clearance</th>
          <th>Indigency</th>
          <th>Total Issued</th>
          <th>Last Issued</th>
          <th>Share</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $staff_doc_rows = [];
        $max_issued = 1;
        while ($s = $staff_docs->fetch_assoc()) {
            $staff_doc_rows[] = $s;
            if ($s['total_issued'] > $max_issued) $max_issued = $s['total_issued'];
        }
        if (empty($staff_doc_rows)):
      ?>
        <tr><td colspan="8">
          <div class="empty-state" style="padding:28px;">
            <svg width="52" height="52" viewBox="0 0 80 80" fill="none"><rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/></svg>
            <h4>No documents issued yet</h4>
          </div>
        </td></tr>
      <?php else:
        $medals = ['🥇','🥈','🥉'];
        foreach ($staff_doc_rows as $rank => $s):
          $pct = $max_issued > 0 ? round(($s['total_issued'] / $max_issued) * 100) : 0;
          $bar_color = $rank === 0 ? '#f39c12' : ($rank === 1 ? '#95a5a6' : ($rank === 2 ? '#cd7f32' : '#2980b9'));
      ?>
        <tr style="cursor:pointer;" onclick="viewStaffActions(<?= (int)$s['user_id'] ?>, '<?= sanitize_output($s['full_name']) ?>')"
            onmouseover="this.style.background='var(--primary-xlight)'" onmouseout="this.style.background=''"
            title="Click to view actions"
        >
          <td style="text-align:center;font-size:1.1rem;">
            <?= isset($medals[$rank]) ? $medals[$rank] : ($rank + 1) ?>
          </td>
          <td>
            <div style="font-weight:600;"><?= sanitize_output($s['full_name']) ?></div>
            <div style="font-size:.75rem;color:var(--text-muted);">@<?= sanitize_output($s['username']) ?></div>
          </td>
          <td>
            <span class="badge <?= $s['role_name']==='System Administrator'?'badge-danger':'badge-info' ?>">
              <?= sanitize_output($s['role_name']) ?>
            </span>
          </td>
          <td style="text-align:center;">
            <span class="badge badge-secondary"><?= number_format($s['clearance_count']) ?></span>
          </td>
          <td style="text-align:center;">
            <span class="badge badge-secondary"><?= number_format($s['indigency_count']) ?></span>
          </td>
          <td style="text-align:center;">
            <strong style="font-size:1.05rem;color:<?= $bar_color ?>;"><?= number_format($s['total_issued']) ?></strong>
          </td>
          <td style="font-size:.8rem;color:var(--text-muted);">
            <?= $s['last_issued'] ? date('M d, Y', strtotime($s['last_issued'])) : '—' ?>
          </td>
          <td style="min-width:120px;">
            <div style="background:#eee;border-radius:20px;height:8px;">
              <div style="background:<?= $bar_color ?>;height:8px;border-radius:20px;width:<?= $pct ?>%;"></div>
            </div>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:3px;"><?= $pct ?>% of top</div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Top Staff Activity -->
<div class="card mb-3">
  <div class="card-header"><h3><i class="fas fa-star"></i> Top Staff Activity</h3></div>
  <div class="card-body" style="padding:0;">
    <table>
      <thead><tr><th>Staff Name</th><th>Total Actions Logged</th><th>Activity</th></tr></thead>
      <tbody>
      <?php $max_actions = 1;
        $staff_rows = [];
        while ($s = $staff_activity->fetch_assoc()) { $staff_rows[] = $s; if ($s['actions'] > $max_actions) $max_actions = $s['actions']; }
        foreach ($staff_rows as $s):
      ?>
        <tr>
          <td><strong><?= sanitize_output($s['full_name']) ?></strong></td>
          <td><?= number_format($s['actions']) ?> actions</td>
          <td>
            <div style="background:#eee;border-radius:20px;height:10px;width:100%;max-width:300px;">
              <div style="background:var(--primary-light);height:10px;border-radius:20px;width:<?= round(($s['actions']/$max_actions)*100) ?>%;"></div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const analyticsReportData = {
  ageLabels: <?= json_encode(array_keys($age_groups), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
  ageCounts: <?= json_encode(array_values($age_groups)) ?>,
  sexLabels: <?= json_encode($sex_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
  sexCounts: <?= json_encode($sex_counts) ?>,
  periods: <?= json_encode(array_values($report_periods)) ?>,
  types: <?= json_encode($report_types, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
  issueMatrix: <?= json_encode(array_values($report_issue_matrix)) ?>
};

function escapeReportText(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
}

function printAnalyticsReport() {
  const reportWindow = window.open('', '_blank', 'width=1000,height=800');
  if (!reportWindow) {
    showToast('error', 'Allow pop-ups to print the analytics report.');
    return;
  }

  const imageFor = id => {
    const canvas = document.getElementById(id);
    return canvas ? `<img class="chart" src="${canvas.toDataURL('image/png')}" alt="${escapeReportText(id)}">` : '';
  };
  const distributionTable = (labels, values, heading) => `<table><thead><tr><th>${heading}</th><th>Residents</th></tr></thead><tbody>${labels.map((label, index) => `<tr><td>${escapeReportText(label)}</td><td>${Number(values[index] || 0).toLocaleString()}</td></tr>`).join('')}</tbody></table>`;
  const issueRows = analyticsReportData.periods.map((period, index) => {
    const counts = analyticsReportData.issueMatrix[index] || {};
    const total = analyticsReportData.types.reduce((sum, type) => sum + Number(counts[type] || 0), 0);
    return `<tr><td>${escapeReportText(period)}</td>${analyticsReportData.types.map(type => `<td>${Number(counts[type] || 0).toLocaleString()}</td>`).join('')}<td><strong>${total.toLocaleString()}</strong></td></tr>`;
  }).join('');
  const issueHeaders = analyticsReportData.types.map(type => `<th>${escapeReportText(type)}</th>`).join('');
  const generatedAt = new Date().toLocaleString();
  const filterSummary = <?= json_encode('Period: '.$filter_from.' to '.$filter_to.' | Staff: '.($filter_user_id ? 'selected staff' : 'all staff').' | Status: '.($filter_status === 'all' ? 'all issued statuses' : $filter_status).' | Action: '.($filter_action === 'all' ? 'all actions' : $filter_action), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

  reportWindow.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Analytics Report</title><style>
    @page{size:A4 portrait;margin:16mm;@bottom-center{content:"Page " counter(page) " of " counter(pages);font:9pt "Times New Roman",serif;color:#555}}*{box-sizing:border-box}body{font-family:"Times New Roman",serif;color:#111;font-size:11pt;margin:0}.header{text-align:center;border-bottom:3px double #000;padding:0 0 12px;margin-bottom:18px}.header h1{font-size:17pt;margin:0 0 6px}.header p{font-size:10pt;margin:0}.section{margin:18px 0;break-inside:avoid}.section h2{font-size:13pt;border-bottom:1px solid #777;padding-bottom:5px;margin-bottom:10px}.charts{display:grid;grid-template-columns:1fr 1fr;gap:16px}.chart-block{border:1px solid #aaa;padding:8px;text-align:center}.chart{max-width:100%;max-height:280px;object-fit:contain}table{border-collapse:collapse;width:100%;font-size:9.5pt}thead{display:table-header-group}tr{break-inside:avoid}th,td{border:1px solid #777;padding:6px 8px;text-align:left}th{background:#eee}td:not(:first-child),th:not(:first-child){text-align:right}.note{font-size:9pt;color:#444;margin-top:6px}.footer{margin-top:22px;border-top:1px solid #aaa;padding-top:6px;font-size:9pt;display:flex;justify-content:space-between}@media print{.section{break-inside:avoid}.charts{break-inside:avoid}}
    </style></head><body><header class="header"><h1>Barangay San Isidro — Analytics &amp; Reports</h1><p>City of Ilagan, Isabela</p><p>${escapeReportText(filterSummary)}</p><p>Date Generated: ${escapeReportText(generatedAt)}</p></header>
    <section class="section"><h2>Age Group Distribution</h2><div class="charts"><div class="chart-block">${imageFor('chartAge')}</div><div>${distributionTable(analyticsReportData.ageLabels, analyticsReportData.ageCounts, 'Age Group')}</div></div></section>
    <section class="section"><h2>Sex Distribution</h2><div class="charts"><div class="chart-block">${imageFor('chartSex')}</div><div>${distributionTable(analyticsReportData.sexLabels, analyticsReportData.sexCounts, 'Sex')}</div></div></section>
    <section class="section"><h2>Document Issuance Frequency by Type and Period</h2><p class="note">Printed documents grouped by issuance month for the current and previous five calendar months.</p><table><thead><tr><th>Period</th>${issueHeaders}<th>Total</th></tr></thead><tbody>${issueRows || '<tr><td colspan="2">No issued documents in this period.</td></tr>'}</tbody></table></section>
    <footer class="footer"><span>Barangay San Isidro — Analytics &amp; Reports</span><span>${escapeReportText(filterSummary)} · Generated ${escapeReportText(generatedAt)}</span></footer>
    <script>window.onload=function(){window.focus();window.print();};<\/script></body></html>`);
  reportWindow.document.close();
}

const palette = ['#2980b9','#27ae60','#e74c3c','#f39c12','#8e44ad','#16a085','#d35400','#2c3e50'];

// Documents per Purok — Horizontal Bar Chart
new Chart(document.getElementById('chartDocPurok'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($puroks) ?>,
    datasets: [{
      label: 'Documents Issued',
      data: <?= json_encode($doc_purok_counts) ?>,
      backgroundColor: ['#8e44ad','#16a085','#d35400','#2c3e50'],
      borderColor:     ['#7d3c98','#148f77','#ba4a00','#1a252f'],
      borderWidth: 1.5,
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    indexAxis: 'y',   // horizontal bars
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => ` ${ctx.parsed.x} document${ctx.parsed.x !== 1 ? 's' : ''} issued`
        }
      }
    },
    scales: {
      x: {
        beginAtZero: true,
        ticks: { stepSize: 1, precision: 0 },
        grid: { color: 'rgba(0,0,0,.06)' }
      },
      y: {
        grid: { display: false },
        ticks: { font: { weight: '600' } }
      }
    }
  }
});

// Purok Doughnut (residents)
new Chart(document.getElementById('chartPurok'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($puroks) ?>,
    datasets: [{ data: <?= json_encode($purok_counts) ?>, backgroundColor: ['#2980b9','#27ae60','#e74c3c','#f39c12'], hoverOffset: 10 }]
  },
  options: {
    plugins: {
      legend: { position: 'bottom' },
      tooltip: {
        callbacks: {
          label: ctx => ` ${ctx.label}: ${ctx.parsed} residents`
        }
      }
    },
    cutout: '60%'
  }
});

// Sex Pie
new Chart(document.getElementById('chartSex'), {
  type:'doughnut',
  data:{ labels:<?= json_encode($sex_labels) ?>, datasets:[{ data:<?= json_encode($sex_counts) ?>, backgroundColor:palette }] },
  options:{ plugins:{ legend:{ position:'bottom' } }, cutout:'60%' }
});

// Civil Status Pie
new Chart(document.getElementById('chartCivil'), {
  type:'pie',
  data:{ labels:<?= json_encode($civil_labels) ?>, datasets:[{ data:<?= json_encode($civil_counts) ?>, backgroundColor:palette }] },
  options:{ plugins:{ legend:{ position:'bottom' } } }
});

// Age Bar
new Chart(document.getElementById('chartAge'), {
  type:'bar',
  data:{ labels:<?= json_encode(array_keys($age_groups)) ?>, datasets:[{ label:'Residents', data:<?= json_encode(array_values($age_groups)) ?>, backgroundColor:'rgba(41,128,185,0.75)', borderRadius:6 }] },
  options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } } }
});

// Monthly Line
new Chart(document.getElementById('chartMonthly'), {
  type:'line',
  data:{ labels:<?= json_encode($monthly_labels) ?>, datasets:[{ label:'Requests', data:<?= json_encode($monthly_counts) ?>, borderColor:'#2980b9', backgroundColor:'rgba(41,128,185,0.1)', fill:true, tension:0.4, pointRadius:5 }] },
  options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true } } }
});

// Doc Types Doughnut
new Chart(document.getElementById('chartDocType'), {
  type:'doughnut',
  data:{ labels:<?= json_encode($doctype_labels) ?>, datasets:[{ data:<?= json_encode($doctype_counts) ?>, backgroundColor:palette }] },
  options:{ plugins:{ legend:{ position:'bottom' } }, cutout:'55%' }
});

// Issued documents by saved purpose
const purposeCanvas = document.getElementById('chartDocPurpose');
if (purposeCanvas) new Chart(purposeCanvas, {
  type: 'bar',
  data: {
    labels: <?= json_encode($purpose_labels) ?>,
    datasets: [{
      label: 'Documents Issued',
      data: <?= json_encode($purpose_counts) ?>,
      backgroundColor: palette,
      borderRadius: 6
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true,
    plugins: { legend: { display: false } },
    scales: { x: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 } }, y: { ticks: { font: { size: 11 } } } }
  }
});

// Blotter Doughnut
new Chart(document.getElementById('chartBlotter'), {
  type:'doughnut',
  data:{ labels:<?= json_encode($blot_labels) ?>, datasets:[{ data:<?= json_encode($blot_counts) ?>, backgroundColor:['#e74c3c','#f39c12','#27ae60','#2980b9'] }] },
  options:{ plugins:{ legend:{ position:'bottom' } }, cutout:'55%' }
});

// Incident Types Bar
new Chart(document.getElementById('chartIncident'), {
  type:'bar',
  data:{ labels:<?= json_encode($inc_labels) ?>, datasets:[{ label:'Cases', data:<?= json_encode($inc_counts) ?>, backgroundColor:'rgba(231,76,60,0.75)', borderRadius:6 }] },
  options:{ indexAxis:'y', plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true } } }
});
</script>

<!-- Staff Actions Modal -->
<div class="modal-overlay" id="modalStaffActions">
  <div class="modal" style="max-width:700px;">
    <div class="modal-header">
      <h4><i class="fas fa-clock-rotate-left"></i> Actions — <span id="staffActionName"></span></h4>
      <button class="modal-close" onclick="closeModal('modalStaffActions')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:0;">
      <div id="staffActionContent" style="min-height:120px;display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-circle-notch fa-spin" style="font-size:1.5rem;color:var(--text-muted);"></i>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalStaffActions')">Close</button>
    </div>
  </div>
</div>

<script>
async function viewStaffActions(uid, name) {
  document.getElementById('staffActionName').textContent = name;
  document.getElementById('staffActionContent').innerHTML =
    '<div style="padding:30px;text-align:center;"><i class="fas fa-circle-notch fa-spin" style="font-size:1.5rem;color:var(--text-muted);"></i></div>';
  openModal('modalStaffActions');

  try {
    const resp = await fetch(`/admin/staff_actions_api.php?uid=${uid}&csrf_token=<?= generate_csrf_token() ?>`);
    const data = await resp.json();
    if (!data.success) {
      document.getElementById('staffActionContent').innerHTML =
        '<div class="empty-state" style="padding:28px;"><h4>No actions found</h4></div>';
      return;
    }

    const actionLabels = {
      'CREATE_DOC_REQUEST'  : { icon:'fa-file-plus',           color:'#2980b9', label:'New Document Request' },
      'GENERATE_DOCUMENT'   : { icon:'fa-print',               color:'#27ae60', label:'Generated & Printed Document' },
      'PRINT_DOCUMENT'      : { icon:'fa-print',               color:'#27ae60', label:'Printed Document' },
      'UPDATE_DOC_STATUS'   : { icon:'fa-pen-to-square',       color:'#f39c12', label:'Updated Document Status' },
      'FILE_CASE_REPORT'    : { icon:'fa-book-open',           color:'#e74c3c', label:'Filed Case Report' },
      'UPDATE_CASE_STATUS'  : { icon:'fa-handshake',           color:'#8e44ad', label:'Updated Case Status' },
      'PRINT_CASE_REPORT'   : { icon:'fa-print',               color:'#16a085', label:'Printed Case Report' },
      'CREATE_RESIDENT'     : { icon:'fa-user-plus',           color:'#2980b9', label:'Added Resident' },
      'UPDATE_RESIDENT'     : { icon:'fa-user-pen',            color:'#d68910', label:'Updated Resident' },
      'ARCHIVE_RESIDENT'    : { icon:'fa-box-archive',         color:'#e74c3c', label:'Archived Resident' },
    };

    let html = '<div class="table-wrapper"><table><thead><tr><th>Action</th><th>Details</th><th>Date & Time</th></tr></thead><tbody>';
    data.logs.forEach(log => {
      const a = actionLabels[log.action_type] || { icon:'fa-circle-dot', color:'#888', label: log.action_type };
      html += `<tr>
        <td>
          <span style="display:flex;align-items:center;gap:8px;">
            <i class="fas ${a.icon}" style="color:${a.color};width:18px;text-align:center;"></i>
            <span style="font-size:.83rem;font-weight:600;color:${a.color};">${a.label}</span>
          </span>
        </td>
        <td style="font-size:.8rem;color:var(--text-muted);max-width:220px;word-break:break-word;">${log.details||log.affected_record||'—'}</td>
        <td style="font-size:.78rem;white-space:nowrap;">${log.timestamp}</td>
      </tr>`;
    });
    html += '</tbody></table></div>';
    if (data.logs.length === 0) {
      html = '<div class="empty-state" style="padding:28px;"><h4>No relevant actions found</h4><p>Login/logout events are excluded.</p></div>';
    }
    document.getElementById('staffActionContent').innerHTML = html;
  } catch(e) {
    document.getElementById('staffActionContent').innerHTML =
      '<div style="padding:20px;text-align:center;color:var(--danger);">Failed to load actions.</div>';
  }
}
</script>
<?php require_once '../includes/footer.php'; ?>
