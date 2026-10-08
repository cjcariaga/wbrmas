<?php
$page_title = 'Documents';
$active_page = 'documents';
$breadcrumb = ['Documents', 'Requests'];
require_once '../includes/auth_check.php';
require_permission('view_documents');

$conn = getDBConnection();
require_once '../includes/drawer_index.php';
ensure_default_drawers($conn);
$csrf = generate_csrf_token();
$filter   = sanitize_input($_GET['status'] ?? '');
$allowed_statuses = ['PENDING','APPROVED','STORED','RELEASED','PRINTED','REJECTED'];
if (!in_array($filter, $allowed_statuses, true)) {
    $filter = '';
}
$code_q   = sanitize_input($_GET['q'] ?? '');
$drawer_search = sanitize_input($_GET['drawer'] ?? '');
$pickup_mode = sanitize_input($_GET['pickup'] ?? 'all');
if (!in_array($pickup_mode, ['all','scheduled','week','due','overdue'], true)) $pickup_mode = 'all';
$pickup_date = sanitize_input($_GET['pickup_date'] ?? '');
if ($pickup_date !== '') {
  $parts = explode('-', $pickup_date);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pickup_date) || count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) $pickup_date = '';
}
$sort_pickup = ($_GET['sort'] ?? '') === 'pickup';
$doc_view = sanitize_input($_GET['dview'] ?? 'list'); // list | purok
$uid      = (int)$_SESSION['user_id'];
$per_page = 15; $page = max(1,(int)($_GET['page']??1)); $offset=($page-1)*$per_page;

$received_col_check = $conn->query("SHOW COLUMNS FROM tbl_document_requests LIKE 'received_by'");
$has_received_by = $received_col_check && $received_col_check->num_rows > 0;
$drawer_col_check = $conn->query("SHOW COLUMNS FROM tbl_document_requests LIKE 'drawer_location'");
$has_drawer_location = $drawer_col_check && $drawer_col_check->num_rows > 0;
$available_drawers = [];
$drawer_options = $conn->query("SELECT drawer_id, drawer_name FROM tbl_storage_drawers ORDER BY drawer_name");
if ($drawer_options) while ($drawer_option = $drawer_options->fetch_assoc()) $available_drawers[] = $drawer_option;

// Admins see all records.
// Staff should see their own issued records plus legacy records that were not assigned yet.
$user_filter = is_admin() ? "" : "AND (dr.issued_by_user_id = $uid OR dr.issued_by_user_id IS NULL)";
$code_sql = $code_q
    ? "AND dr.request_code LIKE '%".$conn->real_escape_string($code_q)."%'"
    : "";
$drawer_sql = ($drawer_search && $has_drawer_location)
  ? "AND dr.drawer_location LIKE '%".$conn->real_escape_string($drawer_search)."%'"
  : "";
$pickup_sql = '';
if ($pickup_mode === 'scheduled') $pickup_sql .= " AND dr.preferred_pickup_date IS NOT NULL";
if ($pickup_mode === 'week') {
  $week_start = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
  $week_end = (new DateTimeImmutable('sunday this week'))->format('Y-m-d');
  $pickup_sql .= " AND dr.preferred_pickup_date BETWEEN '$week_start' AND '$week_end' AND dr.status NOT IN ('PRINTED','RELEASED','REJECTED')";
}
if ($pickup_mode === 'due') $pickup_sql .= " AND dr.preferred_pickup_date <= CURDATE() AND dr.status NOT IN ('PRINTED','RELEASED','REJECTED')";
if ($pickup_mode === 'overdue') $pickup_sql .= " AND dr.preferred_pickup_date < CURDATE() AND dr.status NOT IN ('PRINTED','RELEASED','REJECTED')";
if ($pickup_date !== '') $pickup_sql .= " AND dr.preferred_pickup_date='".$conn->real_escape_string($pickup_date)."'";

$where = $filter ? "WHERE dr.status = '$filter' $user_filter $code_sql $drawer_sql $pickup_sql" : "WHERE 1=1 $user_filter $code_sql $drawer_sql $pickup_sql";
$total = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests dr $where")->fetch_row()[0];
$total_pages = ceil($total / $per_page);
$request_order = $sort_pickup
    ? "ORDER BY (dr.preferred_pickup_date IS NULL), dr.preferred_pickup_date ASC, dr.requested_at DESC"
    : "ORDER BY dr.requested_at DESC";

// Open blotter cases are linked to a resident as complainant or by respondent name.
$open_blotter_by_complainant = [];
$open_blotter_by_name = [];
$open_blotter = $conn->query(
  "SELECT complainant_id, respondent_name
   FROM tbl_blotter
   WHERE resolution_status IN ('Active','Under Mediation')"
);
if ($open_blotter) {
  while ($case = $open_blotter->fetch_assoc()) {
    $complainant_id = (int)$case['complainant_id'];
    $open_blotter_by_complainant[$complainant_id] = ($open_blotter_by_complainant[$complainant_id] ?? 0) + 1;
    $respondent_key = strtolower(trim((string)$case['respondent_name']));
    if ($respondent_key !== '') {
      $open_blotter_by_name[$respondent_key] = ($open_blotter_by_name[$respondent_key] ?? 0) + 1;
    }
  }
}

function openBlotterCountForResident($residentId, $firstName, $lastName) {
  global $open_blotter_by_complainant, $open_blotter_by_name;
  $full_name = strtolower(trim($lastName . ', ' . $firstName));
  $alternate_name = strtolower(trim($firstName . ' ' . $lastName));
  return ($open_blotter_by_complainant[(int)$residentId] ?? 0)
     + ($open_blotter_by_name[$full_name] ?? 0)
     + ($open_blotter_by_name[$alternate_name] ?? 0);
}

