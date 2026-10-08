<?php
$page_title  = 'Residents';
$active_page = 'residents';
$breadcrumb  = ['Residents', 'Records'];
require_once '../includes/auth_check.php';
require_permission('view_residents');
require_once '../includes/resident_age.php';

$conn    = getDBConnection();
$csrf    = generate_csrf_token();
$search  = sanitize_input($_GET['q']          ?? '');
$drawer_search = sanitize_input($_GET['drawer'] ?? '');
$view    = sanitize_input($_GET['view']       ?? 'purok'); // purok | age
$status_filter = sanitize_input($_GET['rs']  ?? '');      // record_status filter
$per_page= in_array((int)($_GET['pp'] ?? 25), [10,25,50,100])
             ? (int)($_GET['pp'] ?? 25) : 25;

// Validate status filter
$allowed_rs = ['Active','Pending Verification',''];
if (!in_array($status_filter, $allowed_rs)) $status_filter = '';

// ── Count (search-aware + status filter) ─────────────────────────────────────
$search_sql = $search
    ? "AND (resident_code LIKE '%".($conn->real_escape_string($search))."%')"
    : "";
$drawer_sql = $drawer_search
  ? "AND drawer_location LIKE '%".$conn->real_escape_string($drawer_search)."%'"
  : "";
$status_sql = $status_filter
    ? "AND record_status = '".($conn->real_escape_string($status_filter))."'"
    : "";
$total_rows = (int)$conn->query(
    "SELECT COUNT(*) FROM tbl_residents WHERE is_archived=0 $search_sql $drawer_sql $status_sql"
)->fetch_row()[0];

// Status counts for filter tabs
$status_counts = [];
foreach (['Active','Pending Verification'] as $rs) {
    $esc = $conn->real_escape_string($rs);
    $status_counts[$rs] = (int)$conn->query(
        "SELECT COUNT(*) FROM tbl_residents WHERE is_archived=0 AND record_status='$esc' $search_sql $drawer_sql"
    )->fetch_row()[0];
}

// ── Fetch ALL matching rows ───────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT resident_id, resident_code, first_name, middle_name, last_name,
            birth_date, sex, civil_status, contact_number, is_indigent,
                 purok, drawer_location, photo_path, record_status, created_at
               FROM tbl_residents WHERE is_archived=0 $search_sql $drawer_sql $status_sql
     ORDER BY FIELD(purok,'Purok 1','Purok 2','Purok 3','Purok 4'), last_name"
);
$stmt->execute();
$residents_res = $stmt->get_result();
$stmt->close();

// Group residents
$puroks     = ['Purok 1'=>[],'Purok 2'=>[],'Purok 3'=>[],'Purok 4'=>[],'Unassigned'=>[]];
$age_groups = ['Infant'=>[],'Children'=>[],'Youth'=>[],'Young Adult'=>[],'Adult'=>[],'Senior Citizen'=>[],'Unknown'=>[]];

while ($r = $residents_res->fetch_assoc()) {
    $r['first_name']     = aes_decrypt($r['first_name']);
    $r['middle_name']    = aes_decrypt($r['middle_name']);
    $r['last_name']      = aes_decrypt($r['last_name']);
    $r['birth_date']     = aes_decrypt($r['birth_date']);
    $r['contact_number'] = aes_decrypt($r['contact_number']);
    $r['age']            = resident_age_from_birth_date($r['birth_date']);
    $r['age_group']      = resident_age_group_from_age($r['age']);
    $pk = (isset($puroks[$r['purok']]) && $r['purok']) ? $r['purok'] : 'Unassigned';
    $puroks[$pk][] = $r;

    $ag = isset($age_groups[$r['age_group']]) ? $r['age_group'] : 'Unknown';
    $age_groups[$ag][] = $r;
}

// ── Color maps ────────────────────────────────────────────────────────────────
$purok_colors = [
    'Purok 1'    => ['bg'=>'#e8f4fd','border'=>'#2980b9','icon'=>'#2980b9'],
    'Purok 2'    => ['bg'=>'#eafaf1','border'=>'#27ae60','icon'=>'#27ae60'],
    'Purok 3'    => ['bg'=>'#fef9e7','border'=>'#f39c12','icon'=>'#d68910'],
    'Purok 4'    => ['bg'=>'#fdedec','border'=>'#e74c3c','icon'=>'#e74c3c'],
    'Unassigned' => ['bg'=>'#f8f9fa','border'=>'#aaa',   'icon'=>'#aaa'],
];
$age_colors = [
    'Infant'        => ['bg'=>'#fde8ef','border'=>'#e91e63','icon'=>'#c0134f','fa'=>'fa-baby',          'range'=>'0–1 yrs'],
    'Children'      => ['bg'=>'#fff0d6','border'=>'#e67e00','icon'=>'#b86000','fa'=>'fa-child',         'range'=>'2–12 yrs'],
    'Youth'         => ['bg'=>'#e0f5e9','border'=>'#1e8449','icon'=>'#155d33','fa'=>'fa-person-running','range'=>'13–17 yrs'],
    'Young Adult'   => ['bg'=>'#daeeff','border'=>'#1565c0','icon'=>'#0d47a1','fa'=>'fa-person',        'range'=>'18–30 yrs'],
    'Adult'         => ['bg'=>'#ede0f7','border'=>'#6a1b9a','icon'=>'#4a148c','fa'=>'fa-briefcase',     'range'=>'31–59 yrs'],
    'Senior Citizen'=> ['bg'=>'#fde8e0','border'=>'#bf360c','icon'=>'#8b1a00','fa'=>'fa-person-cane',   'range'=>'60+ yrs (RA 9994)'],
    'Unknown'       => ['bg'=>'#f0f0f0','border'=>'#757575','icon'=>'#424242','fa'=>'fa-circle-question','range'=>'No birthdate'],
];

require_once '../includes/header.php';

