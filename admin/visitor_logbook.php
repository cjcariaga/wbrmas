<?php
$page_title = 'Visitor Logbook';
$active_page = 'visitor_logbook';
$breadcrumb = ['Administration', 'Visitor Logbook'];
require_once '../includes/auth_check.php';
require_permission('view_visitor_logbook');

$conn = getDBConnection();
$csrf = generate_csrf_token();
$page_url = app_url('admin/visitor_logbook.php');
$today = new DateTimeImmutable('today');
$period_input = $_GET['period'] ?? 'today';
$period = is_string($period_input) ? sanitize_input($period_input) : 'today';
$allowed_periods = ['today', 'week', 'custom'];
if (!in_array($period, $allowed_periods, true)) $period = 'today';
$filter_error = '';
$custom_from_input = $_GET['from'] ?? '';
$custom_to_input = $_GET['to'] ?? '';
$custom_from = is_string($custom_from_input) ? sanitize_input($custom_from_input) : '';
$custom_to = is_string($custom_to_input) ? sanitize_input($custom_to_input) : '';
$is_valid_date = static function ($value) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $parts = explode('-', $value);
    return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
};

if ($period === 'week') {
    $filter_from = $today->modify('monday this week')->format('Y-m-d');
    $filter_to = $today->modify('sunday this week')->format('Y-m-d');
} elseif ($period === 'custom') {
    if ($is_valid_date($custom_from) && $is_valid_date($custom_to) && $custom_from <= $custom_to) {
        $filter_from = $custom_from;
        $filter_to = $custom_to;
    } else {
        $period = 'today';
        $filter_from = $today->format('Y-m-d');
        $filter_to = $filter_from;
        $filter_error = 'Pumili ng valid na custom date range.';
    }
} else {
    $filter_from = $today->format('Y-m-d');
    $filter_to = $filter_from;
}

$search_input = $_GET['q'] ?? '';
$search = is_string($search_input)
    ? trim(html_entity_decode(sanitize_input($search_input), ENT_QUOTES, 'UTF-8'))
    : '';
if (mb_strlen($search, 'UTF-8') > 120) $search = mb_substr($search, 0, 120, 'UTF-8');
$start_datetime = $filter_from . ' 00:00:00';
$end_datetime = (new DateTimeImmutable($filter_to))->modify('+1 day')->format('Y-m-d 00:00:00');
$stmt = $conn->prepare(
    "SELECT visitor_id, full_name, contact_number, address, purpose, person_to_visit, time_in, time_out
     FROM tbl_visitor_logbook
     WHERE time_in >= ? AND time_in < ?
     ORDER BY time_in DESC, visitor_id DESC"
);
if (!$stmt) {
    http_response_code(500);
    exit('Could not load visitor logbook entries.');
}
$stmt->bind_param('ss', $start_datetime, $end_datetime);
$stmt->execute();
$result = $stmt->get_result();
$filtered_entries = [];
$search_needle = mb_strtolower($search, 'UTF-8');
while ($row = $result->fetch_assoc()) {
    $row['full_name'] = aes_decrypt($row['full_name']);
    $row['contact_number'] = aes_decrypt($row['contact_number'] ?? '');
    $row['address'] = aes_decrypt($row['address'] ?? '');
    $row['person_to_visit'] = (string)($row['person_to_visit'] ?? '');
    if ($search_needle !== '') {
        $search_text = mb_strtolower(
            implode(' ', [
                (string)$row['visitor_id'],
                $row['full_name'],
                $row['contact_number'],
                $row['address'],
                (string)$row['purpose'],
                $row['person_to_visit']
            ]),
            'UTF-8'
        );
        if (mb_strpos($search_text, $search_needle, 0, 'UTF-8') === false) continue;
    }
    $filtered_entries[] = $row;
}
$stmt->close();

$total_entries = count($filtered_entries);
$per_page = 25;
$total_pages = max(1, (int)ceil($total_entries / $per_page));
$page_input = $_GET['page'] ?? 1;
$requested_page = is_string($page_input) || is_int($page_input)
    ? filter_var($page_input, FILTER_VALIDATE_INT)
    : false;
$page = min(max(1, $requested_page ?: 1), $total_pages);
$print_input = $_GET['print'] ?? '';
$print_view = is_string($print_input) && $print_input === '1';
$page_entries = $print_view
    ? $filtered_entries
    : array_slice($filtered_entries, ($page - 1) * $per_page, $per_page);
$query_args = [
    'period' => $period,
    'from' => $period === 'custom' ? $filter_from : '',
    'to' => $period === 'custom' ? $filter_to : '',
    'q' => $search
];

function visitorCsvCell($value): string {
    $value = (string)$value;
    return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'" . $value : $value;
}

