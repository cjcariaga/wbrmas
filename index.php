<?php
require_once 'config/security.php';
secure_session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
$csrf  = generate_csrf_token();
$error = '';
$show_step2 = false;
$pending_name = $_SESSION['pending_user'] ?? '';

function finish_staff_login($user, $auth_method) {
  $conn = getDBConnection();
  $upd = $conn->prepare("UPDATE tbl_users SET failed_attempts=0, locked_until=NULL, last_login=NOW() WHERE user_id=?");
  $upd->bind_param('i', $user['user_id']);
  $upd->execute();
  $upd->close();
  session_regenerate_id(true);
  $_SESSION = [];
  $_SESSION['user_id'] = (int)$user['user_id'];
  $_SESSION['username'] = $user['username'];
  $_SESSION['full_name'] = $user['full_name'];
  $_SESSION['role_id'] = (int)$user['role_id'];
  $_SESSION['role_name'] = $user['role_name'];
  $_SESSION['last_activity'] = time();
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  write_audit_log($user['user_id'], 'LOGIN_SUCCESS', $user['username'], 'Authenticated via '.$auth_method);
  header('Location: dashboard.php');
  exit;
}

// ── Step 2: Fingerprint verification POST ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fingerprint_ok') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
  elseif (empty($_SESSION['fingerprint_verified'])) { $error = 'Fingerprint verification is required.'; }
    elseif (empty($_SESSION['pending_uid'])) { $error = 'Session expired. Please sign in again.'; }
    else {
        $uid = (int)$_SESSION['pending_uid'];
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "SELECT u.user_id, u.username, u.full_name, u.role_id, r.role_name
             FROM tbl_users u JOIN tbl_roles r ON u.role_id=r.role_id
             WHERE u.user_id=? AND u.is_active=1"
        );
        $stmt->bind_param("i", $uid); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$user) { $error = 'Session error. Please sign in again.'; }
        else finish_staff_login($user, 'fingerprint');
    }
    $csrf = generate_csrf_token();
}

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!check_rate_limit('login_' . $ip)) {
            $error = 'Too many attempts. Please wait a moment.';
        } else {
            $username = sanitize_input($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            if (empty($username) || empty($password)) {
                $error = 'Please enter your username and password.';
            } else {
                $conn = getDBConnection();
                $stmt = $conn->prepare(
                    "SELECT u.user_id, u.username, u.password_hash, u.full_name, u.role_id,
                            u.is_active, u.failed_attempts, u.locked_until, r.role_name
                     FROM tbl_users u JOIN tbl_roles r ON u.role_id=r.role_id
                     WHERE u.username=?"
                );
                $stmt->bind_param("s", $username); $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc(); $stmt->close();

                if (!$user || !$user['is_active']) {
                    $error = 'Invalid credentials.';
                    write_audit_log(null, 'LOGIN_FAILED', $username, 'User not found or inactive');
                } elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                    $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
                    $error = "Account locked. Try again in $remaining minute(s).";
                } elseif (!password_verify($password, $user['password_hash'])) {
                    $attempts = $user['failed_attempts'] + 1;
                    $lock_sql  = $attempts >= MAX_LOGIN_ATTEMPTS
                        ? ", locked_until=DATE_ADD(NOW(), INTERVAL ".LOCKOUT_TIME." SECOND)"
                        : "";
                    $upd = $conn->prepare("UPDATE tbl_users SET failed_attempts=? $lock_sql WHERE user_id=?");
                    $upd->bind_param("ii", $attempts, $user['user_id']); $upd->execute(); $upd->close();
                    $error = $attempts >= MAX_LOGIN_ATTEMPTS
                        ? 'Account locked after '.MAX_LOGIN_ATTEMPTS.' failed attempts.'
                        : 'Invalid credentials. Attempt '.$attempts.'/'.MAX_LOGIN_ATTEMPTS.'.';
                    write_audit_log($user['user_id'], 'LOGIN_FAILED', $username, "Attempt $attempts");
                } else {
                    finish_staff_login($user, 'username and password');
                }
            }
        }
    }
    $csrf = generate_csrf_token();
}