// ── Render rows helper (shared by both views) ─────────────────────────────────
function renderRows($residents, $offset = 0) {
    global $csrf;
    $out = '';
    foreach ($residents as $i => $r) {
        $fname = $r['first_name']; $mname = $r['middle_name'];
        $lname = $r['last_name'];  $bdate  = $r['birth_date'];
        $age   = $r['age'] ?? null;

        $thumb = $r['photo_path']
            ? '<img src="/'.htmlspecialchars($r['photo_path'],ENT_QUOTES,'UTF-8').'"
                   style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--border);"
                   alt="photo">'
            : '<div style="width:36px;height:36px;border-radius:50%;background:var(--primary-xlight);
                   display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                 <i class="fas fa-user" style="color:var(--primary-light);font-size:.9rem;"></i>
               </div>';

        $viewData = json_encode([
            'id'        => $r['resident_id'],
            'code'      => $r['resident_code'],
            'name'      => "$lname, $fname" . ($mname ? " $mname" : ''),
            'bdate'     => $bdate,
            'age'       => $age,
            'sex'       => $r['sex'],
            'civil'     => $r['civil_status'],
            'purok'     => $r['purok'],
            'drawer_location' => $r['drawer_location'] ?? '',
            'contact'   => $r['contact_number'],
            'indigent'  => $r['is_indigent'],
            'photo'     => $r['photo_path'],
            'age_group' => $r['age_group'],
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

        $rs_cfg = [
            'Active'               => ['bg'=>'#eafaf1','color'=>'#27ae60','icon'=>'fa-circle-check'],
            'Pending Verification' => ['bg'=>'#fef9e7','color'=>'#d68910','icon'=>'fa-clock'],
            'Archived'             => ['bg'=>'#f2f3f4','color'=>'#7f8c8d','icon'=>'fa-box-archive'],
        ];
        $rs = $r['record_status'] ?? 'Pending Verification';
        $rsc = $rs_cfg[$rs] ?? $rs_cfg['Pending Verification'];
        $rsBadge = '<span style="background:'.$rsc['bg'].';color:'.$rsc['color'].';border:1px solid '.$rsc['color'].';
                        padding:2px 9px;border-radius:20px;font-size:.7rem;font-weight:700;white-space:nowrap;">
                      <i class="fas '.$rsc['icon'].'" style="font-size:.65rem;"></i> '.$rs.'
                    </span>';

        $safeViewData = htmlspecialchars($viewData, ENT_QUOTES, 'UTF-8');

        $safe_name  = addslashes("$lname, $fname");
        $archiveBtn = is_admin()
            ? '<button class="btn btn-icon btn-sm" title="Archive" style="color:var(--danger);"
                 onclick="openArchiveModal('.(int)$r['resident_id'].', \''.$safe_name.'\')">
                 <i class="fas fa-box-archive"></i>
               </button>'
            : '';

        $editBtn = is_admin()
            ? '<button class="btn btn-icon btn-sm" title="Edit"
                 onclick="editResident('.sanitize_output($r['resident_id']).')">
                 <i class="fas fa-pen-to-square"></i>
               </button>'
            : '';

        // Quick verify button — only for Pending Verification records
        $verifyBtn = (is_admin() && $rs === 'Pending Verification')
            ? '<button class="btn btn-icon btn-sm" title="Mark as Active / Verify Record"
                 style="color:#27ae60;border-color:#27ae60;"
                 onclick="quickVerify('.(int)$r['resident_id'].')">
                 <i class="fas fa-circle-check"></i>
               </button>'
            : '';

        $out .= '<tr>
            <td>'.($offset + $i + 1).'</td>
            <td style="display:flex;align-items:center;gap:8px;padding:10px 16px;">'
                .$thumb.'</td>
            <td><code style="font-size:.78rem;">'.sanitize_output($r['resident_code']).'</code></td>
            <td><strong>'.sanitize_output("$lname, $fname".($mname?" $mname":'')).'</strong></td>
            <td>'.($age !== null ? $age.' yrs' : '—').'</td>
            <td>'.sanitize_output($r['sex'] ?? '-').'</td>
            <td>'.sanitize_output($r['civil_status'] ?? '-').'</td>
            <td>'.sanitize_output($r['drawer_location'] ?: '—').'</td>
            <td>'.$rsBadge.'</td>
            <td>'.($r['is_indigent']
                ? '<span class="badge badge-warning"><i class="fas fa-hand-holding-heart"></i> Yes</span>'
                : '<span class="badge badge-secondary">No</span>').'</td>
            <td>'.date('M d, Y', strtotime($r['created_at'])).'</td>
            <td>
              <div class="d-flex gap-2">
                <button class="btn btn-icon btn-sm" title="View"
                  onclick="viewResident(this)"
                  data-resident="'.$safeViewData.'">
                  <i class="fas fa-eye"></i>
                </button>
                '.$editBtn.'
                '.$verifyBtn.'
                <button class="btn btn-icon btn-sm" title="Request Document"
                  onclick="requestDoc('.sanitize_output($r['resident_id']).')">
                  <i class="fas fa-file-plus"></i>
                </button>
                '.$archiveBtn.'
              </div>
            </td>
          </tr>';
    }
    return $out;
}

