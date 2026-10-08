<?php
require_once 'config/security.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !check_session_timeout()) {
    echo json_encode(['success'=>false,'notifications'=>[],'count'=>0]); exit;
}
if (($_SESSION['role_name'] ?? '') === 'Barangay Treasurer') {
    echo json_encode(['success'=>true,'notifications'=>[],'count'=>0]); exit;
}

$conn = getDBConnection();
$uid  = (int)$_SESSION['user_id'];
$is_admin = ($_SESSION['role_name'] ?? '') === 'System Administrator';

$notifications = [];

// ── Pending document requests ─────────────────────────────────────────────────
$uid_filter = $is_admin ? "" : "AND issued_by_user_id = $uid";
$pending = $conn->query("SELECT COUNT(*) FROM tbl_document_requests WHERE status='PENDING' $uid_filter");
$pending_count = $pending ? (int)$pending->fetch_row()[0] : 0;
if ($pending_count > 0) {
    $notifications[] = [
        'type'  => 'warning',
        'icon'  => 'fa-clock',
        'title' => "$pending_count Pending Document Request" . ($pending_count > 1 ? 's' : ''),
        'body'  => 'Awaiting approval or processing.',
        'link'  => '/BRGYMS/documents/index.php?status=PENDING'
    ];
}

// ── Active blotter cases ──────────────────────────────────────────────────────
$blotter = $conn->query("SELECT COUNT(*) FROM tbl_blotter WHERE resolution_status='Active'");
$blotter_count = $blotter ? (int)$blotter->fetch_row()[0] : 0;
if ($blotter_count > 0) {
    $notifications[] = [
        'type'  => 'danger',
        'icon'  => 'fa-book-open',
        'title' => "$blotter_count Active Blotter Case" . ($blotter_count > 1 ? 's' : ''),
        'body'  => 'Unresolved cases need attention.',
        'link'  => '/BRGYMS/blotter/index.php?status=Active'
    ];
}

// ── Under mediation cases ─────────────────────────────────────────────────────
$mediation = $conn->query("SELECT COUNT(*) FROM tbl_blotter WHERE resolution_status='Under Mediation'");
$med_count = $mediation ? (int)$mediation->fetch_row()[0] : 0;
if ($med_count > 0) {
    $notifications[] = [
        'type'  => 'info',
        'icon'  => 'fa-handshake',
        'title' => "$med_count Case" . ($med_count > 1 ? 's' : '') . " Under Mediation",
        'body'  => 'Hearing or mediation in progress.',
        'link'  => '/BRGYMS/blotter/index.php?status=Under+Mediation'
    ];
}

// ── Approved docs waiting to be printed ──────────────────────────────────────
$approved = $conn->query("SELECT COUNT(*) FROM tbl_document_requests WHERE status='APPROVED' $uid_filter");
$appr_count = $approved ? (int)$approved->fetch_row()[0] : 0;
if ($appr_count > 0) {
    $notifications[] = [
        'type'  => 'success',
        'icon'  => 'fa-print',
        'title' => "$appr_count Document" . ($appr_count > 1 ? 's' : '') . " Ready to Print",
        'body'  => 'Approved requests waiting to be issued.',
        'link'  => '/BRGYMS/documents/index.php?status=APPROVED'
    ];
}

if (empty($notifications)) {
    $notifications[] = [
        'type'  => 'success',
        'icon'  => 'fa-circle-check',
        'title' => 'All Clear!',
        'body'  => 'No pending items require your attention.',
        'link'  => null
    ];
}

echo json_encode([
    'success'       => true,
    'notifications' => $notifications,
    'count'         => max(0, count($notifications) - (empty($notifications) ? 0 : ($pending_count + $blotter_count + $med_count + $appr_count > 0 ? 0 : 1)))
]);
?>
