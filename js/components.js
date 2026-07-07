function renderSharedNavbar() {
  const navbar = document.getElementById('navbar');
  if (!navbar) return;

  // Ensure the navbar container has the .navbar class
  if (!navbar.classList.contains('navbar')) {
    navbar.classList.add('navbar');
  }

  const currentPath = window.location.pathname.split('/').pop() || 'marketplace.html';

  const user = window.db ? window.db.getCurrentUser() : null;
  if (!user) {
    window.location.href = 'login.html';
    return;
  }

  // Route Guard Logic
  const role = user.role || 'freelancer';
  const allowedRoutes = {
    freelancer: ['marketplace.html', 'ai-match.html', 'freelancer-projects.html', 'trail-map.html', 'portfolio.html', 'profile.html'],
    umkm: ['umkm-dashboard.html', 'umkm-projects.html', 'talent-ai.html', 'profile.html'],
    admin: ['admin-dashboard.html', 'admin-users.html', 'admin-umkm.html', 'admin-projects.html']
  };

  // If page is not in their allowed list, redirect them to their primary dashboard
  if (!allowedRoutes[role].includes(currentPath)) {
    if (role === 'admin') window.location.href = 'admin-dashboard.html';
    else if (role === 'umkm') window.location.href = 'umkm-dashboard.html';
    else window.location.href = 'marketplace.html';
    return;
  }

  // Build Dynamic Nav Tabs
  let tabsHTML = '';
  if (role === 'freelancer') {
    tabsHTML = `
        <a href="ai-match.html" class="nav-tab ${currentPath === 'ai-match.html' ? 'active' : ''}">AI Match</a>
        <a href="marketplace.html" class="nav-tab ${currentPath === 'marketplace.html' ? 'active' : ''}">Marketplace</a>
        <a href="freelancer-projects.html" class="nav-tab ${currentPath === 'freelancer-projects.html' ? 'active' : ''}">Proyek Saya</a>
        <a href="trail-map.html" class="nav-tab ${currentPath === 'trail-map.html' ? 'active' : ''}">Trail Map</a>
        <a href="portfolio.html" class="nav-tab ${currentPath === 'portfolio.html' ? 'active' : ''}">Impact Portfolio</a>
    `;
  } else if (role === 'umkm') {
    tabsHTML = `
        <a href="umkm-dashboard.html" class="nav-tab ${currentPath === 'umkm-dashboard.html' ? 'active' : ''}">Dashboard</a>
        <a href="umkm-projects.html" class="nav-tab ${currentPath === 'umkm-projects.html' ? 'active' : ''}">Kelola Proyek</a>
        <a href="talent-ai.html" class="nav-tab ${currentPath === 'talent-ai.html' ? 'active' : ''}">Talent AI</a>
        <a href="profile.html" class="nav-tab ${currentPath === 'profile.html' ? 'active' : ''}">Profil UMKM</a>
    `;
  } else if (role === 'admin') {
    tabsHTML = `
        <a href="admin-dashboard.html" class="nav-tab ${currentPath === 'admin-dashboard.html' ? 'active' : ''}">Dashboard</a>
        <a href="admin-users.html" class="nav-tab ${currentPath === 'admin-users.html' ? 'active' : ''}">Users</a>
        <a href="admin-umkm.html" class="nav-tab ${currentPath === 'admin-umkm.html' ? 'active' : ''}">UMKM</a>
        <a href="admin-projects.html" class="nav-tab ${currentPath === 'admin-projects.html' ? 'active' : ''}">Projects</a>
    `;
  }

  navbar.innerHTML = `
    <div class="nav-inner">
      <a href="index.html" class="nav-logo" aria-label="Kembali ke Beranda Bantul Creative">
        <div class="nav-logo-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
          </svg>
        </div>
        <div class="nav-logo-text">
          <span class="brand-name">Bantul Creative</span>
          <span class="brand-sub">${role === 'admin' ? 'Admin System' : role === 'umkm' ? 'UMKM Hub' : 'Freelancer Trail'}</span>
        </div>
      </a>

      <div class="nav-tabs" role="tablist">
        ${tabsHTML}
      </div>

      <div class="nav-right">

        <div class="notif-btn-wrap">
          <button class="notif-btn" id="notif-btn" aria-label="Notifikasi" aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <span class="notif-badge" aria-label="3 notifikasi">3</span>
          </button>

          <!-- ── NOTIFICATION DROPDOWN ──────────────────── -->
          <div class="notif-dropdown" id="notif-dropdown" role="dialog" aria-label="Notifikasi" hidden>
            <div class="nd-header">
              <span class="nd-title">Notifikasi</span>
              <button class="nd-mark-all" aria-label="Tandai semua sudah dibaca">Tandai sudah dibaca</button>
            </div>
            <div class="nd-tabs" role="tablist">
              <button class="nd-tab nd-tab--active" role="tab" data-target="nd-panel-sistem">Sistem</button>
              <button class="nd-tab" role="tab" data-target="nd-panel-pesan">Pesan Klien</button>
            </div>
            <!-- Sistem panel -->
            <div class="nd-panel nd-panel--active" id="nd-panel-sistem" role="tabpanel">
              <div class="nd-list">
                <div class="nd-item nd-unread">
                  <div class="nd-item-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                  </div>
                  <div class="nd-item-body">
                    <p class="nd-item-text">Ada 2 proyek baru di Kasongan yang cocok dengan skill kamu!</p>
                    <span class="nd-item-time">5 menit lalu</span>
                  </div>
                  <div class="nd-item-dot"></div>
                </div>
                <div class="nd-item nd-unread">
                  <div class="nd-item-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  </div>
                  <div class="nd-item-body">
                    <p class="nd-item-text">Proyek "Desain Poster Festival" telah disetujui oleh admin.</p>
                    <span class="nd-item-time">1 jam lalu</span>
                  </div>
                  <div class="nd-item-dot"></div>
                </div>
                <div class="nd-item">
                  <div class="nd-item-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
                  </div>
                  <div class="nd-item-body">
                    <p class="nd-item-text">Selamat! Impact Score kamu naik ke 128 poin.</p>
                    <span class="nd-item-time">Kemarin</span>
                  </div>
                  <div class="nd-item-dot"></div>
                </div>
              </div>
            </div>
            <!-- Pesan Klien panel — diisi live dari tabel chats
                 oleh loadClientInbox() di js/app.js (bukan dummy) -->
            <div class="nd-panel" id="nd-panel-pesan" role="tabpanel">
              <div class="nd-list" id="nd-pesan-list">
                <div class="nd-item">
                  <div class="nd-item-body">
                    <p class="nd-item-text">Memuat pesan...</p>
                  </div>
                </div>
              </div>
            </div>
            <div class="nd-footer"><a href="#">Lihat semua notifikasi</a></div>
          </div><!-- /notif-dropdown -->
        </div><!-- /notif-btn-wrap -->
        <div class="user-pill-wrap">
          <div class="user-pill" id="user-pill" aria-label="Buka profil pengguna admin" role="button" tabindex="0">
            <div class="user-avatar" id="nav-user-avatar">U</div>
            <span class="user-name" id="nav-user-name">User123</span>
          </div>

          <!-- ── PROFILE MODAL ──────────────────────────── -->
          <div class="profile-modal" id="profile-modal" role="dialog" aria-modal="true" aria-label="Profil pengguna" hidden>

            <!-- Header -->
            <div class="pm-header">
              <div class="pm-avatar" id="dropdown-user-avatar">U</div>
              <div class="pm-info">
                <p class="pm-name" id="dropdown-user-name">User123</p>
                <p class="pm-email" id="dropdown-user-email">user123@gmail.com</p>
                <p class="pm-join">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  Bergabung: Jan 2026
                </p>
              </div>
            </div>

            <!-- UMKM Section -->
            <div class="pm-section" id="pm-section-umkm" style="display: none;">
              <p class="pm-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                Detail Bisnis
              </p>
              <p style="font-size: var(--fs-sm); color: var(--text-color); margin-bottom: 0.5rem;"><strong style="color:var(--text-muted);">Kategori:</strong> <span id="pm-umkm-category">—</span></p>
              <p style="font-size: var(--fs-sm); color: var(--text-color);"><strong style="color:var(--text-muted);">Lokasi:</strong> <span id="pm-umkm-location">—</span></p>
            </div>

            <!-- Section 1 — Skills -->
            <div class="pm-section" id="pm-section-skills">
              <p class="pm-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96-.46 2.5 2.5 0 0 1-2.96-3.08 3 3 0 0 1-.34-5.58 2.5 2.5 0 0 1 1.96-4.67A2.5 2.5 0 0 1 9.5 2Z"/><path d="M14.5 2A2.5 2.5 0 0 0 12 4.5v15a2.5 2.5 0 0 0 4.96-.46 2.5 2.5 0 0 0 2.96-3.08 3 3 0 0 0 .34-5.58 2.5 2.5 0 0 0-1.96-4.67A2.5 2.5 0 0 0 14.5 2Z"/></svg>
                Skill yang Dikuasai
              </p>
              <div class="pm-pills pm-pills--green" id="pm-skills-container">
                <!-- Populated dynamically -->
              </div>
            </div>

            <!-- Section 2 — Interests -->
            <div class="pm-section" id="pm-section-interests">
              <p class="pm-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                Minat &amp; Bidang
              </p>
              <div class="pm-pills pm-pills--amber" id="pm-interests-container">
                <!-- Populated dynamically -->
              </div>
            </div>

            <!-- Actions -->
            <div class="pm-actions">
              <button class="pm-btn pm-btn--outline" id="pm-settings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                Pengaturan
              </button>
              <button class="pm-btn pm-btn--outline" id="pm-ai-rec">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="8.5" cy="16" r="1.5"/><circle cx="15.5" cy="16" r="1.5"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><line x1="12" y1="3" x2="12" y2="5"/></svg>
                Lihat Rekomendasi AI
              </button>
              <button class="pm-btn pm-btn--danger" id="pm-logout">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
              </button>
            </div>

          </div><!-- /profile-modal -->
        </div><!-- /user-pill-wrap -->
      </div>
    </div>

  `;
}

document.addEventListener("DOMContentLoaded", () => {
  renderSharedNavbar();

  // Initialize modular UI components since the navbar is now in the DOM
  if (typeof window.initProfileModal === 'function') {
    window.initProfileModal();
  }
  if (typeof window.initNotificationDropdown === 'function') {
    window.initNotificationDropdown();
  }
  if (typeof window.initThemeToggle === 'function') {
    window.initThemeToggle();
  }
});

// =========================================================
// UI COMPONENTS
// =========================================================
window.renderTagSelector = function(containerId, options, selectedValues, baseClass, activeClass) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = '';
  container.className = 'sm-pill-grid';
  
  options.forEach(opt => {
    const pill = document.createElement('div');
    const isSelected = selectedValues.includes(opt);
    pill.className = `${baseClass} ${isSelected ? activeClass : ''}`;
    pill.textContent = opt;
    
    pill.addEventListener('click', () => {
      if (selectedValues.includes(opt)) {
        selectedValues.splice(selectedValues.indexOf(opt), 1);
        pill.classList.remove(activeClass);
      } else {
        selectedValues.push(opt);
        pill.classList.add(activeClass);
      }
    });
    
    container.appendChild(pill);
  });
};