// ── Render one folder (with per-folder pagination) ────────────────────────────
function renderFolder($folder_id, $label, $all_residents, $color, $is_special, $per_page) {
    $count      = count($all_residents);
    $page_param = 'p_' . $folder_id;
    $cur_page   = max(1, (int)($_GET[$page_param] ?? 1));
    $total_pages= max(1, (int)ceil($count / $per_page));
    $cur_page   = min($cur_page, $total_pages);
    $offset     = ($cur_page - 1) * $per_page;
    $page_res   = array_slice($all_residents, $offset, $per_page);

    // Build pagination URL (preserve all GET params except this folder's page)
    $params = $_GET;
    unset($params[$page_param]);
    $base_url = '?' . http_build_query($params) . '&' . $page_param . '=';

    ob_start();
?>
<div class="purok-folder card mb-3" data-folder="<?= $folder_id ?>">
  <div style="background:<?= $color['bg'] ?>;border-left:4px solid <?= $color['border'] ?>;
              cursor:pointer;padding:14px 20px;display:flex;align-items:center;gap:14px;user-select:none;"
       onclick="toggleFolder('<?= $folder_id ?>')">
    <i class="fas <?= isset($color['fa']) ? $color['fa'] : 'fa-folder' ?> folder-icon-<?= $folder_id ?>"
       style="font-size:1.4rem;color:<?= $color['icon'] ?>;transition:all .2s;"></i>
    <div style="flex:1;">
      <span style="font-weight:700;font-size:1rem;color:<?= $color['icon'] ?>;"><?= htmlspecialchars($label) ?></span>
      <?php if (isset($color['range'])): ?>
        <span style="font-size:.72rem;color:#888;margin-left:8px;"><?= $color['range'] ?></span>
      <?php elseif ($is_special): ?>
        <span style="font-size:.75rem;color:var(--text-muted);margin-left:8px;">no purok assigned</span>
      <?php endif; ?>
    </div>
    <span style="background:<?= $color['border'] ?>;color:#fff;font-size:.75rem;font-weight:700;padding:3px 12px;border-radius:20px;">
      <?= $count ?> resident<?= $count !== 1 ? 's' : '' ?>
    </span>
    <i class="fas fa-chevron-down folder-chevron-<?= $folder_id ?>" style="color:<?= $color['icon'] ?>;transition:transform .25s;"></i>
  </div>

  <div class="folder-body-<?= $folder_id ?>">
    <?php if ($count === 0): ?>
      <div class="empty-state" style="padding:32px;">
        <svg width="52" height="52" viewBox="0 0 80 80" fill="none">
          <rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/>
          <line x1="22" y1="36" x2="58" y2="36" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity=".2"/>
        </svg>
        <h4>No residents here</h4>
        <p>No residents belong to this category yet.</p>
      </div>
    <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead><tr>
          <th>#</th><th style="width:52px;">Photo</th><th>Code</th><th>Full Name</th>
          <th>Age</th><th>Sex</th><th>Civil Status</th><th>Drawer Location</th>
          <th>Status</th><th>Indigent</th><th>Registered</th><th>Actions</th>
        </tr></thead>
        <tbody><?= renderRows($page_res, $offset) ?></tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <!-- Per-folder pagination -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid var(--border);flex-wrap:wrap;gap:10px;">
      <span style="font-size:.8rem;color:var(--text-muted);">
        Showing <?= $offset+1 ?>–<?= min($offset+$per_page,$count) ?> of <?= $count ?>
      </span>
      <div class="pagination" style="padding:0;">
        <?php if ($cur_page > 1): ?>
        <a href="<?= $base_url.($cur_page-1) ?>#<?= $folder_id ?>" class="page-btn">‹ Prev</a>
        <?php endif; ?>
        <?php
        // Show at most 5 page numbers around current
        $start = max(1, $cur_page - 2);
        $end   = min($total_pages, $cur_page + 2);
        if ($start > 1)             echo '<span style="padding:0 4px;color:var(--text-muted);">…</span>';
        for ($p = $start; $p <= $end; $p++):
        ?>
        <a href="<?= $base_url.$p ?>#<?= $folder_id ?>"
           class="page-btn <?= $p===$cur_page?'active':'' ?>"><?= $p ?></a>
        <?php endfor;
        if ($end < $total_pages) echo '<span style="padding:0 4px;color:var(--text-muted);">…</span>';
        ?>
        <?php if ($cur_page < $total_pages): ?>
        <a href="<?= $base_url.($cur_page+1) ?>#<?= $folder_id ?>" class="page-btn">Next ›</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>
<?php
    return ob_get_clean();
}
?>

<!-- ── PAGE HEADER ────────────────────────────────────────────────────────── -->
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-people-group"></i> Resident Records</h2>
    <p><?= number_format($total_rows) ?> registered resident<?= $total_rows!==1?'s':'' ?> in Barangay San Isidro</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('modalAddResident')">
    <i class="fas fa-plus"></i> Add Resident
  </button>
</div>

