<?php
require_once 'config/security.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !check_session_timeout()) {
    echo json_encode(['success' => false, 'message' => 'Session expired.', 'results' => []]);
    exit;
}
if (($_SESSION['role_name'] ?? '') === 'Barangay Treasurer') {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => [], 'hint' => 'Type at least 2 characters.']);
    exit;
}

$needle = mb_strtolower($q);
$conn   = getDBConnection();
$uid    = (int)$_SESSION['user_id'];
$is_admin = (($_SESSION['role_name'] ?? '') === 'System Administrator');
$results = [];

function name_matches($haystack, $needle) {
    return $haystack !== '' && mb_stripos($haystack, $needle) !== false;
}

function full_name($last, $first, $middle = '') {
    $last  = trim($last);
    $first = trim($first);
    $mid   = trim($middle);
    return trim($last . ', ' . $first . ($mid !== '' ? ' ' . $mid : ''));
}

// ── Residents: decrypt-then-filter (names are AES, so SQL LIKE cannot search them)
$res_rows = $conn->query(
    "SELECT resident_id, resident_code, first_name, middle_name, last_name, purok
     FROM tbl_residents WHERE is_archived=0
     ORDER BY resident_id DESC LIMIT 1500"
);
if ($res_rows) {
    $count = 0;
    while ($r = $res_rows->fetch_assoc()) {
        $fn   = aes_decrypt($r['first_name']);
        $mn   = aes_decrypt($r['middle_name']);
        $ln   = aes_decrypt($r['last_name']);
        $name = full_name($ln, $fn, $mn);
        $code = (string)$r['resident_code'];
        $pk   = (string)($r['purok'] ?: 'Unassigned');
        $blob = mb_strtolower($name . ' ' . $code . ' ' . $pk);
        if (!name_matches($blob, $needle)) continue;

        $results[] = [
            'type'    => 'resident',
            'icon'    => 'fa-people-group',
            'title'   => $name,
            'body'    => $code . ' · ' . $pk,
            'link'    => '/BRGYMS/residents/view.php?id=' . (int)$r['resident_id'],
            'badge'   => 'Resident',
        ];
        if (++$count >= 6) break;
    }
}

// ── Document requests: codes are plaintext; resident names need decrypt
$uid_filter = $is_admin ? '' : "AND dr.issued_by_user_id = $uid";
$doc_rows = $conn->query(
    "SELECT dr.request_id, dr.request_code, dr.status, dt.type_name,
            r.first_name, r.last_name
     FROM tbl_document_requests dr
     JOIN tbl_residents r ON dr.resident_id = r.resident_id
     JOIN tbl_document_types dt ON dr.document_type_id = dt.type_id
     WHERE 1=1 $uid_filter
     ORDER BY dr.requested_at DESC LIMIT 400"
);
if ($doc_rows) {
    $count = 0;
    while ($d = $doc_rows->fetch_assoc()) {
        $name = full_name(aes_decrypt($d['last_name']), aes_decrypt($d['first_name']));
        $blob = mb_strtolower($d['request_code'] . ' ' . $d['type_name'] . ' ' . $d['status'] . ' ' . $name);
        if (!name_matches($blob, $needle)) continue;

        $results[] = [
            'type'    => 'document',
            'icon'    => 'fa-file-lines',
            'title'   => $d['request_code'] . ' · ' . $d['type_name'],
            'body'    => $name . ' · ' . $d['status'],
            'link'    => '/BRGYMS/documents/index.php?q=' . rawurlencode($d['request_code']),
            'badge'   => 'Document',
        ];
        if (++$count >= 4) break;
    }
}

// ── Blotter / case reports
$blot_rows = $conn->query(
    "SELECT b.case_id, b.case_number, b.resolution_status, b.case_type,
            b.respondent_name, r.first_name, r.last_name
     FROM tbl_blotter b
     JOIN tbl_residents r ON b.complainant_id = r.resident_id
     ORDER BY b.filed_at DESC LIMIT 400"
);
if ($blot_rows) {
    $count = 0;
    while ($b = $blot_rows->fetch_assoc()) {
        $complainant = full_name(aes_decrypt($b['last_name']), aes_decrypt($b['first_name']));
        $blob = mb_strtolower(
            $b['case_number'] . ' ' . ($b['case_type'] ?? '') . ' ' .
            ($b['respondent_name'] ?? '') . ' ' . $complainant
        );
        if (!name_matches($blob, $needle)) continue;

        $results[] = [
            'type'    => 'blotter',
            'icon'    => 'fa-book-open',
            'title'   => $b['case_number'] . ' · ' . ($b['case_type'] ?: 'Case'),
            'body'    => $complainant . ' · ' . $b['resolution_status'],
            'link'    => '/BRGYMS/blotter/index.php?q=' . rawurlencode($b['case_number']),
            'badge'   => 'Case',
        ];
        if (++$count >= 4) break;
    }
}

echo json_encode([
    'success' => true,
    'query'   => $q,
    'count'   => count($results),
    'results' => $results,
]);
