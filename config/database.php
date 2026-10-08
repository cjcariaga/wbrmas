<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // ← PUT YOUR MYSQL PASSWORD HERE if you have one
define('DB_NAME', 'wbrmas_db');

// Security Keys
define('AES_SECRET_KEY', 'WBRMAS@AES256Key!2024#SecureBarangay');
define('HMAC_SECRET_KEY', 'WBRMAS@HMAC256Key!2024#AuditIntegrity');
define('FINGERPRINT_BRIDGE_SECRET', 'WBRMAS@ZK9500Bridge!2026#ChangeThisSecret');
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 600); // 10 minutes
define('RATE_LIMIT_WINDOW', 60); // 1 minute
define('RATE_LIMIT_MAX', 10);

function getDBConnection() {
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_OFF); // Handle errors manually
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_errno) {
            $err = $conn->connect_error;
            $code = $conn->connect_errno;
            // Show friendly setup page instead of fatal error
            http_response_code(500);
            echo '<!DOCTYPE html><html><head>
            <meta charset="UTF-8">
            <title>WBRMAS – Setup Required</title>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap">
            <style>
              *{box-sizing:border-box;margin:0;padding:0;}
              body{font-family:Inter,sans-serif;background:#f0f4f8;display:flex;align-items:center;justify-content:center;min-height:100vh;}
              .box{background:#fff;border-radius:16px;padding:40px;max-width:540px;width:100%;box-shadow:0 8px 30px rgba(0,0,0,.1);}
              h2{color:#c0392b;margin-bottom:12px;display:flex;align-items:center;gap:10px;}
              p{color:#555;line-height:1.7;margin-bottom:10px;}
              code{background:#f1f3f5;padding:3px 8px;border-radius:5px;font-size:.9rem;color:#c0392b;}
              ol{padding-left:20px;color:#444;line-height:2;}
              .tip{background:#e8f4fd;border-left:4px solid #2980b9;padding:12px 16px;border-radius:8px;font-size:.88rem;color:#1a6fa8;margin-top:16px;}
            </style></head><body>
            <div class="box">
              <h2>⚠️ Database Connection Error</h2>
              <p><strong>Error:</strong> <code>' . htmlspecialchars($err) . '</code></p>
              <p>The system could not connect to MySQL. Please follow these steps:</p>
              <ol>
                <li>Open <strong>XAMPP Control Panel</strong></li>
                <li>Make sure <strong>MySQL</strong> is <strong>Running</strong> (green)</li>
                <li>Go to <a href="http://localhost/phpmyadmin" target="_blank">phpMyAdmin</a></li>
                <li>Import the file: <code>C:\xampp\htdocs\BRGYMS\database\wbrmas_db.sql</code></li>
                <li>If MySQL has a password, open <code>config/database.php</code> and set <code>DB_PASS</code></li>
              </ol>
              <div class="tip">💡 Default XAMPP MySQL has <strong>no password</strong>. If you set one via phpMyAdmin, update DB_PASS in config/database.php</div>
            </div></body></html>';
            exit;
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
?>