<!-- ── VIEW TOGGLE + STATUS FILTER ───────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;
            background:var(--card);border:1px solid var(--border);border-radius:10px;padding:10px 14px;">

  <!-- View Type -->
  <div style="display:flex;gap:6px;align-items:center;">
    <span style="font-size:.72rem;color:var(--text-muted);font-weight:600;margin-right:2px;">VIEW:</span>
    <a href="?view=purok&q=<?= urlencode($search) ?>&pp=<?= $per_page ?>&rs=<?= urlencode($status_filter) ?>&drawer=<?= urlencode($drawer_search) ?>"
       style="padding:5px 14px;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none;
              border:1.5px solid <?= $view==='purok'?'var(--primary)':'var(--border)' ?>;
              background:<?= $view==='purok'?'var(--primary)':'transparent' ?>;
              color:<?= $view==='purok'?'#fff':'var(--text-muted)' ?>;">
      <i class="fas fa-map-marker-alt"></i> By Purok
    </a>
    <a href="?view=age&q=<?= urlencode($search) ?>&pp=<?= $per_page ?>&rs=<?= urlencode($status_filter) ?>&drawer=<?= urlencode($drawer_search) ?>"
       style="padding:5px 14px;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none;
              border:1.5px solid <?= $view==='age'?'var(--primary)':'var(--border)' ?>;
              background:<?= $view==='age'?'var(--primary)':'transparent' ?>;
              color:<?= $view==='age'?'#fff':'var(--text-muted)' ?>;">
      <i class="fas fa-layer-group"></i> By Age Group
    </a>
  </div>

  <div style="width:1px;height:24px;background:var(--border);margin:0 4px;"></div>

  <!-- Status Filter -->
  <div style="display:flex;gap:6px;align-items:center;">
    <span style="font-size:.72rem;color:var(--text-muted);font-weight:600;margin-right:2px;">STATUS:</span>
    <a href="?view=<?= $view ?>&q=<?= urlencode($search) ?>&pp=<?= $per_page ?>&drawer=<?= urlencode($drawer_search) ?>"
       style="padding:5px 14px;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none;
              border:1.5px solid <?= $status_filter===''?'#1a3a5c':'var(--border)' ?>;
              background:<?= $status_filter===''?'#1a3a5c':'transparent' ?>;
              color:<?= $status_filter===''?'#fff':'var(--text-muted)' ?>;">
      All
      <span style="background:rgba(255,255,255,.25);color:<?= $status_filter===''?'#fff':'var(--text-muted)' ?>;
                   font-size:.68rem;padding:1px 7px;border-radius:20px;margin-left:4px;">
        <?= number_format($total_rows) ?>
      </span>
    </a>
    <a href="?view=<?= $view ?>&q=<?= urlencode($search) ?>&pp=<?= $per_page ?>&rs=Active&drawer=<?= urlencode($drawer_search) ?>"
       style="padding:5px 14px;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none;
              border:1.5px solid <?= $status_filter==='Active'?'#1e8449':'#a9dfbf' ?>;
              background:<?= $status_filter==='Active'?'#1e8449':'transparent' ?>;
              color:<?= $status_filter==='Active'?'#fff':'#1e8449' ?>;">
      <i class="fas fa-circle-check" style="font-size:.65rem;"></i> Active
      <span style="background:<?= $status_filter==='Active'?'rgba(255,255,255,.25)':'#d5f5e3' ?>;
                   color:<?= $status_filter==='Active'?'#fff':'#1e8449' ?>;
                   font-size:.68rem;padding:1px 7px;border-radius:20px;margin-left:4px;">
        <?= number_format($status_counts['Active'] ?? 0) ?>
      </span>
    </a>
    <a href="?view=<?= $view ?>&q=<?= urlencode($search) ?>&pp=<?= $per_page ?>&rs=Pending+Verification&drawer=<?= urlencode($drawer_search) ?>"
       style="padding:5px 14px;border-radius:6px;font-size:.78rem;font-weight:600;text-decoration:none;
              border:1.5px solid <?= $status_filter==='Pending Verification'?'#b7770d':'#fad7a0' ?>;
              background:<?= $status_filter==='Pending Verification'?'#b7770d':'transparent' ?>;
              color:<?= $status_filter==='Pending Verification'?'#fff':'#b7770d' ?>;">
      <i class="fas fa-clock" style="font-size:.65rem;"></i> Pending
      <span style="background:<?= $status_filter==='Pending Verification'?'rgba(255,255,255,.25)':'#fdebd0' ?>;
                   color:<?= $status_filter==='Pending Verification'?'#fff':'#b7770d' ?>;
                   font-size:.68rem;padding:1px 7px;border-radius:20px;margin-left:4px;">
        <?= number_format($status_counts['Pending Verification'] ?? 0) ?>
      </span>
    </a>
  </div>

  <?php if (is_admin() && ($status_counts['Pending Verification'] ?? 0) > 0): ?>
  <button class="btn btn-success btn-sm" onclick="acceptAllPending()" title="Accept all pending resident records">
    <i class="fas fa-check-double"></i> Accept All Pending
    <span style="margin-left:4px;">(<?= number_format($status_counts['Pending Verification']) ?>)</span>
  </button>
  <?php endif; ?>

  <!-- Per-page selector pushed to right -->
  <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
    <span style="font-size:.72rem;color:var(--text-muted);font-weight:600;">PER PAGE:</span>
    <form method="GET" style="display:inline;">
      <input type="hidden" name="view" value="<?= sanitize_output($view) ?>">
      <input type="hidden" name="q"    value="<?= sanitize_output($search) ?>">
      <input type="hidden" name="rs"   value="<?= sanitize_output($status_filter) ?>">
      <input type="hidden" name="drawer" value="<?= sanitize_output($drawer_search) ?>">
      <select name="pp" class="form-control"
              style="width:72px;padding:4px 8px;font-size:.78rem;border-radius:6px;"
              onchange="this.form.submit()">
        <?php foreach([10,25,50,100] as $n): ?>
        <option value="<?= $n ?>" <?= $n===$per_page?'selected':'' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>
  <!-- Per-page selector -->
  <form method="GET" style="margin-left:auto;display:flex;align-items:center;gap:8px;">
    <input type="hidden" name="view" value="<?= sanitize_output($view) ?>">
    <input type="hidden" name="q"    value="<?= sanitize_output($search) ?>">
    <input type="hidden" name="drawer" value="<?= sanitize_output($drawer_search) ?>">
    <label style="font-size:.8rem;color:var(--text-muted);">Per folder page:</label>
    <select name="pp" class="form-control" style="width:80px;padding:6px 10px;font-size:.82rem;"
            onchange="this.form.submit()">
      <?php foreach([10,25,50,100] as $n): ?>
      <option value="<?= $n ?>" <?= $n===$per_page?'selected':'' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($view === 'age'): ?>
