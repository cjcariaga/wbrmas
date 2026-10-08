<?php
require_once '../includes/auth_check.php';
require_role('System Administrator');

$conn = getDBConnection();
$filter_action = sanitize_input($_GET['action_type'] ?? '');
$filter_user   = (int)($_GET['user_id'] ?? 0);
$filter_date   = sanitize_input($_GET['date'] ?? 'all');
$filter_from   = sanitize_input($_GET['date_from'] ?? '');
$filter_to     = sanitize_input($_GET['date_to'] ?? '');
$search        = sanitize_input($_GET['q'] ?? '');
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
if ($filter_action) $where .= " AND a.action_type='".$conn->real_escape_string($filter_action)."'";
if ($filter_user)   $where .= " AND a.user_id=$filter_user";
if ($filter_date === 'today') $where .= " AND a.timestamp >= CURDATE() AND a.timestamp < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
if ($filter_from) $where .= " AND a.timestamp >= '".$conn->real_escape_string($filter_from)." 00:00:00'";
if ($filter_to)   $where .= " AND a.timestamp < DATE_ADD('".$conn->real_escape_string($filter_to)."', INTERVAL 1 DAY)";
if ($search)        $where .= " AND (a.action_type LIKE '%".$conn->real_escape_string($search)."%' OR a.affected_record LIKE '%".$conn->real_escape_string($search)."%')";

$logs = $conn->query(
    "SELECT a.log_id, a.timestamp, u.username, a.action_type, a.affected_record, a.ip_address, a.details, a.hmac_signature
     FROM tbl_audit_logs a
     LEFT JOIN tbl_users u ON a.user_id=u.user_id
     $where ORDER BY a.timestamp DESC"
);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="wbrmas_audit_log_'.date('Y-m-d').'.csv"');

$out = fopen('php://output','w');
fputcsv($out, ['Log ID','Timestamp','Username','Action','Affected Record','IP Address','Details','HMAC Signature']);

while ($row = $logs->fetch_assoc()) {
    fputcsv($out, [
        $row['log_id'], $row['timestamp'], $row['username'] ?? 'System',
        $row['action_type'], $row['affected_record'], $row['ip_address'],
        $row['details'], substr($row['hmac_signature'],0,16).'...'
    ]);
}
fclose($out);
write_audit_log($_SESSION['user_id'],'EXPORT_AUDIT_LOG','audit_trail','CSV export');
?>