function residentPurokGroup($address) {
    $address = trim((string)($address ?? ''));
    if (preg_match('/Purok\s*\d+/i', $address, $m)) {
        return trim($m[0]);
    }
    if (preg_match('/Sitio\s*[^,\n]+/i', $address, $m)) {
        return trim($m[0]);
    }
    return 'Unassigned';
}

// Base SELECT with resident address fallback for purok grouping and blotter warnings.
$base_select = "SELECT dr.request_id, dr.request_code, dr.status, dr.requested_at, dr.processed_at,
               dr.purpose, dr.preferred_pickup_date" . ($has_received_by ? ", dr.received_by, dr.received_at" : "")
               . ($has_drawer_location ? ", dr.drawer_location" : "") . ", dt.type_name, dt.fee,
               r.first_name, r.last_name, r.resident_id, r.address,
               u.full_name as issued_by
        FROM tbl_document_requests dr
        JOIN tbl_residents r ON dr.resident_id=r.resident_id
        JOIN tbl_document_types dt ON dr.document_type_id=dt.type_id
        LEFT JOIN tbl_users u ON dr.issued_by_user_id=u.user_id";

if ($doc_view === 'purok') {
    // Fetch all for purok grouping (no pagination)
    $sql_all = "$base_select $where $request_order";
    $all_requests = $conn->query($sql_all);
    if ($all_requests === false) {
        $purok_docs = ['Purok 1'=>[],'Purok 2'=>[],'Purok 3'=>[],'Purok 4'=>[],'Unassigned'=>[]];
        $requests = null;
    } else {
        // Group by purok
        $purok_docs = ['Purok 1'=>[],'Purok 2'=>[],'Purok 3'=>[],'Purok 4'=>[],'Unassigned'=>[]];
        while ($row = $all_requests->fetch_assoc()) {
            $row['last_name']  = aes_decrypt($row['last_name']);
            $row['first_name'] = aes_decrypt($row['first_name']);
            $row['open_blotter_count'] = openBlotterCountForResident($row['resident_id'], $row['first_name'], $row['last_name']);
            $pk = residentPurokGroup($row['address']);
            if (!isset($purok_docs[$pk])) {
                $purok_docs[$pk] = [];
            }
            $purok_docs[$pk][] = $row;
        }
        $requests = null;
    }
} else {
    $sql = "$base_select $where $request_order LIMIT $per_page OFFSET $offset";
    $requests = $conn->query($sql);
  if ($requests) {
    $rows = [];
    while ($row = $requests->fetch_assoc()) {
      $row['last_name']  = aes_decrypt($row['last_name']);
      $row['first_name'] = aes_decrypt($row['first_name']);
      $row['open_blotter_count'] = openBlotterCountForResident($row['resident_id'], $row['first_name'], $row['last_name']);
      $rows[] = $row;
    }
    $requests = $rows;
  }
}

// Status counts
$counts = [];
foreach ($allowed_statuses as $s) {
    $counts[$s] = (int)$conn->query("SELECT COUNT(*) FROM tbl_document_requests dr WHERE dr.status='$s' $user_filter $drawer_sql $pickup_sql")->fetch_row()[0];
}

  function pickupScheduleBadge($date, $status) {
    if (!$date) return '<span style="color:var(--text-muted);">—</span>';
    $date = (string)$date;
    $label = 'Scheduled · '.date('M d, Y', strtotime($date));
    $style = 'background:#e8f4fd;color:#1a6fa8;';
    if (!in_array($status, ['PRINTED','RELEASED','REJECTED'], true) && $date < date('Y-m-d')) {
      $label = 'OVERDUE · '.date('M d, Y', strtotime($date));
      $style = 'background:#fdecea;color:#a93226;';
    } elseif (!in_array($status, ['PRINTED','RELEASED','REJECTED'], true) && $date === date('Y-m-d')) {
      $label = 'DUE TODAY';
      $style = 'background:#fff3cd;color:#856404;';
    }
    return '<span class="badge" style="'.$style.'">'.sanitize_output($label).'</span>';
  }

  function documentStatusLabel($status) {
    $labels = ['STORED'=>'Stored — Awaiting Pickup','RELEASED'=>'Released','PRINTED'=>'Printed'];
    return $labels[$status] ?? ucfirst(strtolower($status));
  }

  function documentStatusClass($status) {
    $classes = ['PENDING'=>'warning','APPROVED'=>'info','STORED'=>'warning','RELEASED'=>'success','PRINTED'=>'secondary','REJECTED'=>'danger'];
    return $classes[$status] ?? 'secondary';
  }

$purok_colors = [
    'Purok 1'    => ['bg'=>'#e8f4fd','border'=>'#2980b9','icon'=>'#2980b9'],
    'Purok 2'    => ['bg'=>'#eafaf1','border'=>'#27ae60','icon'=>'#27ae60'],
    'Purok 3'    => ['bg'=>'#fef9e7','border'=>'#f39c12','icon'=>'#d68910'],
    'Purok 4'    => ['bg'=>'#fdedec','border'=>'#e74c3c','icon'=>'#e74c3c'],
    'Unassigned' => ['bg'=>'#f8f9fa','border'=>'#aaa','icon'=>'#aaa'],
];