<!-- ── AGE GROUP SUMMARY BOXES ───────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
  <?php foreach ($age_groups as $ag_name => $ag_residents):
    if ($ag_name === 'Unknown' && count($ag_residents) === 0) continue;
    $ac    = $age_colors[$ag_name];
    $ag_id = 'agfolder_' . preg_replace('/[\s]+/', '_', strtolower($ag_name));
    $cnt   = count($ag_residents);
  ?>
  <div onclick="scrollToFolder('<?= $ag_id ?>')"
       style="background:<?= $ac['bg'] ?>;border:2px solid <?= $ac['border'] ?>;border-radius:12px;
              padding:16px 12px;text-align:center;cursor:pointer;transition:all .2s;user-select:none;
              box-shadow:0 2px 8px rgba(0,0,0,.06);"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 20px rgba(0,0,0,.13)';"
       onmouseout="this.style.transform='';this.style.boxShadow='0 2px 8px rgba(0,0,0,.06)';">
    <i class="fas <?= $ac['fa'] ?>" style="font-size:1.6rem;color:<?= $ac['icon'] ?>;margin-bottom:8px;display:block;"></i>
    <div style="font-weight:800;font-size:.82rem;color:<?= $ac['icon'] ?>;margin-bottom:2px;"><?= $ag_name ?></div>
    <div style="font-size:2rem;font-weight:900;color:<?= $ac['icon'] ?>;line-height:1.1;"><?= $cnt ?></div>
    <div style="font-size:.66rem;color:<?= $ac['icon'] ?>;opacity:.75;margin-top:3px;"><?= $ac['range'] ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── SEARCH + CONTROLS ──────────────────────────────────────────────────── -->
<div class="d-flex gap-2 align-center mb-3 flex-wrap">
  <div class="search-box" style="flex:1;min-width:240px;max-width:420px;">
    <i class="fas fa-search"></i>
    <input type="text" id="liveSearch" placeholder="Search by name or resident code..."
           class="form-control" style="padding-left:34px;"
           oninput="liveSearchResidents(this.value)"
           value="<?= sanitize_output($search) ?>">
  </div>
  <form method="GET" class="d-flex gap-2 align-center">
    <input type="hidden" name="view" value="<?= sanitize_output($view) ?>">
    <input type="hidden" name="q" value="<?= sanitize_output($search) ?>">
    <input type="hidden" name="rs" value="<?= sanitize_output($status_filter) ?>">
    <input type="hidden" name="drawer" value="<?= sanitize_output($drawer_search) ?>">
    <input type="hidden" name="pp" value="<?= $per_page ?>">
    <input type="text" name="drawer" class="form-control" value="<?= sanitize_output($drawer_search) ?>"
           maxlength="100" placeholder="Filter by drawer location..." aria-label="Filter residents by drawer location">
    <button class="btn btn-secondary btn-sm" type="submit" title="Filter by drawer location"><i class="fas fa-filter"></i></button>
  </form>
  <?php if ($search): ?>
  <a href="?view=<?= $view ?>&pp=<?= $per_page ?>&drawer=<?= urlencode($drawer_search) ?>" class="btn btn-secondary btn-sm">
    <i class="fas fa-xmark"></i> Clear
  </a>
  <?php endif; ?>
  <button class="btn btn-secondary btn-sm" onclick="toggleAllFolders(true)">
    <i class="fas fa-folder-open"></i> Expand All
  </button>
  <button class="btn btn-secondary btn-sm" onclick="toggleAllFolders(false)">
    <i class="fas fa-folder"></i> Collapse All
  </button>
</div>

<!-- ── FOLDERS ───────────────────────────────────────────────────────────── -->
<?php
if ($view === 'purok') {
    foreach ($puroks as $purok_name => $residents) {
        $fid = 'folder_' . preg_replace('/\s+/', '_', strtolower($purok_name));
        echo renderFolder($fid, $purok_name, $residents, $purok_colors[$purok_name],
                          $purok_name === 'Unassigned', $per_page);
    }
} else {
    foreach ($age_groups as $ag_name => $residents) {
        if ($ag_name === 'Unknown' && count($residents) === 0) continue;
        $fid = 'agfolder_' . preg_replace('/[\s]+/', '_', strtolower($ag_name));
        echo renderFolder($fid, $ag_name, $residents, $age_colors[$ag_name], false, $per_page);
    }
}
?>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════════════════════ -->

