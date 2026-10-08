<?php
require_once __DIR__ . '/config/database.php';

$conn = getDBConnection();

$password  = 'Admin@1234';
$hash      = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

// Update hash and reset lockout
$stmt = $conn->prepare(
    "UPDATE tbl_users 
     SET password_hash = ?, failed_attempts = 0, locked_until = NULL 
     WHERE username = 'admin'"
);
$stmt->bind_param("s", $hash);
$stmt->execute();
$stmt->close();

// Verify by reading back from DB
$row = $conn->query("SELECT password_hash, failed_attempts, is_active FROM tbl_users WHERE username='admin'")->fetch_assoc();

$verify = password_verify($password, $row['password_hash']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Fix Login</title>
<style>
  body { font-family: Arial, sans-serif; background: #f0f4f8; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .box { background: #fff; padding: 36px 40px; border-radius: 14px; box-shadow: 0 6px 24px rgba(0,0,0,.12); max-width: 460px; width: 100%; }
  h2 { margin-bottom: 16px; }
  .ok  { color: #27ae60; }
  .err { color: #e74c3c; }
  table { width: 100%; border-collapse: collapse; margin: 16px 0; }
  td { padding: 8px 12px; border: 1px solid #ddd; font-size: .9rem; }
  td:first-child { font-weight: bold; background: #f8f9fa; width: 45%; }
  .btn { display: inline-block; margin-top: 16px; padding: 12px 28px;
         background: linear-gradient(135deg,#1a3a5c,#2980b9); color: #fff;
         border-radius: 8px; text-decoration: none; font-weight: 600; font-size: .95rem; }
  .warn { color: #e74c3c; font-size: .82rem; margin-top: 14px; }
</style>
</head>
<body>
<div class="box">

<?php if ($verify): ?>
  <h2 class="ok">✅ Login Fixed Successfully!</h2>
  <table>
    <tr><td>Username</td><td><strong>admin</strong></td></tr>
    <tr><td>Password</td><td><strong>Admin@1234</strong></td></tr>
    <tr><td>Hash valid</td><td class="ok">✅ YES</td></tr>
    <tr><td>Failed attempts</td><td><?= (int)$row['failed_attempts'] ?> (reset to 0)</td></tr>
    <tr><td>Account active</td><td><?= $row['is_active'] ? '✅ Active' : '❌ Inactive' ?></td></tr>
    <tr><td>Locked</td><td>No</td></tr>
  </table>
  <a href="/" class="btn">→ Go to Login Page</a>
  <p class="warn">⚠️ Delete fix_login.php after logging in!</p>

<?php else: ?>
  <h2 class="err">❌ Fix Failed</h2>
  <p>Hash stored in DB: <code><?= htmlspecialchars($row['password_hash']) ?></code></p>
  <p>This is unexpected. Please check database connection.</p>
<?php endif; ?>

</div>
</body>
</html>
