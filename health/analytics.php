<?php
$page_title = 'Health Analytics';
$active_page = 'health';
$breadcrumb = ['Health Records', 'Analytics'];
require_once '../includes/auth_check.php';
$allowed_roles = ['System Administrator', 'Barangay Staff'];
if (!in_array($_SESSION['role_name'] ?? '', $allowed_roles, true)) {
    http_response_code(403);
    exit('Access denied.');
}
require_permission('view_health_records');

$conn = getDBConnection();
$active_residents = (int)$conn->query("SELECT COUNT(*) FROM tbl_residents WHERE is_archived=0")->fetch_row()[0];
$records = $conn->query(
  "SELECT h.resident_id, h.record_type_enc, h.item_name_enc
   FROM tbl_health_records h
   JOIN tbl_residents r ON r.resident_id=h.resident_id
   WHERE r.is_archived=0"
);
$vaccinated_residents = [];
$program_residents = [];
if ($records) {
    while ($record = $records->fetch_assoc()) {
        $record_type = aes_decrypt($record['record_type_enc']);
        if ($record_type === 'vaccination') {
            $vaccinated_residents[(int)$record['resident_id']] = true;
        } elseif ($record_type === 'program') {
            $program_name = trim(aes_decrypt($record['item_name_enc']));
            if ($program_name !== '') {
                $program_key = mb_strtolower($program_name, 'UTF-8');
                if (!isset($program_residents[$program_key])) {
                    $program_residents[$program_key] = ['name' => $program_name, 'residents' => []];
                }
                $program_residents[$program_key]['residents'][(int)$record['resident_id']] = true;
            }
        }
    }
}
$program_rows = array_values($program_residents);
usort($program_rows, function ($left, $right) {
    return count($right['residents']) <=> count($left['residents']);
});
$vaccinated_count = count($vaccinated_residents);
$vaccination_coverage = $active_residents > 0 ? round(($vaccinated_count / $active_residents) * 100, 1) : 0;
require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-chart-column"></i> Health Records Analytics</h2>
    <p>Descriptive counts from recorded health entries</p>
  </div>
  <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Health Records</a>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:20px;">
  <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-syringe"></i></div><div><div class="stat-value"><?= number_format($vaccinated_count) ?></div><div class="stat-label">Residents with vaccination records</div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-chart-pie"></i></div><div><div class="stat-value"><?= number_format($vaccination_coverage, 1) ?>%</div><div class="stat-label">Vaccination record coverage of active residents</div></div></div>
  <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-people-group"></i></div><div><div class="stat-value"><?= number_format($active_residents) ?></div><div class="stat-label">Active residents</div></div></div>
</div>

<div class="card">
  <div class="card-header"><h3><i class="fas fa-people-group"></i> Residents per Health Program</h3></div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Program</th><th>Residents Enrolled</th></tr></thead>
        <tbody>
        <?php if ($program_rows): foreach ($program_rows as $program): ?>
          <tr><td><?= sanitize_output($program['name']) ?></td><td><?= number_format(count($program['residents'])) ?></td></tr>
        <?php endforeach; else: ?>
          <tr><td colspan="2"><div class="empty-state"><i class="fas fa-chart-column"></i><p>No health program enrollments recorded.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<p style="font-size:.8rem;color:var(--text-muted);margin-top:12px;">Descriptive totals only. Vaccination coverage is the number of active residents with at least one vaccination entry divided by active resident count. No predictions are generated.</p>
<?php require_once '../includes/footer.php'; ?>