<!-- ADD RESIDENT MODAL -->
<div class="modal-overlay" id="modalAddResident">
  <div class="modal" style="max-width:680px;">
    <div class="modal-header">
      <h4><i class="fas fa-user-plus"></i> Add New Resident</h4>
      <button class="modal-close" onclick="closeModal('modalAddResident')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <!-- enctype set via JS on submit since modal forms use fetch -->
      <form id="addResidentForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <!-- Photo upload -->
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding:14px;
                    background:var(--primary-xlight);border-radius:10px;border:1.5px solid var(--border);">
          <div id="addPhotoPreview"
               style="width:72px;height:72px;border-radius:50%;border:2px solid var(--border);
                      background:#f0f0f0;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;">
            <i class="fas fa-user" style="font-size:1.8rem;color:#ccc;"></i>
          </div>
          <div style="flex:1;">
            <label style="font-size:.83rem;font-weight:600;color:#4a5568;display:block;margin-bottom:5px;">
              <i class="fas fa-camera"></i> Photo
              <span style="font-weight:400;color:var(--text-muted);font-size:.75rem;"> (JPG/PNG, max 2MB, optional)</span>
            </label>
            <input type="file" name="photo" id="addPhotoInput" accept="image/jpeg,image/png"
                   class="form-control" onchange="previewAddPhoto(this)">
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label>First Name <span class="req">*</span></label>
                 <input type="text" name="first_name" class="form-control name-input" required maxlength="100"
                   pattern="[A-Za-zÀ-ÿ .'-]+" title="Letters, spaces, apostrophes, periods, and hyphens only">
          </div>
          <div class="form-group">
            <label>Middle Name</label>
                 <input type="text" name="middle_name" class="form-control name-input" maxlength="100"
                   pattern="[A-Za-zÀ-ÿ .'-]+" title="Letters, spaces, apostrophes, periods, and hyphens only">
          </div>
          <div class="form-group">
            <label>Last Name <span class="req">*</span></label>
                 <input type="text" name="last_name" class="form-control name-input" required maxlength="100"
                   pattern="[A-Za-zÀ-ÿ .'-]+" title="Letters, spaces, apostrophes, periods, and hyphens only">
          </div>
          <div class="form-group">
            <label>Date of Birth <span class="req">*</span></label>
            <input type="date" name="birth_date" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Sex <span class="req">*</span></label>
            <select name="sex" class="form-control" required>
              <option value="">-- Select --</option>
              <option>Male</option><option>Female</option>
            </select>
          </div>
          <div class="form-group">
            <label>Civil Status</label>
            <select name="civil_status" class="form-control">
              <option value="">-- Select --</option>
              <option>Single</option><option>Married</option>
              <option>Widowed</option><option>Separated</option>
            </select>
          </div>
          <div class="form-group">
            <label>Contact Number</label>
                 <input type="text" name="contact_number" class="form-control numeric-input" maxlength="20"
                   inputmode="tel" pattern="[0-9+() .-]+" title="Numbers and phone characters only">
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" maxlength="150">
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label>Address</label>
            <textarea name="address" class="form-control" rows="2" maxlength="500"></textarea>
          </div>
          <div class="form-group">
            <label>Purok <span class="req">*</span></label>
            <select name="purok" class="form-control" required>
              <option value="">-- Select Purok --</option>
              <option>Purok 1</option><option>Purok 2</option>
              <option>Purok 3</option><option>Purok 4</option>
            </select>
          </div>
          <div class="form-group">
            <label>Drawer Location</label>
            <input type="text" name="drawer_location" class="form-control" maxlength="100" placeholder="e.g. Drawer 2">
          </div>
          <div class="form-group">
            <label>Years of Residency</label>
                 <input type="text" name="years_of_residency" class="form-control numeric-input" inputmode="numeric"
                   pattern="[0-9]+" min="0" max="200" value="0" title="Numbers only">
          </div>
          <div class="form-group" style="justify-content:center;">
            <label>&nbsp;</label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
              <input type="checkbox" name="is_indigent" value="1" style="width:16px;height:16px;">
              Mark as Indigent
            </label>
          </div>
        </div>
        <!-- Household section -->
        <div style="border:1.5px solid var(--border);border-radius:10px;padding:14px 16px;margin-top:8px;background:var(--primary-xlight);">
          <div style="font-weight:700;font-size:.85rem;color:var(--primary);margin-bottom:12px;">
            <i class="fas fa-house-user"></i> Household Information
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                <input type="checkbox" name="is_head_of_family" id="addIsHeadChk" value="1"
                       style="width:16px;height:16px;" onchange="toggleAddHouseholdHead()">
                Mark as Head of Family
              </label>
            </div>
            <div class="form-group" id="addHouseholdHeadGroup">
              <label>Household Head <small style="color:var(--text-muted);font-weight:400;">(optional)</small></label>
              <div style="position:relative;">
                <input type="text" id="addHouseholdHeadSearch" class="form-control"
                       placeholder="Search by name, code, or purok..." autocomplete="off"
                       oninput="filterAddHouseholdHeads(this.value)"
                       onfocus="showAddHouseholdHeads()">
                <input type="hidden" name="household_head_id" id="addHouseholdHeadSelect" value="">
                <div id="addHouseholdHeadResults" style="display:none;position:absolute;top:100%;left:0;right:0;
                     z-index:20;background:#fff;border:1px solid var(--border);border-top:none;
                     border-radius:0 0 8px 8px;max-height:190px;overflow-y:auto;box-shadow:var(--shadow-md);">
                </div>
              </div>
              <small id="addHouseholdHeadHint" style="color:var(--text-muted);font-size:.75rem;margin-top:4px;display:block;">
                Type to search among registered household heads.
              </small>
            </div>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalAddResident')">Cancel</button>
      <button class="btn btn-primary" onclick="submitAddResident()">
        <i class="fas fa-save"></i> Save Resident
      </button>
    </div>
  </div>
</div>

<!-- VIEW RESIDENT MODAL -->
<div class="modal-overlay" id="modalViewResident">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h4><i class="fas fa-id-card"></i> Resident Profile</h4>
      <button class="modal-close" onclick="closeModal('modalViewResident')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="viewResidentContent"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalViewResident')">Close</button>
    </div>
  </div>
</div>

<!-- ARCHIVE REASON MODAL -->
<div class="modal-overlay" id="modalArchive">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header">
      <h4><i class="fas fa-box-archive" style="color:var(--danger);"></i> Archive Resident</h4>
      <button class="modal-close" onclick="closeModal('modalArchive')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 14px;
                  font-size:.83rem;color:#856404;margin-bottom:18px;">
        <i class="fas fa-triangle-exclamation"></i>
        Archiving removes the resident from the active list. This is logged in the audit trail.
      </div>
      <p style="font-size:.9rem;margin-bottom:14px;">
        Archiving: <strong id="archiveName"></strong>
      </p>
      <input type="hidden" id="archiveId">
      <div class="form-group">
        <label>Reason for Archiving <span class="req">*</span></label>
        <select id="archiveReasonType" class="form-control" onchange="toggleArchiveOther()">
          <option value="">-- Select Reason --</option>
          <option value="Transferred">Transferred</option>
          <option value="Deceased">Deceased</option>
          <option value="Duplicate Record">Duplicate Record</option>
          <option value="Other">Other (specify)</option>
        </select>
      </div>
      <div class="form-group mt-2" id="archiveOtherGroup" style="display:none;">
        <label>Please specify <span class="req">*</span></label>
        <textarea id="archiveOtherText" class="form-control" rows="2"
                  maxlength="480" placeholder="Describe the reason..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modalArchive')">Cancel</button>
      <button class="btn btn-danger" onclick="confirmArchive()">
        <i class="fas fa-box-archive"></i> Confirm Archive
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════════════ -->
<script>
const CSRF = '<?= $csrf ?>';

