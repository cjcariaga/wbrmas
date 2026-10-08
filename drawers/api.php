<?php
require_once __DIR__ . '/../includes/auth_check.php';
header('Content-Type: application/json; charset=utf-8');

function drawerApiResponse($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function drawerApiText($value, $max_length) {
    $value = trim(strip_tags((string)$value));
    if (mb_strlen($value, 'UTF-8') > $max_length) return null;
    return $value;
}

if (!user_has_permission('view_drawer_index')) {
    http_response_code(403);
    drawerApiResponse(false, 'Access denied.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    drawerApiResponse(false, 'Method not allowed.');
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    drawerApiResponse(false, 'Invalid CSRF token.');
}

$action = sanitize_input($_GET['action'] ?? '');
$conn = getDBConnection();
$user_id = (int)$_SESSION['user_id'];

function requireDrawerManager() {
    if (!user_has_permission('manage_drawer_index')) {
        http_response_code(403);
        drawerApiResponse(false, 'You do not have permission to manage drawer index entries.');
    }
}

function drawerExists($conn, $drawer_id) {
    $stmt = $conn->prepare("SELECT drawer_id FROM tbl_storage_drawers WHERE drawer_id=?");
    if (!$stmt) return false;
    $stmt->bind_param('i', $drawer_id);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function validateEntryLinks($conn, $resident_id, $request_id) {
    if ($resident_id && $request_id) return 'Choose at most one linked record.';
    if ($resident_id) {
        $stmt = $conn->prepare("SELECT resident_id FROM tbl_residents WHERE resident_id=?");
        if (!$stmt) return 'Could not validate resident link.';
        $stmt->bind_param('i', $resident_id);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) return 'Linked resident record was not found.';
    }
    if ($request_id) {
        $stmt = $conn->prepare("SELECT request_id FROM tbl_document_requests WHERE request_id=?");
        if (!$stmt) return 'Could not validate document link.';
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) return 'Linked document request was not found.';
    }
    return '';
}

if ($action === 'create_drawer') {
    requireDrawerManager();
    $drawer_name = drawerApiText($_POST['drawer_name'] ?? '', 100);
    if ($drawer_name === null || $drawer_name === '') drawerApiResponse(false, 'Drawer name is required and must be 100 characters or fewer.');
    $stmt = $conn->prepare("INSERT INTO tbl_storage_drawers (drawer_name, created_by) VALUES (?, ?)");
    if (!$stmt) drawerApiResponse(false, 'Could not create drawer.');
    $stmt->bind_param('si', $drawer_name, $user_id);
    if (!$stmt->execute()) {
        $duplicate = $stmt->errno === 1062;
        $stmt->close();
        drawerApiResponse(false, $duplicate ? 'A drawer with that name already exists.' : 'Could not create drawer.');
    }
    $drawer_id = $conn->insert_id;
    $stmt->close();
    write_audit_log($user_id, 'CREATE_STORAGE_DRAWER', "drawer:$drawer_id", "Name:$drawer_name");
    drawerApiResponse(true, 'Drawer created.', ['drawer_id' => $drawer_id]);
}

if ($action === 'rename_drawer') {
    requireDrawerManager();
    $drawer_id = (int)($_POST['drawer_id'] ?? 0);
    $drawer_name = drawerApiText($_POST['drawer_name'] ?? '', 100);
    if (!$drawer_id || $drawer_name === null || $drawer_name === '') drawerApiResponse(false, 'Drawer name is required and must be 100 characters or fewer.');
    $stmt = $conn->prepare("UPDATE tbl_storage_drawers SET drawer_name=? WHERE drawer_id=?");
    if (!$stmt) drawerApiResponse(false, 'Could not rename drawer.');
    $stmt->bind_param('si', $drawer_name, $drawer_id);
    if (!$stmt->execute()) {
        $duplicate = $stmt->errno === 1062;
        $stmt->close();
        drawerApiResponse(false, $duplicate ? 'A drawer with that name already exists.' : 'Could not rename drawer.');
    }
    $changed = $stmt->affected_rows;
    $stmt->close();
    if (!$changed && !drawerExists($conn, $drawer_id)) drawerApiResponse(false, 'Drawer not found.');
    write_audit_log($user_id, 'RENAME_STORAGE_DRAWER', "drawer:$drawer_id", "Name:$drawer_name");
    drawerApiResponse(true, 'Drawer renamed.');
}

if ($action === 'create_entry' || $action === 'update_entry') {
    requireDrawerManager();
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    $drawer_id = (int)($_POST['drawer_id'] ?? 0);
    $entry_label = drawerApiText($_POST['entry_label'] ?? '', 200);
    $entry_notes = drawerApiText($_POST['entry_notes'] ?? '', 2000);
    $resident_id = (int)($_POST['resident_id'] ?? 0);
    $request_id = (int)($_POST['request_id'] ?? 0);
    if (($action === 'update_entry' && !$entry_id) || !$drawer_id || $entry_label === null || $entry_label === '' || $entry_notes === null) {
        drawerApiResponse(false, 'Entry label is required; label and notes must be within their length limits.');
    }
    if ($action === 'update_entry') {
        $existing = $conn->prepare(
            "SELECT dr.status FROM tbl_storage_entries e
             LEFT JOIN tbl_document_requests dr ON dr.request_id=e.request_id
             WHERE e.entry_id=?"
        );
        $existing->bind_param('i', $entry_id);
        $existing->execute();
        $existing_status = $existing->get_result()->fetch_assoc()['status'] ?? '';
        $existing->close();
        if (in_array($existing_status, ['STORED','RELEASED'], true)) {
            drawerApiResponse(false, 'Stored or claimed document entries are managed by the issuance workflow.');
        }
    }
    if (!drawerExists($conn, $drawer_id)) drawerApiResponse(false, 'Drawer not found.');
    $link_error = validateEntryLinks($conn, $resident_id, $request_id);
    if ($link_error !== '') drawerApiResponse(false, $link_error);
    if ($request_id) {
        $status_check = $conn->prepare("SELECT status FROM tbl_document_requests WHERE request_id=?");
        $status_check->bind_param('i', $request_id);
        $status_check->execute();
        $linked_status = $status_check->get_result()->fetch_assoc()['status'] ?? '';
        $status_check->close();
        if (in_array($linked_status, ['STORED','RELEASED'], true)) {
            drawerApiResponse(false, 'Stored or claimed document entries are managed by the issuance workflow.');
        }
    }
    $resident_value = $resident_id ?: null;
    $request_value = $request_id ?: null;
    $notes_value = $entry_notes !== '' ? $entry_notes : null;

    if ($action === 'create_entry') {
        $stmt = $conn->prepare("INSERT INTO tbl_storage_entries (drawer_id, entry_label, entry_notes, resident_id, request_id, created_by, updated_by) VALUES (?,?,?,?,?,?,?)");
        if (!$stmt) drawerApiResponse(false, 'Could not create index entry.');
        $stmt->bind_param('issiiii', $drawer_id, $entry_label, $notes_value, $resident_value, $request_value, $user_id, $user_id);
        if (!$stmt->execute()) {
            $stmt->close();
            drawerApiResponse(false, 'Could not create index entry.');
        }
        $entry_id = $conn->insert_id;
        $stmt->close();
        write_audit_log($user_id, 'CREATE_STORAGE_ENTRY', "entry:$entry_id", "Drawer:$drawer_id Label:$entry_label");
        drawerApiResponse(true, 'Index entry created.', ['entry_id' => $entry_id]);
    }

    $stmt = $conn->prepare("UPDATE tbl_storage_entries SET drawer_id=?, entry_label=?, entry_notes=?, resident_id=?, request_id=?, updated_by=? WHERE entry_id=?");
    if (!$stmt) drawerApiResponse(false, 'Could not update index entry.');
    $stmt->bind_param('issiiii', $drawer_id, $entry_label, $notes_value, $resident_value, $request_value, $user_id, $entry_id);
    if (!$stmt->execute()) {
        $stmt->close();
        drawerApiResponse(false, 'Could not update index entry.');
    }
    $changed = $stmt->affected_rows;
    $stmt->close();
    if (!$changed) {
        $check = $conn->prepare("SELECT entry_id FROM tbl_storage_entries WHERE entry_id=?");
        $check->bind_param('i', $entry_id);
        $check->execute();
        $exists = (bool)$check->get_result()->fetch_assoc();
        $check->close();
        if (!$exists) drawerApiResponse(false, 'Index entry not found.');
    }
    write_audit_log($user_id, 'UPDATE_STORAGE_ENTRY', "entry:$entry_id", "Drawer:$drawer_id Label:$entry_label");
    drawerApiResponse(true, 'Index entry updated.');
}

if ($action === 'delete_entry') {
    requireDrawerManager();
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    if (!$entry_id) drawerApiResponse(false, 'Invalid index entry.');
    $stmt = $conn->prepare(
        "SELECT e.drawer_id, e.entry_label, dr.status AS request_status
         FROM tbl_storage_entries e
         LEFT JOIN tbl_document_requests dr ON dr.request_id=e.request_id
         WHERE e.entry_id=?"
    );
    if (!$stmt) drawerApiResponse(false, 'Could not find index entry.');
    $stmt->bind_param('i', $entry_id);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$entry) drawerApiResponse(false, 'Index entry not found.');
    if (in_array($entry['request_status'] ?? '', ['STORED','RELEASED'], true)) {
        drawerApiResponse(false, 'Stored or claimed document history cannot be removed.');
    }
    $delete = $conn->prepare("DELETE FROM tbl_storage_entries WHERE entry_id=?");
    if (!$delete) drawerApiResponse(false, 'Could not remove index entry.');
    $delete->bind_param('i', $entry_id);
    if (!$delete->execute()) {
        $delete->close();
        drawerApiResponse(false, 'Could not remove index entry.');
    }
    $delete->close();
    write_audit_log($user_id, 'DELETE_STORAGE_ENTRY', "entry:$entry_id", "Drawer:{$entry['drawer_id']} Label:{$entry['entry_label']}");
    drawerApiResponse(true, 'Index entry removed.');
}

http_response_code(400);
drawerApiResponse(false, 'Unknown drawer index action.');
