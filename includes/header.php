<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $page_title ?? 'WBRMAS' ?> – Barangay San Isidro</title>
<link rel="stylesheet" href="/assets/css/main.css?v=<?= filemtime(__DIR__ . '/../assets/css/main.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
      integrity="sha512-Avb2QiuTny/E8c4z5VJgCl+oFCLJKqv2O2IM6Qn+NaYSJ5Nc1GBWoCH3X0GOWsQhGjl0K3RBGb83SKS/o/0Q=="
      crossorigin="anonymous" referrerpolicy="no-referrer">
<!-- FA fallback if CDN fails -->
<script>
(function(){
  var test = document.createElement('span');
  test.className = 'fas';
  test.style.cssText = 'position:absolute;visibility:hidden;';
  document.head.appendChild(test);
  setTimeout(function(){
    var style = window.getComputedStyle(test,'::before');
    if(!style || style.fontFamily.indexOf('Font Awesome') === -1){
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = 'https://use.fontawesome.com/releases/v6.5.0/css/all.css';
      document.head.appendChild(link);
    }
    document.head.removeChild(test);
  }, 500);
})();
</script>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
</head>
<body>
<div class="app-layout">
<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="/assets/img/brgy_seal.png" alt="Brgy Seal" class="brand-seal-img">
    <div class="brand-text">
      <span class="brand-name">WBRMAS</span>
      <span class="brand-sub">Brgy. San Isidro</span>
    </div>
    <img src="/assets/img/city_seal.png" alt="City Seal" class="brand-seal-img">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
  </div>

  <div class="sidebar-user">
    <div class="user-avatar"><i class="fas fa-user-circle"></i></div>
    <div class="user-info">
      <span class="user-name"><?= sanitize_output($_SESSION['full_name']) ?></span>
      <span class="user-role badge-<?= is_admin() ? 'admin' : 'staff' ?>">
        <?= sanitize_output($_SESSION['role_name']) ?>
      </span>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Main</div>
    <?php if (can('view_dashboard')): ?>
    <a href="/dashboard.php" class="nav-item <?= $active_page === 'dashboard' ? 'active' : '' ?>">
      <i class="fas fa-gauge-high"></i><span>Dashboard</span>
    </a>
    <?php endif; ?>
    <?php if (can('view_residents')): ?>
    <a href="/residents/index.php" class="nav-item <?= $active_page === 'residents' ? 'active' : '' ?>">
      <i class="fas fa-people-group"></i><span>Residents</span>
    </a>
    <?php endif; ?>
    <?php if (can('view_documents')): ?>
    <a href="/documents/index.php" class="nav-item <?= $active_page === 'documents' ? 'active' : '' ?>">
      <i class="fas fa-file-lines"></i><span>Documents</span>
    </a>
    <?php endif; ?>
    <?php if (can('view_blotter')): ?>
    <a href="/blotter/index.php" class="nav-item <?= $active_page === 'blotter' ? 'active' : '' ?>">
      <i class="fas fa-book-open"></i><span>Blotter</span>
    </a>
    <?php endif; ?>
    <?php if (can('view_drawer_index')): ?>
    <a href="/drawers/index.php" class="nav-item <?= $active_page === 'drawers' ? 'active' : '' ?>">
      <i class="fas fa-boxes-stacked"></i><span>Drawer Index</span>
    </a>
    <?php endif; ?>
    <?php if (in_array($_SESSION['role_name'] ?? '', ['System Administrator', 'Barangay Staff'], true) && can('view_health_records')): ?>
    <a href="/health/index.php" class="nav-item <?= $active_page === 'health' ? 'active' : '' ?>">
      <i class="fas fa-heart-pulse"></i><span>Health Records</span>
    </a>
    <?php endif; ?>
    <?php if (can('view_financial_reports')): ?>
    <a href="/finance/index.php" class="nav-item <?= $active_page === 'finance' ? 'active' : '' ?>">
      <i class="fas fa-coins"></i><span>Financial Reports</span>
    </a>
    <?php endif; ?>

    <?php if (is_admin() || can('manage_users') || can('view_audit') || can('view_analytics')): ?>
    <div class="nav-section-label">Administration</div>
    <?php if (is_admin() || can('manage_users')): ?>
    <a href="/admin/users.php" class="nav-item <?= $active_page === 'users' ? 'active' : '' ?>">
      <i class="fas fa-users-cog"></i><span>User Accounts</span>
    </a>
    <?php endif; ?>
    <?php if (is_admin() || can('view_audit')): ?>
    <a href="/admin/audit.php" class="nav-item <?= $active_page === 'audit' ? 'active' : '' ?>">
      <i class="fas fa-clipboard-list"></i><span>Audit Trail</span>
    </a>
    <?php endif; ?>
    <?php if (is_admin() || can('view_analytics')): ?>
    <a href="/admin/analytics.php" class="nav-item <?= $active_page === 'analytics' ? 'active' : '' ?>">
      <i class="fas fa-chart-pie"></i><span>Analytics</span>
    </a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <a href="/admin/masterlist.php" class="nav-item <?= $active_page === 'masterlist' ? 'active' : '' ?>">
      <i class="fas fa-list-ol"></i><span>Master List</span>
    </a>
    <a href="/admin/backup.php" class="nav-item <?= $active_page === 'backup' ? 'active' : '' ?>">
      <i class="fas fa-database"></i><span>DB Backup</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <div class="nav-section-label">Account</div>
    <a href="/profile.php" class="nav-item <?= $active_page === 'profile' ? 'active' : '' ?>">
      <i class="fas fa-user-gear"></i><span>My Profile</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <i class="fas fa-lock"></i> AES-256 &amp; HMAC-SHA256
  </div>