document.querySelectorAll('#addResidentForm .name-input').forEach(function (input) {
  input.addEventListener('input', function () { this.value = this.value.replace(/[\d]/g, ''); });
});
document.querySelectorAll('#addResidentForm .numeric-input').forEach(function (input) {
  input.addEventListener('input', function () {
    this.value = this.name === 'years_of_residency'
      ? this.value.replace(/\D/g, '')
      : this.value.replace(/[^0-9+() .-]/g, '');
  });
});

// ── Photo preview (Add form) ──────────────────────────────────────────────────
function previewAddPhoto(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  if (file.size > 2*1024*1024) { showToast('warning','Photo must be under 2MB.'); input.value=''; return; }
  if (!['image/jpeg','image/png'].includes(file.type)) { showToast('warning','Only JPG/PNG allowed.'); input.value=''; return; }
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('addPhotoPreview').innerHTML =
      `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;" alt="preview">`;
  };
  reader.readAsDataURL(file);
}

// ── Add Resident (FormData for file upload) ───────────────────────────────────
async function submitAddResident() {
  const form = document.getElementById('addResidentForm');
  const fd   = new FormData(form);
  fd.set('is_indigent',       form.querySelector('[name=is_indigent]')?.checked       ? '1' : '0');
  fd.set('is_head_of_family', form.querySelector('[name=is_head_of_family]')?.checked ? '1' : '0');
  const hhSel = form.querySelector('[name=household_head_id]');
  fd.set('household_head_id', hhSel ? (hhSel.value || '0') : '0');
  if (!fd.get('first_name') || !fd.get('last_name') || !fd.get('birth_date')) {
    showToast('error','First name, last name, and birth date are required.'); return;
  }
  try {
    const resp = await fetch('/residents/api.php?action=add', { method:'POST', body:fd });
    const text = await resp.text();
    let res;
    try { res = JSON.parse(text); } catch(parseErr) {
      showToast('error', 'Server error while adding resident.');
      return;
    }
    if (res.success) {
      showToast('success','Resident added! Code: '+res.code);
      closeModal('modalAddResident');
      setTimeout(() => location.reload(), 1300);
    } else showToast('error', res.message || 'Failed to add resident.');
  } catch(e) { showToast('error','Network error.'); }
}

// ── Household Head helpers (Add modal) ────────────────────────────────────────
async function loadAddHouseholdHeads() {
  const search = document.getElementById('addHouseholdHeadSearch');
  if (!search) return;
  try {
    const resp = await fetch('/residents/api.php?action=get_heads&csrf_token=<?= $csrf ?>');
    const data = await resp.json();
    if (!data.success) return;
    window.addHouseholdHeads = data.heads || [];
  } catch(e) {}
}

function renderAddHouseholdHeads(heads) {
  const results = document.getElementById('addHouseholdHeadResults');
  if (!results) return;
  if (!heads.length) {
    results.innerHTML = '<div style="padding:10px;color:var(--text-muted);font-size:.8rem;">No household head found.</div>';
    results.style.display = 'block';
    return;
  }
  results.innerHTML = heads.map(h => `<button type="button" onclick="selectAddHouseholdHead(${h.id}, this)"
    style="display:block;width:100%;padding:9px 11px;border:0;border-bottom:1px solid var(--border);background:#fff;
           text-align:left;color:var(--text);cursor:pointer;font-size:.82rem;">
    <strong>${escapeAddHouseholdText(h.name)}</strong><br>
    <small style="color:var(--text-muted);">[${escapeAddHouseholdText(h.code)}]${h.purok ? ' — ' + escapeAddHouseholdText(h.purok) : ''}</small>
  </button>`).join('');
  results.style.display = 'block';
}

function filterAddHouseholdHeads(query) {
  const q = (query || '').toLowerCase().trim();
  const heads = (window.addHouseholdHeads || []).filter(h =>
    `${h.name} ${h.code} ${h.purok || ''}`.toLowerCase().includes(q)
  );
  document.getElementById('addHouseholdHeadSelect').value = '';
  renderAddHouseholdHeads(heads);
}

function showAddHouseholdHeads() {
  const search = document.getElementById('addHouseholdHeadSearch');
  filterAddHouseholdHeads(search ? search.value : '');
}

function selectAddHouseholdHead(id, button) {
  const head = (window.addHouseholdHeads || []).find(h => String(h.id) === String(id));
  if (!head) return;
  document.getElementById('addHouseholdHeadSelect').value = head.id;
  document.getElementById('addHouseholdHeadSearch').value = `[${head.code}] ${head.name}${head.purok ? ' — ' + head.purok : ''}`;
  document.getElementById('addHouseholdHeadResults').style.display = 'none';
  document.getElementById('addHouseholdHeadHint').textContent = 'Selected household head. Clear the field to choose another.';
}

