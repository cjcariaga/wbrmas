<?php
require_once __DIR__ . '/config/security.php';
secure_session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed.']);
    exit;
}
$csrf_token = $_POST['csrf_token'] ?? '';
if (!is_string($csrf_token) || !verify_csrf_token($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Invalid request. Refresh the page and try again.']);
    exit;
}

function visitor_form_value(string $key): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) return '';
    return trim(html_entity_decode(sanitize_input($value), ENT_QUOTES, 'UTF-8'));
}

$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
if (!filter_var($ip_address, FILTER_VALIDATE_IP)) $ip_address = '0.0.0.0';
if (!check_rate_limit('visitor_logbook_' . $ip_address)) {
    http_response_code(429);
    echo json_encode(['success'=>false,'message'=>'Masyadong maraming submission. Subukan muli pagkalipas ng ilang sandali.']);
    exit;
}
if (visitor_form_value('website') !== '') {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Hindi naisumite ang form. Pakisubukan muli.']);
    exit;
}

$full_name = visitor_form_value('full_name');
$contact_number = visitor_form_value('contact_number');
$address = visitor_form_value('address');
$purpose = visitor_form_value('purpose');
$person_to_visit = visitor_form_value('person_to_visit');
$allowed_purposes = ['Document Request', 'Complaint/Blotter', 'Inquiry', 'Meeting', 'Other'];

if ($full_name === '' || mb_strlen($full_name, 'UTF-8') > 150) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Ilagay ang buong pangalan (hanggang 150 character).']);
    exit;
}
if ($contact_number !== '' && (mb_strlen($contact_number, 'UTF-8') > 50 || !preg_match('/^[0-9+().\-\s]+$/D', $contact_number))) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Pakisuri ang contact number.']);
    exit;
}
if (mb_strlen($address, 'UTF-8') > 300 || mb_strlen($person_to_visit, 'UTF-8') > 150) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'May field na lumampas sa pinapayagang haba.']);
    exit;
}
if (!in_array($purpose, $allowed_purposes, true)) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Pumili ng valid na purpose of visit.']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare(
    "INSERT INTO tbl_visitor_logbook
        (full_name, contact_number, address, purpose, person_to_visit, ip_address)
     VALUES (?, ?, ?, ?, ?, ?)"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Hindi naitala ang pagbisita. Pakisubukan muli.']);
    exit;
}
$encrypted_name = aes_encrypt($full_name);
$encrypted_contact = $contact_number !== '' ? aes_encrypt($contact_number) : null;
$encrypted_address = $address !== '' ? aes_encrypt($address) : null;
$person_value = $person_to_visit !== '' ? $person_to_visit : null;
$stmt->bind_param('ssssss', $encrypted_name, $encrypted_contact, $encrypted_address, $purpose, $person_value, $ip_address);
$saved = $stmt->execute();
$stmt->close();

if (!$saved) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Hindi naitala ang pagbisita. Pakisubukan muli.']);
    exit;
}

echo json_encode(['success'=>true,'message'=>'Salamat! Naitala na ang inyong pagbisita.']);