</aside>

<!-- Main Content -->
<main class="main-content" id="mainContent">
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
      <div class="breadcrumb">
        <span><i class="fas fa-house"></i></span>
        <?php if (isset($breadcrumb)): foreach ($breadcrumb as $crumb): ?>
        <span class="bc-sep">/</span><span><?= sanitize_output($crumb) ?></span>
        <?php endforeach; endif; ?>
      </div>
      <span class="topbar-system-name">
        Barangay San-Isidro Record Management System
      </span>
    </div>

    <?php if (($_SESSION['role_name'] ?? '') !== 'Barangay Treasurer'): ?>
    <div class="global-search" id="globalSearch">
      <i class="fas fa-magnifying-glass"></i>
      <input type="search" id="globalSearchInput" autocomplete="off" spellcheck="false"
             placeholder="Search residents, documents, cases…"
             aria-label="Global search" aria-controls="globalSearchResults" aria-expanded="false">
      <kbd class="global-search-kbd">Ctrl K</kbd>
      <div class="global-search-dropdown" id="globalSearchResults" role="listbox" hidden></div>
    </div>
    <?php endif; ?>

    <div class="topbar-right">
      <div class="topbar-time" id="topbarTime"></div>

      <!-- Dark/Light Mode Toggle -->
      <button onclick="toggleTheme()" id="themeToggle" title="Toggle dark/light mode"
        style="background:none;border:none;cursor:pointer;padding:6px 10px;border-radius:8px;font-size:17px;display:flex;align-items:center;color:#555;transition:all .2s;">
        <i class="fas fa-moon" id="themeIcon"></i>
      </button>

      <!-- Notification Bell -->
      <?php if (($_SESSION['role_name'] ?? '') !== 'Barangay Treasurer'): ?>
      <div class="notif-wrap" id="notifWrap">
        <button onclick="toggleNotif()" title="Notifications"
          style="background:none;border:none;cursor:pointer;padding:6px 10px;border-radius:8px;font-size:17px;position:relative;display:flex;align-items:center;color:#555;transition:all .2s;">
          <i class="fas fa-bell"></i>
          <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-header">
            <span><i class="fas fa-bell"></i> Notifications</span>
            <button onclick="toggleNotif()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:13px;"><i class="fas fa-xmark"></i></button>
          </div>
          <div class="notif-list" id="notifList">
            <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:.83rem;">
              <i class="fas fa-circle-notch fa-spin"></i> Loading...
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="session-badge" id="sessionBadge"><i class="fas fa-circle-check"></i> Session Active</div>
      <a href="/logout.php" class="topbar-signout" title="Sign Out">
        <i class="fas fa-right-from-bracket"></i><span>Sign Out</span>
      </a>
    </div>
  </div>
  <div class="page-content">
