  </div><!-- .page-content -->
</main>
</div><!-- .app-layout -->

<script src="/BRGYMS/assets/js/main.js"></script>
<script>
// ── Live clock ───────────────────────────────────────────────────────────────
function updateClock() {
  const now = new Date();
  const opts = {weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'};
  const el = document.getElementById('topbarTime');
  if (el) el.textContent = now.toLocaleDateString('en-PH', opts);
}
setInterval(updateClock, 1000);
updateClock();

// ── Session countdown ────────────────────────────────────────────────────────
let sessionTimer = <?= SESSION_TIMEOUT ?>;
let warnShown = false;

function formatTime(s) {
  const m = Math.floor(s / 60);
  const sec = s % 60;
  return m + ':' + String(sec).padStart(2, '0');
}

function updateSessionBadge() {
  const badge = document.getElementById('sessionBadge');
  if (!badge) return;
  if (sessionTimer > 300) {
    badge.innerHTML = '<i class="fas fa-circle-check"></i> Session Active';
    badge.style.cssText = '';
  } else if (sessionTimer > 60) {
    badge.innerHTML = `<i class="fas fa-clock"></i> Session: ${formatTime(sessionTimer)}`;
    badge.style.cssText = 'background:rgba(243,156,18,.15);color:#f39c12;border:1px solid #f39c12;border-radius:20px;padding:4px 12px;';
    if (!warnShown) { warnShown=true; showToast('warning','Session expires in 5 minutes. Please save your work.'); }
  } else if (sessionTimer > 0) {
    badge.innerHTML = `<i class="fas fa-triangle-exclamation"></i> Expiring: ${formatTime(sessionTimer)}`;
    badge.style.cssText = 'background:rgba(231,76,60,.2);color:#e74c3c;border:1px solid #e74c3c;border-radius:20px;padding:4px 12px;';
  } else {
    window.location.href = '/BRGYMS/index.php?timeout=1';
  }
}
setInterval(() => { sessionTimer--; updateSessionBadge(); }, 1000);
updateSessionBadge();

// ── Sidebar toggles ──────────────────────────────────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  document.getElementById('sidebar').classList.toggle('collapsed');
  document.getElementById('mainContent').classList.toggle('expanded');
});

// ── Dark / Light Mode ────────────────────────────────────────────────────────
function applyTheme(dark) {
  document.body.classList.toggle('dark-mode', dark);
  const icon = document.getElementById('themeIcon');
  if (icon) icon.className = dark ? 'fas fa-sun' : 'fas fa-moon';
}
function toggleTheme() {
  const isDark = !document.body.classList.contains('dark-mode');
  localStorage.setItem('wbrmas_theme', isDark ? 'dark' : 'light');
  applyTheme(isDark);
}
applyTheme(localStorage.getItem('wbrmas_theme') === 'dark');

// ── Notification Bell ────────────────────────────────────────────────────────
let notifOpen = false;
async function loadNotifications() {
  try {
    const res  = await fetch('/BRGYMS/notifications_api.php');
    const data = await res.json();
    if (!data.success) return;
    const badge = document.getElementById('notifBadge');
    const list  = document.getElementById('notifList');
    const alertCount = data.notifications.filter(n => n.title !== 'All Clear!').length;
    if (badge) { badge.textContent = alertCount; badge.style.display = alertCount > 0 ? 'flex' : 'none'; }
    if (list) {
      list.innerHTML = data.notifications.map(n => `
        <${n.link ? `a href="${n.link}"` : 'div'} class="notif-item">
          <div class="notif-icon ${n.type}"><i class="fas ${n.icon}"></i></div>
          <div>
            <div class="notif-title">${n.title}</div>
            <div class="notif-body">${n.body}</div>
          </div>
        </${n.link ? 'a' : 'div'}>`).join('');
    }
  } catch(e) {}
}
function toggleNotif() {
  notifOpen = !notifOpen;
  const dd = document.getElementById('notifDropdown');
  if (dd) dd.classList.toggle('show', notifOpen);
  if (notifOpen) loadNotifications();
}
document.addEventListener('click', e => {
  const wrap = document.getElementById('notifWrap');
  if (wrap && !wrap.contains(e.target) && notifOpen) {
    notifOpen = false;
    document.getElementById('notifDropdown')?.classList.remove('show');
  }
});
loadNotifications();
setInterval(loadNotifications, 60000);

