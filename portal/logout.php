<?php
require_once __DIR__ . '/../config/security.php';
secure_session_start();
if (!empty($_SESSION['resident_portal_account_id'])) {
    write_audit_log(null, 'RESIDENT_PORTAL_LOGOUT', 'portal_account:'.(int)$_SESSION['resident_portal_account_id'], 'Resident portal sign out');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: /BRGYMS/portal/login.php?signed_out=1');
exit;
?>
