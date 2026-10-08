<?php
require_once __DIR__ . '/../includes/auth_check.php';
header('Content-Type: application/json; charset=utf-8');

function financeResponse($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if (!in_array($_SESSION['role_name'] ?? '', ['System Administrator', 'Barangay Treasurer'], true)
    || !user_has_permission('view_financial_reports')) {
    http_response_code(403);
    financeResponse(false, 'Access denied.');
}
if (!user_has_permission('manage_financial_reports')) {
    http_response_code(403);
    financeResponse(false, 'You do not have permission to manage financial entries.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    financeResponse(false, 'Method not allowed.');
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    financeResponse(false, 'Invalid CSRF token.');
}

function financeText($value, $limit) {
    $value = trim(strip_tags((string)$value));
    return mb_strlen($value, 'UTF-8') <= $limit ? $value : null;
}
function financeDateValid($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $parts = explode('-', $date);
    return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}
function assessedDocumentFee($type_name, $purpose, $base_fee) {
    if (stripos($type_name, 'indigency') !== false || stripos($type_name, 'indigent') !== false) return 0.00;
    if (stripos($type_name, 'clearance') !== false) {
        if (stripos($purpose, 'student') !== false || stripos($purpose, 'academic') !== false || stripos($purpose, 'school') !== false) return 0.00;
        if (stripos($purpose, 'loan') !== false) return 100.00;
    }
    return max(0, (float)$base_fee);
}

$action = sanitize_input($_GET['action'] ?? '');
$conn = getDBConnection();
$user_id = (int)$_SESSION['user_id'];

if ($action === 'import_document_fees') {
    $rows = $conn->query(
        "SELECT dr.request_id, dr.request_code, dr.purpose, dr.requested_at, dr.processed_at,
                dt.type_name, dt.fee
         FROM tbl_document_requests dr
         JOIN tbl_document_types dt ON dt.type_id=dr.document_type_id
         LEFT JOIN tbl_financial_entries fe ON fe.source_request_id=dr.request_id
         WHERE dr.status IN ('PRINTED','STORED','RELEASED') AND fe.financial_entry_id IS NULL
         ORDER BY dr.processed_at, dr.requested_at, dr.request_id"
    );
    if (!$rows) financeResponse(false, 'Could not load issued document fees.');
    $insert = $conn->prepare(
        "INSERT IGNORE INTO tbl_financial_entries
         (entry_type, category, amount, entry_date, description, source_request_id, created_by, updated_by)
         VALUES ('INCOME', 'Document Issuance Fees (Assessed)', ?, ?, ?, ?, ?, ?)"
    );
    if (!$insert) financeResponse(false, 'Could not prepare fee import.');
    $imported = 0;
    while ($row = $rows->fetch_assoc()) {
        $amount = assessedDocumentFee($row['type_name'], (string)$row['purpose'], $row['fee']);
        if ($amount <= 0) continue;
        $event_date = $row['processed_at'] ?: $row['requested_at'];
        $entry_date = date('Y-m-d', strtotime($event_date));
        $description = 'Assessed fee for '.$row['request_code'].' — '.$row['type_name'].'; collection not independently verified.';
        $request_id = (int)$row['request_id'];
        $insert->bind_param('dssiii', $amount, $entry_date, $description, $request_id, $user_id, $user_id);
        if ($insert->execute() && $insert->affected_rows > 0) {
            $entry_id = $conn->insert_id;
            write_audit_log($user_id, 'IMPORT_ASSESSED_DOCUMENT_FEE', "finance_entry:$entry_id", "Request:{$row['request_code']} Amount:".number_format($amount, 2));
            $imported++;
        }
    }
    $insert->close();
    write_audit_log($user_id, 'IMPORT_DOCUMENT_FEES', 'financial_ledger', "Imported assessed fee entries:$imported");
    financeResponse(true, "Imported $imported assessed document fee entr".($imported === 1 ? 'y' : 'ies').'.', ['imported' => $imported]);
}

if ($action === 'create' || $action === 'update') {
    $entry_id = (int)($_POST['financial_entry_id'] ?? 0);
    $entry_type = strtoupper(sanitize_input($_POST['entry_type'] ?? ''));
    $category = financeText($_POST['category'] ?? '', 100);
    $amount_raw = trim((string)($_POST['amount'] ?? ''));
    $entry_date = trim((string)($_POST['entry_date'] ?? ''));
    $description = financeText($_POST['description'] ?? '', 2000);

    if (($action === 'update' && !$entry_id)
        || !in_array($entry_type, ['INCOME', 'EXPENSE'], true)
        || $category === null || $category === ''
        || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount_raw)
        || !is_numeric($amount_raw) || (float)$amount_raw <= 0 || (float)$amount_raw > 9999999999.99
        || !financeDateValid($entry_date)
        || $description === null || $description === '') {
        financeResponse(false, 'Enter a valid type, category, positive amount (up to two decimals), date, and description.');
    }
    $amount = round((float)$amount_raw, 2);

    if ($action === 'create') {
        $stmt = $conn->prepare("INSERT INTO tbl_financial_entries (entry_type,category,amount,entry_date,description,created_by,updated_by) VALUES (?,?,?,?,?,?,?)");
        if (!$stmt) financeResponse(false, 'Could not save financial entry.');
        $stmt->bind_param('ssdssii', $entry_type, $category, $amount, $entry_date, $description, $user_id, $user_id);
        if (!$stmt->execute()) {
            $stmt->close();
            financeResponse(false, 'Could not save financial entry.');
        }
        $entry_id = $conn->insert_id;
        $stmt->close();
        write_audit_log($user_id, 'CREATE_FINANCIAL_ENTRY', "finance_entry:$entry_id", "$entry_type Category:$category Amount:".number_format($amount, 2)." Date:$entry_date");
        financeResponse(true, 'Financial entry added.', ['financial_entry_id' => $entry_id]);
    }

    $check = $conn->prepare("SELECT financial_entry_id, source_request_id FROM tbl_financial_entries WHERE financial_entry_id=?");
    if (!$check) financeResponse(false, 'Could not verify financial entry.');
    $check->bind_param('i', $entry_id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$existing) financeResponse(false, 'Financial entry not found.');
    $stmt = $conn->prepare("UPDATE tbl_financial_entries SET entry_type=?,category=?,amount=?,entry_date=?,description=?,updated_by=? WHERE financial_entry_id=?");
    if (!$stmt) financeResponse(false, 'Could not update financial entry.');
    $stmt->bind_param('ssdssii', $entry_type, $category, $amount, $entry_date, $description, $user_id, $entry_id);
    if (!$stmt->execute()) {
        $stmt->close();
        financeResponse(false, 'Could not update financial entry.');
    }
    $stmt->close();
    write_audit_log($user_id, 'UPDATE_FINANCIAL_ENTRY', "finance_entry:$entry_id", "$entry_type Category:$category Amount:".number_format($amount, 2)." Date:$entry_date");
    financeResponse(true, 'Financial entry updated.', ['financial_entry_id' => $entry_id]);
}

if ($action === 'delete') {
    $entry_id = (int)($_POST['financial_entry_id'] ?? 0);
    if (!$entry_id) financeResponse(false, 'Invalid financial entry.');
    $check = $conn->prepare("SELECT entry_type, category, amount FROM tbl_financial_entries WHERE financial_entry_id=?");
    $check->bind_param('i', $entry_id);
    $check->execute();
    $entry = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$entry) financeResponse(false, 'Financial entry not found.');
    $delete = $conn->prepare("DELETE FROM tbl_financial_entries WHERE financial_entry_id=?");
    if (!$delete) financeResponse(false, 'Could not delete financial entry.');
    $delete->bind_param('i', $entry_id);
    if (!$delete->execute()) {
        $delete->close();
        financeResponse(false, 'Could not delete financial entry.');
    }
    $delete->close();
    write_audit_log($user_id, 'DELETE_FINANCIAL_ENTRY', "finance_entry:$entry_id", "{$entry['entry_type']} Category:{$entry['category']} Amount:".number_format((float)$entry['amount'], 2));
    financeResponse(true, 'Financial entry deleted.');
}

http_response_code(400);
financeResponse(false, 'Unknown financial action.');
