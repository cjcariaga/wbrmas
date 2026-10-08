<?php
require_once __DIR__ . '/config/database.php';

$conn = getDBConnection();

// Step 1: Set a known simple password
$password = 'admin123';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

$stmt = $conn->prepare("UPDATE tbl_users SET password_hash=?, failed_attempts=0, locked_until=NULL WHERE username='admin'");
$stmt->bind_param("s", $hash);
$stmt->execute();
$stmt->close();

// Step 2: Read back and verify
$row = $conn->query("SELECT * FROM tbl_users WHERE username='admin'")->fetch_assoc();
$verify = password_verify($password, $row['password_hash']);

// Step 3: Show everything
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Debug Login</title>
<style>
body{font-family:monospace;background:#1a1a2e;color:#eee;padding:30px;}
.box{background:#16213e;padding:24px;border-radius:10px;max-width:700px;margin:auto;}
h2{color:#00d2ff;}
.ok{color:#00ff88;font-weight:bold;}
.err{color:#ff4757;font-weight:bold;}
table{width:100%;border-collapse:collapse;margin:14px 0;}
td{padding:10px;border:1px solid #333;font-size:.9rem;}
td:first-child{color:#00d2ff;width:40%;}
.btn{display:inline-block;margin-top:16px;padding:12px 28px;background:#00d2ff;color:#000;border-radius:8px;text-decoration:none;font-weight:700;}
pre{background:#0f0f1a;padding:12px;border-radius:6px;overflow-x:auto;font-size:.8rem;color:#aaa;}
</style>
</head>
<body>
<div class="box">
<h2>🔍 Login Debug Tool</h2>

<table>
  <tr><td>Username in DB</td><td><?= htmlspecialchars($row['username']) ?></td></tr>
  <tr><td>Full name</td><td><?= htmlspecialchars($row['full_name']) ?></td></tr>
  <tr><td>Is Active</td><td><?= $row['is_active'] ? '<span class="ok">YES (1)</span>' : '<span class="err">NO (0)</span>' ?></td></tr>
  <tr><td>Failed attempts</td><td><?= (int)$row['failed_attempts'] ?></td></tr>
  <tr><td>Locked until</td><td><?= $row['locked_until'] ?? '<span class="ok">NULL (not locked)</span>' ?></td></tr>
  <tr><td>Role ID</td><td><?= (int)$row['role_id'] ?></td></tr>
  <tr><td>Hash length</td><td><?= strlen($row['password_hash']) ?> characters (should be 60)</td></tr>
  <tr><td>Hash starts with</td><td><?= htmlspecialchars(substr($row['password_hash'], 0, 7)) ?> (should be $2y$10)</td></tr>
  <tr><td>Password verify</td><td><?= $verify ? '<span class="ok">✅ PASS - password_verify() works</span>' : '<span class="err">❌ FAIL</span>' ?></td></tr>
</table>

<?php if ($verify): ?>
<p class="ok">✅ Everything is correct in the database!</p>
<p style="color:#ffd700;">Use these credentials:</p>
<table>
  <tr><td>Username</td><td><strong style="color:#00ff88;">admin</strong></td></tr>
  <tr><td>Password</td><td><strong style="color:#00ff88;">admin123</strong></td></tr>
</table>
<a href="/BRGYMS/" class="btn">→ Go Login Now</a>
<?php else: ?>
<p class="err">❌ password_verify() failed — something is wrong with PHP or the DB column type.</p>
<pre>Hash in DB: <?= htmlspecialchars($row['password_hash']) ?></pre>
<pre>Generated:  <?= htmlspecialchars($hash) ?></pre>
<?php endif; ?>

<hr style="border-color:#333;margin:24px 0;">
<p style="color:#666;font-size:.8rem;">⚠️ Delete debug_login.php after use</p>
</div>
</body>
</html>
