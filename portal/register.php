<?php
require_once __DIR__ . '/../config/security.php';
secure_session_start();
if (!empty($_SESSION['resident_portal_account_id']) && empty($_SESSION['user_id'])) {
    header('Location: /portal/index.php');
    exit;
}
if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resident Portal Registration – Barangay San Isidro</title>
<link rel="stylesheet" href="/assets/css/main.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body{background:#edf3f1}.portal-shell{min-height:100vh;display:grid;place-items:center;padding:24px}.portal-box{width:min(100%,620px);background:#fff;border:1px solid #d9e3df;border-radius:12px;padding:30px;box-shadow:0 12px 36px rgba(20,55,45,.1)}.portal-brand{display:flex;align-items:center;gap:14px;margin-bottom:22px}.portal-brand img{width:58px;height:58px;object-fit:contain}.portal-brand h1{font-size:1.2rem;margin:0;color:#1c4f43}.portal-brand p{margin:3px 0 0;color:#60736e;font-size:.82rem}.portal-box h2{font-size:1.1rem;margin:0 0 8px}.portal-intro{font-size:.85rem;color:#62746e;line-height:1.5;margin:0 0 18px}.portal-alert{display:none;margin:0 0 14px;padding:10px 12px;border-radius:6px;background:#fdecea;color:#942d24;font-size:.85rem}.portal-footer{font-size:.82rem;text-align:center;margin-top:18px;color:#657872}.portal-footer a{color:#1d6554;font-weight:600}
</style>
</head>
<body>
<main class="portal-shell">
  <section class="portal-box">
    <div class="portal-brand"><img src="/assets/img/brgy_seal.png" alt="Barangay seal"><div><h1>Barangay San Isidro</h1><p>Resident Services Portal</p></div></div>
    <h2>Link an existing resident record</h2>
    <p class="portal-intro">Enter the first name, last name, and birth date on your existing resident record. This creates a portal login only; it does not create or change a resident record.</p>
    <div class="portal-alert" id="portalError" role="alert"></div>
    <div class="portal-alert" id="portalSuccess" style="background:#e8f5ee;color:#245c42;" role="status"></div>
    <form id="portalRegisterForm">
      <input type="hidden" name="csrf_token" value="<?= sanitize_output($csrf) ?>">
      <div class="form-grid">
        <div class="form-group"><label for="firstName">First Name</label><input id="firstName" name="first_name" class="form-control" maxlength="100" required autocomplete="given-name"></div>
        <div class="form-group"><label for="lastName">Last Name</label><input id="lastName" name="last_name" class="form-control" maxlength="100" required autocomplete="family-name"></div>
        <div class="form-group"><label for="birthDate">Date of Birth</label><input id="birthDate" name="birth_date" type="date" class="form-control" required></div>
        <div class="form-group"><label for="username">Portal Username</label><input id="username" name="username" class="form-control" maxlength="50" pattern="[A-Za-z0-9_.]+" required autocomplete="username"></div>
        <div class="form-group"><label for="password">Password</label><input id="password" name="password" type="password" class="form-control" minlength="8" maxlength="100" required autocomplete="new-password"></div>
        <div class="form-group"><label for="confirmPassword">Confirm Password</label><input id="confirmPassword" name="confirm_password" type="password" class="form-control" minlength="8" maxlength="100" required autocomplete="new-password"></div>
      </div>
      <button class="btn btn-primary w-100 mt-3" type="submit"><i class="fas fa-user-plus"></i> Verify and Register</button>
    </form>
    <div class="portal-footer">Already registered? <a href="login.php">Sign in</a></div>
  </section>
</main>
<script>
document.getElementById('portalRegisterForm').addEventListener('submit', async event => {
  event.preventDefault();
  const errorBox = document.getElementById('portalError');
  const successBox = document.getElementById('portalSuccess');
  errorBox.style.display = 'none'; successBox.style.display = 'none';
  const form = event.currentTarget;
  if (form.elements.password.value !== form.elements.confirm_password.value) { errorBox.textContent='Passwords do not match.'; errorBox.style.display='block'; return; }
  try {
    const response = await fetch('api.php?action=register', {method:'POST', body:new FormData(form)});
    const result = await response.json();
    if (!result.success) { errorBox.textContent=result.message || 'Could not register.'; errorBox.style.display='block'; return; }
    successBox.textContent=result.message || 'Registration complete.';
    successBox.style.display='block';
    form.reset();
  } catch (error) {
    errorBox.textContent='Unable to contact the portal. Please try again.';
    errorBox.style.display='block';
  }
});
</script>
</body>
</html>