function escapeAddHouseholdText(value) {
  return String(value || '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
}

document.addEventListener('click', event => {
  const wrapper = document.getElementById('addHouseholdHeadResults');
  const search = document.getElementById('addHouseholdHeadSearch');
  if (wrapper && search && !wrapper.contains(event.target) && event.target !== search) wrapper.style.display = 'none';
});

function toggleAddHouseholdHead() {
  const isHead = document.getElementById('addIsHeadChk').checked;
  const grp    = document.getElementById('addHouseholdHeadGroup');
  if (grp) grp.style.display = isHead ? 'none' : 'block';
}

// Load heads when modal opens
document.addEventListener('DOMContentLoaded', () => loadAddHouseholdHeads());

// ── View Resident — navigate to 360 view page ────────────────────────────────
function viewResident(btn) {
  const d = JSON.parse(btn.dataset.resident);
  window.location.href = '/residents/view.php?id=' + d.id;
}

// ── Archive modal ─────────────────────────────────────────────────────────────
function openArchiveModal(id, name) {
  document.getElementById('archiveId').value = id;
  document.getElementById('archiveName').textContent = name;
  document.getElementById('archiveReasonType').value = '';
  document.getElementById('archiveOtherText').value  = '';
  document.getElementById('archiveOtherGroup').style.display = 'none';
  openModal('modalArchive');
}

function toggleArchiveOther() {
  const show = document.getElementById('archiveReasonType').value === 'Other';
  document.getElementById('archiveOtherGroup').style.display = show ? 'block' : 'none';
}

async function confirmArchive() {
  const id          = document.getElementById('archiveId').value;
  const reason_type = document.getElementById('archiveReasonType').value;
  const other_text  = document.getElementById('archiveOtherText').value.trim();

  if (!reason_type) { showToast('error','Please select a reason.'); return; }
  if (reason_type === 'Other' && !other_text) {
    showToast('error','Please specify the reason.'); return;
  }

  const fd = new FormData();
  fd.append('csrf_token',    CSRF);
  fd.append('resident_id',   id);
  fd.append('reason_type',   reason_type);
  fd.append('archive_reason',reason_type === 'Other' ? other_text : reason_type);

  try {
    const resp = await fetch('/residents/api.php?action=archive', { method:'POST', body:fd });
    const res  = await resp.json();
    if (res.success) {
      showToast('success','Resident archived.');
      closeModal('modalArchive');
      setTimeout(() => location.reload(), 1200);
    } else showToast('error', res.message || 'Archive failed.');
  } catch(e) { showToast('error','Network error.'); }
}

// ── Quick Verify ──────────────────────────────────────────────────────────────
async function quickVerify(id) {
  if (!confirm('Mark this resident as Active (verified)?')) return;
  const fd = new FormData();
  fd.append('csrf_token',    CSRF);
  fd.append('resident_id',   id);
  fd.append('record_status', 'Active');
  const resp = await fetch('/residents/api.php?action=change_status', { method:'POST', body:fd });
  const res  = await resp.json();
  if (res.success) {
    showToast('success', 'Resident marked as Active.');
    setTimeout(() => location.reload(), 1000);
  } else {
    showToast('error', res.message || 'Failed.');
  }
}

async function acceptAllPending() {
  const count = <?= (int)($status_counts['Pending Verification'] ?? 0) ?>;
  if (!count || !confirm(`Accept all ${count} pending resident record${count === 1 ? '' : 's'}?`)) return;

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  const resp = await fetch('/residents/api.php?action=accept_all_pending', { method:'POST', body:fd });
  const result = await resp.json();
  if (result.success) {
    showToast('success', `${result.updated} resident record${result.updated === 1 ? '' : 's'} accepted.`);
    setTimeout(() => location.reload(), 800);
  } else showToast('error', result.message || 'Failed to accept pending records.');
}

// ── Navigation helpers ────────────────────────────────────────────────────────
function editResident(id)  { window.location.href = '/residents/edit.php?id=' + id; }
function requestDoc(id)    { window.location.href = '/documents/create.php?resident_id=' + id; }

// ── Scroll to folder ──────────────────────────────────────────────────────────
function scrollToFolder(id) {
  const el = document.querySelector('[data-folder="'+id+'"]');
  if (!el) return;
  const body    = document.querySelector('.folder-body-'+id);
  const icon    = document.querySelector('.folder-icon-'+id);
  const chevron = document.querySelector('.folder-chevron-'+id);
  if (body)    body.style.display = '';
  if (icon)    icon.className = icon.className.replace('fa-folder ','fa-folder-open ');
  if (chevron) chevron.style.transform = 'rotate(0deg)';
  el.scrollIntoView({ behavior:'smooth', block:'start' });
}

// ── Folder toggle ─────────────────────────────────────────────────────────────
function toggleFolder(id) {
  const body    = document.querySelector('.folder-body-'+id);
  const icon    = document.querySelector('.folder-icon-'+id);
  const chevron = document.querySelector('.folder-chevron-'+id);
  if (!body) return;
  const isOpen = body.style.display !== 'none';
  body.style.display    = isOpen ? 'none' : '';
  icon.className        = isOpen
    ? icon.className.replace('fa-folder-open','fa-folder')
    : icon.className.replace('fa-folder ','fa-folder-open ');
  if (chevron) chevron.style.transform = isOpen ? 'rotate(-90deg)' : 'rotate(0deg)';
}

function toggleAllFolders(open) {
  document.querySelectorAll('[data-folder]').forEach(el => {
    const id      = el.dataset.folder;
    const body    = document.querySelector('.folder-body-'+id);
    const icon    = document.querySelector('.folder-icon-'+id);
    const chevron = document.querySelector('.folder-chevron-'+id);
    if (!body) return;
    body.style.display = open ? '' : 'none';
    if (icon) icon.className = open
      ? icon.className.replace('fa-folder ','fa-folder-open ')
      : icon.className.replace('fa-folder-open','fa-folder');
    if (chevron) chevron.style.transform = open ? 'rotate(0deg)' : 'rotate(-90deg)';
  });
}

// ── Live search (client-side, filters within rendered page) ──────────────────
function liveSearchResidents(query) {
  const q = query.toLowerCase().trim();
  document.querySelectorAll('[data-folder]').forEach(folder => {
    const id   = folder.dataset.folder;
    const body = document.querySelector('.folder-body-'+id);
    const rows = body ? body.querySelectorAll('tbody tr') : [];
    let visible = 0;
    rows.forEach(row => {
      const match = !q || row.textContent.toLowerCase().includes(q);
      row.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    if (q) {
      const icon    = document.querySelector('.folder-icon-'+id);
      const chevron = document.querySelector('.folder-chevron-'+id);
      if (visible > 0) {
        if (body)    body.style.display = '';
        if (icon)    icon.className = icon.className.replace('fa-folder ','fa-folder-open ');
        if (chevron) chevron.style.transform = 'rotate(0deg)';
      } else {
        if (body) body.style.display = 'none';
      }
    }
  });
}
</script>
<?php require_once '../includes/footer.php'; ?>
