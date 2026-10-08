<?php
require_once __DIR__ . '/../config/security.php';
secure_session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /BRGYMS/index.php');
    exit;
}
if (!check_session_timeout()) {
    header('Location: /BRGYMS/index.php?timeout=1');
    exit;
}

// IDOR / Role check helper
function require_role($role_name) {
    if ($_SESSION['role_name'] !== $role_name) {
        http_response_code(403);
        die('<div style="font-family:Inter,sans-serif;text-align:center;padding:60px;color:#c0392b;">
            <h2>403 – Access Denied</h2><p>You do not have permission to access this page.</p>
            <a href="/BRGYMS/dashboard.php" style="color:#2980b9;">Return to Dashboard</a></div>');
    }
}

function is_admin() {
    return $_SESSION['role_name'] === 'System Administrator';
}

function current_user_id() {
    return (int)$_SESSION['user_id'];
}

function user_has_permission($permission) {
    if (is_admin()) return true;
    $role_name = $_SESSION['role_name'] ?? '';
    $financial_permissions = ['view_financial_reports', 'manage_financial_reports'];
    if (in_array($permission, $financial_permissions, true)) {
        return $role_name === 'Barangay Treasurer' && in_array($permission, $financial_permissions, true);
    }
    if ($role_name === 'Barangay Treasurer') {
        return false;
    }
    if (!isset($_SESSION['permissions'])) {
        $_SESSION['permissions'] = [];
        $conn = getDBConnection();
        $uid = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT perm_key FROM tbl_user_permissions WHERE user_id=? AND granted=1");
        if ($stmt) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) $_SESSION['permissions'][] = $row['perm_key'];
            $stmt->close();
        }
    }
    return in_array($permission, $_SESSION['permissions'], true);
}

// Permission map — add more as needed
function require_permission($permission) {
    if (!user_has_permission($permission)) {
        http_response_code(403);
        die('<div style="font-family:Inter,sans-serif;text-align:center;padding:60px;color:#c0392b;">
            <h2>403 – Access Denied</h2><p>You do not have permission to access this page.</p>
            <a href="/BRGYMS/dashboard.php" style="color:#2980b9;">Return to Dashboard</a></div>');
    }
}

// Boolean permission check — use in views with if(can('...'))
function can($permission) {
    return user_has_permission($permission);
}
?>