require_once '../includes/header.php';
?>
<style>
.document-destination-options{display:grid;gap:10px;margin:0 0 18px;padding:0;border:0}
.document-destination-options legend{margin-bottom:10px;color:#344054;font-size:.87rem;font-weight:600}
.document-destination-option{display:flex;align-items:center;gap:11px;padding:12px;border:1px solid #dce3eb;border-radius:8px;background:#fff;cursor:pointer;transition:border-color .15s,background .15s,box-shadow .15s}
.document-destination-option:hover{border-color:#7ea8cc;background:#f8fbfe}
.document-destination-option.selected{border-color:#2877ad;background:#eef7fc;box-shadow:0 0 0 2px rgba(40,119,173,.1)}
.document-destination-option.disabled{opacity:.55;cursor:not-allowed}
.document-destination-option input{width:17px;height:17px;flex:0 0 17px;margin:0;accent-color:#2877ad}
.destination-option-icon{display:grid;width:36px;height:36px;flex:0 0 36px;place-items:center;border-radius:7px;background:#eaf2f8;color:#245d84}
.document-destination-option strong,.document-destination-option small{display:block}
.document-destination-option strong{color:#263746;font-size:.84rem}
.document-destination-option small{margin-top:3px;color:#6b7785;font-size:.73rem;line-height:1.4}
@media(max-width:520px){.document-destination-option{align-items:flex-start;padding:10px}.destination-option-icon{width:32px;height:32px;flex-basis:32px}}
</style>
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-file-lines"></i> Document Requests</h2>
    <p>
      <?php if (is_admin()): ?>
        Manage barangay clearances and certificates of indigency — <strong>all staff records</strong>
      <?php else: ?>
        Showing your document requests only — <strong><?= sanitize_output($_SESSION['full_name']) ?></strong>
      <?php endif; ?>
    </p>
  </div>
  <a href="/BRGYMS/documents/create.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Request</a>
</div>

<!-- Status Filter Tabs + View Toggle -->
<div class="d-flex gap-2 mb-3 flex-wrap align-center justify-between">
  <div class="d-flex gap-2 flex-wrap">
    <a href="?dview=<?= $doc_view ?>&drawer=<?= urlencode($drawer_search) ?>&pickup=<?= urlencode($pickup_mode) ?>&pickup_date=<?= urlencode($pickup_date) ?>&sort=<?= $sort_pickup?'pickup':'' ?>" class="btn <?= !$filter?'btn-primary':'btn-secondary' ?>">All <span class="badge badge-secondary" style="margin-left:4px;"><?= $total ?></span></a>
    <?php
    $tab_colors = ['PENDING'=>'btn-warning','APPROVED'=>'btn-primary','STORED'=>'btn-warning','RELEASED'=>'btn-success','PRINTED'=>'btn-secondary','REJECTED'=>'btn-danger'];
    foreach($counts as $s=>$c):
    ?>
    <a href="?status=<?= $s ?>&dview=<?= $doc_view ?>&drawer=<?= urlencode($drawer_search) ?>&pickup=<?= urlencode($pickup_mode) ?>&pickup_date=<?= urlencode($pickup_date) ?>&sort=<?= $sort_pickup?'pickup':'' ?>" class="btn <?= $filter===$s?$tab_colors[$s]:'btn-secondary' ?>">
      <?= sanitize_output(documentStatusLabel($s)) ?> <span class="badge badge-secondary" style="margin-left:4px;"><?= $c ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <!-- View Toggle -->
  <div class="d-flex gap-2">
    <a href="?status=<?= urlencode($filter) ?>&dview=list&drawer=<?= urlencode($drawer_search) ?>&pickup=<?= urlencode($pickup_mode) ?>&pickup_date=<?= urlencode($pickup_date) ?>&sort=<?= $sort_pickup?'pickup':'' ?>" class="btn btn-sm <?= $doc_view==='list'?'btn-primary':'btn-secondary' ?>">
      <i class="fas fa-list"></i> List View
    </a>
    <a href="?status=<?= urlencode($filter) ?>&dview=purok&drawer=<?= urlencode($drawer_search) ?>&pickup=<?= urlencode($pickup_mode) ?>&pickup_date=<?= urlencode($pickup_date) ?>&sort=<?= $sort_pickup?'pickup':'' ?>" class="btn btn-sm <?= $doc_view==='purok'?'btn-primary':'btn-secondary' ?>">
      <i class="fas fa-folder"></i> By Purok
    </a>
  </div>
</div>

<form method="GET" class="d-flex gap-2 align-center mb-3 flex-wrap">
  <input type="hidden" name="status" value="<?= sanitize_output($filter) ?>">
  <input type="hidden" name="dview" value="<?= sanitize_output($doc_view) ?>">
  <input type="hidden" name="drawer" value="<?= sanitize_output($drawer_search) ?>">
  <label class="d-flex align-center gap-2">Pickup Status
    <select name="pickup" class="form-control">
      <option value="all" <?= $pickup_mode==='all'?'selected':'' ?>>All</option>
      <option value="scheduled" <?= $pickup_mode==='scheduled'?'selected':'' ?>>Scheduled</option>
      <option value="week" <?= $pickup_mode==='week'?'selected':'' ?>>Due this week</option>
      <option value="due" <?= $pickup_mode==='due'?'selected':'' ?>>Due today / overdue</option>
      <option value="overdue" <?= $pickup_mode==='overdue'?'selected':'' ?>>Overdue only</option>
    </select>
  </label>
  <label class="d-flex align-center gap-2">Exact Date <input type="date" name="pickup_date" class="form-control" value="<?= sanitize_output($pickup_date) ?>"></label>
  <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Filter Pickup</button>
  <a href="?status=<?= urlencode($filter) ?>&dview=<?= urlencode($doc_view) ?>&sort=pickup&pickup=<?= urlencode($pickup_mode) ?>&pickup_date=<?= urlencode($pickup_date) ?>" class="btn btn-secondary"><i class="fas fa-arrow-down-wide-short"></i> Sort by Pickup Date</a>
  <?php if ($pickup_mode !== 'all' || $pickup_date || $sort_pickup): ?><a href="?status=<?= urlencode($filter) ?>&dview=<?= urlencode($doc_view) ?>&drawer=<?= urlencode($drawer_search) ?>" class="btn btn-secondary">Clear Schedule Filter</a><?php endif; ?>
</form>

<form method="GET" class="d-flex gap-2 align-center mb-3">
  <input type="hidden" name="status" value="<?= sanitize_output($filter) ?>">
  <input type="hidden" name="dview" value="<?= sanitize_output($doc_view) ?>">
  <input type="text" name="drawer" class="form-control" value="<?= sanitize_output($drawer_search) ?>"
         maxlength="100" placeholder="Filter by drawer location..." aria-label="Filter documents by drawer location">
  <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Filter</button>
  <?php if ($drawer_search): ?>
  <a href="?status=<?= urlencode($filter) ?>&dview=<?= urlencode($doc_view) ?>" class="btn btn-secondary">Clear</a>
  <?php endif; ?>
</form>

<?php if ($doc_view === 'purok'): ?>
<!-- ── PUROK FOLDER VIEW ─────────────────────────────────────────────────── -->
<?php foreach ($purok_docs as $purok_name => $rows):
  $pc    = $purok_colors[$purok_name];
  $cnt   = count($rows);
  $fid   = 'docfolder_' . preg_replace('/\s+/','_', strtolower($purok_name));
?>
<div class="card mb-3" data-folder="<?= $fid ?>">
  <div style="background:<?= $pc['bg'] ?>;border-left:4px solid <?= $pc['border'] ?>;cursor:pointer;padding:14px 20px;display:flex;align-items:center;gap:14px;user-select:none;"
       onclick="toggleFolder('<?= $fid ?>')">
    <i class="fas fa-folder folder-icon-<?= $fid ?>" style="font-size:1.3rem;color:<?= $pc['icon'] ?>;transition:all .2s;"></i>
    <div style="flex:1;">
      <span style="font-weight:700;font-size:1rem;color:<?= $pc['icon'] ?>;"><?= $purok_name ?></span>
    </div>
    <span style="background:<?= $pc['border'] ?>;color:#fff;font-size:.75rem;font-weight:700;padding:3px 12px;border-radius:20px;">
      <?= $cnt ?> request<?= $cnt!==1?'s':'' ?>
    </span>
    <i class="fas fa-chevron-down folder-chevron-<?= $fid ?>" style="color:<?= $pc['icon'] ?>;transition:transform .25s;"></i>
  </div>
  <div class="folder-body-<?= $fid ?>">
    <?php if ($cnt === 0): ?>
      <div class="empty-state" style="padding:28px;">
        <svg width="52" height="52" viewBox="0 0 80 80" fill="none"><rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/></svg>
        <h4>No requests from <?= $purok_name ?></h4>
        <p>No document requests from residents of this purok yet.</p>
      </div>
    <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>#</th><th>Code</th><th>Resident</th><th>Document Type</th><th>Purpose</th><th>Fee</th><th>Status</th><th>Received By</th><th>Drawer Location</th><th>Date</th><th>Preferred Pickup</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($rows as $i=>$row):
          $is_clr = stripos($row['type_name'],'clearance') !== false;
          $is_ind = stripos($row['type_name'],'indigency') !== false || stripos($row['type_name'],'indigent') !== false;
        ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><code><?= sanitize_output($row['request_code']) ?></code></td>
            <td><?= sanitize_output($row['last_name'].', '.$row['first_name']) ?></td>
            <td><?= sanitize_output($row['type_name']) ?></td>
            <td><?= sanitize_output(($row['purpose'] !== null && trim((string)$row['purpose']) !== '' && $row['purpose'] !== '0') ? $row['purpose'] : 'Not recorded') ?></td>
            <td><?php
              if ($is_ind) echo '<span style="color:var(--success);font-weight:600;">FREE</span>';
              elseif ($is_clr) {
                if (stripos($row['purpose'],'Student')!==false||stripos($row['purpose'],'Academic')!==false||stripos($row['purpose'],'School')!==false) echo '<span style="color:var(--success);font-weight:600;">FREE</span>';
                elseif (stripos($row['purpose'],'Loan')!==false) echo '<span style="font-weight:600;">₱100.00</span>';
                else echo '₱'.number_format($row['fee'],2);
              } else echo '₱'.number_format($row['fee'],2);
            ?></td>
            <td><span class="badge badge-<?= documentStatusClass($row['status']) ?>"><?= sanitize_output(documentStatusLabel($row['status'])) ?></span>
              <?php if (!empty($row['preferred_pickup_date'])): ?><span class="badge" style="background:#e8f4fd;color:#1a6fa8;">Scheduled for <?= date('M d, Y', strtotime($row['preferred_pickup_date'])) ?></span><?php endif; ?>
              <?php
              $is_clr_row = stripos($row['type_name'],'clearance') !== false;
              $ob = (int)($row['open_blotter_count'] ?? 0);
              if ($is_clr_row && $ob > 0 && in_array($row['status'],['PENDING','APPROVED'])):
              ?>
              <span title="<?= $ob ?> open blotter case(s) — review before approving"
                    style="display:inline-flex;align-items:center;gap:3px;background:#fff3cd;
                           border:1px solid #ffc107;color:#856404;padding:1px 7px;border-radius:12px;
                           font-size:.68rem;font-weight:700;margin-left:4px;cursor:default;">
                <i class="fas fa-triangle-exclamation"></i> <?= $ob ?> blotter
              </span>
              <?php endif; ?>
            </td>
            <td><?= sanitize_output($row['received_by'] ?: '—') ?></td>
            <td><?= sanitize_output($row['drawer_location'] ?? '—') ?: '—' ?></td>
            <td><?= date('M d, Y', strtotime($row['requested_at'])) ?></td>
            <td><?= pickupScheduleBadge($row['preferred_pickup_date'], $row['status']) ?></td>
            <td>
              <div class="d-flex gap-2">
                <?php if($row['status']==='PENDING' && can('approve_document')): ?>
                <button class="btn btn-icon btn-sm" title="Approve" style="color:var(--success);" onclick="updateStatus(<?= $row['request_id'] ?>,'APPROVED','<?= $csrf ?>')"><i class="fas fa-check"></i></button>
                <button class="btn btn-icon btn-sm" title="Reject" style="color:var(--danger);" onclick="updateStatus(<?= $row['request_id'] ?>,'REJECTED','<?= $csrf ?>')"><i class="fas fa-xmark"></i></button>
                <?php endif; ?>
                <?php if($row['status']==='APPROVED' && can('approve_document')): ?>
                <button class="btn btn-icon btn-sm" title="Generate & Print" style="color:var(--primary);" onclick="generateDoc(<?= (int)$row['request_id'] ?>, <?= htmlspecialchars(json_encode($row['preferred_pickup_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-print"></i></button>
                <?php endif; ?>
                <?php if($row['status']==='STORED' && can('approve_document') && (empty($row['preferred_pickup_date']) || $row['preferred_pickup_date'] <= date('Y-m-d'))): ?>
                <button class="btn btn-icon btn-sm" title="Mark as Picked Up" style="color:var(--success);" onclick="openReleaseModal(<?= (int)$row['request_id'] ?>, <?= htmlspecialchars(json_encode($row['request_code']), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-person-walking-arrow-right"></i></button>
                <?php endif; ?>
                <?php if(in_array($row['status'],['PRINTED','STORED','RELEASED'],true) && can('approve_document')): ?>
                <button class="btn btn-icon btn-sm" title="Reprint" onclick="reprintDoc(<?= $row['request_id'] ?>)"><i class="fas fa-rotate-right"></i></button>
                <?php endif; ?>
                <?php if(can('approve_document') && !in_array($row['status'],['STORED','RELEASED'],true)): ?>
                <button class="btn btn-icon btn-sm" title="Set Drawer Location" onclick='editDocumentDrawer(<?= (int)$row['request_id'] ?>, <?= json_encode($row['drawer_location'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-box-archive"></i></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php else: ?>
<!-- ── LIST VIEW ────────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <h3><i class="fas fa-list"></i> Request List</h3>
    <div class="search-box" style="max-width:260px;">
      <i class="fas fa-search"></i>
      <input type="text" id="residentSearch" placeholder="Search resident name..."
             class="form-control" style="padding-left:34px;"
             oninput="filterTable(this.value,'docTable',2)">
    </div>
  </div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrapper">
      <table id="docTable">
        <thead>
          <tr><th>#</th><th>Code</th><th>Resident</th><th>Document Type</th><th>Purpose</th><th>Fee</th><th>Status</th><th>Received By</th><th>Drawer Location</th><th>Date</th><th>Preferred Pickup</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if(!empty($requests)): $i=$offset+1; foreach($requests as $row): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><code><?= sanitize_output($row['request_code']) ?></code></td>
            <td><?= sanitize_output($row['last_name'].', '.$row['first_name']) ?></td>
            <td><?= sanitize_output($row['type_name']) ?></td>
            <td><?= sanitize_output(($row['purpose'] !== null && trim((string)$row['purpose']) !== '' && $row['purpose'] !== '0') ? $row['purpose'] : 'Not recorded') ?></td>
            <td><?php
              $is_clr = stripos($row['type_name'],'clearance') !== false;
              $is_ind = stripos($row['type_name'],'indigency') !== false || stripos($row['type_name'],'indigent') !== false;
              if ($is_ind) echo '<span style="color:var(--success);font-weight:600;">FREE</span>';
              elseif ($is_clr) {
                if (stripos($row['purpose'],'Student')!==false||stripos($row['purpose'],'Academic')!==false||stripos($row['purpose'],'School')!==false) echo '<span style="color:var(--success);font-weight:600;">FREE</span>';
                elseif (stripos($row['purpose'],'Loan')!==false) echo '<span style="font-weight:600;">₱100.00</span>';
                else echo '₱'.number_format($row['fee'],2);
              } else echo '₱'.number_format($row['fee'],2);
            ?></td>
            <td>
              <span class="badge badge-<?= documentStatusClass($row['status']) ?>"><?= sanitize_output(documentStatusLabel($row['status'])) ?></span>
              <?php if (!empty($row['preferred_pickup_date'])): ?><span class="badge" style="background:#e8f4fd;color:#1a6fa8;">Scheduled for <?= date('M d, Y', strtotime($row['preferred_pickup_date'])) ?></span><?php endif; ?>
              <?php
              $is_clr_lv = stripos($row['type_name'],'clearance') !== false;
              $ob_lv = (int)($row['open_blotter_count'] ?? 0);
              if ($is_clr_lv && $ob_lv > 0 && in_array($row['status'],['PENDING','APPROVED'])):
              ?>
              <span title="<?= $ob_lv ?> open blotter case(s) — review before approving"
                    style="display:inline-flex;align-items:center;gap:3px;background:#fff3cd;
                           border:1px solid #ffc107;color:#856404;padding:1px 7px;border-radius:12px;
                           font-size:.68rem;font-weight:700;margin-left:4px;cursor:default;">
                <i class="fas fa-triangle-exclamation"></i> <?= $ob_lv ?> blotter
              </span>
              <?php endif; ?>
            </td>
            <td><?= sanitize_output($row['received_by'] ?: '—') ?></td>
            <td><?= sanitize_output($row['drawer_location'] ?? '—') ?: '—' ?></td>
            <td><?= date('M d, Y', strtotime($row['requested_at'])) ?></td>
            <td><?= pickupScheduleBadge($row['preferred_pickup_date'], $row['status']) ?></td>
            <td>
              <div class="d-flex gap-2">
                <?php if($row['status']==='PENDING' && can('approve_document')): ?>
                <button class="btn btn-icon btn-sm" title="Approve" style="color:var(--success);" onclick="updateStatus(<?= $row['request_id'] ?>,'APPROVED','<?= $csrf ?>')"><i class="fas fa-check"></i></button>
                <button class="btn btn-icon btn-sm" title="Reject" style="color:var(--danger);" onclick="updateStatus(<?= $row['request_id'] ?>,'REJECTED','<?= $csrf ?>')"><i class="fas fa-xmark"></i></button>
                <?php endif; ?>
                <?php if($row['status']==='APPROVED' && can('approve_document')): ?>
                <button class="btn btn-icon btn-sm" title="Generate & Print" style="color:var(--primary);" onclick="generateDoc(<?= (int)$row['request_id'] ?>, <?= htmlspecialchars(json_encode($row['preferred_pickup_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-print"></i></button>
                <?php endif; ?>
                <?php if($row['status']==='STORED' && can('approve_document') && (empty($row['preferred_pickup_date']) || $row['preferred_pickup_date'] <= date('Y-m-d'))): ?>
                <button class="btn btn-icon btn-sm" title="Mark as Picked Up" style="color:var(--success);" onclick="openReleaseModal(<?= (int)$row['request_id'] ?>, <?= htmlspecialchars(json_encode($row['request_code']), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-person-walking-arrow-right"></i></button>
                <?php endif; ?>
                <?php if(in_array($row['status'],['PRINTED','STORED','RELEASED'],true) && can('approve_document')): ?>
                <button class="btn btn-icon btn-sm" title="Reprint" onclick="reprintDoc(<?= $row['request_id'] ?>)"><i class="fas fa-rotate-right"></i></button>
                <?php endif; ?>
                <?php if(can('approve_document') && !in_array($row['status'],['STORED','RELEASED'],true)): ?>
                <button class="btn btn-icon btn-sm" title="Set Drawer Location" onclick='editDocumentDrawer(<?= (int)$row['request_id'] ?>, <?= json_encode($row['drawer_location'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-box-archive"></i></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="12">
            <div class="empty-state">
              <svg width="64" height="64" viewBox="0 0 80 80" fill="none"><rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/><rect x="20" y="12" width="40" height="6" rx="3" fill="currentColor" opacity=".12"/><line x1="22" y1="36" x2="58" y2="36" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity=".25"/></svg>
              <h4>No requests found</h4><p>No document requests match the current filter.</p>
            </div>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($total_pages>1): ?>
    <div class="pagination" style="padding:16px 22px;">
      <?php for($p=1;$p<=$total_pages;$p++): ?>
        <a href="?status=<?= urlencode($filter) ?>&dview=list&page=<?= $p ?>&drawer=<?= urlencode($drawer_search) ?>&pickup=<?= urlencode($pickup_mode) ?>&pickup_date=<?= urlencode($pickup_date) ?>&sort=<?= $sort_pickup?'pickup':'' ?>" class="page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="receivedByModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <h4><i class="fas fa-box-open"></i> Document Destination</h4>
      <button type="button" class="modal-close" onclick="closeModal('receivedByModal')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <fieldset class="document-destination-options">
        <legend>Where should the document go? <span class="req">*</span></legend>
        <?php if ($has_drawer_location): ?>
        <label class="document-destination-option">
          <input type="radio" name="documentDestination" value="drawer" onchange="setDocumentDestination(this.value)">
          <span class="destination-option-icon"><i class="fas fa-box-archive"></i></span>
          <span><strong>Store in drawer</strong><small>Record where the document is kept for pickup.</small></span>
        </label>
        <?php endif; ?>
        <?php if ($has_received_by): ?>
        <label class="document-destination-option">
          <input type="radio" name="documentDestination" value="recipient" onchange="setDocumentDestination(this.value)">
          <span class="destination-option-icon"><i class="fas fa-user-check"></i></span>
          <span><strong>Hand to a person</strong><small>Record the name of the person receiving it.</small></span>
        </label>
        <?php endif; ?>
      </fieldset>
      <p id="scheduledPickupNotice" class="badge badge-info" style="display:none;margin:0 0 14px;white-space:normal;"></p>
      <div id="recipientDestinationFields" hidden>
        <label for="receivedByName">Received By <span class="req">*</span></label>
        <input type="text" id="receivedByName" class="form-control" maxlength="150"
               placeholder="Name of person who collected the document">
      </div>
      <?php if ($has_drawer_location): ?>
      <div id="drawerDestinationFields" style="margin-top:14px;" hidden>
        <label for="documentDrawerLocation">Select Drawer <span class="req">*</span></label>
        <select id="documentDrawerLocation" class="form-control">
          <option value="">Choose a drawer</option>
          <?php foreach ($available_drawers as $drawer_option): ?>
          <option value="<?= (int)$drawer_option['drawer_id'] ?>"><?= sanitize_output($drawer_option['drawer_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('receivedByModal')">Cancel</button>
      <button type="button" class="btn btn-primary" id="confirmIssueButton" onclick="confirmGenerateDoc()">
        <i class="fas fa-print"></i> Record & Print
      </button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="releaseDocumentModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header">
      <h4><i class="fas fa-person-circle-check"></i> Confirm Document Pickup</h4>
      <button type="button" class="modal-close" onclick="closeModal('releaseDocumentModal')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="releaseRequestId">
      <p id="releaseRequestCode" style="margin:0 0 16px;color:var(--text-muted);"></p>
      <div class="form-group">
        <label for="releaseReceivedBy">Received By <span class="req">*</span></label>
        <input type="text" id="releaseReceivedBy" class="form-control" maxlength="150" required placeholder="Resident or authorized representative">
      </div>
      <div class="form-group">
        <label for="releaseDateTime">Date and Time Released <span class="req">*</span></label>
        <input type="datetime-local" id="releaseDateTime" class="form-control" required>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('releaseDocumentModal')">Cancel</button>
      <button type="button" class="btn btn-primary" id="confirmReleaseButton" onclick="confirmDocumentRelease()"><i class="fas fa-check"></i> Confirm Release</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="drawerLocationModal">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header">
      <h4><i class="fas fa-box-archive"></i> Drawer Location</h4>
      <button type="button" class="modal-close" onclick="closeModal('drawerLocationModal')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="drawerRequestId">
      <label for="drawerLocationValue">Physical Storage Location</label>
      <input type="text" id="drawerLocationValue" class="form-control" maxlength="100" placeholder="e.g. Drawer 2">
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('drawerLocationModal')">Cancel</button>
      <button type="button" class="btn btn-primary" id="saveDrawerLocationButton" onclick="saveDocumentDrawer()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<script>
async function updateStatus(id, status, csrf, blotter_acknowledged) {
  const labels = {APPROVED:'approve',REJECTED:'reject'};
  if(!confirm(`Are you sure you want to ${labels[status]} this request?`)) return;

  const payload = {request_id:id, status, csrf_token:csrf};
  if (blotter_acknowledged) payload.blotter_acknowledged = 1;

  const res = await apiRequest('/BRGYMS/documents/api.php?action=update_status', payload);

  if (res.success) {
    showToast('success', `Request ${status.toLowerCase()}.`);
    setTimeout(() => location.reload(), 1200);
  } else if (res.blotter_warning) {
    // Show blotter warning modal — staff must explicitly acknowledge
    showBlotterWarning(id, status, csrf, res.open_cases, res.message);
  } else {
    showToast('error', res.message || 'Failed.');
  }
}

function showBlotterWarning(id, status, csrf, caseCount, message) {
  // Create modal if not exists
  let overlay = document.getElementById('blotterWarningOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'blotterWarningOverlay';
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
      <div class="modal" style="max-width:480px;">
        <div class="modal-header" style="background:#fff3cd;border-bottom:1.5px solid #ffc107;">
          <h4 style="color:#856404;"><i class="fas fa-triangle-exclamation"></i> Blotter Case Warning</h4>
          <button onclick="closeModal('blotterWarningOverlay')" class="modal-close"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
          <div id="blotterWarningMsg" style="font-size:.9rem;line-height:1.7;margin-bottom:16px;"></div>
          <div style="background:#fefefe;border:1.5px solid #e0e0e0;border-radius:8px;padding:12px 14px;font-size:.82rem;color:#555;">
            <i class="fas fa-book-open" style="color:#e74c3c;"></i>
            This document states the resident has <strong>GOOD MORAL CHARACTER</strong>
            and <strong>NO DEROGATORY RECORD</strong>. Please review the blotter
            case(s) before proceeding.
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" onclick="closeModal('blotterWarningOverlay')">
            <i class="fas fa-xmark"></i> Cancel
          </button>
          <button class="btn btn-warning" id="blotterProceedBtn"
                  style="background:#d68910;color:#fff;border-color:#d68910;">
            <i class="fas fa-check-double"></i> I Acknowledge — Proceed Anyway
          </button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
  }

  document.getElementById('blotterWarningMsg').innerHTML = message;
  document.getElementById('blotterProceedBtn').onclick = async () => {
    closeModal('blotterWarningOverlay');
    await updateStatus(id, status, csrf, true); // send with acknowledged=1
  };
  openModal('blotterWarningOverlay');
}

let pendingPrintRequestId = 0;
let pendingPrintPickupDate = '';
const DOCUMENT_SERVER_DATE = <?= json_encode(date('Y-m-d')) ?>;

function editDocumentDrawer(requestId, drawerLocation) {
  document.getElementById('drawerRequestId').value = requestId;
  document.getElementById('drawerLocationValue').value = drawerLocation || '';
  openModal('drawerLocationModal');
}

async function saveDocumentDrawer() {
  const requestId = document.getElementById('drawerRequestId').value;
  const drawerLocation = document.getElementById('drawerLocationValue').value.trim();
  const button = document.getElementById('saveDrawerLocationButton');
  button.disabled = true;
  const res = await apiRequest('/BRGYMS/documents/api.php?action=update_drawer', {
    request_id: requestId,
    drawer_location: drawerLocation,
    csrf_token: '<?= $csrf ?>'
  });
  button.disabled = false;
  if (res.success) {
    closeModal('drawerLocationModal');
    showToast('success', 'Drawer location saved.');
    setTimeout(() => location.reload(), 500);
  } else {
    showToast('error', res.message || 'Could not save drawer location.');
  }
}

function generateDoc(id, preferredPickupDate = '') {
  pendingPrintRequestId = id;
  pendingPrintPickupDate = preferredPickupDate || '';
  const input = document.getElementById('receivedByName');
  input.value = '';
  const scheduledAhead = pendingPrintPickupDate !== '' && pendingPrintPickupDate > DOCUMENT_SERVER_DATE;
  const destinationOptions = document.querySelectorAll('input[name="documentDestination"]');
  destinationOptions.forEach(option => {
    option.checked = false;
    option.disabled = scheduledAhead && option.value !== 'drawer';
    option.closest('.document-destination-option')?.classList.toggle('disabled', option.disabled);
  });
  const scheduleNotice = document.getElementById('scheduledPickupNotice');
  scheduleNotice.style.display = scheduledAhead ? 'inline-flex' : 'none';
  scheduleNotice.textContent = scheduledAhead ? `Scheduled pickup: ${pendingPrintPickupDate}. Store this document in a drawer until collection.` : '';
  if (scheduledAhead) {
    const drawerOption = document.querySelector('input[name="documentDestination"][value="drawer"]');
    if (drawerOption) drawerOption.checked = true;
    setDocumentDestination('drawer');
  } else {
    setDocumentDestination('');
  }
  const drawerInput = document.getElementById('documentDrawerLocation');
  if (drawerInput) drawerInput.value = '';
  openModal('receivedByModal');
}

function setDocumentDestination(destination) {
  document.querySelectorAll('.document-destination-option').forEach(option => {
    option.classList.toggle('selected', option.querySelector('input')?.value === destination);
  });
  const recipientFields = document.getElementById('recipientDestinationFields');
  const drawerFields = document.getElementById('drawerDestinationFields');
  const recipientInput = document.getElementById('receivedByName');
  const drawerInput = document.getElementById('documentDrawerLocation');
  recipientFields.hidden = destination !== 'recipient';
  recipientInput.required = destination === 'recipient';
  if (drawerFields && drawerInput) {
    drawerFields.hidden = destination !== 'drawer';
    drawerInput.required = destination === 'drawer';
  }
}

async function confirmGenerateDoc() {
  const input = document.getElementById('receivedByName');
  const destination = document.querySelector('input[name="documentDestination"]:checked')?.value || '';
  const receivedBy = destination === 'recipient' ? input.value.trim() : '';
  const drawerInput = document.getElementById('documentDrawerLocation');
  const drawerId = destination === 'drawer' ? (drawerInput?.value || '') : '';
  const button = document.getElementById('confirmIssueButton');
  if (!pendingPrintRequestId || !['drawer', 'recipient'].includes(destination)) {
    showToast('error','Choose whether to store the document in a drawer or hand it to a person.');
    return;
  }
  if (destination === 'recipient' && !receivedBy) {
    input.focus();
    showToast('error','Enter the name of the person receiving the document.');
    return;
  }
  if (destination === 'drawer' && drawerInput && !drawerId) {
    drawerInput.focus();
    showToast('error','Choose the drawer where the document will be stored.');
    return;
  }

  button.disabled = true;
  try {
    const res = await apiRequest('/BRGYMS/documents/print_request.php', {
      request_id: pendingPrintRequestId,
      delivery_method: destination,
      received_by: receivedBy,
      drawer_id: drawerId,
      csrf_token: '<?= $csrf ?>'
    });
    if (res.success) {
      closeModal('receivedByModal');
      showToast('success','Document generated!');
      printDocument(res.html);
      setTimeout(()=>location.reload(),2000);
    } else {
      showToast('error',res.message||'Failed to generate.');
    }
  } catch (error) {
    showToast('error','Could not generate the document. Please try again.');
  } finally {
    button.disabled = false;
  }
}

function openReleaseModal(requestId, requestCode) {
  document.getElementById('releaseRequestId').value = requestId;
  document.getElementById('releaseRequestCode').textContent = `Request ${requestCode} is stored and awaiting pickup.`;
  document.getElementById('releaseReceivedBy').value = '';
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  document.getElementById('releaseDateTime').value = now.toISOString().slice(0, 16);
  openModal('releaseDocumentModal');
}

async function confirmDocumentRelease() {
  const requestId = document.getElementById('releaseRequestId').value;
  const receivedBy = document.getElementById('releaseReceivedBy').value.trim();
  const releasedAt = document.getElementById('releaseDateTime').value;
  const button = document.getElementById('confirmReleaseButton');
  if (!receivedBy || !releasedAt) {
    showToast('error', 'Enter the receiving person and release date/time.');
    return;
  }
  button.disabled = true;
  try {
    const result = await apiRequest('/BRGYMS/documents/api.php?action=release_document', {
      request_id: requestId,
      received_by: receivedBy,
      released_at: releasedAt,
      csrf_token: '<?= $csrf ?>'
    });
    if (!result.success) {
      showToast('error', result.message || 'Could not confirm document release.');
      return;
    }
    closeModal('releaseDocumentModal');
    showToast('success', 'Document marked as released.');
    setTimeout(() => location.reload(), 700);
  } catch (error) {
    showToast('error', 'Could not confirm document release. Please try again.');
  } finally {
    button.disabled = false;
  }
}

async function reprintDoc(id) {
  const res = await apiRequest('/BRGYMS/documents/print_request.php',{request_id:id,csrf_token:'<?= $csrf ?>'});
  if(res.success) printDocument(res.html);
  else showToast('error','Failed to reprint.');
}

// Live resident search — filters table rows by column index
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
  // Show empty state if nothing matches
  const empty = document.getElementById(tableId + 'Empty');
  if (empty) empty.style.display = visible === 0 ? '' : 'none';
}
</script>
<?php require_once '../includes/footer.php'; ?>
