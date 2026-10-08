<?php
require_once __DIR__ . '/../config/security.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !check_session_timeout()) {
    echo json_encode(['success'=>false,'message'=>'Session expired.']); exit;
}
if (($_SESSION['role_name'] ?? '') === 'Barangay Treasurer') {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token.']); exit;
}

$q     = sanitize_input($_POST['q'] ?? '');
$purok = sanitize_input($_POST['purok'] ?? '');
$conn  = getDBConnection();

if (!$q) { echo json_encode(['success'=>false,'message'=>'No search term.']); exit; }

$like = '%' . $conn->real_escape_string($q) . '%';

// Build purok filter
$purok_sql = '';
if ($purok) {
    if ($purok === 'Unassigned') {
        $purok_sql = "AND (purok IS NULL OR purok = '')";
    } else {
        $esc_purok = $conn->real_escape_string($purok);
        $purok_sql = "AND purok = '$esc_purok'";
    }
}

$stmt = $conn->prepare(
    "SELECT resident_id, resident_code, first_name, middle_name, last_name, sex, is_indigent, purok
     FROM tbl_residents WHERE is_archived=0
     AND (first_name LIKE ? OR last_name LIKE ? OR resident_code LIKE ?)
     $purok_sql
     ORDER BY last_name ASC LIMIT 20"
);
$stmt->bind_param("sss", $like, $like, $like);
$stmt->execute();
$rows = $stmt->get_result();
$stmt->close();

$results = [];
while ($r = $rows->fetch_assoc()) {
    $fn = aes_decrypt($r['first_name']);
    $mn = aes_decrypt($r['middle_name']);
    $ln = aes_decrypt($r['last_name']);
    $results[] = [
        'id'      => $r['resident_id'],
        'code'    => $r['resident_code'],
        'name'    => "$ln, $fn" . ($mn ? " $mn" : ''),
        'sex'     => $r['sex'],
        'indigent'=> (bool)$r['is_indigent'],
    ];
}

echo json_encode(['success'=>true,'residents'=>$results]);
?>
