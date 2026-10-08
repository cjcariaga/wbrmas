<?php
require_once __DIR__ . '/config/security.php';
secure_session_start();

// Log the logout before destroying session
if (isset($_SESSION['user_id'])) {
    write_audit_log(
        $_SESSION['user_id'],
        'LOGOUT',
        $_SESSION['username'] ?? 'unknown',
        'User logged out'
    );
}

// Destroy session completely
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

// Redirect to login with logout flag
header('Location: /index.php?logged_out=1');
exit;
?>