// ── Global search (Ctrl/Cmd+K) ───────────────────────────────────────────────
(function () {
  const wrap  = document.getElementById('globalSearch');
  const input = document.getElementById('globalSearchInput');
  const box   = document.getElementById('globalSearchResults');
  if (!wrap || !input || !box) return;

  let timer = null;
  let activeIndex = -1;
  let items = [];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));

  function openBox() {
    box.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }
  function closeBox() {
    box.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    activeIndex = -1;
  }
  function setActive(i) {
    const nodes = box.querySelectorAll('.gs-item');
    nodes.forEach(n => n.classList.remove('active'));
    if (i >= 0 && nodes[i]) {
      nodes[i].classList.add('active');
      nodes[i].scrollIntoView({ block: 'nearest' });
    }
  }
  function render(data, query) {
    items = data.results || [];
    activeIndex = items.length ? 0 : -1;
    if (!query) {
      box.innerHTML = '<div class="gs-hint"><i class="fas fa-keyboard"></i> Search residents, document codes, or case numbers.</div>';
      openBox();
      return;
    }
    if (!items.length) {
      box.innerHTML = `<div class="gs-empty"><i class="fas fa-magnifying-glass"></i> No matches for “${esc(query)}”</div>`;
      openBox();
      return;
    }
    box.innerHTML = items.map((r, i) => `
      <a class="gs-item${i === 0 ? ' active' : ''}" href="${esc(r.link)}" role="option">
        <div class="gs-icon ${esc(r.type)}"><i class="fas ${esc(r.icon)}"></i></div>
        <div style="min-width:0;flex:1;">
          <div class="gs-title">${esc(r.title)}</div>
          <div class="gs-body">${esc(r.body)}</div>
        </div>
        <span class="gs-badge">${esc(r.badge)}</span>
      </a>`).join('');
    openBox();
  }
  async function runSearch(q) {
    if (q.length < 2) { render({ results: [] }, ''); return; }
    box.innerHTML = '<div class="gs-hint"><i class="fas fa-circle-notch fa-spin"></i> Searching…</div>';
    openBox();
    try {
      const res  = await fetch('/BRGYMS/search_api.php?q=' + encodeURIComponent(q));
      const data = await res.json();
      if (input.value.trim() !== q) return;
      render(data.success ? data : { results: [] }, q);
    } catch (e) {
      box.innerHTML = '<div class="gs-empty">Search is unavailable right now.</div>';
    }
  }
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    timer = setTimeout(() => runSearch(q), 250);
  });
  input.addEventListener('focus', () => {
    if (input.value.trim().length >= 2) runSearch(input.value.trim());
    else render({ results: [] }, '');
  });
  input.addEventListener('keydown', (e) => {
    const nodes = box.querySelectorAll('.gs-item');
    if (e.key === 'Escape') { closeBox(); input.blur(); return; }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!nodes.length) return;
      activeIndex = (activeIndex + 1) % nodes.length;
      setActive(activeIndex);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!nodes.length) return;
      activeIndex = (activeIndex - 1 + nodes.length) % nodes.length;
      setActive(activeIndex);
    } else if (e.key === 'Enter' && activeIndex >= 0 && nodes[activeIndex]) {
      e.preventDefault();
      window.location.href = nodes[activeIndex].href;
    }
  });
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      input.focus();
      input.select();
    }
  });
  document.addEventListener('click', (e) => {
    if (!wrap.contains(e.target)) closeBox();
  });
})();

// ── Print Preview ────────────────────────────────────────────────────────────
window.printDocument = function(html) {
  let overlay = document.getElementById('previewModalOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'previewModalOverlay';
    overlay.className = 'modal-overlay preview-modal';
    overlay.innerHTML = `
      <div class="modal" style="max-width:860px;height:90vh;display:flex;flex-direction:column;">
        <div class="modal-header">
          <h4><i class="fas fa-eye"></i> Print Preview — Review before printing</h4>
          <button onclick="closePreview()" class="modal-close"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="flex:1;padding:0;overflow:hidden;">
          <iframe id="previewFrame" style="width:100%;height:100%;border:none;background:#fff;"></iframe>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" onclick="closePreview()"><i class="fas fa-xmark"></i> Cancel</button>
          <button class="btn btn-primary" onclick="confirmPrint()"><i class="fas fa-print"></i> Confirm &amp; Print</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
  }
  document.getElementById('previewFrame').srcdoc = html;
  window._pendingPrintHtml = html;
  overlay.classList.add('show');
};
window.closePreview = function() {
  document.getElementById('previewModalOverlay')?.classList.remove('show');
};
window.confirmPrint = function() {
  closePreview();
  const pw = window.open('','_blank','width=900,height=700,scrollbars=yes');
  if (pw) { pw.document.write(window._pendingPrintHtml); pw.document.close(); pw.focus(); setTimeout(()=>pw.print(),600); }
};

// ── Skeleton Loader ──────────────────────────────────────────────────────────
window.showSkeleton = function(tbodyId, cols, rows=5) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  let html = '';
  for (let r=0; r<rows; r++) {
    html += '<tr class="skeleton-row">';
    for (let c=0; c<cols; c++) {
      html += `<td><span class="skeleton skeleton-text ${c===0?'short':c===cols-1?'long':''}"></span></td>`;
    }
    html += '</tr>';
  }
  tbody.innerHTML = html;
};

// ── Empty State SVG helper ───────────────────────────────────────────────────
window.emptyState = function(message, subtext) {
  return `<tr><td colspan="99">
    <div class="empty-state">
      <svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="10" y="20" width="60" height="50" rx="6" fill="currentColor" opacity=".08"/>
        <rect x="20" y="12" width="40" height="6" rx="3" fill="currentColor" opacity=".12"/>
        <line x1="22" y1="36" x2="58" y2="36" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity=".25"/>
        <line x1="22" y1="46" x2="50" y2="46" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity=".18"/>
        <line x1="22" y1="56" x2="44" y2="56" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity=".12"/>
      </svg>
      <h4>${message||'No records found'}</h4>
      <p>${subtext||'Nothing to display here yet.'}</p>
    </div>
  </td></tr>`;
};
</script>
</body>
</html>
