<?php
require_once __DIR__ . '/config/database.php';

$password = 'Admin@1234';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

$conn = getDBConnection();

// Reset failed attempts and update password
$conn->query("UPDATE tbl_users SET failed_attempts=0, locked_until=NULL, password_hash='" . $conn->real_escape_string($hash) . "' WHERE username='admin'");

// Verify it works
$result = $conn->query("SELECT password_hash FROM tbl_users WHERE username='admin'")->fetch_assoc();
$ok = password_verify($password, $result['password_hash']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>WBRMAS Setup</title>
<style>
body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f4f8;}
.box{background:#fff;padding:40px;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.1);max-width:440px;width:100%;text-align:center;}
.ok{color:#27ae60;font-size:3rem;}
.err{color:#e74c3c;font-size:3rem;}
h2{margin:12px 0;}
.creds{background:#f8f9fa;border-radius:8px;padding:16px;margin:16px 0;text-align:left;}
.creds p{margin:6px 0;font-size:1rem;}
.creds strong{color:#1a3a5c;}
a.btn{display:inline-block;margin-top:16px;padding:12px 28px;background:linear-gradient(135deg,#1a3a5c,#2980b9);color:#fff;border-radius:8px;text-decoration:none;font-weight:600;}
.warn{color:#e74c3c;font-size:.85rem;margin-top:12px;}
</style>
</head>
<body>
<div class="box">
<?php if ($ok): ?>
  <div class="ok">✅</div>
  <h2>Password Fixed!</h2>
  <div class="creds">
    <p>👤 Username: <strong>admin</strong></p>
    <p>🔑 Password: <strong>Admin@1234</strong></p>
  </div>
  <a href="/" class="btn">Go to Login →</a>
  <p class="warn">⚠️ Delete this file after logging in!</p>
<?php else: ?>
  <div class="err">❌</div>
  <h2>Something went wrong</h2>
  <p>Hash verification failed. Check database connection.</p>
<?php endif; ?>
</div>
</body>
</html>