$export_input = $_GET['export'] ?? '';
if (is_string($export_input) && $export_input === 'csv') {
    write_audit_log(
        current_user_id(),
        'EXPORT_VISITOR_LOGBOOK',
        'visitor_logbook',
        "Range:$filter_from..$filter_to Rows:$total_entries"
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="visitor_logbook_'.$filter_from.'_to_'.$filter_to.'.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Visitor ID', 'Full Name', 'Contact Number', 'Address / Purok', 'Purpose', 'Person / Office to Visit', 'Time In', 'Time Out']);
    foreach ($filtered_entries as $entry) {
        fputcsv($output, array_map('visitorCsvCell', [
            $entry['visitor_id'],
            $entry['full_name'],
            $entry['contact_number'],
            $entry['address'],
            $entry['purpose'],
            $entry['person_to_visit'],
            $entry['time_in'],
            $entry['time_out'] ?? ''
        ]));
    }
    fclose($output);
    exit;
}

$export_url = $page_url . '?' . http_build_query($query_args + ['export' => 'csv']);
$print_url = $page_url . '?' . http_build_query($query_args + ['print' => '1']);
require_once '../includes/header.php';
?>
<style>
@page {margin:12mm}
.visitor-print-heading {display:none}
@media print {
  .no-print,.sidebar,.topbar,.page-header,.sidebar-overlay {display:none!important}
  .app-layout {display:block!important}
  .main-content {width:100%!important;margin:0!important}
  .page-content {padding:0!important}
  body {background:#fff!important}
  .card {border:0!important;box-shadow:none!important}
  .visitor-print-heading {display:block;margin-bottom:16px}
}
</style>

<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-clipboard-list"></i> Visitor Logbook</h2>
    <p>Visitor entries and hall attendance for Barangay San Isidro</p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-secondary no-print" href="<?= sanitize_output($export_url) ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
    <a class="btn btn-secondary no-print" href="<?= sanitize_output($print_url) ?>" target="_blank" rel="noopener"><i class="fas fa-print"></i> Print</a>
  </div>
</div>
<div class="visitor-print-heading">
  <h1>Barangay San Isidro — Visitor Logbook</h1>
  <p>Date range: <?= sanitize_output($filter_from) ?> to <?= sanitize_output($filter_to) ?> · <?= number_format($total_entries) ?> entries</p>
</div>

<form method="GET" action="<?= sanitize_output($page_url) ?>" class="card mb-3 no-print">
  <div class="card-body d-flex gap-2 align-center flex-wrap" style="padding:14px 18px;">
    <label class="d-flex align-center gap-2">Date filter
      <select name="period" id="visitorPeriod" class="form-control">
        <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
        <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>This Week</option>
        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
      </select>
    </label>
    <label class="d-flex align-center gap-2">From
      <input type="date" name="from" class="form-control" value="<?= sanitize_output($custom_from) ?>">
    </label>
    <label class="d-flex align-center gap-2">To
      <input type="date" name="to" class="form-control" value="<?= sanitize_output($custom_to) ?>">
    </label>
    <input type="search" name="q" class="form-control" maxlength="120" value="<?= sanitize_output($search) ?>" placeholder="Search visitor details..." aria-label="Search visitor details">
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
  </div>
  <?php if ($filter_error !== ''): ?><div class="card-body" style="padding:0 18px 14px;color:var(--danger);"><?= sanitize_output($filter_error) ?></div><?php endif; ?>
</form>

<div class="card">
  <div class="card-header">
    <h3><i class="fas fa-list"></i> Log Entries</h3>
    <span class="badge badge-secondary"><?= number_format($total_entries) ?> entries · <?= sanitize_output($filter_from) ?> to <?= sanitize_output($filter_to) ?></span>
  </div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>#</th><th>Full Name</th><th>Contact</th><th>Address / Purok</th><th>Purpose</th><th>Person / Office</th><th>Time In</th><th>Time Out</th><th class="no-print">Action</th></tr>
        </thead>
        <tbody>
        <?php if ($page_entries): foreach ($page_entries as $entry): ?>
          <tr>
            <td><?= (int)$entry['visitor_id'] ?></td>
            <td><?= sanitize_output($entry['full_name']) ?></td>
            <td><?= sanitize_output($entry['contact_number'] ?: '—') ?></td>
            <td><?= sanitize_output($entry['address'] ?: '—') ?></td>
            <td><?= sanitize_output($entry['purpose']) ?></td>
            <td><?= sanitize_output($entry['person_to_visit'] ?: '—') ?></td>
            <td><?= sanitize_output(date('M d, Y h:i A', strtotime($entry['time_in']))) ?></td>
            <td><?= $entry['time_out'] ? sanitize_output(date('M d, Y h:i A', strtotime($entry['time_out']))) : '—' ?></td>
            <td class="no-print">
              <?php if (empty($entry['time_out'])): ?>
              <button type="button" class="btn btn-success btn-sm" onclick="timeOutVisitor(<?= (int)$entry['visitor_id'] ?>)"><i class="fas fa-right-from-bracket"></i> Time Out</button>
              <?php else: ?>
              <span class="text-muted">Completed</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="9"><div class="empty-state"><i class="fas fa-clipboard-list"></i><h4>No visitor entries</h4><p>Walang visitor entries para sa napiling filter.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="pagination no-print" style="padding:16px 22px;">
      <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <a href="<?= sanitize_output($page_url . '?' . http_build_query($query_args + ['page' => $p])) ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const VISITOR_LOGBOOK_API = <?= json_encode(app_url('admin/visitor_logbook_api.php')) ?>;
const VISITOR_LOGBOOK_CSRF = <?= json_encode($csrf) ?>;

async function timeOutVisitor(visitorId) {
  if (!confirm('I-record ang oras ng paglabas ng bisitang ito?')) return;
  const result = await apiRequest(VISITOR_LOGBOOK_API, {
    visitor_id: visitorId,
    csrf_token: VISITOR_LOGBOOK_CSRF
  });
  if (!result.success) {
    showToast('error', result.message || 'Hindi naitala ang time out.');
    return;
  }
  showToast('success', 'Naitala na ang time out.');
  setTimeout(() => window.location.reload(), 500);
}
</script>
<?php if ($print_view): ?>
<script>window.addEventListener('load', () => window.print());</script>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