// Clear pending on request
if (isset($_GET['clear_pending'])) {
  unset($_SESSION['pending_uid'], $_SESSION['pending_user'], $_SESSION['pending_started'], $_SESSION['pending_login_method'], $_SESSION['fingerprint_verified'], $_SESSION['fingerprint_challenge'], $_SESSION['biometric_ticket'], $_SESSION['biometric_challenge']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WBRMAS – Barangay San Isidro</title>
<link rel="stylesheet" href="assets/css/login.css?v=<?= filemtime(__DIR__ . '/assets/css/login.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
  integrity="sha512-Avb2QiuDEEvB4bZJYdft2mNjVShBftLdPG8FJ0V7irTLQ8Uo0qcPxh4Plq7G5tGm0rU+1SPhVotteLpBERwTkw=="
      crossorigin="anonymous" referrerpolicy="no-referrer">
<style>
/* ── Fingerprint Step 2 ─────────────────────────────────────────── */
.step-wrapper.hidden { display:none; }

.fp-ring-wrap {
  display:flex; align-items:center; justify-content:center;
  margin:28px auto 20px; position:relative; width:130px; height:130px;
}
.fp-ring {
  position:absolute; inset:0; border-radius:50%;
  border:3px solid #c5d8ee;
}
.fp-ring.pulse { animation:fpPulse 1.4s ease-in-out infinite; }
@keyframes fpPulse {
  0%,100% { transform:scale(1);    opacity:1; }
  50%     { transform:scale(1.18); opacity:.4; }
}
.fp-ripple {
  position:absolute; inset:-14px; border-radius:50%;
  border:2.5px solid #2980b9; opacity:0;
  animation:fpRipple 1.6s ease-out infinite;
}
.fp-ripple:nth-child(2) { animation-delay:.5s; }
.fp-ripple:nth-child(3) { animation-delay:1s; }
@keyframes fpRipple {
  0%   { transform:scale(.8);   opacity:.6; }
  100% { transform:scale(1.35); opacity:0; }
}
.fp-icon-circle {
  width:100px; height:100px; border-radius:50%;
  background:linear-gradient(135deg,#e8f4fd,#d0e8f8);
  border:2.5px solid #b0d0ee;
  display:flex; align-items:center; justify-content:center;
  z-index:2; position:relative; transition:background .3s,border-color .3s;
}
.fp-icon-circle.success { background:linear-gradient(135deg,#eafaf1,#c8f0d8); border-color:#27ae60; }
.fp-icon-circle.failure { background:linear-gradient(135deg,#fdedec,#f8c8c4); border-color:#e74c3c; }
.fp-icon { font-size:2.6rem; color:#2980b9; transition:color .3s; }
.fp-icon.success { color:#27ae60; }
.fp-icon.failure { color:#e74c3c; }

.fp-status-text {
  text-align:center; font-size:.88rem; color:#555;
  min-height:22px; margin-bottom:6px; font-weight:500;
}
.fp-status-text.success { color:#27ae60; font-weight:700; }
.fp-status-text.failure { color:#e74c3c; font-weight:700; }
.fp-sub-text {
  text-align:center; font-size:.78rem; color:#999;
  margin-bottom:20px; min-height:18px;
}
.step2-header { text-align:center; margin-bottom:4px; }
.step2-header h2 { font-size:1.2rem; font-weight:700; color:#1a3a5c; margin-bottom:4px; }
.step2-header .greeting { font-size:.82rem; color:#888; }
.back-link {
  display:block; text-align:center; font-size:.78rem;
  color:#aaa; margin-top:6px; cursor:pointer; text-decoration:none;
}
.back-link:hover { color:#2980b9; }
</style>
</head>
<body>
<div class="staff-site">
  <header class="staff-nav">
    <a class="staff-brand" href="#staffHome"><img src="assets/img/brgy_seal.png" alt=""><span><strong>Barangay San Isidro</strong><small>Staff Management Portal</small></span></a>
    <nav class="staff-nav-links" aria-label="Main navigation">
      <a href="#staffHome">Home</a><a href="#staffAbout">About</a><a href="#staffServices">Services</a>
      <a class="staff-nav-login" href="#staff-login"><i class="fas fa-right-to-bracket" aria-hidden="true"></i> Staff Login</a>
    </nav>
  </header>
  <main>
    <section class="staff-hero" id="staffHome" aria-labelledby="staffHeroTitle">
      <img class="staff-hero-scene" src="assets/img/header.png" alt="" aria-hidden="true">
      <div class="staff-hero-shade" aria-hidden="true"></div>
      <div class="staff-hero-inner">
        <div class="staff-hero-copy">
          <div class="staff-eyebrow">Official Barangay Portal</div>
          <h1 id="staffHeroTitle">Barangay<br>San Isidro</h1>
          <p class="staff-hero-location">City of Ilagan, Isabela · Staff and Administration</p>
          <p class="staff-hero-description">A secure workspace for barangay personnel to manage records and deliver responsive public service.</p>
          <div class="staff-hero-actions"><a class="staff-action-primary" href="#staff-login">Staff sign in <i class="fas fa-arrow-right" aria-hidden="true"></i></a><a class="staff-action-secondary" href="/BRGYMS/portal/login.php">Resident portal</a></div>
        </div>
        <div class="staff-hero-facts" id="staffServices">
          <div class="staff-hero-fact"><div class="staff-fact-label"><i class="far fa-building" aria-hidden="true"></i> Barangay</div><div class="staff-fact-value">San Isidro</div></div>
          <div class="staff-hero-fact"><div class="staff-fact-label"><i class="fas fa-location-dot" aria-hidden="true"></i> Location</div><div class="staff-fact-value">City of Ilagan, Isabela</div></div>
          <div class="staff-hero-fact"><div class="staff-fact-label"><i class="fas fa-shield-halved" aria-hidden="true"></i> Staff access</div><div class="staff-fact-value">WBRMAS · Authorized personnel</div></div>
        </div>
      </div>
    </section>

    <section class="staff-access" id="staff-login">
      <div class="staff-access-inner">
        <div class="staff-access-copy" id="staffAbout">
          <div class="staff-section-kicker">Staff access</div>
          <h2>Welcome to your<br>barangay workspace.</h2>
          <p>Sign in to continue to the Web-Based Records Management System. Choose username and password or use your enrolled ZKTeco fingerprint.</p>
          <div class="staff-access-note"><i class="fas fa-lock" aria-hidden="true"></i><span>For authorized barangay personnel only.</span></div>
        </div>
        <div class="login-bg staff-login-shell">
          <div class="login-card staff-login-card">
            <div class="staff-card-heading"><span class="staff-card-icon"><i class="fas fa-building-user" aria-hidden="true"></i></span><div><h2>Staff sign in</h2><p>Select your preferred sign-in method.</p></div></div>

    <!-- ── STEP 1: Username + Password ──────────────────────────── -->
    <div class="method-tabs" role="tablist" aria-label="Choose a staff sign-in method">
      <button type="button" class="method-tab active" id="passwordMethodTab" role="tab" aria-selected="true" aria-controls="step1" onclick="showLoginMethod('password')"><i class="fas fa-user-lock" aria-hidden="true"></i> Username &amp; Password</button>
      <button type="button" class="method-tab" id="fingerprintMethodTab" role="tab" aria-selected="false" aria-controls="step2" onclick="showLoginMethod('fingerprint')"><i class="fas fa-fingerprint" aria-hidden="true"></i> Fingerprint</button>
    </div>

    <div class="step-wrapper" id="step1" role="tabpanel" aria-labelledby="passwordMethodTab">
      <?php if ($error): ?>
      <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= sanitize_output($error) ?></div>
      <?php endif; ?>
      <form method="POST" id="loginForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="login">
        <div class="form-group">
          <label><i class="fas fa-user"></i> Username</label>
          <input type="text" name="username" id="username" placeholder="Enter username"
                 autocomplete="username" maxlength="50" required>
        </div>
        <div class="form-group">
          <label><i class="fas fa-lock"></i> Password</label>
          <div class="input-icon-right">
            <input type="password" name="password" id="password" placeholder="Enter password"
                   autocomplete="current-password" maxlength="100" required>
            <span class="toggle-pw" onclick="togglePw()"><i class="fas fa-eye" id="eyeIcon"></i></span>
          </div>
        </div>
        <button type="submit" class="btn-login" id="signInBtn">
          <i class="fas fa-right-to-bracket"></i> Login
        </button>
      </form>
    </div>

    <!-- ── STEP 2: Fingerprint MFA ──────────────────────────────── -->
    <div class="step-wrapper hidden" id="step2" role="tabpanel" aria-labelledby="fingerprintMethodTab">
      <div class="step2-header">
        <h2><i class="fas fa-fingerprint" style="color:#2980b9;margin-right:6px;"></i>Fingerprint Verification</h2>
        <?php if ($pending_name): ?>
        <div class="greeting">Welcome, <strong><?= sanitize_output($pending_name) ?></strong></div>
        <?php endif; ?>
      </div>

      <!-- Scanner visual -->
      <div class="fp-ring-wrap">
        <div class="fp-ripple" id="ripple1" style="display:none;"></div>
        <div class="fp-ripple" id="ripple2" style="display:none;"></div>
        <div class="fp-ripple" id="ripple3" style="display:none;"></div>
        <div class="fp-ring"   id="fpRing"></div>
        <div class="fp-icon-circle" id="fpCircle">
          <i class="fas fa-fingerprint fp-icon" id="fpIcon"></i>
        </div>
      </div>

      <div class="fp-status-text" id="fpStatusText">Place your finger on the scanner</div>
      <div class="fp-sub-text"   id="fpSubText">Waiting for fingerprint…</div>

      <!-- Hidden form — submitted after successful fingerprint verify -->
      <form method="POST" id="fpFinalForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="fingerprint_ok">
      </form>

      <!-- Try Again (shown on failure) -->
      <div id="tryAgainWrap" style="display:none;text-align:center;margin-bottom:10px;">
        <button class="btn-login" style="padding:10px 28px;width:auto;font-size:.88rem;"
                onclick="fpReset()">
          <i class="fas fa-rotate-right"></i> Try Again
        </button>
      </div>

      <div style="text-align:center;margin:4px 0 14px;color:#718096;font-size:.78rem;">
        <i class="fas fa-usb"></i> ZKTeco ZK9500 scanner required
      </div>

      <a class="back-link" onclick="showLoginMethod('password'); return false;" href="#step1">
        <i class="fas fa-arrow-left" style="font-size:.7rem;"></i> Use username and password instead
      </a>
    </div>

            <div class="login-footer"><span>RA 10173 Compliant</span><a href="/BRGYMS/portal/login.php">Resident Portal Login</a></div>
          </div>
        </div>
      </div>
    </section>
  </main>
  <footer class="staff-footer"><div class="staff-footer-inner"><span>Barangay San Isidro · City of Ilagan, Isabela</span><a href="#staffHome">Back to top ↑</a></div></footer>
  </div>

<script>

/* ── Login method selection ──────────────────────────────────────── */
function showLoginMethod(method) {
  const biometric = method === 'fingerprint';
  document.getElementById('step1').classList.toggle('hidden', biometric);
  document.getElementById('step2').classList.toggle('hidden', !biometric);
  document.getElementById('passwordMethodTab').classList.toggle('active', !biometric);
  document.getElementById('fingerprintMethodTab').classList.toggle('active', biometric);
  document.getElementById('passwordMethodTab').setAttribute('aria-selected', String(!biometric));
  document.getElementById('fingerprintMethodTab').setAttribute('aria-selected', String(biometric));
  if (biometric) startBiometricLogin();
}

/* ── Username and password login ─────────────────────────────────── */
function togglePw() {
  const pw = document.getElementById('password');
  const ic = document.getElementById('eyeIcon');
  pw.type = pw.type === 'password' ? 'text' : 'password';
  ic.className = pw.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}
document.getElementById('loginForm')?.addEventListener('submit', function(e) {
  const u = document.getElementById('username').value.trim();
  const p = document.getElementById('password').value;
  if (!u || !p) { e.preventDefault(); alert('Please fill in all fields.'); return; }
  const btn = document.getElementById('signInBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Verifying…';
});

/* ── Step 2 state machine ────────────────────────────────────────── */
const fpIcon   = document.getElementById('fpIcon');
const fpCircle = document.getElementById('fpCircle');
const fpRing   = document.getElementById('fpRing');
const fpStatus = document.getElementById('fpStatusText');
const fpSub    = document.getElementById('fpSubText');
const ripples  = [document.getElementById('ripple1'),
                  document.getElementById('ripple2'),
                  document.getElementById('ripple3')];
const tryAgain = document.getElementById('tryAgainWrap');

async function postFingerprintApi(action, data) {
  const formData = new FormData();
  Object.entries(data).forEach(([key, value]) => formData.append(key, value));
  const response = await fetch('/BRGYMS/fingerprint_api.php?action=' + encodeURIComponent(action), {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  });
  return response.json();
}

function setFpState(state, msg, sub) {
  fpIcon.className   = 'fas fp-icon';
  fpCircle.className = 'fp-icon-circle';
  fpStatus.className = 'fp-status-text';
  fpRing.classList.remove('pulse');
  ripples.forEach(r => r.style.display = 'none');
  tryAgain.style.display = 'none';

  switch (state) {
    case 'idle':
      fpIcon.classList.add('fa-fingerprint');
      fpStatus.textContent = msg || 'Place your finger on the scanner';
      fpSub.textContent    = sub || 'Waiting for fingerprint…';
      break;
    case 'scanning':
      fpIcon.classList.add('fa-fingerprint');
      fpRing.classList.add('pulse');
      ripples.forEach(r => r.style.display = 'block');
      fpStatus.textContent = msg || 'Scanning…';
      fpSub.textContent    = sub || 'Hold still while we read your fingerprint';
      break;
    case 'success':
      fpIcon.classList.add('fa-circle-check', 'success');
      fpCircle.classList.add('success');
      fpStatus.classList.add('success');
      fpStatus.textContent = msg || 'Fingerprint Verified!';
      fpSub.textContent    = sub || 'Redirecting to dashboard…';
      break;
    case 'failure':
      fpIcon.classList.add('fa-circle-xmark', 'failure');
      fpCircle.classList.add('failure');
      fpStatus.classList.add('failure');
      fpStatus.textContent   = msg || 'Fingerprint Not Recognized';
      fpSub.textContent      = sub || 'Please try again or contact your administrator.';
      tryAgain.style.display = 'block';
      break;
  }
}

/* ── Standalone ZK9500 biometric login ───────────────────────────── */
let biometricLoginStarted = false;
async function startBiometricLogin() {
  if (biometricLoginStarted) return;
  biometricLoginStarted = true;
  setFpState('scanning', 'Starting secure login…', 'Preparing the fingerprint scanner');
  try {
    const startResponse = await fetch('/BRGYMS/fingerprint_api.php?action=start_biometric', {
      method: 'POST',
      body: new URLSearchParams({csrf_token: '<?= $csrf ?>'}),
      credentials: 'same-origin'
    });
    const session = await startResponse.json();
    if (!session.success) throw new Error(session.message || 'Could not start fingerprint login.');

    const bridgeHealth = await fetch('http://127.0.0.1:8765/health');
    if (!bridgeHealth.ok) throw new Error('Fingerprint bridge is not running.');
    const health = await bridgeHealth.json();
    if (!health.success) throw new Error(health.message || 'Fingerprint bridge is unavailable.');

    setFpState('scanning', 'Place your finger on the scanner', 'Matching against enrolled staff fingerprints');
    const verifyResponse = await fetch('http://127.0.0.1:8765/identify', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        apiUrl: window.location.origin + '/BRGYMS/fingerprint_api.php',
        challenge: session.challenge,
        ticket: session.ticket,
        candidateTimestamp: session.candidate_timestamp,
        candidateToken: session.candidate_token
      })
    });
    const result = await verifyResponse.json();
    if (!result.success) throw new Error(result.message || 'Fingerprint not recognized.');

    const proof = await postFingerprintApi('identify_verify', {
      csrf_token: '<?= $csrf ?>', challenge: session.challenge, ticket: session.ticket,
      user_id: result.user_id, score: result.score, signature: result.signature
    });
    if (!proof.success) throw new Error(proof.message || 'Fingerprint proof was rejected.');

    setFpState('success', 'Fingerprint recognized', 'Signing you in…');
    setTimeout(() => document.getElementById('fpFinalForm').submit(), 800);
  } catch (error) {
    biometricLoginStarted = false;
    postFingerprintApi('fingerprint_failure', {
      csrf_token: '<?= $csrf ?>', message: error.message || 'Fingerprint verification failed.'
    }).catch(() => {});
    setFpState('failure', 'Fingerprint Verification Failed', error.message);
  }
}

function fpReset() {
  biometricLoginStarted = false;
  startBiometricLogin();
}

setFpState('idle');
</script>
</body>
</html>
