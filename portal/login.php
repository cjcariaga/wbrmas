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
<title>Resident Portal – Barangay San Isidro</title>
<link rel="stylesheet" href="/assets/css/main.css?v=<?= filemtime(__DIR__ . '/../assets/css/main.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
html,body{margin:0 !important;padding:0 !important}body{background:#f3f4ef;color:#172923}.resident-site{--forest:#143e35;--forest-deep:#102b28;--gold:#e6a858;--ink:#172923;--muted:#60716a;font-family:'Inter',sans-serif}.site-nav{height:82px;display:flex;align-items:center;justify-content:space-between;gap:24px;padding:0 max(24px,calc((100vw - 1200px)/2));background:#102b28;color:#fff}.portal-brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:inherit;min-width:0}.portal-brand img{width:46px;height:46px;object-fit:contain}.portal-brand strong{display:block;font-size:.92rem;line-height:1.25}.portal-brand small{display:block;color:#c5d1ca;font-size:.72rem;margin-top:3px}.site-nav-links{display:flex;align-items:center;gap:28px}.site-nav-links>a:not(.nav-login){color:#d8e0da;text-decoration:none;font-size:.82rem}.site-nav-links>a:not(.nav-login):hover{color:#fff}.nav-login{display:inline-flex;align-items:center;gap:8px;padding:11px 17px;color:#172923;background:var(--gold);border-radius:4px;text-decoration:none;font-size:.82rem;font-weight:700}.hero{position:relative;isolation:isolate;min-height:590px;display:flex;align-items:center;overflow:hidden;color:#fff;background:var(--forest-deep)}.hero-scene,.hero-shade{position:absolute;inset:0;width:100%;height:100%;z-index:-1}.hero-scene{object-fit:cover;object-position:78% center}.hero-shade{background:rgba(10,30,29,.76)}.hero-inner{width:min(1200px,100%);margin:0 auto;padding:68px 24px 54px}.hero-copy{max-width:710px;animation:rise-in .65s ease both}.hero-eyebrow{display:flex;align-items:center;gap:9px;color:#f0c17f;font-size:.72rem;font-weight:700;letter-spacing:1.25px;text-transform:uppercase}.hero-eyebrow:before{content:'';width:7px;height:7px;border-radius:50%;background:var(--gold)}.hero h1{max-width:700px;margin:20px 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:clamp(3rem,6vw,5rem);font-weight:600;line-height:.99;letter-spacing:0}.hero-location{margin:0 0 19px;color:#efc58d;font-size:.91rem;font-weight:600}.hero-description{max-width:560px;color:#e0e6e1;font-size:.96rem;line-height:1.75}.hero-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:26px}.action-primary,.action-secondary{min-height:44px;display:inline-flex;align-items:center;gap:9px;padding:0 17px;border-radius:4px;text-decoration:none;font-size:.82rem;font-weight:700}.action-primary{background:var(--gold);color:#172923}.action-secondary{border:1px solid rgba(255,255,255,.52);color:#fff}.action-primary:hover,.nav-login:hover{background:#f0bd78}.action-secondary:hover{background:rgba(255,255,255,.12)}.hero-facts{display:grid;grid-template-columns:1fr 1.2fr 1fr;max-width:940px;margin-top:50px;border:1px solid rgba(255,255,255,.19);border-radius:8px;background:rgba(255,255,255,.08);backdrop-filter:blur(5px)}.hero-fact{padding:16px 20px;min-width:0}.hero-fact+.hero-fact{border-left:1px solid rgba(255,255,255,.18)}.hero-fact-label{display:flex;align-items:center;gap:8px;margin-bottom:6px;color:#eac58e;font-size:.66rem;font-weight:700;letter-spacing:.7px;text-transform:uppercase}.hero-fact-value{color:#fff;font-size:.78rem;font-weight:600}.content-band{padding:70px 24px}.content-inner{width:min(1120px,100%);margin:0 auto}.section-kicker{margin-bottom:10px;color:#9b652d;font-size:.7rem;font-weight:700;letter-spacing:1.3px;text-transform:uppercase}.section-title{margin:0;color:var(--ink);font-family:Georgia,'Times New Roman',serif;font-size:2.25rem;font-weight:600;letter-spacing:0}.section-intro{max-width:620px;margin:12px 0 0;color:var(--muted);font-size:.91rem;line-height:1.7}.services-band{background:#f8f8f4}.service-list{display:grid;grid-template-columns:repeat(3,1fr);margin-top:34px;border-top:1px solid #d9ded6;border-bottom:1px solid #d9ded6}.service-item{padding:24px 22px 25px 0}.service-item+.service-item{padding-left:22px;border-left:1px solid #d9ded6}.service-icon{display:inline-flex;width:37px;height:37px;align-items:center;justify-content:center;margin-bottom:15px;border:1px solid #c9d1c9;border-radius:50%;color:#345d4f}.service-item h3{margin:0 0 7px;font-size:.96rem;color:var(--ink)}.service-item p{margin:0;color:var(--muted);font-size:.82rem;line-height:1.6}.service-item a{display:inline-flex;align-items:center;gap:7px;margin-top:14px;color:#285b4b;text-decoration:none;font-size:.78rem;font-weight:700}.login-band{background:#e9ede6}.login-layout{display:grid;grid-template-columns:1fr minmax(320px,430px);align-items:center;gap:60px}.login-context p{max-width:470px}.portal-box{padding:28px;background:#fff;border:1px solid #d9dfd7;border-radius:6px;box-shadow:0 14px 38px rgba(25,50,38,.09);scroll-margin-top:24px}.portal-box h2{margin:0 0 7px;color:var(--ink);font-size:1.13rem}.login-subtitle{margin:0 0 20px;color:var(--muted);font-size:.82rem}.portal-alert{display:none;margin:0 0 14px;padding:10px 12px;border-radius:4px;background:#fdecea;color:#942d24;font-size:.85rem}.portal-box .form-group{margin:0 0 14px}.portal-box .form-control{min-height:42px;border-radius:4px}.portal-submit{width:100%;justify-content:center;margin-top:4px;background:var(--forest);color:#fff}.portal-submit:hover{background:#1d5949}.portal-footer{font-size:.78rem;text-align:center;margin-top:17px;color:#657872;line-height:1.8}.portal-footer a{color:#285b4b;font-weight:700;text-decoration:none}.site-footer{padding:24px;background:#102b28;color:#cad5cd}.footer-inner{width:min(1120px,100%);margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px;font-size:.75rem}.site-footer a{color:#f0c17f;text-decoration:none}@keyframes rise-in{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}@media(max-width:760px){.site-nav{height:auto;min-height:72px;padding:12px 18px}.site-nav-links{gap:0}.site-nav-links>a:not(.nav-login){display:none}.nav-login{padding:10px 12px}.hero{min-height:620px}.hero-inner{padding:56px 20px 36px}.hero h1{font-size:3.15rem}.hero-facts{grid-template-columns:1fr;margin-top:34px}.hero-fact{padding:13px 15px}.hero-fact+.hero-fact{border-left:0;border-top:1px solid rgba(255,255,255,.18)}.content-band{padding:54px 20px}.service-list{grid-template-columns:1fr}.service-item,.service-item+.service-item{padding:20px 0;border-left:0}.service-item+.service-item{border-top:1px solid #d9ded6}.login-layout{grid-template-columns:1fr;gap:26px}.portal-box{padding:23px 20px}.footer-inner{align-items:flex-start;flex-direction:column}}@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important}}
</style>
<style>.hero-scene{left:auto;width:64%;object-position:right center}.hero-shade{left:0;width:100%;background:linear-gradient(90deg,rgba(16,43,40,1) 38%,rgba(16,43,40,.9) 53%,rgba(16,43,40,.35) 100%)}@media(max-width:760px){.hero-scene{width:64%}.hero-shade{background:linear-gradient(90deg,rgba(16,43,40,1) 30%,rgba(16,43,40,.86) 58%,rgba(16,43,40,.38) 100%)}}</style>
<style>
.resident-site{--forest:#1b4f78;--forest-deep:#102f52;--ink:#172b40;--muted:#5f7082}
.site-nav,.site-footer{background:#102f52}
.portal-brand small{color:#c6d8e7}
.site-nav-links>a:not(.nav-login){color:#d9e6f0}
.hero{background:#102f52}
.hero-shade{background:linear-gradient(90deg,rgba(16,47,82,1) 38%,rgba(16,47,82,.9) 53%,rgba(16,47,82,.35) 100%)}
.hero-fact-label{color:#f0c17f}
.service-icon{color:#285f88;border-color:#c6d5e1}
.service-item a,.portal-footer a{color:#205e88}
.login-band{background:#e8eff4}
.portal-submit{background:#1b4f78}
.portal-submit:hover{background:#153f64}
.footer-credit{display:grid;gap:6px}
.footer-credit-label{color:#9fb8cd;font-size:.65rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.footer-developers{display:flex;flex-wrap:wrap;gap:5px 18px;margin:0;padding:0;list-style:none}
.footer-developers li{color:#e0eaf2;font-size:.76rem;line-height:1.5}
@media(max-width:760px){.hero-shade{background:linear-gradient(90deg,rgba(16,47,82,1) 30%,rgba(16,47,82,.86) 58%,rgba(16,47,82,.38) 100%)}}
@media(max-width:760px){.footer-developers{display:grid;gap:4px}}
</style>
</head>
<body>
<div class="resident-site">
  <header class="site-nav">
    <a class="portal-brand" href="#home"><img src="/assets/img/brgy_seal.png" alt=""><span><strong>Barangay San Isidro</strong><small>Resident Services Portal</small></span></a>
    <nav class="site-nav-links" aria-label="Main navigation">
      <a href="#home">Home</a><a href="#about">About</a><a href="#services">Services</a><a href="#contact">Contact</a>
      <a class="nav-login" href="#login"><i class="fas fa-right-to-bracket" aria-hidden="true"></i> Log in</a>
    </nav>
  </header>
  <main>
    <section class="hero" id="home" aria-labelledby="heroTitle">
      <img class="hero-scene" src="/assets/img/header.png" alt="" aria-hidden="true">
      <div class="hero-shade" aria-hidden="true"></div>
      <div class="hero-inner">
        <div class="hero-copy">
          <div class="hero-eyebrow">Official Barangay Portal</div>
          <h1 id="heroTitle">Barangay<br>San Isidro</h1>
          <p class="hero-location">City of Ilagan, Isabela · Serving our community</p>
          <p class="hero-description">Access resident services, request barangay documents, and keep track of your requests in one convenient place.</p>
          <div class="hero-actions"><a class="action-primary" href="#services">Explore Services <i class="fas fa-arrow-right" aria-hidden="true"></i></a><a class="action-secondary" href="#login">Resident log in</a></div>
        </div>
        <div class="hero-facts" id="about">
          <div class="hero-fact"><div class="hero-fact-label"><i class="far fa-building" aria-hidden="true"></i> Barangay</div><div class="hero-fact-value">San Isidro</div></div>
          <div class="hero-fact"><div class="hero-fact-label"><i class="fas fa-location-dot" aria-hidden="true"></i> Location</div><div class="hero-fact-value">City of Ilagan, Isabela</div></div>
          <div class="hero-fact"><div class="hero-fact-label"><i class="fas fa-file-lines" aria-hidden="true"></i> Online access</div><div class="hero-fact-value">Resident services and requests</div></div>
        </div>
      </div>
    </section>
    <section class="content-band services-band" id="services">
      <div class="content-inner">
        <div class="section-kicker">Resident services</div><h2 class="section-title">Your barangay, within reach.</h2>
        <p class="section-intro">Sign in to access services connected to your resident record. New to the portal? Register using your existing barangay record.</p>
        <div class="service-list">
          <article class="service-item"><span class="service-icon"><i class="fas fa-file-circle-plus" aria-hidden="true"></i></span><h3>Request a document</h3><p>Submit a barangay document request for staff review.</p><a href="#login">Go to resident log in <i class="fas fa-arrow-right" aria-hidden="true"></i></a></article>
          <article class="service-item"><span class="service-icon"><i class="fas fa-list-check" aria-hidden="true"></i></span><h3>Track your requests</h3><p>Review request status and pickup details from your account.</p><a href="#login">View your requests <i class="fas fa-arrow-right" aria-hidden="true"></i></a></article>
          <article class="service-item"><span class="service-icon"><i class="fas fa-id-card" aria-hidden="true"></i></span><h3>View your profile</h3><p>Check the resident information linked to your portal account.</p><a href="#login">Open resident portal <i class="fas fa-arrow-right" aria-hidden="true"></i></a></article>
        </div>
      </div>
    </section>
    <section class="content-band login-band" id="contact">
      <div class="content-inner login-layout">
        <div class="login-context"><div class="section-kicker">Resident access</div><h2 class="section-title">Welcome back.</h2><p class="section-intro">Sign in to manage your barangay document requests and view your resident profile. For corrections to your resident record, please contact the barangay office.</p></div>
        <section class="portal-box" id="login" aria-labelledby="loginTitle">
          <h2 id="loginTitle">Resident sign in</h2><p class="login-subtitle">This sign-in is for residents with a registered portal account.</p>
          <div class="portal-alert" id="portalError" role="alert"></div>
          <form id="portalLoginForm">
            <input type="hidden" name="csrf_token" value="<?= sanitize_output($csrf) ?>">
            <div class="form-group"><label for="portalUsername">Username</label><input id="portalUsername" name="username" class="form-control" maxlength="50" autocomplete="username" required></div>
            <div class="form-group"><label for="portalPassword">Password</label><input id="portalPassword" name="password" type="password" class="form-control" maxlength="100" autocomplete="current-password" required></div>
            <button class="btn portal-submit" type="submit"><i class="fas fa-right-to-bracket" aria-hidden="true"></i> Sign in</button>
          </form>
          <div class="portal-footer">No account yet? <a href="register.php">Register with your resident record</a></div>
        </section>
      </div>
    </section>
  </main>
  <footer class="site-footer"><div class="footer-inner"><div class="footer-credit"><span>Barangay San Isidro · City of Ilagan, Isabela</span><span class="footer-credit-label">Developed by</span><ul class="footer-developers"><li>Christian Jhay M. Cariaga</li><li>Justin C. Andres</li><li>Mark Josh T. Asuero</li></ul></div><a href="#home">Back to top ↑</a></div></footer>
</div>
<script>
document.getElementById('portalLoginForm').addEventListener('submit', async event => {
  event.preventDefault();
  const errorBox = document.getElementById('portalError');
  errorBox.style.display = 'none';
  try {
    const response = await fetch('api.php?action=login', {method:'POST', body:new FormData(event.currentTarget)});
    const result = await response.json();
    if (!result.success) { errorBox.textContent = result.message || 'Could not sign in.'; errorBox.style.display='block'; return; }
    window.location.href = 'index.php';
  } catch (error) {
    errorBox.textContent = 'Unable to contact the portal. Please try again.';
    errorBox.style.display = 'block';
  }
});
</script>
</body>
</html>
