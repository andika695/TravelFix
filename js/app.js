// ============================================================
// js/app.js — DOM Rendering Logic
// ============================================================

window.formatRupiah = function(angka, prefix) {
  if (!angka) return '';
  // Remove everything except numbers and comma
  var number_string = angka.toString().replace(/[^,\d]/g, ''),
    split = number_string.split(','),
    sisa = split[0].length % 3,
    rupiah = split[0].substr(0, sisa),
    ribuan = split[0].substr(sisa).match(/\d{3}/gi);

  if (ribuan) {
    var separator = sisa ? '.' : '';
    rupiah += separator + ribuan.join('.');
  }

  // Allow only 2 digits after comma for decimals
  rupiah = split[1] !== undefined ? rupiah + ',' + split[1].slice(0, 2) : rupiah;
  return prefix === undefined ? rupiah : (rupiah ? 'Rp ' + rupiah : '');
};

window.formatDateIndo = function(dateStr) {
  if (!dateStr || dateStr === '0000-00-00') return '-';
  const parts = dateStr.split('-');
  if (parts.length === 3) {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const day = parseInt(parts[2], 10);
    const monthIdx = parseInt(parts[1], 10) - 1;
    const year = parts[0];
    if (monthIdx >= 0 && monthIdx < 12) {
      return `${day} ${months[monthIdx]} ${year}`;
    }
  }
  return dateStr;
};

window.formatCurrencyIndo = function(val) {
  if (val === undefined || val === null || val === '') return '-';
  const num = Number(val);
  if (isNaN(num) || num <= 0) return '-';
  if (num % 1 !== 0) {
    return 'Rp ' + num.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  } else {
    return 'Rp ' + num.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }
};


// ─── Theme Initialization ─────────────────────────────────
const savedTheme = localStorage.getItem('theme');
if (savedTheme) {
  document.documentElement.setAttribute('data-theme', savedTheme);
}

// Menghitung skor kecocokan freelancer utk seorang UMKM (skill 60% + kategori/
// minat 40%, dibandingkan dgn kebutuhan proyek Open milik UMKM tsb). Diekstrak
// dari renderTalentAI() supaya dipakai BERSAMA oleh halaman penuh Talent AI
// DAN widget ringkas "Rekomendasi Talenta AI" di UMKM Dashboard -- satu
// algoritma, tidak terduplikasi di dua tempat.
function computeTalentAIMatches(currentUser) {
  const allProjects = window.db ? window.db.getProjects() : [];
  const myOpenProjects = allProjects.filter(p => p.createdBy === currentUser.id && p.status === 'Open');

  // Aggregate required skills and categories from all open projects
  const neededSkills = new Set();
  const neededCats = new Set();
  if (currentUser.businessCategory) neededCats.add(currentUser.businessCategory);

  myOpenProjects.forEach(p => {
    (p.skills || []).forEach(s => neededSkills.add(s));
    (p.categories || []).forEach(c => neededCats.add(c));
  });

  const allUsers = window.db ? window.db.getUsers() : [];
  const freelancers = allUsers.filter(u => u.role === 'freelancer');

  const matches = freelancers.map(f => {
    let score = 0;
    let reasonParts = [];
    const fSkills = f.skills || [];
    const fInterests = f.interests || [];

    // 1. Skills (60%)
    let matchingSkills = [];
    if (neededSkills.size > 0) {
      matchingSkills = fSkills.filter(s => neededSkills.has(s));
      score += (matchingSkills.length / neededSkills.size) * 60;
    } else {
      score += 60;
    }

    // 2. Categories/Interests (40%)
    let matchingCats = [];
    if (neededCats.size > 0) {
      matchingCats = fInterests.filter(c => neededCats.has(c));
      score += (matchingCats.length / neededCats.size) * 40;
    } else {
      score += 40;
    }

    if (matchingSkills.length > 0) {
      reasonParts.push(`Memiliki skill ${matchingSkills.slice(0, 2).join(', ')} yang Anda butuhkan.`);
    }
    if (matchingCats.length > 0) {
      reasonParts.push(`Minat di bidang ${matchingCats[0]} sesuai dengan profil proyek Anda.`);
    }

    return {
      ...f,
      matchPercent: Math.round(score),
      matchReason: reasonParts.join(' ') || 'Profil ini cukup potensial.'
    };
  }).filter(f => f.matchPercent > 0);

  matches.sort((a, b) => b.matchPercent - a.matchPercent);
  return matches;
}

function renderTalentAI() {
  const list = document.getElementById("talent-ai-list");
  if (!list) return;

  const currentUser = window.db ? window.db.getCurrentUser() : null;
  if (!currentUser) {
    list.innerHTML = `<div class="empty-state"><p>Silakan login sebagai UMKM untuk melihat rekomendasi talenta.</p></div>`;
    return;
  }
  if (currentUser.role !== 'umkm') {
    list.innerHTML = `<div class="empty-state"><p>Talent AI hanya tersedia untuk akun UMKM.</p></div>`;
    return;
  }

  const matches = computeTalentAIMatches(currentUser);

  const countSpan = document.querySelector('.section-count');
  if (countSpan) countSpan.textContent = `${matches.length} talenta ditemukan`;
  const pillCount = document.querySelectorAll('.ai-banner-pills .ai-pill')[1];
  if (pillCount) pillCount.innerHTML = `<span class="dot"></span> ${matches.length} rekomendasi talenta`;

  if (matches.length === 0) {
    list.innerHTML = `<div class="empty-state">
      ${getIcon("search")}
      <h3>Belum ada rekomendasi talenta</h3>
      <p>Buat proyek baru terlebih dahulu agar AI dapat mencocokkan kebutuhan Anda dengan talenta kami.</p>
    </div>`;
    return;
  }

  list.innerHTML = matches.map((f) => talentAiCard(f)).join("");
}

function talentAiCard(f) {
  const skills = (f.skills || [])
    .slice(0, 4) // Show max 4
    .map((s) => `<span class="skill-tag">${getIcon("check", "icon-xs")}${escapeHtml(s)}</span>`)
    .join("");
  const initial = (f.name || "?").substring(0, 2).toUpperCase();
  const matchClass = f.matchPercent >= 85 ? "match-high" : f.matchPercent >= 75 ? "match-mid" : "match-low";

  return `
  <article class="card card-horizontal card-talentai" role="listitem">
    <div class="card-h-left">
      <div style="width:60px; height:60px; border-radius:50%; background:var(--card-bg); border: 2px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.25rem; color:var(--primary-color);">${escapeHtml(initial)}</div>
    </div>
    <div class="card-h-body">
      <div class="card-h-header">
        <div>
          <h3 class="card-title">${escapeHtml(f.name)}</h3>
          <div class="card-meta">
            ${getIcon("pin", "icon-sm")}
            <span>${escapeHtml(f.location || 'Bantul')}</span>
          </div>
        </div>
        <span class="match-badge ${matchClass}">${f.matchPercent}% Cocok</span>
      </div>
      <p class="card-desc">${escapeHtml(f.bio || f.role || 'Freelancer independen siap mengerjakan proyek Anda.')}</p>
      ${f.matchReason ? `<p style="font-size: 0.85rem; color: var(--accent-green-dk); margin-bottom: 0.75rem;">${getIcon("check", "icon-xs")} ${escapeHtml(f.matchReason)}</p>` : ''}
      <div class="card-tags skills-row">${skills}</div>
      <div class="card-footer-h">
        <button class="btn btn-outline" style="padding: 0.4rem 1rem; font-size: 0.85rem;" onclick="showFreelancerProfileModal(${f.id})">Lihat Profil</button>
      </div>
    </div>
  </article>`;
}

// Widget ringkas "Rekomendasi Talenta AI" di UMKM Dashboard -- preview 3
// teratas dari algoritma yg sama dgn halaman penuh talent-ai.html
// (computeTalentAIMatches), dirender pakai talentAiCard() yg SAMA persis
// (tombol "Lihat Profil"-nya sudah terhubung ke showFreelancerProfileModal(),
// modal + riwayat proyek yg SAMA dgn Talent AI) -- tidak ada markup/logika
// baru yg terduplikasi. No-op kalau elemen widget tidak ada di halaman ini.
function renderUMKMTalentWidget() {
  const section = document.getElementById("umkm-talent-reco-section");
  const grid = document.getElementById("umkm-talent-reco-grid");
  if (!section || !grid) return;

  const currentUser = window.db ? window.db.getCurrentUser() : null;
  if (!currentUser || currentUser.role !== 'umkm') return;

  const matches = computeTalentAIMatches(currentUser).slice(0, 3);
  if (matches.length === 0) return; // tetap tersembunyi, sama seperti ai-reco-section di marketplace.html

  const countEl = document.getElementById("umkm-talent-reco-count");
  if (countEl) countEl.textContent = `${matches.length} rekomendasi teratas`;
  section.style.display = "";
  grid.innerHTML = matches.map((f) => talentAiCard(f)).join("");
}

document.addEventListener("DOMContentLoaded", () => {
  initThemeToggle();
  highlightActiveNav();
  initSkillModal();
  initDetailModals();
  initNotificationDropdown();
  initLocationPickerModal(); // no-op kalau #location-picker-modal tidak ada di halaman ini
  initLogoutLinks();
  const page = window.location.pathname.split("/").pop() || "";

  function renderCurrentPage() {
    if (page === "marketplace.html") {
      renderMarketplace();
      loadAiRecommendations();
    } else if (page === "ai-match.html") {
      renderAIMatch();
    } else if (page === "trail-map.html") {
      renderTrailMap();
      initTrailLeafletMap();
    } else if (page === "portfolio.html") {
      renderPortfolio();
    } else if (page === "umkm-dashboard.html") {
      renderUMKMDashboard();
    } else if (page === "umkm-projects.html") {
      renderUMKMProjects();
    } else if (page === "talent-ai.html") {
      renderTalentAI();
    } else if (page === "admin-dashboard.html") {
      renderAdminDashboardChart();
    }
  }

  // Tunggu data dari server (fetch /api/*.php) selesai dimuat sebelum
  // merender halaman, agar proyek/pengguna dari database langsung tampil.
  if (window.db && typeof window.db.whenReady === "function") {
    window.db.whenReady().then(renderCurrentPage);
  } else {
    renderCurrentPage();
  }
});

// ─── Theme Toggle Logic ───────────────────────────────────
function initThemeToggle() {
  const toggleBtns = document.querySelectorAll('.theme-toggle-btn');
  toggleBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
      const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
      
      document.documentElement.setAttribute('data-theme', newTheme);
      localStorage.setItem('theme', newTheme);
    });
  });
}

// ─── Active Nav Highlight ─────────────────────────────────
function highlightActiveNav() {
  const path = window.location.pathname.split("/").pop() || "";
  const links = document.querySelectorAll(".nav-tab");
  links.forEach((link) => {
    const hrefAttr = link.getAttribute("href");
    if (!hrefAttr) return; // .nav-tab non-<a> (mis. tombol filter) tidak relevan di sini
    const href = hrefAttr.split("/").pop();
    const isMarket = (href === "marketplace.html") && (path === "marketplace.html");
    if (href === path || isMarket) {
      link.classList.add("active");
    }
  });
}

// ─── Logout (sidebar Admin, dst.) ──────────────────────────
// Sebelumnya tombol/link Logout cuma window.location.href = 'login.html'
// TANPA menghapus sessionStorage ATAU session PHP di server -- "logout"
// jadi tidak benar-benar keluar. Dipasang lewat delegasi supaya berlaku
// untuk semua link berclass .js-logout-link di halaman manapun.
function initLogoutLinks() {
  document.querySelectorAll(".js-logout-link").forEach((link) => {
    link.addEventListener("click", (e) => {
      e.preventDefault();
      const href = link.getAttribute("href") || "login.html";
      const API_BASE = (window.db && window.db.API_BASE) || "api";
      fetch(`${API_BASE}/logout.php`, { method: "POST" })
        .catch(() => {})
        .finally(() => {
          if (window.db && typeof window.db.logout === "function") {
            window.db.logout();
          } else {
            sessionStorage.removeItem("currentUser");
          }
          window.location.href = href;
        });
    });
  });
}

// ─── SVG Icons ───────────────────────────────────────────
const icons = {
  puzzle: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 14.5a2 2 0 0 0 0-4V8a2 2 0 0 0-2-2h-2.5a2 2 0 0 0-4 0H9.5a2 2 0 0 0-2 2v2.5a2 2 0 0 0 0 4V17a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-2.5z"/></svg>`,
  camera: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>`,
  globe: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>`,
  megaphone: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>`,
  palette: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>`,
  music: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>`,
  pin: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>`,
  calendar: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`,
  users: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
  check: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
  star: `<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`,
  chart: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>`,
  video: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>`,
  "shopping-bag": `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>`,
  "map-pin": `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>`,
  "trending-up": `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>`,
  package: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>`,
  dollar: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>`,
  eye: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`,
  "shopping-cart": `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>`,
  download: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`,
  map: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>`,
  award: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>`,
  pottery: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2h8l2 6H6L8 2z"/><path d="M6 8c0 6 2 10 6 12 4-2 6-6 6-12"/><line x1="9" y1="12" x2="15" y2="12"/></svg>`,
  waves: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6c.6.5 1.2 1 2.4 1C7 7 7 5 9.6 5c2.4 0 2.4 2 4.8 2 2.4 0 2.4-2 4.8-2 1.2 0 1.8.5 2.4 1"/><path d="M2 12c.6.5 1.2 1 2.4 1 2.6 0 2.6-2 5.2-2 2.4 0 2.4 2 4.8 2 2.4 0 2.4-2 4.8-2 1.2 0 1.8.5 2.4 1"/><path d="M2 18c.6.5 1.2 1 2.4 1 2.6 0 2.6-2 5.2-2 2.4 0 2.4 2 4.8 2 2.4 0 2.4-2 4.8-2 1.2 0 1.8.5 2.4 1"/></svg>`,
  bag: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>`,
  fabric: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`,
  mountain: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 20 15.5 4 22 20 3 20"/><polyline points="3 20 9 12 13 16"/></svg>`,
  "music-note": `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>`,
};

function getIcon(name, cls = "") {
  return `<span class="icon ${cls}">${icons[name] || icons["puzzle"]}</span>`;
}

// Kelas badge untuk sistem status proyek baru
// (Open / In Progress / Submitted / Completed / Closed + status lamaran)
function statusBadgeClass(status) {
  if (status === "Open" || status === "Approved" || status === "Completed") return "badge-open";
  if (status === "In Review" || status === "In Progress" || status === "Submitted") return "badge-review";
  return "badge-closed"; // Closed, Rejected, Done, dll.
}

function renderStars(rating) {
  const full = Math.floor(rating);
  const half = rating % 1 >= 0.5;
  let html = "";
  for (let i = 0; i < 5; i++) {
    if (i < full) html += `<span class="star filled">${icons.star}</span>`;
    else if (i === full && half) html += `<span class="star half">${icons.star}</span>`;
    else html += `<span class="star empty">${icons.star}</span>`;
  }
  return `<div class="stars-row">${html}</div>`;
}

// ─── Page: Challenge Marketplace (index.html) ──────────────
// Peta tombol filter (data-status) -> status ENUM asli, dipakai fallback
// mode file:// (tanpa server) supaya perilakunya sama persis dengan yang
// dihitung server di api/get_marketplace_projects.php.
const MARKETPLACE_STATUS_BUCKETS = {
  open: ["Open"],
  review: ["In Progress", "Submitted"],
  close: ["Completed", "Closed"],
};

function renderMarketplace() {
  const grid = document.getElementById("marketplace-grid");
  if (!grid) return;

  loadProfileCompletionCard(
    "freelancer-profile-completion-card",
    "freelancer",
    "Halo! Profil Anda belum lengkap. Lengkapi profil Anda agar dapat direkomendasikan secara maksimal oleh sistem AI kami."
  );

  const searchInput = document.getElementById("search-input");
  const categoryFilter = document.getElementById("category-filter");
  const statusTabs = document.getElementById("status-filter-tabs");
  const countEl = document.getElementById("marketplace-count");

  if (categoryFilter && categoryFilter.options.length <= 1) {
    const options = window.SKILL_OPTIONS || [];
    options.forEach(opt => {
      const option = document.createElement("option");
      option.value = opt;
      option.textContent = opt;
      categoryFilter.appendChild(option);
    });
  }

  // Daftar proyek dari status yang lagi aktif dipilih (tab). Diisi ulang
  // setiap kali tab diklik — search & kategori cuma menyaring hasil ini
  // di sisi klien, TIDAK memicu fetch baru.
  let currentProjects = [];

  function doRender() {
    const query = searchInput ? searchInput.value.toLowerCase() : "";
    const category = categoryFilter ? categoryFilter.value : "";

    let filtered = currentProjects.filter((p) => {
      const matchQ = p.title.toLowerCase().includes(query) || p.description.toLowerCase().includes(query);
      const matchC = !category || p.categories.includes(category);
      return matchQ && matchC;
    });

    if (countEl) countEl.textContent = `${filtered.length} proyek tersedia`;

    grid.innerHTML = filtered.length
      ? filtered.map((p) => marketplaceCard(p)).join("")
      : `<div class="empty-state"><p>Tidak ada proyek yang cocok dengan filter ini.</p></div>`;
  }

  // status: 'all' | 'open' | 'review' | 'close' — dikirim persis ke
  // ?status= di api/get_marketplace_projects.php.
  function loadMarketplaceProjects(status) {
    const isServer = window.location.protocol === "http:" || window.location.protocol === "https:";

    if (!isServer) {
      // Mode file:// tanpa server — fallback ke cache lokal, filter status di klien
      const all = window.db ? db.getProjects() : [];
      const allowedStatuses = MARKETPLACE_STATUS_BUCKETS[status];
      currentProjects = allowedStatuses
        ? all.filter((p) => allowedStatuses.includes(p.status))
        : all;
      doRender();
      return;
    }

    grid.innerHTML = `<div class="empty-state"><p>Memuat proyek...</p></div>`;
    const API_BASE = (window.db && window.db.API_BASE) || "api";

    fetchJsonSafe(`${API_BASE}/get_marketplace_projects.php?status=${encodeURIComponent(status)}`)
      .then((res) => {
        const rows = res.data || [];
        currentProjects = window.db && typeof window.db.mapProjectRow === "function"
          ? rows.map(window.db.mapProjectRow)
          : rows;
        doRender();
      })
      .catch((err) => {
        console.error(err);
        grid.innerHTML = `<div class="empty-state"><p>Gagal memuat proyek. Buka Console (F12) untuk detail error.</p></div>`;
      });
  }

  if (searchInput) searchInput.addEventListener("input", doRender);
  if (categoryFilter) categoryFilter.addEventListener("change", doRender);

  if (statusTabs) {
    statusTabs.addEventListener("click", (e) => {
      const tab = e.target.closest(".status-tab");
      if (!tab || !statusTabs.contains(tab)) return;

      statusTabs.querySelectorAll(".status-tab").forEach((t) => {
        t.classList.remove("active");
        t.setAttribute("aria-selected", "false");
      });
      tab.classList.add("active");
      tab.setAttribute("aria-selected", "true");

      loadMarketplaceProjects(tab.dataset.status);
    });
  }

  loadMarketplaceProjects("all");
  openProjectFromUrlParam();
}

// Buka modal detail proyek otomatis kalau URL mengandung ?id=N — dipakai
// tautan "Lihat Detail Proyek" pada popup marker Creative Trail Map.
// Diambil langsung dari api/projects.php (bukan dari daftar "openProjects"
// yang sudah difilter status Open saja), supaya proyek yang statusnya sudah
// In Progress/Submitted pun tetap bisa dibuka detailnya lewat tautan ini.
function openProjectFromUrlParam() {
  const id = new URLSearchParams(window.location.search).get("id");
  if (!id) return;

  // Catatan: api/projects.php memakai format respons lama {success:bool},
  // BUKAN {status:'success'} — jadi dipanggil dengan fetch() biasa, bukan
  // fetchJsonSafe() (yang mewajibkan format baru).
  const API_BASE = (window.db && window.db.API_BASE) || "api";
  fetch(`${API_BASE}/projects.php?id=${encodeURIComponent(id)}`)
    .then((res) => res.json())
    .then((json) => {
      if (!json.success || !json.project) return;
      const data = (window.db && typeof window.db.mapProjectRow === "function")
        ? window.db.mapProjectRow(json.project)
        : json.project;
      if (typeof populateAndOpenProjectModal === "function") {
        populateAndOpenProjectModal(data, window.openModal);
      }
    })
    .catch((err) => {
      console.error("[marketplace] Gagal memuat detail proyek dari tautan:", err);
    });
}

// ─── Page: Admin Dashboard (admin-dashboard.html) ─────────
function hexToRgba(hex, alpha) {
  const h = (hex || "").trim().replace("#", "");
  if (!/^([0-9a-f]{3}|[0-9a-f]{6})$/i.test(h)) return `rgba(45, 106, 79, ${alpha})`;
  const full = h.length === 3 ? h.split("").map((c) => c + c).join("") : h;
  const bigint = parseInt(full, 16);
  const r = (bigint >> 16) & 255;
  const g = (bigint >> 8) & 255;
  const b = bigint & 255;
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function renderAdminDashboardChart() {
  const canvas = document.getElementById("growthChart");
  if (!canvas) return;

  const API_BASE = (window.db && window.db.API_BASE) || (function () {
    const path = window.location.pathname;
    const dir = path.substring(0, path.lastIndexOf("/") + 1);
    return dir + "api";
  })();

  // Catatan: kartu statistik (USER TOTAL, PROYEK AKTIF, dst.) sudah diperbarui
  // secara dinamis oleh renderAdminStats() — fungsi ini hanya bertanggung
  // jawab merender grafik pertumbuhan agar tidak ada dua kode yang menulis
  // ke elemen DOM yang sama dengan data/label yang berbeda.
  // Rentang tanggal mengikuti date picker di header (jika diisi).
  const rangeQuery = (typeof adminDateRangeQuery === 'function') ? adminDateRangeQuery() : '';
  fetch(`${API_BASE}/dashboard_stats.php${rangeQuery}`)
    .then((r) => r.json())
    .then((data) => {
      if (!data.success) return;

      // Widget "Aktivitas Terbaru" / "5 Proyek Terbaru" / "Kategori Proyek
      // Populer" -- dari response yg sama, tidak tergantung Chart.js jadi
      // dirender duluan sebelum guard di bawah.
      renderAdminRecentActivity(data.recentActivity);
      renderAdminRecentProjectsTable(data.recentProjects);
      renderAdminTopCategories(data.topCategories);

      if (typeof Chart === "undefined") return;

      const styles = getComputedStyle(document.documentElement);
      const green = styles.getPropertyValue("--accent-green").trim() || "#2D6A4F";
      const greenMid = styles.getPropertyValue("--accent-green-mid").trim() || "#52B788";
      const muted = styles.getPropertyValue("--text-muted").trim() || "#6C757D";
      const border = styles.getPropertyValue("--border-color").trim() || "#E5E5E5";

      // Buang instance chart sebelumnya jika ada (mis. saat toggle tema/re-render)
      const existing = Chart.getChart(canvas);
      if (existing) existing.destroy();

      new Chart(canvas.getContext("2d"), {
        type: "line",
        data: {
          labels: data.labels,
          datasets: [
            {
              label: "Pengguna Baru",
              data: data.users,
              borderColor: green,
              backgroundColor: hexToRgba(green, 0.15),
              tension: 0.4,
              fill: true,
              borderWidth: 3,
              pointRadius: 4,
              pointBackgroundColor: "#fff",
              pointBorderColor: green,
              pointBorderWidth: 2,
              pointHoverRadius: 6,
            },
            {
              label: "Proyek Baru",
              data: data.projects,
              borderColor: greenMid,
              backgroundColor: hexToRgba(greenMid, 0.1),
              tension: 0.4,
              fill: true,
              borderWidth: 3,
              pointRadius: 4,
              pointBackgroundColor: "#fff",
              pointBorderColor: greenMid,
              pointBorderWidth: 2,
              pointHoverRadius: 6,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: 1200, easing: "easeOutQuart" },
          interaction: { mode: "index", intersect: false },
          plugins: {
            legend: {
              display: true,
              position: "top",
              align: "end",
              labels: { boxWidth: 10, usePointStyle: true, color: muted, font: { size: 11, weight: "600" } },
            },
            tooltip: { backgroundColor: "#1B4332", padding: 10, cornerRadius: 8 },
          },
          scales: {
            x: { grid: { display: false }, ticks: { color: muted, font: { size: 11, weight: "600" } } },
            y: {
              beginAtZero: true,
              grid: { color: border, borderDash: [4, 4] },
              ticks: { color: muted, precision: 0 },
            },
          },
        },
      });
    })
    .catch((e) => console.error("[admin-dashboard] Gagal memuat statistik:", e));
}

// Ikon + warna per jenis aktivitas ("Aktivitas Terbaru") -- user ikon orang
// (dipakai jg di sidebar nav "Semua Pengguna"), proyek ikon koper (dipakai jg
// di sidebar nav "Semua Proyek"), supaya bahasa visualnya konsisten.
function adminActivityIcon(type) {
  if (type === "project_created") {
    return {
      cls: "activity-project",
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
    };
  }
  return {
    cls: "activity-user",
    svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  };
}

// Panel kanan grafik: gabungan 5 pendaftaran + 5 proyek terbaru (sudah
// digabung & diurutkan di backend, lihat api/dashboard_stats.php).
function renderAdminRecentActivity(items) {
  const container = document.getElementById("admin-recent-activity-list");
  if (!container) return;

  if (!items || items.length === 0) {
    container.innerHTML = '<p style="color:var(--text-muted); font-size:var(--fs-sm);">Belum ada aktivitas.</p>';
    return;
  }

  container.innerHTML = items.map((item) => {
    const icon = adminActivityIcon(item.type);
    return `
      <div class="admin-activity-item">
        <div class="admin-activity-icon ${icon.cls}">${icon.svg}</div>
        <div class="admin-activity-info">
          <div class="admin-activity-title">${escapeHtml(item.title)}</div>
          <div class="admin-activity-time">${timeAgo(item.created_at)}</div>
        </div>
      </div>
    `;
  }).join("");
}

// Panel kiri-bawah: tabel 5 proyek terbaru. Reuse statusBadgeClass()/.admin-badge
// yg sama persis dipakai renderAdminProjects() (halaman Semua Proyek) supaya
// warna status konsisten di seluruh admin panel.
function renderAdminRecentProjectsTable(items) {
  const tbody = document.getElementById("admin-recent-projects-table");
  if (!tbody) return;

  if (!items || items.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:1rem;">Belum ada proyek.</td></tr>';
    return;
  }

  tbody.innerHTML = items.map((p) => {
    const badgeClass = statusBadgeClass(p.status);
    const budget = window.formatCurrencyIndo ? window.formatCurrencyIndo(p.budget) : `Rp ${p.budget}`;
    return `
      <tr>
        <td><strong>${escapeHtml(p.title)}</strong></td>
        <td>${escapeHtml(p.creator_name)}</td>
        <td>${budget}</td>
        <td><span class="admin-badge ${badgeClass}">${escapeHtml(p.status)}</span></td>
      </tr>
    `;
  }).join("");
}

// Panel kanan-bawah: top 5 kategori proyek (project_categories, GROUP BY di
// backend) dgn peringkat 1-5 + jumlah proyek per kategori.
function renderAdminTopCategories(items) {
  const list = document.getElementById("admin-top-categories-list");
  if (!list) return;

  if (!items || items.length === 0) {
    list.innerHTML = '<li class="admin-list-group-item" style="border-bottom:none; color:var(--text-muted); font-size:var(--fs-sm);">Belum ada data kategori.</li>';
    return;
  }

  list.innerHTML = items.map((c, idx) => `
    <li class="admin-list-group-item">
      <span class="admin-list-group-rank">${idx + 1}</span>
      <span class="admin-list-group-label">${escapeHtml(c.category)}</span>
      <span class="admin-list-group-count">${Number(c.total)} proyek</span>
    </li>
  `).join("");
}

// Tombol aksi kanan-bawah card Marketplace, menyesuaikan status proyek supaya
// freelancer tidak salah tekan (Open = bisa lamar, selain itu = disabled).
function marketplaceActionButton(p) {
  const currentUser = window.db ? db.getCurrentUser() : null;
  const applicants = Array.isArray(p.applicants) ? p.applicants : [];
  const alreadyApplied = !!(currentUser && applicants.map(String).includes(String(currentUser.id)));

  if (p.status === "Open") {
    if (alreadyApplied) {
      return `<button type="button" class="btn btn-sm" disabled>Sudah Dilamar</button>`;
    }
    return `<button type="button" class="btn btn-primary btn-sm btn-marketplace-apply" data-project-id="${p.id}">Lamar Sekarang</button>`;
  }

  if (p.status === "In Progress" || p.status === "Submitted") {
    return `<button type="button" class="btn btn-sm" disabled>Sedang Direview</button>`;
  }

  // Completed / Closed (atau status lain di luar 4 di atas) -> arsip/referensi
  return `<button type="button" class="btn btn-sm" disabled>Proyek Selesai</button>`;
}

function marketplaceCard(p) {
  const statusClass = statusBadgeClass(p.status);
  const tags = p.categories.map((c) => `<span class="tag">${escapeHtml(c)}</span>`).join("");

  const formattedPrize = (p.prize && p.prize.toString().startsWith('Rp'))
    ? p.prize
    : (window.formatCurrencyIndo ? window.formatCurrencyIndo(p.prize) : p.prize);
  const formattedDeadline = window.formatDateIndo ? window.formatDateIndo(p.deadline) : p.deadline;

  return `
  <article class="card card-marketplace" data-id="${p.id}" role="listitem">
    <div class="card-top">
      <div class="card-icon-wrap">${getIcon(p.icon)}</div>
      <span class="badge ${statusClass}">${escapeHtml(p.status)}</span>
    </div>
    <h3 class="card-title">${escapeHtml(p.title)}</h3>
    <p class="card-desc">${escapeHtml(p.description)}</p>
    <div class="card-meta">
      ${getIcon("pin", "icon-sm")}
      <span>${escapeHtml(p.location)}</span>
    </div>
    <div class="card-tags">${tags}</div>
    <div class="card-footer">
      <div class="card-footer-info">
        ${getIcon("calendar", "icon-sm")}
        <span>${formattedDeadline}</span>
        <span class="prize-label">${formattedPrize}</span>
      </div>
      <div class="card-footer-actions">
        <button type="button" class="btn btn-outline btn-sm btn-marketplace-detail">Lihat Detail</button>
        ${marketplaceActionButton(p)}
      </div>
    </div>
  </article>`;
}

// ── AI Proximity & Skill Matcher — "Rekomendasi Proyek Pintar" ────────
// Widget di marketplace.html (dashboard freelancer): fetch ke
// api/ai_talent_match.php saat halaman dimuat, render max 5 kartu proyek
// terurut dari skor kecocokan tertinggi (gabungan skill + jarak).
function aiRecoCard(p) {
  const tags = (p.categories || []).map((c) => `<span class="tag">${escapeHtml(c)}</span>`).join("");
  const formattedPrize = window.formatCurrencyIndo ? window.formatCurrencyIndo(p.budget) : `Rp ${p.budget}`;
  const formattedDeadline = window.formatDateIndo ? window.formatDateIndo(p.deadline) : p.deadline;

  return `
  <article class="card card-ai-reco" data-id="${Number(p.project_id)}" role="listitem">
    <div class="card-top">
      <div class="card-icon-wrap">${getIcon(p.icon)}</div>
      <span class="badge ai-reco-match-badge">${Number(p.persentase_cocok)}% Cocok</span>
    </div>
    <h3 class="card-title">${escapeHtml(p.title)}</h3>
    <p class="card-desc">${escapeHtml(p.description || "")}</p>
    <div class="card-meta">
      ${getIcon("pin", "icon-sm")}
      <span>${escapeHtml(p.location)} · ${escapeHtml(p.umkm_name)}</span>
    </div>
    <p class="ai-reco-distance">
      ${getIcon("pin", "icon-sm")} Berjarak ${Number(p.jarak_km)} KM dari lokasi Anda
    </p>
    <div class="card-tags">${tags}</div>
    <div class="card-footer">
      <div class="card-footer-info">
        ${getIcon("calendar", "icon-sm")}
        <span>${formattedDeadline}</span>
        <span class="prize-label">${formattedPrize}</span>
      </div>
      <button type="button" class="btn btn-primary btn-sm btn-ai-reco-apply" data-project-id="${Number(p.project_id)}">Lamar Sekarang</button>
    </div>
  </article>`;
}

function loadAiRecommendations() {
  const section = document.getElementById("ai-reco-section");
  const grid = document.getElementById("ai-reco-grid");
  if (!section || !grid) return;

  const currentUser = window.db ? db.getCurrentUser() : null;
  const isServer = window.location.protocol === "http:" || window.location.protocol === "https:";

  // Widget khusus freelancer yang sedang login & berjalan lewat server (API MySQL)
  if (!currentUser || currentUser.role !== "freelancer" || !isServer) return;

  const countEl = document.getElementById("ai-reco-count");
  const API_BASE = (window.db && window.db.API_BASE) || "api";

  fetch(`${API_BASE}/ai_talent_match.php?freelancer_id=${encodeURIComponent(currentUser.id)}`)
    .then((res) => {
      if (!res.ok) throw new Error("Server bermasalah (Status " + res.status + ")");
      return res.json();
    })
    .then((json) => {
      if (json.status !== "success") {
        // Freelancer belum atur lokasi — ajak lengkapi profil, bukan cuma disembunyikan
        if (json.code === "location_missing") {
          section.style.display = "";
          if (countEl) countEl.textContent = "";
          grid.innerHTML = `
            <div class="empty-state" style="grid-column: 1 / -1;">
              <p>${escapeHtml(json.message)}</p>
              <a class="dm-btn-primary" href="profile.html" style="margin-top: 15px; display: inline-block;">Lengkapi Profil Saya</a>
            </div>`;
        }
        return;
      }

      const projects = json.data || [];
      if (!projects.length) return; // tidak ada rekomendasi cocok -> widget tetap tersembunyi

      section.style.display = "";
      if (countEl) countEl.textContent = `${projects.length} rekomendasi untuk Anda`;
      grid.innerHTML = projects.map(aiRecoCard).join("");
    })
    .catch((err) => {
      console.error("[ai-reco] Gagal memuat rekomendasi AI:", err);
    });
}
window.loadAiRecommendations = loadAiRecommendations;

// ─── Page: Freelancer Projects (freelancer-projects.html) ──────────────
function renderMyProjects() {
  const grid = document.getElementById("my-projects-grid");
  const countEl = document.getElementById("my-projects-count");
  if (!grid) return;

  const searchInput = document.getElementById("search-input");
  const statusFilter = document.getElementById("status-filter");
  const categoryFilter = document.getElementById("category-filter");
  
  if (categoryFilter && categoryFilter.options.length <= 1) {
    const options = window.SKILL_OPTIONS || [];
    options.forEach(opt => {
      const option = document.createElement("option");
      option.value = opt;
      option.textContent = opt;
      categoryFilter.appendChild(option);
    });
  }
  
  const currentUser = window.db ? db.getCurrentUser() : null;

  if (!currentUser) {
    grid.innerHTML = `<div class="empty-state"><p>Silakan login terlebih dahulu.</p></div>`;
    if (countEl) countEl.textContent = `0 proyek dilamar`;
    return;
  }

  // Label status diturunkan dari application_status + project_status baru:
  //   Pending  → 'In Review'
  //   Ditolak  → 'Rejected'
  //   Diterima → mengikuti project_status ('In Progress' / 'Submitted' /
  //              'Completed' / 'Closed')
  function mapStatusForFreelancer(a) {
    if (a.application_status === 'Diterima') {
      const ps = a.project_status;
      if (ps === 'In Progress' || ps === 'Submitted' || ps === 'Completed' || ps === 'Closed') {
        return ps;
      }
      return 'Approved';
    }
    if (a.application_status === 'Ditolak') return 'Rejected';
    return 'In Review'; // Pending
  }

  let applications = [];

  function doRender() {
    const query = searchInput ? searchInput.value.toLowerCase() : "";
    const status = statusFilter ? statusFilter.value : "";
    const category = categoryFilter ? categoryFilter.value : "";

    let filtered = applications.filter((a) => {
      const mappedStatus = mapStatusForFreelancer(a);
      const matchQ = (a.title || "").toLowerCase().includes(query) || (a.description || "").toLowerCase().includes(query);
      const matchS = !status || mappedStatus === status;
      const matchC = !category || (a.categories || []).includes(category);
      return matchQ && matchS && matchC;
    });

    if (countEl) countEl.textContent = `${filtered.length} proyek dilamar`;

    grid.innerHTML = filtered.length
      ? filtered.map((a) => myProjectCard(a, mapStatusForFreelancer(a))).join("")
      : `<div class="empty-state">
           <p>Belum ada proyek yang sesuai atau dilamar.</p>
           <button class="dm-btn-primary" onclick="window.location.href='marketplace.html'" style="margin-top: 15px;">Browse Marketplace</button>
         </div>`;
  }

  function loadApplications() {
    const isServer = window.location.protocol === "http:" || window.location.protocol === "https:";

    if (!isServer) {
      // Mode file:// tanpa server — fallback ke cache lokal (perkiraan status
      // lamaran dari status proyek, karena tidak ada endpoint untuk di-fetch)
      applications = db.getAppliedProjects(currentUser.id).map((p) => ({
        project_id: p.id,
        title: p.title,
        description: p.description,
        budget: p.prize,
        deadline: p.deadline,
        icon: p.icon,
        location: p.location,
        categories: p.categories,
        project_status: p.status,
        application_status: p.assignedTo === currentUser.id ? "Diterima" : "Pending",
      }));
      doRender();
      return;
    }

    grid.innerHTML = `<div class="empty-state"><p>Memuat proyek...</p></div>`;
    const API_BASE = (window.db && window.db.API_BASE) || "api";

    fetchJsonSafe(`${API_BASE}/get_freelancer_applications.php?freelancer_id=${encodeURIComponent(currentUser.id)}`)
      .then((res) => {
        applications = res.data || [];
        doRender();
      })
      .catch((err) => {
        console.error(err);
        grid.innerHTML = `<div class="empty-state"><p>Gagal memuat proyek. Buka Console (F12) untuk detail error.</p></div>`;
      });
  }

  if (searchInput) searchInput.addEventListener("input", doRender);
  if (statusFilter) statusFilter.addEventListener("change", doRender);
  if (categoryFilter) categoryFilter.addEventListener("change", doRender);

  loadApplications();
}

function applicationToCardData(a, displayStatus) {
  return {
    id: a.project_id,
    title: a.title,
    description: a.description || "",
    prize: a.budget || "",
    deadline: a.deadline || "",
    status: displayStatus,
    icon: a.icon || "puzzle",
    location: a.location || "",
    categories: a.categories || [],
  };
}

// Kartu "Proyek Saya" (freelancer) — marketplaceCard + baris tombol aksi:
//   In Progress → [Submit Proyek] [Chat Client]
//   Submitted   → [Chat Client]
//   Completed   → (tanpa tombol chat — diskusi ditutup setelah UMKM menerima hasil)
function myProjectCard(a, displayStatus) {
  const cardHtml = marketplaceCard(applicationToCardData(a, displayStatus));
  const client = escapeHtml(a.creator_business_name || a.creator_name || 'Client');

  const buttons = [];
  if (displayStatus === 'In Progress') {
    buttons.push(
      `<button type="button" class="btn btn-primary btn-sm btn-submit-project" data-project-id="${Number(a.project_id)}">Submit Proyek</button>`
    );
  }
  if (displayStatus === 'Submitted') {
    buttons.push(
      `<span style="font-size:0.8rem; color: var(--text-muted); align-self:center;">Menunggu review client…</span>`
    );
  }
  if (displayStatus === 'In Progress' || displayStatus === 'Submitted') {
    buttons.push(
      `<button type="button" class="btn btn-outline btn-sm btn-chat-project" data-project-id="${Number(a.project_id)}" data-partner="${client}">Chat Client</button>`
    );
  }

  if (!buttons.length) return cardHtml;

  const actionsRow = `
    <div class="card-actions-row" style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid var(--border-color);">
      ${buttons.join('')}
    </div>
  </article>`;

  // Sisipkan baris aksi tepat sebelum penutup </article>
  return cardHtml.replace(/<\/article>\s*$/, actionsRow);
}

// ─── Progress Bar Kelengkapan Profil (UMKM & Freelancer) ──────────────────
// Field wajib berbeda per role -- UMKM: Nama, Lokasi Peta, Email Publik, WA.
// Freelancer: Nama, Lokasi Peta, Skill, Portofolio, Email Publik, WA. Dipakai
// bersama oleh renderUMKMDashboard() (umkm-dashboard.html) dan
// renderMarketplace() ("dashboard" freelancer setelah login, marketplace.html)
// supaya logika & markup kelengkapan tidak terduplikasi.
//
// `profile` adalah bentuk MENTAH api/me.php (snake_case: business_name,
// public_email, whatsapp, portfolio_url, skills[]) -- sengaja BUKAN
// sessionStorage.currentUser, yang di-cache per-tab dan bisa basi kalau
// browser yang sama dipakai login sebagai akun lain di tab lain (lihat
// catatan session-mismatch di initCreateProjectModal()).
function computeProfileCompletion(profile, role) {
  const nameValue = role === 'umkm' ? profile.business_name : profile.name;
  const hasLocation = !!(profile.location && profile.latitude != null && profile.longitude != null);

  const checks = [
    !!String(nameValue || '').trim(),
    hasLocation,
    !!String(profile.public_email || '').trim(),
    !!String(profile.whatsapp || '').trim(),
  ];

  if (role === 'freelancer') {
    checks.push(Array.isArray(profile.skills) && profile.skills.length > 0);
    checks.push(!!String(profile.portfolio_url || '').trim());
  }

  const doneCount = checks.filter(Boolean).length;
  return {
    percent: Math.round((doneCount / checks.length) * 100),
    isComplete: doneCount === checks.length,
  };
}

// Render (atau sembunyikan total kalau sudah 100%) card progress bar ke
// dalam containerId. Dipakai project ini TIDAK memakai Bootstrap sama sekali
// (dikonfirmasi grep -- semua modal/komponen custom, lihat .dm-*/.pm-*/.sm-*
// di css/components.css), jadi progress bar reuse .sm-progress-bar/
// .sm-progress-fill yang sudah ada (dipakai modal "Update Skill & Minat"),
// bukan komponen Bootstrap baru.
function renderProfileCompletionCard(containerId, completion, warningText) {
  const container = document.getElementById(containerId);
  if (!container) return;

  if (completion.isComplete) {
    container.hidden = true;
    container.innerHTML = '';
    return;
  }

  container.hidden = false;
  container.innerHTML = `
    <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.25rem 1.5rem;">
      <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:0.6rem; gap:0.5rem; flex-wrap:wrap;">
        <strong style="font-size: var(--fs-md); color: var(--text-main);">Kelengkapan Profil</strong>
        <span style="font-weight:800; color: var(--accent-green);">${completion.percent}%</span>
      </div>
      <div class="sm-progress-bar" role="progressbar" aria-valuenow="${completion.percent}" aria-valuemin="0" aria-valuemax="100" aria-label="Kelengkapan profil ${completion.percent} persen">
        <div class="sm-progress-fill" style="width:${completion.percent}%;"></div>
      </div>
      <p style="margin: 0.9rem 0 1rem; color: var(--color-review); background: var(--color-review-bg); padding: 0.6rem 0.85rem; border-radius: var(--radius-md); font-size: var(--fs-sm); line-height:1.5;">${escapeHtml(warningText)}</p>
      <a href="profile.html" class="btn btn-primary btn-sm">Lengkapi Profil Sekarang</a>
    </div>
  `;
}

// Fetch api/me.php (identitas session PHP AKTIF, bukan cache/sessionStorage
// -- lihat catatan di atas) lalu render card kelengkapan ke containerId.
// No-op diam-diam kalau elemen containerId tidak ada di halaman ini, mode
// file:// tanpa server, belum login, atau role sesi aktif tidak cocok
// dengan `role` yang diharapkan (mis. UMKM nyasar ke halaman freelancer di
// tab lain -- jangan tampilkan card yang salah, cukup sembunyikan).
function loadProfileCompletionCard(containerId, role, warningText) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const isServer = window.location.protocol === 'http:' || window.location.protocol === 'https:';
  if (!isServer) { container.hidden = true; return; }

  const API_BASE = (window.db && window.db.API_BASE) || 'api';
  fetch(`${API_BASE}/me.php`)
    .then((res) => (res.ok ? res.json() : null))
    .then((json) => {
      if (!json || json.status !== 'success' || !json.data || json.data.role !== role) {
        container.hidden = true;
        return;
      }
      renderProfileCompletionCard(containerId, computeProfileCompletion(json.data, role), warningText);
    })
    .catch((err) => {
      console.error('[profile-completion] Gagal memuat status kelengkapan profil:', err);
      container.hidden = true;
    });
}

// ─── Page: UMKM Dashboard (umkm-dashboard.html) ───────────────────
function renderUMKMDashboard() {
  const currentUser = window.db ? db.getCurrentUser() : null;
  if (!currentUser) return;

  const nameEl = document.getElementById("umkm-user-name");
  if (nameEl) nameEl.textContent = currentUser.name;

  // Independen dari status "Recent Projects" di bawah (termasuk early-return
  // saat UMKM belum punya proyek sama sekali) -- UMKM baru pun tetap boleh
  // melihat cuplikan rekomendasi talenta.
  renderUMKMTalentWidget();

  loadProfileCompletionCard(
    "umkm-profile-completion-card",
    "umkm",
    "Halo! Profil Anda belum lengkap. Silakan lengkapi profil Anda agar bisa mulai membuat proyek."
  );

  const projects = window.db ? db.getProjects().filter(p => p.createdBy === currentUser.id) : [];

  const statTotal = document.getElementById("stat-total");
  const statOpen = document.getElementById("stat-open");
  const statInProgress = document.getElementById("stat-inprogress");
  const statCompleted = document.getElementById("stat-completed");

  if (statTotal) statTotal.textContent = projects.length;
  if (statOpen) statOpen.textContent = projects.filter(p => p.status === "Open").length;
  if (statInProgress) statInProgress.textContent = projects.filter(p => p.status === "In Progress" || p.status === "Submitted" || p.status === "In Review").length;
  if (statCompleted) statCompleted.textContent = projects.filter(p => p.status === "Completed" || p.status === "Done" || p.status === "Closed").length;

  const recentGrid = document.getElementById("umkm-recent-projects");
  if (!recentGrid) return;

  if (projects.length === 0) {
    recentGrid.innerHTML = `
      <div class="empty-state" style="grid-column: 1 / -1; padding: var(--space-8); text-align: center;">
        <p style="margin-bottom: var(--space-4);">You don't have any projects yet.</p>
        <button type="button" class="btn btn-primary btn-md btn-open-create-project" id="create-project-btn-empty">Create Project</button>
      </div>
    `;
    initCreateProjectModal();
    return;
  }

  // Sort by updatedAt descending
  projects.sort((a, b) => new Date(b.updatedAt || b.createdAt || 0) - new Date(a.updatedAt || a.createdAt || 0));

  const recentProjects = projects.slice(0, 5);
  // umkmProjectCard() (bukan marketplaceCard()) -- proyek di sini milik UMKM
  // yang sedang login sendiri, jadi tidak boleh menampilkan tombol
  // "Lamar Sekarang" (marketplaceCard menyisipkannya lewat marketplaceActionButton
  // untuk status Open tanpa mengecek role pemilik proyek).
  recentGrid.innerHTML = recentProjects.map(p => umkmProjectCard(p)).join("");

  initCreateProjectModal();
}

function initCreateProjectModal() {
  const modal = document.getElementById("create-project-modal");
  if (!modal) return;

  // Modal peta pemilih lokasi (idempoten sendiri — aman dipanggil berulang)
  initLocationPickerModal();

  // "Create Project" buttons can be re-created when Recent Projects re-renders
  // (e.g. the empty-state button), so opening is handled via delegation and is
  // safe to (re)bind. The modal's own internal controls are static, so they're
  // bound once via modal.dataset.cpBound to avoid stacking duplicate listeners
  // (which would otherwise double-submit on every re-render).
  const closeBtn = document.getElementById("btn-close-create-project");
  const cancelBtn = document.getElementById("btn-cancel-create-project");
  const submitBtn = document.getElementById("btn-submit-create-project");
  const errorSpan = document.getElementById("cp-error");
  const form = document.getElementById("create-project-form");
  const budgetInput = document.getElementById("cp-budget");
  const defaultErrorMsg = errorSpan ? errorSpan.textContent : "";

  let selectedCategories = [];

  function openModal() {
    modal.removeAttribute("hidden");
    selectedCategories = [];

    // Render the tag selector using the global function
    if (typeof window.renderTagSelector === 'function') {
      window.renderTagSelector('cp-category-container', window.SKILL_OPTIONS || [], selectedCategories, 'skill-pill', 'skill-pill--active');
    }

    requestAnimationFrame(() => {
      modal.classList.add("dm-visible");
    });

    if (errorSpan) {
      errorSpan.textContent = defaultErrorMsg;
      errorSpan.style.display = "none";
    }
    if (form) form.reset();
  }

  function closeModal() {
    modal.classList.remove("dm-visible");

    setTimeout(() => {
      modal.setAttribute("hidden", "");
    }, 280); // sesuaikan dengan durasi transition CSS
  }

  if (!window.__cpModalOpenDelegated) {
    document.body.addEventListener("click", (e) => {
      if (!e.target.closest(".btn-open-create-project, #create-project-btn")) return;

      const modalNow = document.getElementById("create-project-modal");
      if (!modalNow || typeof modalNow.__openCreateProjectModal !== "function") return;

      // Wajib lengkapi Lokasi Bisnis di Profil sebelum bisa membuat proyek --
      // dicek live ke server (bukan cache/sessionStorage yang bisa basi
      // setelah login) supaya sesuai data terkini di database.
      const currentUser = window.db ? db.getCurrentUser() : null;
      const isServer = window.location.protocol === "http:" || window.location.protocol === "https:";

      if (!currentUser || !isServer) {
        modalNow.__openCreateProjectModal();
        return;
      }

      // api/me.php (identitas dari SESSION PHP aktif), BUKAN
      // get_user_profile.php?id=<currentUser.id> -- currentUser.id berasal
      // dari sessionStorage yang di-cache PER TAB, bisa basi kalau browser
      // yang sama dipakai login sebagai akun lain di tab lain (session PHP
      // dibagi ke semua tab). Precheck yang basi bisa salah mengizinkan/
      // menolak modal dibuka berdasarkan profil akun yang keliru.
      const API_BASE = (window.db && window.db.API_BASE) || "api";
      fetch(`${API_BASE}/me.php`)
        .then((res) => res.json().then((json) => ({ status: res.status, json })))
        .then(({ status, json }) => {
          // Hanya paksa reload untuk kondisi TERKONFIRMASI jelas -- 401
          // (sesi benar-benar tidak ada) atau role yang terkonfirmasi bukan
          // umkm. Kegagalan ambigu lainnya (403 suspended, 500, dll.) TIDAK
          // memaksa reload -- cukup batalkan pembukaan modal kali ini,
          // supaya hiccup sesaat di server tidak mengusir UMKM dari
          // halamannya sendiri.
          if (status === 401) {
            alert("Sesi Anda sudah berakhir. Silakan login kembali.");
            window.location.href = "login.html";
            return;
          }
          if (json.status === "success" && json.data) {
            if (json.data.role !== "umkm") {
              alert("Sesi Anda saat ini bukan akun UMKM (kemungkinan Anda login sebagai akun lain di tab/jendela lain). Halaman akan dimuat ulang.");
              window.location.reload();
              return;
            }
            const location = (json.data.location || "").trim();
            if (!location) {
              alert("Silakan lengkapi Lokasi Bisnis di Profil Anda terlebih dahulu sebelum membuat proyek.");
              return;
            }
            modalNow.__openCreateProjectModal();
            return;
          }
          console.error("[create-project] Respons tak terduga saat cek profil:", status, json);
          alert("Gagal memeriksa profil Anda. Silakan coba lagi.");
        })
        .catch((err) => {
          console.error("[create-project] Gagal memeriksa Lokasi Bisnis di profil:", err);
          alert("Gagal memeriksa profil Anda. Silakan coba lagi.");
        });
    });
    window.__cpModalOpenDelegated = true;
  }
  // Always point the modal at the latest openModal() closure.
  modal.__openCreateProjectModal = openModal;

  if (modal.dataset.cpBound === "true") return;
  modal.dataset.cpBound = "true";

  if (budgetInput) {
    budgetInput.addEventListener("input", function() {
      this.value = window.formatRupiah ? window.formatRupiah(this.value) : this.value;
    });
  }

  if (closeBtn) closeBtn.addEventListener("click", closeModal);
  if (cancelBtn) cancelBtn.addEventListener("click", closeModal);

  if (submitBtn) {
    submitBtn.addEventListener("click", function (e) {
      e.preventDefault();

      const title = document.getElementById("cp-title").value.trim();
      const location = document.getElementById("cp-location").value.trim();
      const budget = document.getElementById("cp-budget").value.trim().replace(/\./g, '').replace(/,/g, '.'); // strip thousand separators and convert decimal comma to dot
      const deadline = document.getElementById("cp-deadline").value.trim();
      const description = document.getElementById("cp-description").value.trim();
      const requirements = document.getElementById("cp-requirements").value.trim();
      const icon = document.getElementById("cp-icon").value;
      const latitude = document.getElementById("lat-input") ? document.getElementById("lat-input").value : "";
      const longitude = document.getElementById("lng-input") ? document.getElementById("lng-input").value : "";

      const categories = [...selectedCategories];

      if (!title || categories.length === 0 || !location || !budget || !deadline || !description || !requirements) {
        errorSpan.textContent = defaultErrorMsg;
        errorSpan.style.display = "block";
        return;
      }

      // Validasi lokasi: wajib mengandung kata "Bantul" — lokasi diisi lewat
      // modal "Pilih Lokasi di Peta" (map picker), tapi tetap dipastikan tidak
      // keluar dari wilayah Kabupaten Bantul sebagai lapisan pengaman terakhir.
      if (!location.toLowerCase().includes("bantul")) {
        alert("Mohon pilih/masukkan lokasi yang berada di dalam wilayah Kabupaten Bantul.");
        return;
      }

      errorSpan.style.display = "none";

      const projectData = {
        title,
        categories,
        location,
        prize: budget,
        deadline,
        description,
        requirements,
        icon: icon || "puzzle",
        latitude,
        longitude,
      };

      if (window.db && typeof window.db.createProject === "function") {
        const originalLabel = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = "Menyimpan...";

        Promise.resolve(window.db.createProject(projectData))
          .then(() => {
            closeModal();
          })
          .catch((err) => {
            console.error("[create-project] Gagal menyimpan proyek:", err);
            errorSpan.textContent = (err && err.message) ? err.message : "Gagal menyimpan proyek. Coba lagi.";
            errorSpan.style.display = "block";
          })
          .finally(() => {
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
            // Perbarui "Recent Projects" / daftar proyek secara asinkronus,
            // baik berhasil (data baru) maupun gagal (buang entri sementara).
            if (document.getElementById("stat-total")) renderUMKMDashboard();
            if (document.getElementById("mp-stat-total")) renderUMKMProjects();
          });
      }
    });
  }
}

// ── Modal "Pilih Lokasi di Peta" (dipakai form "Buat Proyek Baru" UMKM
// MAUPUN "Profil Saya" freelancer) ────────────────────────────────────
// Peta Leaflet.js (OpenStreetMap, gratis tanpa API key) dikunci ke wilayah
// Kabupaten Bantul + kolom pencarian alamat via Nominatim OSM. User bisa
// cari alamat ATAU klik langsung di peta — keduanya memindahkan marker dan
// memperbarui teks lokasi. Saat "Konfirmasi Lokasi" diklik, teks itu
// dimasukkan ke field readonly lokasi yang ada di halaman (#cp-location di
// form proyek, atau #prof-location di form profil — dideteksi otomatis).
function initLocationPickerModal() {
  const modal = document.getElementById("location-picker-modal");
  if (!modal || modal.dataset.lpBound === "true") return;
  modal.dataset.lpBound = "true";

  // Field teks lokasi tujuan berbeda per halaman — cari mana yang ada.
  function getLocationTextField() {
    return document.getElementById("cp-location") || document.getElementById("prof-location");
  }

  const mapEl        = document.getElementById("lp-leaflet-map");
  const searchInput  = document.getElementById("lp-search-input");
  const searchBtn    = document.getElementById("lp-search-btn");
  const addressLabel = document.getElementById("lp-selected-address");
  const closeBtn     = document.getElementById("lp-close-btn");
  const cancelBtn    = document.getElementById("lp-cancel-btn");
  const confirmBtn   = document.getElementById("lp-confirm-btn");
  const defaultAddressText = addressLabel ? addressLabel.textContent : "";

  // Titik pusat Kabupaten Bantul, Yogyakarta
  const BANTUL_CENTER = [-7.8894, 110.3284];
  const BANTUL_SW = [-8.05, 110.15]; // sudut Barat Daya (Southwest)
  const BANTUL_NE = [-7.70, 110.50]; // sudut Timur Laut (Northeast)
  // Format viewbox Nominatim: lonMin,latMax,lonMax,latMin
  const NOMINATIM_VIEWBOX = `${BANTUL_SW[1]},${BANTUL_NE[0]},${BANTUL_NE[1]},${BANTUL_SW[0]}`;

  let map = null;
  let marker = null;
  let selectedLatLng = null;
  let selectedAddressText = "";

  // Peta baru dibuat saat modal pertama kali dibuka (bukan saat halaman
  // dimuat) — Leaflet butuh wadahnya sudah terlihat & punya ukuran nyata,
  // padahal modal ini masih "hidden" saat halaman pertama kali dimuat.
  function ensureMap() {
    if (map || typeof L === "undefined" || !mapEl) return;

    const bounds = L.latLngBounds(BANTUL_SW, BANTUL_NE);
    map = L.map(mapEl, {
      center: BANTUL_CENTER,
      zoom: 12,
      minZoom: 10,
      maxZoom: 18,
      maxBounds: bounds,
      maxBoundsViscosity: 1.0, // batas "keras" — tidak bisa digeser lewat sama sekali
    });

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: 19,
    }).addTo(map);

    // Klik manual di peta -> pindahkan marker & cari nama alamatnya (reverse geocoding)
    map.on("click", (e) => {
      setMarker(e.latlng.lat, e.latlng.lng);
      reverseGeocode(e.latlng.lat, e.latlng.lng);
    });
  }

  function setMarker(lat, lng) {
    selectedLatLng = { lat, lng };
    if (marker) {
      marker.setLatLng([lat, lng]);
    } else {
      marker = L.marker([lat, lng]).addTo(map);
    }
  }

  function setSelectedAddress(text) {
    selectedAddressText = text;
    if (addressLabel) addressLabel.textContent = `Lokasi terpilih: ${text}`;
  }

  // Teks cadangan (koordinat) kalau reverse-geocoding gagal/lambat
  function coordFallbackText(lat, lng) {
    return `${lat.toFixed(5)}, ${lng.toFixed(5)} (Kabupaten Bantul)`;
  }

  // Pastikan teks lokasi yang dikirim ke form utama selalu memuat kata
  // "Bantul" — supaya konsisten dengan validasi submit di initCreateProjectModal().
  function ensureMentionsBantul(text) {
    return /bantul/i.test(text) ? text : `${text}, Kabupaten Bantul`;
  }

  // Reverse geocoding: koordinat -> nama alamat (dipakai saat user klik peta)
  async function reverseGeocode(lat, lng) {
    if (addressLabel) addressLabel.textContent = "Mencari nama alamat...";
    try {
      const res = await fetch(
        `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`
      );
      const data = await res.json();
      const name = data && data.display_name ? data.display_name : null;
      setSelectedAddress(ensureMentionsBantul(name || coordFallbackText(lat, lng)));
    } catch (err) {
      console.error("[location-picker] Reverse geocoding gagal:", err);
      setSelectedAddress(ensureMentionsBantul(coordFallbackText(lat, lng)));
    }
  }

  // Forward geocoding: teks pencarian -> koordinat (dipakai tombol "Cari").
  // Nominatim API OpenStreetMap — gratis, tanpa API key. viewbox+bounded=1
  // membatasi HASIL PENCARIAN hanya di dalam wilayah Kabupaten Bantul.
  async function searchAddress() {
    const query = (searchInput.value || "").trim();
    if (!query) {
      alert("Silakan ketik alamat, dusun, atau nama instansi yang ingin dicari.");
      return;
    }

    ensureMap();
    searchBtn.disabled = true;
    searchBtn.textContent = "Mencari...";
    if (addressLabel) addressLabel.textContent = "Mencari lokasi...";

    try {
      const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&viewbox=${NOMINATIM_VIEWBOX}&bounded=1&countrycodes=id&limit=1`;
      const res = await fetch(url);
      const results = await res.json();

      if (!results || results.length === 0) {
        alert("Lokasi tidak ditemukan di wilayah Kabupaten Bantul. Coba kata kunci lain (nama jalan, dusun, atau instansi).");
        if (addressLabel) addressLabel.textContent = defaultAddressText;
        return;
      }

      const hit = results[0];
      const lat = parseFloat(hit.lat);
      const lng = parseFloat(hit.lon);

      map.setView([lat, lng], 16);
      setMarker(lat, lng);
      setSelectedAddress(ensureMentionsBantul(hit.display_name));
    } catch (err) {
      console.error("[location-picker] Pencarian alamat gagal:", err);
      alert("Gagal menghubungi layanan pencarian alamat. Periksa koneksi internet Anda dan coba lagi.");
    } finally {
      searchBtn.disabled = false;
      searchBtn.textContent = "Cari";
    }
  }

  function openPicker() {
    const currentField = getLocationTextField();
    const currentLocationValue = currentField ? currentField.value : "";
    const latInput = document.getElementById("lat-input");
    const lngInput = document.getElementById("lng-input");
    const savedLat = latInput && latInput.value !== "" ? parseFloat(latInput.value) : NaN;
    const savedLng = lngInput && lngInput.value !== "" ? parseFloat(lngInput.value) : NaN;
    const hasSavedCoords = !isNaN(savedLat) && !isNaN(savedLng);

    if (!currentLocationValue) {
      // Kalau form utama sedang kosong (form baru saja di-reset, mis. setelah
      // proyek sebelumnya sukses dibuat), jangan bawa-bawa pilihan lokasi lama
      // dari sesi picker sebelumnya — mulai bersih lagi.
      selectedLatLng = null;
      selectedAddressText = "";
      if (addressLabel) addressLabel.textContent = defaultAddressText;
      if (marker && map) {
        map.removeLayer(marker);
        marker = null;
      }
    } else if (hasSavedCoords) {
      // Lokasi & koordinat SUDAH tersimpan sebelumnya (mis. dimuat dari
      // database saat halaman Profil Saya/Buat Proyek dimuat, lihat
      // applyProfileData() di profile.html) -- tandai supaya pin langsung
      // muncul di posisi itu begitu peta dibuka (lihat di bawah), bukan peta
      // kosong yang membuat user mengira lokasi lama mereka hilang.
      selectedLatLng = { lat: savedLat, lng: savedLng };
      setSelectedAddress(currentLocationValue);
    }

    window.openModal(modal);
    // Leaflet wajib di-invalidateSize() setelah wadahnya benar-benar terlihat
    // (kalau di-init saat modal masih "hidden", ukuran petanya salah/kosong).
    setTimeout(() => {
      ensureMap();
      if (map) map.invalidateSize();
      // Pin lokasi yang sudah tersimpan sebelumnya (lihat blok di atas) --
      // ditaruh di sini (bukan sebelum ensureMap()) karena marker Leaflet
      // baru bisa dibuat setelah peta sungguhan ter-inisialisasi.
      if (selectedLatLng && map) {
        map.setView([selectedLatLng.lat, selectedLatLng.lng], 16);
        setMarker(selectedLatLng.lat, selectedLatLng.lng);
      }
    }, 60);
  }

  // Tombol pemicu ("Pilih Lokasi di Peta") DAN klik langsung pada field
  // readonly-nya — keduanya membuka modal ini.
  const openBtn = document.getElementById("btn-open-location-picker");
  const locationInput = getLocationTextField();
  if (openBtn) openBtn.addEventListener("click", openPicker);
  if (locationInput) locationInput.addEventListener("click", openPicker);

  if (closeBtn) closeBtn.addEventListener("click", () => window.closeModal(modal));
  if (cancelBtn) cancelBtn.addEventListener("click", () => window.closeModal(modal));
  modal.addEventListener("click", (e) => {
    if (e.target === modal) window.closeModal(modal);
  });

  if (searchBtn) searchBtn.addEventListener("click", searchAddress);
  if (searchInput) {
    searchInput.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        searchAddress();
      }
    });
  }

  if (confirmBtn) {
    confirmBtn.addEventListener("click", () => {
      if (!selectedLatLng || !selectedAddressText) {
        alert("Silakan pilih lokasi terlebih dahulu — klik di peta atau cari alamatnya.");
        return;
      }
      const target = getLocationTextField();
      if (target) target.value = selectedAddressText;

      // Simpan koordinat marker ke hidden input form utama, ikut terkirim
      // ke api/projects.php saat proyek dibuat — inilah yang membuat proyek
      // ini otomatis muncul sebagai titik di Creative Trail Map.
      const latField = document.getElementById("lat-input");
      const lngField = document.getElementById("lng-input");
      if (latField) latField.value = selectedLatLng.lat;
      if (lngField) lngField.value = selectedLatLng.lng;

      window.closeModal(modal);
    });
  }
}

// ─── Page: UMKM Projects (umkm-projects.html) ───────────────────
function renderUMKMProjects() {
  const currentUser = window.db ? db.getCurrentUser() : null;
  if (!currentUser) return;

  const projects = window.db.getProjects().filter(p => p.createdBy === currentUser.id);

  // Update stats
  const statTotal = document.getElementById("mp-stat-total");
  const statOpen = document.getElementById("mp-stat-open");
  const statReview = document.getElementById("mp-stat-review");
  const statClosed = document.getElementById("mp-stat-closed");

  if (statTotal) statTotal.textContent = projects.length;
  if (statOpen) statOpen.textContent = projects.filter(p => p.status === "Open").length;
  if (statReview) statReview.textContent = projects.filter(p => p.status === "In Progress" || p.status === "Submitted" || p.status === "In Review").length;
  if (statClosed) statClosed.textContent = projects.filter(p => p.status === "Completed" || p.status === "Closed" || p.status === "Done").length;

  const grid = document.getElementById("umkm-projects-grid");
  if (!grid) return;

  if (projects.length === 0) {
    grid.innerHTML = `
      <div class="empty-state" style="grid-column: 1 / -1; padding: var(--space-8); text-align: center;">
        <p style="margin-bottom: var(--space-4);">You don't have any projects yet.</p>
        <button type="button" class="btn btn-primary btn-md btn-open-create-project" id="create-project-btn-empty">Create Project</button>
      </div>
    `;
    initCreateProjectModal();
    return;
  }

  projects.sort((a, b) => new Date(b.updatedAt || b.createdAt || 0) - new Date(a.updatedAt || a.createdAt || 0));
  grid.innerHTML = projects.map(p => umkmProjectCard(p)).join("");
  initCreateProjectModal();
}

function umkmProjectCard(p) {
  const statusClass = statusBadgeClass(p.status);
  const tags = p.categories && p.categories.length ? p.categories.map((c) => `<span class="tag">${escapeHtml(c)}</span>`).join("") : "";
  const applicantsCount = Array.isArray(p.applicants) ? p.applicants.length : (p.applicants || 0);
  const needsReview = p.status === "Submitted";

  return `
  <article class="card card-marketplace" data-id="${p.id}" role="listitem">
    <div class="card-top">
      <div class="card-icon-wrap">${getIcon(p.icon)}</div>
      <span class="badge ${statusClass}">${escapeHtml(p.status)}</span>
    </div>
    <h3 class="card-title">${escapeHtml(p.title)}</h3>
    <div class="card-meta">
      ${getIcon("users", "icon-sm")}
      <span>${applicantsCount} Pelamar</span>
    </div>
    ${needsReview ? `<p style="font-size:0.8rem; font-weight:600; color: var(--accent-green-dk, #1B4332); margin: 0 0 0.5rem;">${getIcon("check", "icon-xs")} Hasil disubmit — menunggu review Anda</p>` : ''}
    <div class="card-tags">${tags}</div>
    <div class="card-footer">
      <div class="card-footer-info">
        ${getIcon("calendar", "icon-sm")}
        <span>${p.deadline}</span>
        <span class="prize-label">${p.prize}</span>
      </div>
    </div>
  </article>`;
}

// ─── Page: AI Match (ai-match.html) ───────────────────────
function calculateFreelancerMatch(user, project) {
  let score = 0;
  let reasonParts = [];

  const userSkills = user.skills || [];
  const userInterests = user.interests || [];
  const projCategories = project.categories || [];

  let matchingSkills = [];
  let matchingCats = [];

  if (projCategories.length === 0) {
    score = 100; // Default high if no requirements
  } else {
    // Score is strictly based on skills match percentage
    matchingSkills = projCategories.filter(c => userSkills.includes(c));
    score = (matchingSkills.length / projCategories.length) * 100;

    // Interests are only used for explanations
    matchingCats = projCategories.filter(c => userInterests.includes(c));

    if (matchingSkills.length > 0) {
      reasonParts.push(`Membutuhkan ${matchingSkills.length} keahlian Anda (${matchingSkills.slice(0, 2).join(', ')}${matchingSkills.length > 2 ? ', dll' : ''}).`);
    }
    if (matchingCats.length > 0 && matchingSkills.length < projCategories.length) {
      reasonParts.push(`Sesuai minat Anda di bidang ${matchingCats[0]}.`);
    }
  }

  // Location is only used for explanations
  let locMatch = false;
  if (project.location && user.location && project.location.toLowerCase().includes(user.location.toLowerCase())) {
    locMatch = true;
    reasonParts.push(`Lokasi sesuai domisili Anda.`);
  }

  return {
    score: Math.min(100, Math.round(score)),
    reason: reasonParts.join(' ') || 'Profil cocok.'
  };
}

// Halaman AI Talent Match penuh (ai-match.html). Sebelumnya fungsi ini
// menghitung kecocokan sendiri secara lokal dari db.getProjects() +
// sessionStorage.currentUser.skills/interests -- TIDAK PERNAH tersambung ke
// api/ai_talent_match.php (endpoint yang sama, sudah jalan & dipakai widget
// "Rekomendasi Proyek Pintar" di marketplace.html). Masalahnya, sessionStorage
// currentUser hanya diisi field terbatas oleh login.php (tidak ada
// skills/interests di dalamnya kalau freelancer belum pernah membuka+
// menyimpan profil di TAB itu), jadi kecocokan lokal selalu berakhir 0% dan
// halaman ini tampak "tidak bekerja" walau skill sudah tersimpan di DB.
// Sekarang disambungkan ke server (skor gabungan skill + jarak, sama seperti
// widget marketplace) -- fallback ke perhitungan lokal HANYA saat file://
// tanpa server.
function renderAIMatch() {
  const list = document.getElementById("ai-match-list");
  if (!list) return;

  const currentUser = window.db ? window.db.getCurrentUser() : null;
  const countSpan = document.querySelector('.section-count');
  const pillCount = document.querySelectorAll('.ai-banner-pills .ai-pill')[1];

  if (!currentUser) {
    list.innerHTML = `<div class="empty-state"><p>Silakan login sebagai freelancer untuk melihat AI Talent Match.</p></div>`;
    return;
  }
  if (currentUser.role !== 'freelancer') {
    list.innerHTML = `<div class="empty-state"><p>AI Talent Match hanya tersedia untuk akun freelancer.</p></div>`;
    return;
  }

  function showMatches(matches) {
    if (countSpan) countSpan.textContent = `${matches.length} proyek ditemukan`;
    if (pillCount) pillCount.innerHTML = `<span class="dot"></span> ${matches.length} rekomendasi untukmu`;

    if (matches.length === 0) {
      list.innerHTML = `<div class="empty-state">
        ${getIcon("search")}
        <h3>Belum ada rekomendasi</h3>
        <p>Lengkapi profil (skill, minat, dan lokasi) Anda untuk mendapatkan rekomendasi proyek yang cocok.</p>
      </div>`;
      return;
    }

    list.innerHTML = matches.map((p) => aiMatchCard(p)).join("");
  }

  const isServer = window.location.protocol === "http:" || window.location.protocol === "https:";
  if (!isServer) {
    // Mode file:// tanpa server — fallback ke perkiraan lokal (tidak
    // memperhitungkan jarak, hanya skill/minat vs kategori proyek).
    const allProjects = window.db ? window.db.getProjects() : [];
    const openProjects = allProjects.filter(p => p.status === 'Open');
    const matches = openProjects.map(p => {
      const matchData = calculateFreelancerMatch(currentUser, p);
      return { ...p, matchPercent: matchData.score, matchReason: matchData.reason };
    }).filter(p => p.matchPercent > 0);
    matches.sort((a, b) => b.matchPercent - a.matchPercent);
    showMatches(matches);
    return;
  }

  list.innerHTML = `<div class="empty-state"><p>Memuat rekomendasi...</p></div>`;
  const API_BASE = (window.db && window.db.API_BASE) || "api";

  fetch(`${API_BASE}/ai_talent_match.php?freelancer_id=${encodeURIComponent(currentUser.id)}`)
    .then((res) => {
      if (!res.ok) throw new Error("Server bermasalah (Status " + res.status + ")");
      return res.json();
    })
    .then((json) => {
      if (json.status !== "success") {
        // Freelancer belum atur lokasi — ajak lengkapi profil, bukan cuma kosong
        const message = json.code === "location_missing"
          ? json.message
          : (json.message || "Gagal memuat rekomendasi.");
        list.innerHTML = `
          <div class="empty-state">
            <p>${escapeHtml(message)}</p>
            ${json.code === "location_missing" ? `<a class="dm-btn-primary" href="profile.html" style="margin-top: 15px; display: inline-block;">Lengkapi Profil Saya</a>` : ""}
          </div>`;
        if (countSpan) countSpan.textContent = `0 proyek ditemukan`;
        return;
      }

      const matches = (json.data || []).map((p) => ({
        id: p.project_id,
        title: p.title,
        description: p.description || "",
        location: p.location || "",
        categories: p.categories || [],
        deadline: p.deadline,
        status: "Open",
        applicants: [],
        matchPercent: Number(p.persentase_cocok) || 0,
        matchReason: `Kecocokan skill ${Number(p.skor_skill)}% · Berjarak ${Number(p.jarak_km)} KM dari ${escapeHtml(p.umkm_name || 'UMKM')}.`,
      }));

      showMatches(matches);
    })
    .catch((err) => {
      console.error("[ai-match] Gagal memuat rekomendasi AI:", err);
      list.innerHTML = `<div class="empty-state"><p>Gagal memuat rekomendasi. Buka Console (F12) untuk detail error.</p></div>`;
    });
}

function aiMatchCard(p) {
  const categories = (p.categories || [])
    .map((s) => `<span class="skill-tag">${getIcon("check", "icon-xs")}${escapeHtml(s)}</span>`)
    .join("");
  const matchClass = p.matchPercent >= 85 ? "match-high" : p.matchPercent >= 75 ? "match-mid" : "match-low";
  return `
  <article class="card card-horizontal card-aimatch" data-id="${p.id}" role="listitem">
    <div class="card-h-left">
      <div class="card-icon-wrap large">${getIcon("puzzle")}</div>
    </div>
    <div class="card-h-body">
      <div class="card-h-header">
        <div>
          <h3 class="card-title">${escapeHtml(p.title)}</h3>
          <div class="card-meta">
            ${getIcon("pin", "icon-sm")}
            <span>${escapeHtml(p.location || 'Bantul')}</span>
          </div>
        </div>
        <span class="match-badge ${matchClass}">${p.matchPercent}% Cocok</span>
      </div>
      <p class="card-desc">${escapeHtml(p.description)}</p>
      ${p.matchReason ? `<p style="font-size: 0.85rem; color: var(--accent-green-dk); margin-bottom: 0.75rem;">${getIcon("check", "icon-xs")} ${escapeHtml(p.matchReason)}</p>` : ''}
      <div class="card-tags skills-row">${categories}</div>
      <div class="card-footer-h">
        <div class="footer-meta-row">
          <span class="meta-item">${getIcon("users", "icon-sm")} ${(p.applicants || []).length} pelamar</span>
          <span class="meta-item">${getIcon("calendar", "icon-sm")} ${escapeHtml(p.deadline)}</span>
          <span class="badge badge-open">${escapeHtml(p.status)}</span>
        </div>
      </div>
    </div>
  </article>`;
}

// ─── Page: Creative Trail Map (trail-map.html) ────────────
function renderTrailMap() {
  const grid = document.getElementById("trail-grid");
  if (!grid) return;
  grid.innerHTML = trailLocations.map((loc) => trailCard(loc)).join("");
}

// Peta interaktif Leaflet.js (OpenStreetMap) — gratis, tanpa API key.
// Ini baru inisialisasi peta DASAR: hanya menampilkan wilayah Kabupaten
// Bantul dengan tampilan & area geser yang dikunci. Belum ada
// marker/penanda lokasi (menyusul di iterasi berikutnya).
function initTrailLeafletMap() {
  const mapEl = document.getElementById("trail-leaflet-map");

  // Guard: wadah peta harus ada di halaman ini, dan library Leaflet (variabel
  // global "L") harus sudah termuat dari CDN sebelum fungsi ini dipanggil.
  if (!mapEl || typeof L === "undefined") return;

  // Kalau peta sudah pernah diinisialisasi (mis. karena script re-run),
  // jangan buat instance baru di elemen yang sama.
  if (mapEl.__leafletMap) return;

  // Titik pusat Kabupaten Bantul, Yogyakarta
  const BANTUL_CENTER = [-7.8894, 110.3284];
  const BANTUL_ZOOM_AWAL = 12;

  // Bounding box (kotak pembatas) wilayah Kabupaten Bantul + sedikit area
  // sekitarnya — dipakai agar peta TIDAK BISA digeser/pan sampai keluar
  // dari kawasan ini.
  const BANTUL_BOUNDS = L.latLngBounds(
    L.latLng(-8.05, 110.15), // sudut Barat Daya (Southwest)
    L.latLng(-7.70, 110.50)  // sudut Timur Laut (Northeast)
  );

  const map = L.map(mapEl, {
    center: BANTUL_CENTER,
    zoom: BANTUL_ZOOM_AWAL,
    minZoom: 10,              // cegah zoom out sampai keluar konteks wilayah Bantul
    maxZoom: 18,
    maxBounds: BANTUL_BOUNDS,
    maxBoundsViscosity: 1.0,  // 1.0 = batas "keras", peta berhenti tepat di tepi kotak pembatas
  });

  // Layer tile dari OpenStreetMap (gratis, tanpa API key)
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19,
  }).addTo(map);

  // Simpan referensi instance peta pada elemennya (cegah inisialisasi ganda)
  // dan pada window (memudahkan penambahan marker di iterasi berikutnya).
  mapEl.__leafletMap = map;
  window.trailLeafletMap = map;

  // Marker proyek-proyek aktif — dipilih UMKM lewat Map Picker di form
  // "Buat Proyek Baru", ditarik otomatis dari api/get_projects_map.php.
  loadProjectMarkersOnMap(map);
}

// Ambil semua proyek aktif yang punya koordinat, lalu render satu marker per
// proyek di peta Trail Map. Setiap marker punya popup ringkas (judul, UMKM,
// kategori) + tautan "Lihat Detail Proyek" menuju marketplace.html.
function loadProjectMarkersOnMap(map) {
  const API_BASE = (window.db && window.db.API_BASE) || "api";

  fetchJsonSafe(`${API_BASE}/get_projects_map.php`)
    .then((res) => {
      const projects = res.data || [];
      projects.forEach((p) => {
        if (typeof p.latitude !== "number" || typeof p.longitude !== "number") return;

        L.marker([p.latitude, p.longitude])
          .addTo(map)
          .bindPopup(buildProjectMarkerPopup(p));
      });
    })
    .catch((err) => {
      // Kegagalan memuat marker tidak boleh menghentikan peta dasar tampil
      console.error("[trail-map] Gagal memuat titik proyek:", err);
    });
}

// Kartu mini HTML untuk isi popup marker — semua data dari server (judul,
// nama UMKM, kategori) DI-ESCAPE karena berasal dari input pengguna.
function buildProjectMarkerPopup(p) {
  const judul = escapeHtml(p.judul_proyek || "Proyek tanpa judul");
  const umkm = escapeHtml(p.nama_umkm || "UMKM Bantul");
  const kategori = escapeHtml(p.kategori || "Umum");
  const lokasi = escapeHtml(p.lokasi_teks || "-");
  const detailUrl = `marketplace.html?id=${encodeURIComponent(p.id_proyek)}`;

  return `
    <div class="trail-map-popup">
      <h4 class="trail-map-popup-title">${judul}</h4>
      <p class="trail-map-popup-row"><strong>UMKM:</strong> ${umkm}</p>
      <p class="trail-map-popup-row"><strong>Kategori:</strong> ${kategori}</p>
      <p class="trail-map-popup-row"><strong>Lokasi:</strong> ${lokasi}</p>
      <a class="trail-map-popup-link" href="${detailUrl}">Lihat Detail Proyek</a>
    </div>`;
}

function trailCard(loc) {
  const tags = loc.categories.map((c) => `<span class="tag">${c}</span>`).join("");
  return `
  <article class="card card-trail" id="trail-card-${loc.id}">
    <div class="card-top">
      <div class="card-icon-wrap">${getIcon(loc.icon)}</div>
    </div>
    <h3 class="card-title">${loc.name}</h3>
    <p class="card-desc">${loc.description}</p>
    <div class="card-tags">${tags}</div>
    <div class="trail-stats">
      <span>${getIcon("pin", "icon-sm")} ${loc.projects} proyek</span>
      <span>${getIcon("star", "icon-sm")} ${loc.rating}</span>
    </div>
  </article>`;
}

// ─── Page: Impact Portfolio (portfolio.html) ──────────────
// Data diambil live dari api/freelancer_stats.php (bukan dummy data lagi):
// Total Saldo, Proyek Selesai, Engagement, Impact Score, Rating + daftar
// proyek Completed milik freelancer yang sedang login.
function renderPortfolio() {
  const list = document.getElementById("portfolio-list");
  const statsRow = document.getElementById("portfolio-stats");
  const countEl = document.querySelector(".section-count");
  if (!list) return;

  const currentUser = window.db ? db.getCurrentUser() : null;

  if (!currentUser) {
    if (statsRow) statsRow.innerHTML = "";
    list.innerHTML = `<div class="empty-state"><p>Silakan login sebagai freelancer untuk melihat Impact Portfolio Anda.</p></div>`;
    return;
  }
  if (currentUser.role !== 'freelancer') {
    if (statsRow) statsRow.innerHTML = "";
    list.innerHTML = `<div class="empty-state"><p>Impact Portfolio hanya tersedia untuk akun freelancer.</p></div>`;
    return;
  }

  function renderStatsCards(s) {
    if (!statsRow) return;
    const fmtSaldo = window.formatCurrencyIndo ? window.formatCurrencyIndo(s.total_saldo) : s.total_saldo;
    const stats = [
      { label: "Total Saldo", value: s.total_saldo > 0 ? fmtSaldo : "Rp 0", icon: "dollar" },
      { label: "Proyek Selesai", value: s.completed_projects, icon: "package" },
      { label: "Rata-rata Engagement", value: `+${s.avg_engagement}%`, icon: "trending-up" },
      { label: "Impact Score", value: s.impact_score, icon: "award" },
      { label: "Rating Rata-rata", value: s.avg_rating ? `${s.avg_rating} ★` : "—", icon: "star" },
    ];
    statsRow.innerHTML = stats
      .map(
        (st) => `
      <div class="stat-card">
        <div class="stat-icon">${getIcon(st.icon)}</div>
        <div class="stat-value">${st.value}</div>
        <div class="stat-label">${st.label}</div>
      </div>`
      )
      .join("");
  }

  list.innerHTML = `<div class="empty-state"><p>Memuat portfolio...</p></div>`;
  const API_BASE = (window.db && window.db.API_BASE) || "api";

  fetch(`${API_BASE}/freelancer_stats.php?freelancer_id=${encodeURIComponent(currentUser.id)}`)
    .then((r) => {
      if (!r.ok) throw new Error('Server bermasalah (Status ' + r.status + ')');
      return r.json();
    })
    .then((res) => {
      if (!res.success) throw new Error(res.message || 'Gagal memuat statistik.');

      renderStatsCards(res.stats);

      const projects = res.projects || [];
      // Simpan untuk modal detail (klik kartu → pop-up)
      window.__completedProjects = projects;

      if (countEl) countEl.textContent = `${projects.length} proyek dengan dampak nyata`;

      list.innerHTML = projects.length
        ? projects.map((p) => completedProjectCard(p)).join("")
        : `<div class="empty-state"><p>Belum ada proyek yang selesai. Selesaikan proyek pertamamu untuk membangun Impact Portfolio!</p></div>`;
    })
    .catch((err) => {
      console.error('[portfolio]', err);
      list.innerHTML = `<div class="empty-state"><p>Gagal memuat portfolio. ${escapeHtml(err.message || '')}</p></div>`;
    });
}

// Kartu proyek selesai (data live dari database)
function completedProjectCard(p) {
  const budget = window.formatCurrencyIndo ? window.formatCurrencyIndo(p.budget) : p.budget;
  const finishedDate = window.formatDateIndo ? window.formatDateIndo(String(p.completed_at || '').split(' ')[0]) : p.completed_at;
  const category = (p.categories && p.categories[0]) || 'Kreatif';

  const metrics = [
    { icon: "trending-up", label: `+${p.engagement}% engagement` },
    { icon: "award", label: `${p.impact_points} impact points` },
    { icon: "dollar", label: budget },
  ]
    .map(
      (m) => `
    <div class="metric-item">
      ${getIcon(m.icon, "icon-sm")}
      <span>${m.label}</span>
    </div>`
    )
    .join("");

  return `
  <article class="card card-horizontal card-portfolio" data-id="${p.id}" role="listitem">
    <div class="card-h-left">
      <div class="card-icon-wrap large">${getIcon(p.icon)}</div>
      <span class="category-tag">${escapeHtml(category)}</span>
    </div>
    <div class="card-h-body">
      <div class="card-h-header">
        <div>
          <h3 class="card-title">${escapeHtml(p.title)}</h3>
          <span class="freelancer-tag">Klien: ${escapeHtml(p.client_name)}</span>
        </div>
        <div class="rating-block">
          ${renderStars(p.rating)}
          <span class="rating-num">${p.rating}</span>
        </div>
      </div>
      <p class="card-desc">${escapeHtml(p.description || '')}</p>
      <p class="impact-text">${escapeHtml(p.impact_note || '')}</p>
      ${p.review_text ? `
      <blockquote style="margin: 0.5rem 0 0; padding: 0.5rem 0.75rem; border-left: 3px solid var(--accent-green, #2D6A4F); background: var(--bg-color, #f8faf8); border-radius: 0 var(--radius-md, 8px) var(--radius-md, 8px) 0;">
        <span style="display:block; font-size:0.72rem; color:var(--text-muted); margin-bottom:2px;">Ulasan Klien</span>
        <em style="font-size:0.85rem; color:var(--text-color);">"${escapeHtml(p.review_text)}"</em>
      </blockquote>` : ''}
      <div class="metrics-row">${metrics}</div>
      <div class="card-footer-h">
        <span class="meta-item">${getIcon("calendar", "icon-sm")} Selesai: ${finishedDate}</span>
      </div>
    </div>
  </article>`;
}

// Pop-up modal detail proyek selesai (dipanggil saat kartu portfolio diklik)
function populateAndOpenCompletedModal(p) {
  const backdrop = document.getElementById('portfolio-detail-backdrop');
  if (!backdrop) return;

  const budget = window.formatCurrencyIndo ? window.formatCurrencyIndo(p.budget) : p.budget;
  const finishedDate = window.formatDateIndo ? window.formatDateIndo(String(p.completed_at || '').split(' ')[0]) : p.completed_at;

  backdrop.querySelector('#pfdm-title').textContent = p.title;
  backdrop.querySelector('#pfdm-subtitle').textContent = `Klien: ${p.client_name} · Selesai ${finishedDate}`;
  backdrop.querySelector('#pfdm-description').textContent = p.description || '—';
  backdrop.querySelector('#pfdm-impact-text').textContent = p.review_text
    ? `Ulasan klien: "${p.review_text}" — ${p.impact_note || ''}`
    : (p.impact_note || '—');

  // Tautan hasil pekerjaan — tetap dirender & bisa diklik kapan saja meskipun
  // proyek sudah Completed (submission_link tidak pernah dihapus oleh backend
  // saat diterima, lihat api/review_submission.php).
  const submissionWrap = backdrop.querySelector('#pfdm-submission-wrap');
  const submissionLinkEl = backdrop.querySelector('#pfdm-submission-link');
  const submissionLink = p.submission_link || p.submissionLink || '';
  if (submissionWrap && submissionLinkEl) {
    if (submissionLink) {
      submissionWrap.style.display = '';
      submissionLinkEl.href = /^https?:\/\//i.test(submissionLink) ? submissionLink : 'https://' + submissionLink;
      submissionLinkEl.textContent = submissionLink;
    } else {
      submissionWrap.style.display = 'none';
    }
  }

  const metricsContainer = backdrop.querySelector('#pfdm-metrics');
  const badges = [
    `+${p.engagement}% engagement`,
    `Rating ${p.rating} ★`,
    `${p.impact_points} impact points`,
    `Nilai proyek ${budget}`,
  ];
  metricsContainer.innerHTML = badges.map(label => `
    <span class="dm-metric-badge">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
      ${escapeHtml(label)}
    </span>`).join('');

  window.openModal(backdrop);
}
window.populateAndOpenCompletedModal = populateAndOpenCompletedModal;

// ─── Global: Skill & Minat Modal Logic ──────────────────
function initSkillModal() {
  const modal = document.getElementById('skill-modal');
  const backdrop = document.getElementById('skill-modal-backdrop');

  // Guard: if the modal HTML doesn't exist on this page, exit cleanly without error
  if (!modal || !backdrop) return;

  const step1     = document.getElementById('skill-step-1');
  const step2     = document.getElementById('skill-step-2');
  const btnNext   = document.getElementById('skill-btn-next');
  const btnSave   = document.getElementById('skill-btn-save');
  const btnSkip   = document.getElementById('skill-btn-skip');

  function openModal() {
    backdrop.hidden = false;
    modal.hidden    = false;
    document.body.style.overflow = 'hidden';
    
    // small delay so CSS transition fires
    requestAnimationFrame(() => {
      backdrop.classList.add('sm-visible');
      modal.classList.add('sm-visible');
    });
    showStep(1);
  }

  function closeModal() {
    backdrop.classList.remove('sm-visible');
    modal.classList.remove('sm-visible');
    document.body.style.overflow = '';
    
    modal.addEventListener('transitionend', function handler() {
      modal.hidden    = true;
      backdrop.hidden = true;
      modal.removeEventListener('transitionend', handler);
    });
  }

  function showStep(n) {
    if (step1) step1.hidden = (n !== 1);
    if (step2) step2.hidden = (n !== 2);
  }

  // Handle open event triggered from profile-modal.js
  document.addEventListener('open-skill-modal', openModal);

  // Directly bind update button if available
  const pmUpdateBtn = document.getElementById('pm-update-skill');
  if (pmUpdateBtn) {
    pmUpdateBtn.addEventListener('click', openModal);
  }

  // Close when clicking the backdrop
  backdrop.addEventListener('click', closeModal);

  // Bind navigation / action buttons inside modal
  if (btnNext) btnNext.addEventListener('click', () => showStep(2));
  if (btnSave) {
    btnSave.addEventListener('click', () => {
      // Collect active skills and interests
      const activeSkills = Array.from(document.querySelectorAll('#skill-pill-grid .skill-pill--active')).map(p => p.textContent.trim());
      const activeInterests = Array.from(document.querySelectorAll('#interest-pill-grid .interest-pill--active')).map(p => p.textContent.trim());

      // Update in DB and synchronize UI immediately
      const userJson = sessionStorage.getItem('currentUser');
      if (userJson && window.db) {
        const currentUser = JSON.parse(userJson);
        window.db.updateUser(currentUser.id, { skills: activeSkills, interests: activeInterests });
        
        // Update local session to persist on reload
        const updatedUser = window.db.getUserById(currentUser.id);
        sessionStorage.setItem('currentUser', JSON.stringify(updatedUser));
        
        // Refresh the profile modal DOM dynamically
        if (typeof updateProfileUI === 'function') {
          updateProfileUI();
        }
      }
      closeModal();
    });
  }
  if (btnSkip) btnSkip.addEventListener('click', closeModal);

  // Escape key closes modal
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });

  // Safe Pill Click event delegation inside grids (prevents errors and runs cleanly)
  const skillGrid = document.getElementById('skill-pill-grid');
  if (skillGrid) {
    skillGrid.addEventListener('click', (e) => {
      const pill = e.target.closest('.skill-pill');
      if (pill) {
        pill.classList.toggle('skill-pill--active');
        pill.setAttribute('aria-pressed', pill.classList.contains('skill-pill--active'));
      }
    });
  }

  const interestGrid = document.getElementById('interest-pill-grid');
  if (interestGrid) {
    interestGrid.addEventListener('click', (e) => {
      const pill = e.target.closest('.interest-pill');
      if (pill) {
        pill.classList.toggle('interest-pill--active');
        pill.setAttribute('aria-pressed', pill.classList.contains('interest-pill--active'));
      }
    });
  }
}


// ─── Detail Modals ───────────────────────────────────────
window.openModal = function(backdropEl) {
  if (!backdropEl) return;
  backdropEl.hidden = false;
  document.body.style.overflow = 'hidden';
  requestAnimationFrame(() => backdropEl.classList.add('dm-visible'));
};

window.closeModal = function(backdropEl) {
  if (!backdropEl) return;
  backdropEl.classList.remove('dm-visible');
  document.body.style.overflow = '';
  backdropEl.addEventListener('transitionend', function handler() {
    backdropEl.hidden = true;
    backdropEl.removeEventListener('transitionend', handler);
  });
};

function initDetailModals() {

  // ── Close buttons inside each modal footer ──
  document.querySelectorAll('.dm-close-btn, .dm-close-footer-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const backdrop = btn.closest('.detail-backdrop');
      window.closeModal(backdrop);
    });
  });

  // ── Close on backdrop click (outside the modal box) ──
  document.querySelectorAll('.detail-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) {
        window.closeModal(backdrop);
      }
    });
  });

  // ── Escape key ──
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.detail-backdrop.dm-visible').forEach(b => window.closeModal(b));
  });

  // ── Global Event Delegation — card clicks ──
  document.body.addEventListener('click', (e) => {
    // Don't fire if clicking a button/link inside the card
    if (e.target.closest('button') || e.target.closest('a')) return;

    // Project cards: Marketplace & AI Match
    const projectCard = e.target.closest('.card-marketplace, .card-aimatch');
    // Ensure we don't accidentally intercept freelancer cards in Talent AI
    if (projectCard && !projectCard.classList.contains('card-talentai')) {
      const id = parseInt(projectCard.dataset.id, 10);
      const data = db.getProjects().find(p => String(p.id) === String(id));
      if (data) populateAndOpenProjectModal(data, window.openModal);
      return;
    }

    // Trail Map location cards
    const trailCard = e.target.closest('.card-trail');
    if (trailCard) {
      const id = parseInt(trailCard.id.replace('trail-card-', ''), 10);
      const data = trailLocations.find(l => l.id === id);
      if (data) populateAndOpenLocationModal(data, window.openModal);
      return;
    }

    // Portfolio cards — data live dari freelancer_stats.php (bukan dummy)
    const portfolioCard = e.target.closest('.card-portfolio');
    if (portfolioCard) {
      const id = portfolioCard.dataset.id;
      const data = (window.__completedProjects || []).find(p => String(p.id) === String(id));
      if (data) populateAndOpenCompletedModal(data);
      return;
    }
  });
}

// ─── Applicants (Kelola Proyek / umkm-projects.html) ──────
// Helper: fetch yang selalu memberi pesan error jelas di console.
// Respon dibaca sebagai teks dulu — jika PHP mengeluarkan error HTML (bukan
// JSON), isi respon aslinya tampil di console, bukan sekadar "SyntaxError".
// Deteksi pesan otorisasi yang menandakan sesi PHP browser ini sudah TIDAK
// COCOK LAGI dengan identitas yang diasumsikan sessionStorage tab ini --
// pola berulang di banyak endpoint ("Akses ditolak...", "bukan partisipan
// chat...", "Hanya akun X yang dapat...", dst). Root cause paling umum:
// browser yang sama dipakai login sebagai akun lain di tab lain (session
// PHP dibagi ke SEMUA tab, sessionStorage di-cache PER TAB), ATAU riwayat/
// bookmark bercampur antara http://localhost dan http://127.0.0.1 (dua
// origin BERBEDA menurut browser, cookie sesi tidak dibagi -- lihat
// kanonisasi hostname di js/db.js). Dipakai reaktif di catch block manapun
// yang memanggil endpoint dgn otorisasi berbasis session PHP; JANGAN dipakai
// utk error lain (mis. validasi input) supaya tidak salah memicu reload.
function handleSessionMismatchError(message) {
  if (typeof message !== 'string') return false;
  const patterns = ['Akses ditolak', 'bukan partisipan', 'Hanya akun'];
  if (patterns.some((p) => message.indexOf(p) !== -1)) {
    alert('Sesi Anda saat ini tidak sesuai untuk aksi ini (kemungkinan Anda login sebagai akun lain di tab/jendela lain pada browser yang sama, atau membuka halaman ini lewat alamat yang beda dari saat login). Halaman akan dimuat ulang.');
    window.location.reload();
    return true;
  }
  return false;
}

function fetchJsonSafe(url, options) {
  return fetch(url, options).then((response) =>
    response.text().then((raw) => {
      let data;
      try {
        data = JSON.parse(raw);
      } catch (parseErr) {
        throw new Error(
          `Respon server bukan JSON valid (HTTP ${response.status}) dari ${url}\nIsi respon: ${raw.substring(0, 300)}`
        );
      }
      if (!response.ok || data.status !== 'success') {
        const err = new Error(data.message || `HTTP ${response.status} dari ${url}`);
        err.code = data.code;
        err.data = data.data;
        throw err;
      }
      return data;
    })
  );
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

function showSuccessToast(message) {
  const toast = document.createElement('div');
  toast.textContent = message;
  Object.assign(toast.style, {
    position: 'fixed', bottom: '20px', right: '20px', background: '#28a745',
    color: '#fff', padding: '12px 24px', borderRadius: '4px', zIndex: '9999',
    boxShadow: '0 4px 6px rgba(0,0,0,0.1)', fontFamily: 'sans-serif'
  });
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

// ═══ BAGIAN 2: FRONTEND FREELANCER — Apply ke proyek ═══════
// Dipanggil dari tombol Apply di modal detail proyek (atau tombol
// <button id="btnApply" onclick="applyProject(PROJECT_ID, FREELANCER_ID)">).
function applyProject(projectId, freelancerId, btnEl) {
  const btn = btnEl || document.getElementById('btnApply') || document.getElementById('btn-ambil-proyek');

  const setAppliedState = () => {
    if (!btn) return;
    btn.textContent = 'Sudah Dilamar';
    btn.disabled = true;
    btn.style.background = 'var(--text-muted)';
    btn.style.cursor = 'not-allowed';
  };

  let originalLabel;
  if (btn) {
    originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Mengirim...';
  }

  const API_BASE = (window.db && window.db.API_BASE) || 'api';
  const PESAN_PROFIL_BELUM_LENGKAP =
    'Anda diwajibkan mengisi data Minat dan Keahlian (Skill) di profil Anda sebelum melamar proyek ini!';
  const PESAN_LOKASI_BELUM_DIISI =
    'Anda diwajibkan mengisi Lokasi di profil Anda sebelum melamar proyek ini! Silakan lengkapi lewat halaman Profil Saya.';
  const PESAN_SESI_TIDAK_COCOK =
    'Sesi Anda saat ini bukan akun freelancer (kemungkinan Anda login sebagai akun lain di tab/jendela lain pada browser yang sama). Halaman akan dimuat ulang agar sesuai dengan akun yang benar-benar sedang login.';

  // Validasi wajib: cek dulu ke server apakah freelancer sudah mengisi
  // Skill, Minat, DAN Lokasi (+ koordinat) di profilnya. PENTING: pengecekan
  // ini SENGAJA memakai api/me.php (identitas dari SESSION PHP yang sedang
  // aktif), BUKAN get_user_profile.php?id=<freelancerId> seperti sebelumnya.
  // `freelancerId` parameter berasal dari sessionStorage.currentUser, yang
  // di-cache PER TAB -- kalau browser yang sama dipakai login sebagai akun
  // lain di tab lain (mis. UMKM/admin, umum terjadi saat menguji banyak
  // role), sessionStorage tab ini bisa basi sementara session PHP (satu-
  // satunya sumber kebenaran BACKEND, dibagi ke SEMUA tab) sudah berpindah
  // akun. Precheck lama memvalidasi profil id yang basi (bisa saja lolos),
  // lalu apply_project.php (yang sudah benar sejak awal, selalu pakai
  // session) menolak dengan "Hanya akun freelancer..." -- gejala persis
  // yang dilaporkan. Dengan me.php, precheck & submit selalu mengacu ke
  // identitas yang SAMA PERSIS.
  return fetchJsonSafe(`${API_BASE}/me.php`)
    .then((meRes) => {
      const profil = meRes.data || {};

      if (profil.role !== 'freelancer') {
        const err = new Error(PESAN_SESI_TIDAK_COCOK);
        err.code = 'session_role_mismatch';
        throw err;
      }

      const hasSkills = Array.isArray(profil.skills) && profil.skills.length > 0;
      const hasInterests = Array.isArray(profil.interests) && profil.interests.length > 0;
      if (!hasSkills || !hasInterests) {
        const err = new Error(PESAN_PROFIL_BELUM_LENGKAP);
        err.code = 'profile_incomplete';
        throw err;
      }

      const hasLocation = !!(profil.location && String(profil.location).trim())
        && profil.latitude != null && profil.longitude != null;
      if (!hasLocation) {
        const err = new Error(PESAN_LOKASI_BELUM_DIISI);
        err.code = 'location_missing';
        throw err;
      }

      // Sinkronkan sessionStorage tab ini dengan identitas SESSION PHP yang
      // sungguhan -- menutup celah di atas untuk aksi berikutnya di tab ini.
      if (window.db && typeof window.db.mapUserRow === 'function' && String(profil.id) !== String(freelancerId)) {
        sessionStorage.setItem('currentUser', JSON.stringify(window.db.mapUserRow(profil)));
      }

      return fetchJsonSafe(`${API_BASE}/apply_project.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ project_id: projectId, freelancer_id: profil.id }),
      });
    })
    .then((data) => {
      setAppliedState();
      showSuccessToast('Lamaran berhasil dikirim!');

      // Sinkronkan cache lokal agar jumlah pelamar di kartu ikut ter-update --
      // pakai freelancer_id yang DIKONFIRMASI server (data.data), bukan
      // parameter yang mungkin sudah basi.
      const confirmedFreelancerId = (data.data && data.data.freelancer_id) || freelancerId;
      if (window.db && typeof window.db.applyProject === 'function') {
        try { window.db.applyProject(projectId, confirmedFreelancerId); } catch (e) { /* cache-only */ }
      }
      return data;
    })
    .catch((error) => {
      console.error(error);
      if (error.code === 'already_applied') {
        // Sudah pernah melamar — tampilkan sebagai Applied, bukan error
        setAppliedState();
        return;
      }
      if (btn) {
        btn.disabled = false;
        btn.textContent = originalLabel;
      }
      if (error.code === 'profile_incomplete') {
        alert(PESAN_PROFIL_BELUM_LENGKAP);
        throw error;
      }
      if (error.code === 'location_missing') {
        alert(PESAN_LOKASI_BELUM_DIISI);
        throw error;
      }
      if (error.code === 'session_role_mismatch') {
        alert(PESAN_SESI_TIDAK_COCOK);
        window.location.reload();
        throw error;
      }
      alert(error.message || 'Gagal mengirim lamaran. Coba lagi.');
      throw error;
    });
}
window.applyProject = applyProject;

// ═══ BAGIAN 3: FRONTEND UMKM — daftar pelamar di modal ═════
// Halaman bisa memakai #applicants-container (struktur generik) ATAU
// #pdm-applicants-list (modal detail proyek di umkm-projects.html).
function getApplicantsContainer() {
  return document.getElementById('applicants-container')
      || document.querySelector('#project-detail-backdrop #pdm-applicants-list');
}

function loadApplicants(projectId) {
  const container = getApplicantsContainer();
  if (!container) return;

  container.innerHTML = `<p style="color: var(--text-muted); font-size: 0.9rem;">Memuat pelamar...</p>`;

  const API_BASE = (window.db && window.db.API_BASE) || 'api';

  fetchJsonSafe(`${API_BASE}/get_applicants.php?project_id=${encodeURIComponent(projectId)}`)
    .then((data) => {
      // Modal mungkin sudah pindah ke proyek lain saat response ini tiba
      const backdrop = document.getElementById('project-detail-backdrop');
      const activeId = backdrop ? backdrop.getAttribute('data-current-project-id') : null;
      if (activeId && String(activeId) !== String(projectId)) return;

      const applicants = data.data || [];
      container.innerHTML = applicants.length
        ? applicants.map((a) => applicantRow(a, projectId)).join('')
        : `<p style="color: var(--text-muted); font-size: 0.9rem;">Belum ada pelamar.</p>`;
    })
    .catch((error) => {
      console.error(error);
      container.innerHTML = `<p style="color: var(--color-closed); font-size: 0.9rem;">Gagal memuat data pelamar. Buka Console browser (F12) untuk detail error.</p>`;
    });
}
window.loadApplicants = loadApplicants;

function applicantRow(a, projectId) {
  const name = escapeHtml(a.name);
  const email = escapeHtml(a.email);
  const avatar = (a.name || '?').split(' ').map((w) => w[0]).join('').substring(0, 2).toUpperCase();
  const skills = (a.skills || []).slice(0, 3).map((s) => `<span class="dm-pill">${escapeHtml(s)}</span>`).join('');
  const bio = a.bio ? `<p style="margin: 2px 0 0; font-size: 0.78rem; color: var(--text-muted); font-style: italic;">${escapeHtml(a.bio)}</p>` : '';
  const applicantId = Number(a.applicant_id);
  const pid = Number(projectId);

  let actionsHTML;
  if (a.status === 'Pending') {
    actionsHTML = `
      <button type="button" class="btn btn-success btn-sm" onclick="updateApplicant(${applicantId}, 'Diterima', ${pid}, this)">Terima</button>
      <button type="button" class="btn btn-danger btn-sm" onclick="updateApplicant(${applicantId}, 'Ditolak', ${pid}, this)">Tolak</button>
    `;
  } else if (a.status === 'Diterima') {
    actionsHTML = `<span class="badge badge-open">Diterima</span>`;
  } else {
    actionsHTML = `<span class="badge badge-closed">Ditolak</span>`;
  }

  return `
    <div class="applicant-row" style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); flex-wrap: wrap;">
      <div style="display: flex; align-items: center; gap: 0.75rem;">
        <div class="user-avatar" style="width: 40px; height: 40px; font-size: 1rem;">${avatar}</div>
        <div>
          <h4 style="margin: 0; font-size: 0.95rem; color: var(--text-color);">${name}</h4>
          <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted);">${email}</p>
          ${bio}
          ${skills ? `<div class="dm-pills" style="margin-top: 4px;">${skills}</div>` : ''}
        </div>
      </div>
      <div class="applicant-actions" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
        <button type="button" class="btn btn-outline btn-sm btn-view-profile" data-id="${Number(a.freelancer_id)}">Lihat Profil</button>
        <button type="button" class="btn btn-outline btn-sm btn-view-history" data-id="${Number(a.freelancer_id)}" data-name="${name}">Lihat Riwayat</button>
        ${actionsHTML}
      </div>
    </div>
  `;
}

function updateApplicant(applicantId, status, projectId, btnEl) {
  // Cegah klik ganda selama request berjalan
  if (btnEl) {
    const wrap = btnEl.closest('.applicant-actions');
    if (wrap) wrap.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    btnEl.textContent = 'Menyimpan...';
  }

  const API_BASE = (window.db && window.db.API_BASE) || 'api';
  const currentUser = window.db ? db.getCurrentUser() : null;

  fetchJsonSafe(`${API_BASE}/update_applicant_status.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      applicant_id: applicantId,
      status: status,
      umkm_id: currentUser ? currentUser.id : null,
    }),
  })
    .then((res) => {
      // Pesan dari server sudah menjelaskan efeknya, mis. "Freelancer berhasil
      // diterima. Proyek sekarang berstatus In Progress."
      showSuccessToast(res.message || `Pelamar berhasil ${status === 'Diterima' ? 'diterima' : 'ditolak'}.`);

      const projectStatus = res.data && res.data.project_status;
      if (projectStatus) {
        // Update badge status proyek di header modal secara langsung
        const backdrop = document.getElementById('project-detail-backdrop');
        const statusBadge = backdrop ? backdrop.querySelector('#pdm-status') : null;
        if (statusBadge) {
          statusBadge.textContent = projectStatus;
          statusBadge.className = 'badge ' + statusBadgeClass(projectStatus);
        }
        // Sinkronkan cache lokal db.js agar kartu proyek di grid ikut ter-update
        if (window.db && typeof window.db.updateProject === 'function') {
          window.db.updateProject(projectId, { status: projectStatus });
        }
      }

      // Muat ulang daftar pelamar (tombol → badge), termasuk pelamar lain yang
      // otomatis ditolak saat status = Diterima
      loadApplicants(projectId);

      // Refresh grid "Kelola Proyek" di belakang modal tanpa reload halaman
      if (typeof renderUMKMProjects === 'function' && document.getElementById('umkm-projects-grid')) {
        renderUMKMProjects();
      }
    })
    .catch((error) => {
      console.error(error);
      alert(error.message || 'Gagal memperbarui status pelamar. Coba lagi.');
      loadApplicants(projectId);
    });
}
// Alias — beberapa halaman mungkin memanggil nama fungsi ini
window.updateApplicantStatus = updateApplicant;
window.updateApplicant = updateApplicant;

// ── Modal "Riwayat Freelancer" (dipakai UMKM dari daftar pelamar) ────
// Menampilkan proyek Completed milik freelancer + rating & ulasan klien lama.
function ensureFreelancerHistoryModal() {
  let backdrop = document.getElementById('freelancer-history-backdrop');
  if (backdrop) return backdrop;

  backdrop = document.createElement('div');
  backdrop.className = 'detail-backdrop';
  backdrop.id = 'freelancer-history-backdrop';
  backdrop.hidden = true;
  backdrop.setAttribute('aria-modal', 'true');
  backdrop.setAttribute('role', 'dialog');
  backdrop.innerHTML = `
    <div class="detail-modal" style="max-width: 560px;">
      <div class="dm-header">
        <div class="dm-header-info">
          <h2 class="dm-title" style="font-size:1.05rem;">Riwayat Proyek Freelancer</h2>
          <div class="dm-subtitle" id="fh-subtitle">—</div>
        </div>
        <button class="dm-close-btn" id="fh-close" aria-label="Tutup riwayat">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="dm-body">
        <div id="fh-list" style="display:flex; flex-direction:column; gap:0.75rem; max-height:60vh; overflow-y:auto;"></div>
      </div>
    </div>`;
  document.body.appendChild(backdrop);

  backdrop.querySelector('#fh-close').addEventListener('click', () => window.closeModal(backdrop));
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) window.closeModal(backdrop);
  });

  return backdrop;
}

function goldStars(n) {
  if (!n) return '<span style="color:var(--text-muted); font-size:0.8rem;">Belum ada rating</span>';
  let html = '';
  for (let i = 1; i <= 5; i++) {
    html += `<span style="color:${i <= n ? '#f5a623' : '#cbd5cb'};">★</span>`;
  }
  return `${html} <span style="font-size:0.8rem; color:var(--text-muted);">(${n}/5)</span>`;
}

// Render daftar kartu riwayat proyek (dipakai BERSAMA oleh modal "Lihat
// Riwayat" berdiri sendiri (showFreelancerHistoryModal, daftar pelamar) DAN
// seksi "Riwayat Proyek" di dalam showFreelancerProfileModal (Talent AI +
// Kelola Proyek) -- satu-satunya tempat markup kartu riwayat didefinisikan,
// supaya tidak ada logika/tampilan yang terduplikasi/berbeda di dua tempat.
function renderFreelancerHistoryList(projects) {
  if (!projects || !projects.length) {
    return '<p style="color:var(--text-muted); font-size:0.9rem;">Freelancer ini belum pernah menyelesaikan proyek di platform.</p>';
  }
  return projects.map((p) => {
    const budget = window.formatCurrencyIndo ? window.formatCurrencyIndo(p.budget) : p.budget;
    const tgl = window.formatDateIndo ? window.formatDateIndo(String(p.completed_at || '').split(' ')[0]) : (p.completed_at || '-');
    const review = p.review_text
      ? `<p style="margin:6px 0 0; font-size:0.85rem; color:var(--text-color); font-style:italic;">"${escapeHtml(p.review_text)}"</p>`
      : '<p style="margin:6px 0 0; font-size:0.8rem; color:var(--text-muted);">Tidak ada ulasan tertulis.</p>';
    return `
      <div style="padding:0.75rem; border:1px solid var(--border-color); border-radius:var(--radius-md);">
        <div style="display:flex; justify-content:space-between; gap:0.5rem; flex-wrap:wrap;">
          <h4 style="margin:0; font-size:0.95rem; color:var(--text-color);">${escapeHtml(p.title)}</h4>
          <span style="font-size:0.9rem;">${goldStars(p.rating)}</span>
        </div>
        <p style="margin:4px 0 0; font-size:0.8rem; color:var(--text-muted);">
          Klien: ${escapeHtml(p.client_name || '-')} · Selesai: ${tgl} · Nilai proyek: ${budget}
        </p>
        ${review}
      </div>`;
  }).join('');
}

// Ringkasan satu baris ("N proyek selesai · rating X ★") -- juga dipakai
// bersama oleh kedua tempat di atas.
function formatFreelancerHistorySummary(summary) {
  const total = (summary && summary.total_completed) || 0;
  const avg = summary && summary.avg_rating;
  return `${total} proyek selesai` + (avg ? ` · Rata-rata rating ${avg} ★` : '');
}

function showFreelancerHistoryModal(freelancerId, freelancerName) {
  const backdrop = ensureFreelancerHistoryModal();
  const subtitle = backdrop.querySelector('#fh-subtitle');
  const list = backdrop.querySelector('#fh-list');

  subtitle.textContent = freelancerName ? `Proyek yang pernah diselesaikan oleh ${freelancerName}` : 'Memuat...';
  list.innerHTML = '<p style="color:var(--text-muted); font-size:0.9rem;">Memuat riwayat...</p>';
  window.openModal(backdrop);

  const API_BASE = (window.db && window.db.API_BASE) || 'api';

  fetchJsonSafe(`${API_BASE}/get_freelancer_history.php?freelancer_id=${encodeURIComponent(freelancerId)}`)
    .then((res) => {
      const d = res.data || {};
      const projects = d.projects || [];
      const name = (d.freelancer && d.freelancer.name) || freelancerName || 'Freelancer';

      subtitle.textContent = `${name} · ${formatFreelancerHistorySummary(d.summary)}`;
      list.innerHTML = renderFreelancerHistoryList(projects);
    })
    .catch((err) => {
      console.error('[riwayat]', err);
      list.innerHTML = `<p style="color:#c0392b; font-size:0.9rem;">${escapeHtml(err.message || 'Gagal memuat riwayat freelancer.')}</p>`;
    });
}
window.showFreelancerHistoryModal = showFreelancerHistoryModal;

// Delegasi klik tombol "Lihat Riwayat" di daftar pelamar
document.body.addEventListener('click', (e) => {
  const historyBtn = e.target.closest('.btn-view-history');
  if (historyBtn) {
    showFreelancerHistoryModal(historyBtn.dataset.id, historyBtn.dataset.name || '');
  }
});

function populateAndOpenProjectModal(p, openModal) {
  const backdrop = document.getElementById('project-detail-backdrop');
  if (!backdrop) return;

  // Track the current project ID for approval actions
  if (p && p.id) {
    backdrop.setAttribute('data-current-project-id', p.id);
  }

  if (typeof window.switchProjectModalView === 'function') {
    window.switchProjectModalView('project');
  }

  // Detect which dataset this project is from
  const isAiMatch = typeof p.matchPercent !== 'undefined';
  const isUMKMProjectsPage = document.getElementById("umkm-projects-grid") !== null;

  backdrop.querySelector('#pdm-title').textContent = p.title;

  const statusBadge = backdrop.querySelector('#pdm-status');
  if (statusBadge) {
    statusBadge.textContent = p.status;
    statusBadge.className = 'badge ' + statusBadgeClass(p.status);
  }

  const applicantsCount = Array.isArray(p.applicants) ? p.applicants.length : (p.applicants || 0);

  const formattedPrize = (p.prize && p.prize.toString().startsWith('Rp'))
    ? p.prize
    : (window.formatCurrencyIndo ? window.formatCurrencyIndo(p.prize) : p.prize);
  const formattedDeadline = window.formatDateIndo ? window.formatDateIndo(p.deadline) : p.deadline;

  if (isUMKMProjectsPage) {
    backdrop.querySelector('#pdm-subtitle').textContent = `${p.location} · ${applicantsCount} pelamar · Deadline: ${formattedDeadline}`;
  } else {
    backdrop.querySelector('#pdm-subtitle').textContent = isAiMatch
      ? `${p.location} · ${p.applicants} pelamar · Deadline: ${formattedDeadline}`
      : `${p.location} · Deadline: ${formattedDeadline}`;
  }

  backdrop.querySelector('#pdm-description').textContent = p.description || '—';
  
  const reqEl = backdrop.querySelector('#pdm-requirements');
  if (reqEl) {
    // Preserve line breaks if provided
    reqEl.innerHTML = p.requirements ? p.requirements.replace(/\n/g, '<br>') : 'Freelancer wajib memiliki portofolio relevan. Pembayaran 50% di muka, 50% setelah selesai.';
  }

  // Skills or Categories as pills
  const pillContainer = backdrop.querySelector('#pdm-skills');
  const skillsArray = p.skills || p.categories || [];
  pillContainer.innerHTML = skillsArray.map(s => `<span class="dm-pill">${s}</span>`).join('');

  // Price
  backdrop.querySelector('#pdm-price').textContent = formattedPrize || '—';

  // Applicants Rendering (UMKM Projects Only) — dimuat live dari server
  // agar status Terima/Tolak selalu sesuai data terbaru di database.
  if (isUMKMProjectsPage) {
    loadApplicants(p.id);
    updateUmkmReviewSection(p);   // tombol Terima Hasil / Revisi / Tolak saat Submitted
    updateUmkmChatSection(p);     // chat UMKM ↔ freelancer di dalam modal
  }

  // Info freelancer yang mengerjakan (dipakai halaman Admin "Semua Proyek")
  updateFreelancerInfoSection(backdrop, p);

  // Hasil pekerjaan saya (dipakai freelancer sendiri di halaman "Proyek Saya")
  updateFreelancerSubmissionSection(backdrop, p, isUMKMProjectsPage);

  // Apply / Submit Button Integration
  const applyBtn = backdrop.querySelector('#btn-ambil-proyek');
  if (applyBtn) {
    const currentUser = window.db ? db.getCurrentUser() : null;
    const currentProject = window.db ? (db.getProjectById(p.id) || p) : p;
    const assignedId = currentProject.freelancerId ?? currentProject.assignedTo;
    const isAssignedFreelancer = currentUser && assignedId && String(assignedId) === String(currentUser.id);

    // Replace the button to strip any old event listeners
    const newApplyBtn = applyBtn.cloneNode(true);
    applyBtn.parentNode.replaceChild(newApplyBtn, applyBtn);

    if (isAssignedFreelancer && currentProject.status === 'In Progress') {
      // Freelancer yang mengerjakan → submit hasil ke client (status → Submitted)
      newApplyBtn.textContent = 'Submit Proyek';
      newApplyBtn.disabled = false;
      newApplyBtn.addEventListener('click', () => {
        window.submitProject(currentProject.id, newApplyBtn);
      });
    } else if (isAssignedFreelancer && currentProject.status === 'Submitted') {
      newApplyBtn.textContent = 'Menunggu Review Client';
      newApplyBtn.disabled = true;
    } else if (isAssignedFreelancer && currentProject.status === 'Completed') {
      // Proyek sudah selesai — tidak ada aksi lanjutan, tombol berfungsi
      // sebagai penutup modal yang mulus (bukan tombol mati/disabled).
      newApplyBtn.textContent = 'Proyek Selesai ✓';
      newApplyBtn.disabled = false;
      newApplyBtn.title = 'Klik untuk menutup';
      newApplyBtn.addEventListener('click', () => {
        window.closeModal(backdrop);
      });
    } else {
      // Always compute state explicitly to prevent leaking state between cards
      if (currentProject.status !== 'Open') {
        newApplyBtn.textContent = 'Ditutup';
        newApplyBtn.disabled = true;
      } else if (currentUser && currentProject.applicants && currentProject.applicants.includes(currentUser.id)) {
        newApplyBtn.textContent = 'Sudah Dilamar';
        newApplyBtn.disabled = true;
      } else {
        newApplyBtn.textContent = 'Lamar Proyek';
        newApplyBtn.disabled = false;
      }

      newApplyBtn.addEventListener('click', () => {
        if (newApplyBtn.disabled) return;
        if (!currentUser) {
          alert('Anda harus login terlebih dahulu untuk melamar proyek.');
          return;
        }

        const isServer = window.location.protocol === 'http:' || window.location.protocol === 'https:';
        if (isServer) {
          // Simpan lamaran ke MySQL via API agar muncul di modal
          // "Kelola Proyek" milik UMKM. applyProject() menangani sendiri
          // state tombol (Mengirim... → Applied) dan pesan errornya.
          applyProject(currentProject.id, currentUser.id, newApplyBtn).catch(() => {});
          return;
        }

        // Mode file:// (tanpa server) — fallback penyimpanan lokal.
        // Validasi Skill, Minat, & Lokasi tetap diberlakukan dari data user lokal.
        const localUser = db.getUserById(currentUser.id) || currentUser;
        const adaSkill = Array.isArray(localUser.skills) && localUser.skills.length > 0;
        const adaMinat = Array.isArray(localUser.interests) && localUser.interests.length > 0;
        if (!adaSkill || !adaMinat) {
          alert('Anda diwajibkan mengisi data Minat dan Keahlian (Skill) di profil Anda sebelum melamar proyek ini!');
          return;
        }
        const adaLokasi = !!(localUser.location && String(localUser.location).trim())
          && localUser.latitude != null && localUser.longitude != null;
        if (!adaLokasi) {
          alert('Anda diwajibkan mengisi Lokasi di profil Anda sebelum melamar proyek ini! Silakan lengkapi lewat halaman Profil Saya.');
          return;
        }
        const res = db.applyProject(currentProject.id, currentUser.id);
        if (res) {
          showSuccessToast('Lamaran berhasil dikirim!');
          newApplyBtn.textContent = 'Sudah Dilamar';
          newApplyBtn.disabled = true;
        } else {
          alert('Gagal melamar. Proyek mungkin sudah ditutup atau Anda sudah pernah melamar.');
        }
      });
    }
  }

  openModal(backdrop);
}

// ── Seksi review hasil submit (modal Kelola Proyek UMKM) ─────────────
// Tampil hanya saat project_status = 'Submitted'. Menampilkan tautan hasil
// pekerjaan (submission_link) + form rating bintang & ulasan teks. Tombol:
//   Terima Hasil → Completed | Minta Revisi → In Progress | Tolak → Closed
function updateUmkmReviewSection(p) {
  const section = document.getElementById('pdm-review-section');
  const completedSection = document.getElementById('pdm-review-completed');
  if (!section) return;

  const setLink = (linkEl, link, emptyText) => {
    if (!linkEl) return;
    if (link) {
      linkEl.href = /^https?:\/\//i.test(link) ? link : 'https://' + link;
      linkEl.textContent = link;
    } else {
      linkEl.removeAttribute('href');
      linkEl.textContent = emptyText;
    }
  };

  const visible = (p.status === 'Submitted');
  section.style.display = visible ? '' : 'none';

  if (visible) {
    if (completedSection) completedSection.style.display = 'none';

    // Tautan hasil pekerjaan dari freelancer
    setLink(document.getElementById('pdm-submission-link'), p.submissionLink || p.submission_link || '', 'Freelancer belum menyertakan tautan.');

    // Reset form rating & ulasan setiap kali modal dibuka
    const textEl = document.getElementById('pdm-review-text');
    if (textEl) textEl.value = '';
    setPdmRating(0);
    initPdmRatingStars();
    return;
  }

  // Proyek Completed: tampilkan hasil pekerjaan & keputusan review secara
  // read-only. Tautan tetap dirender & bisa diklik kapan saja — TIDAK pernah
  // dihapus dari sini (submission_link hanya dikosongkan backend saat Revisi).
  if (p.status === 'Completed' && completedSection) {
    completedSection.style.display = '';
    setLink(document.getElementById('pdm-completed-link'), p.submissionLink || p.submission_link || '', 'Tidak ada tautan tersimpan.');

    const ratingEl = document.getElementById('pdm-completed-rating');
    if (ratingEl) {
      const r = p.rating;
      ratingEl.textContent = r ? `${'★'.repeat(r)}${'☆'.repeat(5 - r)} (${r}/5)` : 'Belum ada rating.';
    }
    const reviewEl = document.getElementById('pdm-completed-review');
    if (reviewEl) reviewEl.textContent = (p.reviewText || p.review_text) || 'Tidak ada ulasan tertulis.';
    return;
  }

  if (completedSection) completedSection.style.display = 'none';
}

// Nilai rating terpilih (1-5) di form review UMKM
function setPdmRating(value) {
  const wrap = document.getElementById('pdm-rating-stars');
  if (!wrap) return;
  wrap.dataset.rating = String(value);
  wrap.querySelectorAll('.pdm-star').forEach((star) => {
    star.style.color = Number(star.dataset.value) <= value ? '#f5a623' : '#cbd5cb';
  });
}

function getPdmRating() {
  const wrap = document.getElementById('pdm-rating-stars');
  return wrap ? Number(wrap.dataset.rating || 0) : 0;
}

function initPdmRatingStars() {
  const wrap = document.getElementById('pdm-rating-stars');
  if (!wrap || wrap.__starsInit) return;
  wrap.__starsInit = true;
  wrap.addEventListener('click', (e) => {
    const star = e.target.closest('.pdm-star');
    if (star) setPdmRating(Number(star.dataset.value));
  });
}

// ── Seksi chat UMKM ↔ Freelancer (modal Kelola Proyek UMKM) ──────────
function updateUmkmChatSection(p) {
  const section = document.getElementById('pdm-chat-section');
  if (!section) return;

  const currentUser = window.db ? db.getCurrentUser() : null;
  const freelancerId = p.freelancerId ?? p.assignedTo;
  const status = p.status || p.project_status;
  // Chat disembunyikan setelah proyek Completed (UMKM sudah menerima hasil) —
  // diskusi dianggap selesai bersamaan dengan proyeknya.
  const chatReady = !!freelancerId && !!currentUser && status !== 'Completed';

  if (!chatReady) {
    section.style.display = 'none';
    return;
  }

  section.style.display = '';
  const nameEl = document.getElementById('pdm-chat-partner');
  if (nameEl) nameEl.textContent = p.freelancerName || 'Freelancer';

  initChatBox({
    projectId: p.id,
    messagesEl: document.getElementById('pdm-chat-messages'),
    inputEl: document.getElementById('pdm-chat-input'),
    sendBtn: document.getElementById('pdm-chat-send'),
  });
}

// ── Info "Freelancer yang Mengerjakan" (detail proyek Admin) ─────────
function updateFreelancerInfoSection(backdrop, p) {
  const isAdminPage = !!document.querySelector('.admin-layout');
  let section = backdrop.querySelector('#pdm-freelancer-section');

  if (!isAdminPage) {
    if (section) section.style.display = 'none';
    return;
  }

  if (!section) {
    section = document.createElement('div');
    section.className = 'dm-section';
    section.id = 'pdm-freelancer-section';
    section.innerHTML = `
      <span class="dm-section-label">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Pemilik &amp; Pengerjaan Proyek
      </span>
      <p class="dm-section-text" style="margin-bottom:4px;">
        <strong>Nama Bisnis (UMKM):</strong> <span id="pdm-owner-business">—</span>
      </p>
      <p class="dm-section-text">
        <strong>Freelancer yang Mengerjakan:</strong> <span id="pdm-freelancer-name">—</span>
      </p>`;
    const body = backdrop.querySelector('#pdm-view-project') || backdrop.querySelector('.dm-body');
    if (body) body.appendChild(section);
  }

  section.style.display = '';

  // Nama bisnis UMKM pemilik proyek (fallback: nama akun pembuatnya)
  const ownerEl = section.querySelector('#pdm-owner-business');
  if (ownerEl) {
    const creator = (window.db && p.createdBy) ? db.getUserById(p.createdBy) : null;
    const businessName = p.creatorName
      || (creator && (creator.businessName || creator.business_name || creator.name))
      || '';
    ownerEl.textContent = businessName || 'Tidak diketahui';
  }

  const nameEl = section.querySelector('#pdm-freelancer-name');
  const freelancerId = p.freelancerId ?? p.assignedTo;
  if (nameEl) {
    nameEl.textContent = freelancerId
      ? `${p.freelancerName || 'Freelancer #' + freelancerId} (ID: ${freelancerId})`
      : 'Belum ada freelancer yang mengerjakan proyek ini.';
  }
}

// ── Info "Hasil Pekerjaan Saya" (freelancer melihat tautan submission_link
// beserta rating/ulasan yang diterima, di halaman "Proyek Saya" miliknya
// sendiri) — bagian ini SELALU dirender saat status Submitted atau Completed,
// termasuk setelah UMKM menerima hasil, sesuai submission_link yang memang
// tetap tersimpan (tidak pernah dihapus backend saat diterima).
function updateFreelancerSubmissionSection(backdrop, p, isUMKMProjectsPage) {
  const isAdminPage = !!document.querySelector('.admin-layout');
  const currentUser = window.db ? db.getCurrentUser() : null;
  const freelancerId = p.freelancerId ?? p.assignedTo;
  const isOwnProject = !!(currentUser && currentUser.role === 'freelancer' && freelancerId && String(freelancerId) === String(currentUser.id));
  const status = p.status || p.project_status;
  const shouldShow = !isUMKMProjectsPage && !isAdminPage && isOwnProject && (status === 'Submitted' || status === 'Completed');

  let section = backdrop.querySelector('#pdm-my-submission-section');

  if (!shouldShow) {
    if (section) section.style.display = 'none';
    return;
  }

  if (!section) {
    section = document.createElement('div');
    section.className = 'dm-section';
    section.id = 'pdm-my-submission-section';
    section.innerHTML = `
      <span class="dm-section-label">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Hasil Pekerjaan Saya
      </span>
      <div style="margin-bottom: 0.5rem; padding: 0.6rem 0.75rem; border: 1px dashed var(--border-color); border-radius: var(--radius-md); background: var(--bg-color, #fff);">
        <span style="display:block; font-size:0.75rem; color:var(--text-muted); margin-bottom:2px;">Tautan Hasil Pekerjaan</span>
        <a href="#" id="pdm-my-submission-link" target="_blank" rel="noopener"
           style="color: var(--accent-green); word-break: break-all; font-size: 0.9rem;">—</a>
      </div>
      <p class="dm-section-text" id="pdm-my-review-wrap" style="display:none; margin-bottom:0;">
        <strong>Rating dari Client:</strong> <span id="pdm-my-rating">—</span><br>
        <strong>Ulasan Client:</strong> <span id="pdm-my-review-text">—</span>
      </p>`;
    const body = backdrop.querySelector('.dm-body');
    if (body) body.appendChild(section);
  }

  section.style.display = '';

  const link = p.submissionLink || p.submission_link || '';
  const linkEl = section.querySelector('#pdm-my-submission-link');
  if (linkEl) {
    if (link) {
      linkEl.href = /^https?:\/\//i.test(link) ? link : 'https://' + link;
      linkEl.textContent = link;
    } else {
      linkEl.removeAttribute('href');
      linkEl.textContent = 'Belum ada tautan tersimpan.';
    }
  }

  const reviewWrap = section.querySelector('#pdm-my-review-wrap');
  if (reviewWrap) {
    if (status === 'Completed') {
      reviewWrap.style.display = '';
      const ratingEl = section.querySelector('#pdm-my-rating');
      if (ratingEl) {
        const r = p.rating;
        ratingEl.textContent = r ? `${'★'.repeat(r)}${'☆'.repeat(5 - r)} (${r}/5)` : 'Belum ada rating.';
      }
      const reviewTextEl = section.querySelector('#pdm-my-review-text');
      if (reviewTextEl) reviewTextEl.textContent = (p.reviewText || p.review_text) || 'Tidak ada ulasan tertulis.';
    } else {
      reviewWrap.style.display = 'none';
    }
  }
}

function populateAndOpenLocationModal(loc, openModal) {
  const backdrop = document.getElementById('location-detail-backdrop');
  if (!backdrop) return;

  backdrop.querySelector('#ldm-title').textContent = loc.name;
  backdrop.querySelector('#ldm-client').textContent = `${loc.tag} · ${loc.projects} proyek aktif`;
  backdrop.querySelector('#ldm-description').textContent = loc.description;
  backdrop.querySelector('#ldm-address').textContent = `Kawasan ${loc.tag}, Kabupaten Bantul, DI Yogyakarta`;
  backdrop.querySelector('#ldm-contact').textContent = `wa.me/628100000${loc.id} · info@bantulcreative.id`;

  const tagContainer = backdrop.querySelector('#ldm-tags');
  tagContainer.innerHTML = loc.categories.map(c => `<span class="dm-pill">${c}</span>`).join('');

  openModal(backdrop);
}

function populateAndOpenPortfolioModal(p, openModal) {
  const backdrop = document.getElementById('portfolio-detail-backdrop');
  if (!backdrop) return;

  backdrop.querySelector('#pfdm-title').textContent = p.title;
  backdrop.querySelector('#pfdm-subtitle').textContent = `oleh ${p.freelancer} · ${p.date}`;
  backdrop.querySelector('#pfdm-description').textContent = p.description;
  backdrop.querySelector('#pfdm-impact-text').textContent = p.impact;

  const metricsContainer = backdrop.querySelector('#pfdm-metrics');
  metricsContainer.innerHTML = p.metrics.map(m => `
    <span class="dm-metric-badge">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
      ${m.label}
    </span>`).join('');

  openModal(backdrop);
}


// ─── Notification Dropdown ───────────────────────────────
function initNotificationDropdown() {
  const notifWrap = document.querySelector('.notif-btn-wrap');
  const notifBtn  = document.querySelector('.notif-btn');
  const dropdown  = document.getElementById('notif-dropdown');

  if (!notifWrap || !notifBtn || !dropdown) return;

  let isOpen = false;

  function openDropdown() {
    dropdown.hidden = false;
    requestAnimationFrame(() => dropdown.classList.add('nd-visible'));
    isOpen = true;
  }

  function closeDropdown() {
    dropdown.classList.remove('nd-visible');
    dropdown.addEventListener('transitionend', function handler() {
      dropdown.hidden = true;
      dropdown.removeEventListener('transitionend', handler);
    });
    isOpen = false;
  }

  notifBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    isOpen ? closeDropdown() : openDropdown();
  });

  // Close on outside click
  document.addEventListener('click', (e) => {
    if (isOpen && !notifWrap.contains(e.target)) closeDropdown();
  });

  // Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isOpen) closeDropdown();
  });

  // Tab switching
  const tabs = dropdown.querySelectorAll('.nd-tab');
  const panels = dropdown.querySelectorAll('.nd-panel');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('nd-tab--active'));
      panels.forEach(p => p.classList.remove('nd-panel--active'));
      tab.classList.add('nd-tab--active');
      const target = dropdown.querySelector(`#${tab.dataset.target}`);
      if (target) target.classList.add('nd-panel--active');
    });
  });

  // Mark all as read
  const markAllBtn = dropdown.querySelector('.nd-mark-all');
  if (markAllBtn) {
    markAllBtn.addEventListener('click', () => {
      dropdown.querySelectorAll('.nd-item.nd-unread').forEach(item => {
        item.classList.remove('nd-unread');
        const dot = item.querySelector('.nd-item-dot');
        if (dot) dot.style.visibility = 'hidden';
      });
      // Reset badge count
      const badge = document.querySelector('.notif-badge');
      if (badge) badge.textContent = '0';
    });
  }
}

// ============================================================
// PROFILE UPDATE LOGIC
// ============================================================
function updateProfileUI() {
  const userJson = sessionStorage.getItem('currentUser');
  if (!userJson) return;
  const user = JSON.parse(userJson);

  // 1. Update text content
  const navName = document.getElementById('nav-user-name');
  const dropdownName = document.getElementById('dropdown-user-name');
  const dropdownEmail = document.getElementById('dropdown-user-email');
  
  // Display only first name in navbar to save space
  if (navName) navName.textContent = user.name.split(' ')[0]; 
  if (dropdownName) dropdownName.textContent = user.name;
  if (dropdownEmail) dropdownEmail.textContent = user.email;
  
  // 2. Avatar Initials Logic
  const initials = user.name
    .split(' ')
    .map(word => word[0])
    .join('')
    .substring(0, 2)
    .toUpperCase();
    
  const navAvatar = document.getElementById('nav-user-avatar');
  const dropdownAvatar = document.getElementById('dropdown-user-avatar');
  
  if (navAvatar) navAvatar.textContent = initials;
  if (dropdownAvatar) dropdownAvatar.textContent = initials;

  // 3. Update Dropdown Content
  const umkmSection = document.getElementById('pm-section-umkm');
  const skillsSection = document.getElementById('pm-section-skills');
  const interestsSection = document.getElementById('pm-section-interests');

  if (user.role === 'umkm') {
    if (umkmSection) {
      umkmSection.style.display = 'block';
      const catEl = document.getElementById('pm-umkm-category');
      const locEl = document.getElementById('pm-umkm-location');
      if (catEl) catEl.textContent = user.businessCategory || '—';
      if (locEl) locEl.textContent = user.location || '—';
    }
    if (skillsSection) skillsSection.style.display = 'none';
    if (interestsSection) interestsSection.style.display = 'none';
  } else {
    if (umkmSection) umkmSection.style.display = 'none';
    
    if (skillsSection) {
      skillsSection.style.display = 'block';
      const container = document.getElementById('pm-skills-container');
      if (container) {
        if (user.skills && user.skills.length > 0) {
          container.innerHTML = user.skills.map(s => `<span>${s}</span>`).join('');
        } else {
          container.innerHTML = '<span style="color:var(--text-muted); font-size:var(--fs-sm);">Belum ada data</span>';
        }
      }
    }

    if (interestsSection) {
      interestsSection.style.display = 'block';
      const container = document.getElementById('pm-interests-container');
      if (container) {
        if (user.interests && user.interests.length > 0) {
          container.innerHTML = user.interests.map(i => `<span>${i}</span>`).join('');
        } else {
          container.innerHTML = '<span style="color:var(--text-muted); font-size:var(--fs-sm);">Belum ada data</span>';
        }
      }
    }
  }

  // Sinkron ulang SEKALI per muat halaman dari server (api/me.php) --
  // sessionStorage bisa berisi bentuk lama/tidak lengkap (mis. login terjadi
  // sebelum login.php mengembalikan profil penuh, atau profil diubah dari
  // tab lain). Tanpa ini, "Detail Bisnis"/"Skill yang Dikuasai"/"Minat &
  // Bidang" di modal profil tampil kosong sampai user logout-login ulang.
  const isServer = window.location.protocol === 'http:' || window.location.protocol === 'https:';
  if (isServer && !updateProfileUI._syncedFromServer) {
    updateProfileUI._syncedFromServer = true;
    const API_BASE = (window.db && window.db.API_BASE) || 'api';
    fetch(`${API_BASE}/me.php`)
      .then((res) => (res.ok ? res.json() : null))
      .then((json) => {
        if (!json || json.status !== 'success' || !json.data) return;
        const freshUser = (window.db && typeof window.db.mapUserRow === 'function')
          ? window.db.mapUserRow(json.data)
          : json.data;
        // Hanya timpa kalau memang user yang sama (jaga-jaga race dgn logout)
        if (String(freshUser.id) !== String(user.id)) return;
        sessionStorage.setItem('currentUser', JSON.stringify(freshUser));
        updateProfileUI(); // render ulang dengan data lengkap (guard mencegah loop)
      })
      .catch((err) => console.error('[profile-ui] Gagal sinkron profil dari server:', err));
  }
}

// ============================================================
// FREELANCER PROFILE MODAL (UMKM VIEW)
// ============================================================
window.switchProjectModalView = function(viewName) {
  const pHeader = document.getElementById('pdm-header-project');
  const profHeader = document.getElementById('pdm-header-profile');
  const pView = document.getElementById('pdm-view-project');
  const profView = document.getElementById('pdm-view-profile');
  
  if (!pHeader || !profHeader || !pView || !profView) return;

  if (viewName === 'profile') {
    pHeader.style.display = 'none';
    pView.style.display = 'none';
    profHeader.style.display = 'flex';
    profView.style.display = 'block';
  } else {
    // Default to 'project'
    profHeader.style.display = 'none';
    profView.style.display = 'none';
    pHeader.style.display = 'flex';
    pView.style.display = 'block';
  }
};

window.showFreelancerProfileModal = async function(userId) {
  const backdrop = document.getElementById('project-detail-backdrop');
  if (!backdrop) return;

  // Cache lokal sebagai data awal; detail lengkap (skills/interests) tetap
  // di-fetch dari server karena daftar user (dipakai cache) tidak selalu
  // memuat kolom ini.
  let user = window.db ? db.getUserById(userId) : null;

  if (window.location.protocol.startsWith('http')) {
    try {
      const API_BASE = (window.db && window.db.API_BASE) || 'api';
      const res = await fetch(`${API_BASE}/get_user_profile.php?id=${encodeURIComponent(userId)}`);
      if (res.ok) {
        const data = await res.json();
        if (data.status === 'success' && data.data) {
          const u = data.data;
          user = {
            id: u.id,
            name: u.name,
            email: u.email,
            role: u.role,
            bio: u.bio || "",
            location: u.location || "",
            portfolio: u.portfolio_url || "",
            skills: u.skills || [],
            interests: u.interests || [],
            // Struktur kontak baru: Email Publik (public_email, terpisah dari
            // email login), WA, Sosial Media (reuse kolom instagram) --
            // LinkedIn/Website/Alamat tidak lagi dikumpulkan lewat form Profil.
            contact: {
              email: u.public_email || "",
              whatsapp: u.whatsapp || "",
              instagram: u.instagram || "",
            },
          };
        }
      }
    } catch (e) {
      console.error('[showFreelancerProfileModal] Gagal fetch detail user:', e);
    }
  }

  if (!user) return;

  const avatar = backdrop.querySelector('#pdm-profile-avatar');
  const name = backdrop.querySelector('#pdm-profile-name');
  const role = backdrop.querySelector('#pdm-profile-role');
  
  const bioSec = backdrop.querySelector('#pdm-profile-section-bio');
  const bioTxt = backdrop.querySelector('#pdm-profile-bio');
  const skillsContainer = backdrop.querySelector('#pdm-profile-skills');
  const interestsContainer = backdrop.querySelector('#pdm-profile-interests');
  const portSec = backdrop.querySelector('#pdm-profile-section-portfolio');
  const portLnk = backdrop.querySelector('#pdm-profile-portfolio');
  const contSec = backdrop.querySelector('#pdm-profile-section-contact');
  const contTxt = backdrop.querySelector('#pdm-profile-contact');

  if (avatar) avatar.textContent = user.name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
  if (name) name.textContent = user.name;
  if (role) role.textContent = user.role || 'Freelancer';
  
  if (bioSec && bioTxt) {
    if (user.bio) {
      bioSec.style.display = '';
      bioTxt.textContent = user.bio;
    } else {
      bioSec.style.display = 'none';
    }
  }

  if (skillsContainer) {
    if (user.skills && user.skills.length > 0) {
      skillsContainer.innerHTML = user.skills.map(s => `<span class="dm-pill">${escapeHtml(s)}</span>`).join('');
    } else {
      skillsContainer.innerHTML = '<span style="color:var(--text-muted); font-size:var(--fs-sm);">Belum ada data</span>';
    }
  }

  if (interestsContainer) {
    if (user.interests && user.interests.length > 0) {
      interestsContainer.innerHTML = user.interests.map(i => `<span class="dm-pill">${escapeHtml(i)}</span>`).join('');
    } else {
      interestsContainer.innerHTML = '<span style="color:var(--text-muted); font-size:var(--fs-sm);">Belum ada data</span>';
    }
  }

  if (portSec && portLnk) {
    if (user.portfolio) {
      portSec.style.display = '';
      portLnk.href = user.portfolio;
      portLnk.textContent = user.portfolio;
    } else {
      portSec.style.display = 'none';
    }
  }

  if (contSec && contTxt) {
    if (user.contact) {
      contSec.style.display = '';
      if (typeof user.contact === 'object') {
        // Struktur baru: Email Publik, WA, Sosial Media (reuse kolom
        // instagram, label UI saja yang berubah) -- LinkedIn/Website/Alamat
        // tidak lagi ditampilkan (inputannya sudah dihapus dari form Profil).
        const c = user.contact;
        let html = '<div style="display: flex; flex-direction: column; gap: 0.5rem;">';
        if (c.email) html += `<div><strong style="color:var(--text-muted); font-size:var(--fs-sm);">Email Publik:</strong><br>${escapeHtml(c.email)}</div>`;
        if (c.whatsapp) html += `<div><strong style="color:var(--text-muted); font-size:var(--fs-sm);">WhatsApp:</strong><br>${escapeHtml(c.whatsapp)}</div>`;
        if (c.instagram) html += `<div><strong style="color:var(--text-muted); font-size:var(--fs-sm);">Sosial Media:</strong><br>${escapeHtml(c.instagram)}</div>`;
        html += '</div>';

        // If empty object or all fields empty
        if (html === '<div style="display: flex; flex-direction: column; gap: 0.5rem;"></div>') {
          contTxt.innerHTML = '<span style="color:var(--text-muted); font-size:var(--fs-sm);">Belum ada kontak</span>';
        } else {
          contTxt.innerHTML = html;
        }
      } else {
        contTxt.textContent = user.contact;
      }
    } else {
      contSec.style.display = 'none';
    }
  }

  // Handle Approve button logic
  backdrop.setAttribute('data-current-applicant-id', userId);
  const currentProjectId = backdrop.getAttribute('data-current-project-id');
  const currentProject = window.db ? db.getProjectById(currentProjectId) : null;
  const approveBtn = backdrop.querySelector('#btn-approve-freelancer');
  
  if (approveBtn && currentProject) {
    if (currentProject.status !== 'Open') {
      approveBtn.style.display = 'none';
    } else {
      approveBtn.style.display = 'block';
    }
  }

  // Riwayat proyek (dipakai Talent AI & Kelola Proyek UMKM) -- section ini
  // tidak ada di semua halaman yang memakai modal ini (mis. modal profil
  // freelancer dari halaman lain), jadi selalu dicek dulu elemennya ada.
  // Fetch terpisah dari data profil di atas (endpoint beda), TIDAK saling
  // menunggu -- kalau salah satu gagal/lambat, yang lain tetap tampil.
  const historySummaryEl = backdrop.querySelector('#pdm-profile-history-summary');
  const historyListEl = backdrop.querySelector('#pdm-profile-history-list');
  if (historyListEl && user.role === 'freelancer') {
    historyListEl.innerHTML = '<p style="color:var(--text-muted); font-size:0.9rem;">Memuat riwayat...</p>';
    if (historySummaryEl) historySummaryEl.textContent = '';

    const API_BASE = (window.db && window.db.API_BASE) || 'api';
    fetchJsonSafe(`${API_BASE}/get_freelancer_history.php?freelancer_id=${encodeURIComponent(userId)}`)
      .then((res) => {
        // Modal mungkin sudah beralih ke freelancer lain saat respons ini tiba
        if (backdrop.getAttribute('data-current-applicant-id') !== String(userId)) return;
        const d = res.data || {};
        if (historySummaryEl) historySummaryEl.textContent = formatFreelancerHistorySummary(d.summary);
        historyListEl.innerHTML = renderFreelancerHistoryList(d.projects || []);
      })
      .catch((err) => {
        console.error('[showFreelancerProfileModal] Gagal memuat riwayat:', err);
        historyListEl.innerHTML = `<p style="color:#c0392b; font-size:0.9rem;">${escapeHtml(err.message || 'Gagal memuat riwayat freelancer.')}</p>`;
      });
  }

  // Buka modal secara eksplisit -- WAJIB utk pemanggilan "berdiri sendiri"
  // (mis. tombol "Lihat Profil" di Talent AI/UMKM Dashboard, tanpa modal
  // proyek yg sudah terbuka lebih dulu). switchProjectModalView() sendirian
  // TIDAK membuka backdrop -- ia hanya menukar pdm-view-project/profile,
  // yg hanya ada di halaman umkm-projects.html (Kelola Proyek), sehingga
  // sebelumnya modal ini tampak "tidak berfungsi sama sekali" di halaman
  // manapun yang membukanya langsung tanpa modal proyek yg sudah aktif.
  // Aman dipanggil berulang: openModal() idempoten kalau modal sudah terbuka.
  window.openModal(backdrop);
  window.switchProjectModalView('profile');
};

document.body.addEventListener('click', (e) => {
  const viewProfileBtn = e.target.closest('.btn-view-profile');
  if (viewProfileBtn) {
    const userId = viewProfileBtn.dataset.id;
    if (typeof window.showFreelancerProfileModal === 'function') {
      window.showFreelancerProfileModal(userId);
    }
  }
  
  const backBtn = e.target.closest('#pdm-back-btn');
  if (backBtn) {
    if (typeof window.switchProjectModalView === 'function') {
      window.switchProjectModalView('project');
    }
  }
  
  const approveBtn = e.target.closest('#btn-approve-freelancer');
  if (approveBtn) {
    const backdrop = document.getElementById('project-detail-backdrop');
    if (!backdrop) return;
    
    const projectId = backdrop.getAttribute('data-current-project-id');
    const freelancerId = backdrop.getAttribute('data-current-applicant-id');
    
    if (projectId && freelancerId && window.db) {
      const res = db.approveApplicant(projectId, freelancerId);
      if (res) {
        // Show quick success feedback
        const toast = document.createElement('div');
        toast.textContent = 'Freelancer successfully approved!';
        Object.assign(toast.style, {
          position: 'fixed', bottom: '20px', right: '20px', background: '#28a745', 
          color: '#fff', padding: '12px 24px', borderRadius: '4px', zIndex: '9999',
          boxShadow: '0 4px 6px rgba(0,0,0,0.1)', fontFamily: 'sans-serif'
        });
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
        
        window.closeModal(backdrop);
        
        // Refresh UMKM dashboard if it's the current page
        if (typeof renderUMKMProjects === 'function' && document.getElementById('umkm-projects-grid')) {
          renderUMKMProjects();
        }
      } else {
        alert('Failed to approve freelancer.');
      }
    }
  }

});

// Run the profile update as soon as the DOM is ready
document.addEventListener('DOMContentLoaded', updateProfileUI);

// Initialize admin dashboard pages on load
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('admin-view-dashboard') ||
      document.getElementById('admin-view-users') ||
      document.getElementById('admin-view-projects') ||
      document.getElementById('admin-view-umkm')) {
    // Tunggu db.whenReady() dulu (sama seperti router utama halaman lain) --
    // initAdminDashboard() membaca getCurrentUser() untuk memutuskan redirect
    // ke login.html kalau bukan admin. Tanpa menunggu ini, tab baru yang
    // sessionStorage-nya masih kosong (tapi sesi PHP-nya valid) akan salah
    // ter-redirect sebelum rehydrateSessionFromServer() sempat memulihkannya.
    if (window.db && typeof window.db.whenReady === 'function') {
      window.db.whenReady().then(initAdminDashboard);
    } else {
      initAdminDashboard();
    }
  }
});

// ============================================================
// ADMIN DASHBOARD
// ============================================================
async function initAdminDashboard() {
  // Protect admin pages: redirect non-admin users
  const _cu = window.db ? window.db.getCurrentUser() : null;
  if (!_cu || _cu.role !== 'admin') {
    window.location.href = 'login.html';
    return;
  }

  // Guard: initAdminDashboard dipanggil dari dua listener DOMContentLoaded —
  // tanpa guard, delegasi klik terpasang dua kali (aksi tereksekusi ganda).
  if (window.__adminDashboardInit) return;
  window.__adminDashboardInit = true;

  // Verifikasi ULANG identitas ke session PHP AKTIF (bukan cuma sessionStorage
  // tab ini) sebelum merender apa pun. sessionStorage bisa saja masih bilang
  // "admin" (basi) padahal browser yang sama sudah dipakai login sebagai akun
  // lain di tab lain sejak tab admin ini terakhir dimuat -- session PHP
  // dibagi ke SEMUA tab. Tanpa ini, admin baru sadar sesudah klik Hapus/
  // Tangguhkan dan mendapat pesan "Akses ditolak" yang membingungkan (gejala
  // yang dilaporkan 2026-07-05); dengan ini, ketidakcocokan ketahuan di awal.
  try {
    const API_BASE = (window.db && window.db.API_BASE) || 'api';
    const meRes = await fetch(`${API_BASE}/me.php`);
    const meJson = await meRes.json();

    // PENTING: hanya paksa logout+redirect untuk kondisi yang TERKONFIRMASI
    // jelas -- bukan untuk SETIAP kegagalan. Sebelumnya kode ini menyamakan
    // "401 belum login" DENGAN "403 suspended"/"500 server error sesaat"/
    // respons tak terduga lainnya, semuanya dipaksa logout -- kalau server
    // sempat hiccup sebentar (mis. koneksi DB), admin yang SEBENARNYA masih
    // sah malah ikut ter-logout tiap refresh. Sekarang dibedakan:
    if (meRes.status === 401) {
      // Sesi PHP benar-benar tidak ada/kadaluarsa -- jelas, aman utk redirect.
      alert('Sesi Anda sudah berakhir. Silakan login kembali.');
      sessionStorage.removeItem('currentUser');
      window.location.href = 'login.html';
      return;
    }
    if (meJson.status === 'success' && meJson.data) {
      if (meJson.data.role !== 'admin') {
        // Sesi ADA dan VALID, tapi role-nya TERKONFIRMASI bukan admin --
        // mismatch yang jelas (bukan error ambigu), aman utk redirect.
        alert('Sesi Anda saat ini bukan (atau tidak lagi) akun admin -- kemungkinan Anda login sebagai akun lain di tab/jendela lain pada browser yang sama. Anda akan diarahkan ke halaman login.');
        sessionStorage.removeItem('currentUser');
        window.location.href = 'login.html';
        return;
      }
      // Role sama-sama admin -- sinkronkan sessionStorage kalau id-nya beda
      // (jarang), TANPA memaksa reload/redirect.
      if (window.db && typeof window.db.mapUserRow === 'function' && String(meJson.data.id) !== String(_cu.id)) {
        sessionStorage.setItem('currentUser', JSON.stringify(window.db.mapUserRow(meJson.data)));
      }
    }
    // Kasus lain (403 suspended, 500 error server, dll.) SENGAJA tidak
    // memaksa logout -- ambigu, lanjut dengan sessionStorage yang ada.
    // Tombol aksi tetap punya lapisan proteksi reaktif (handleSessionMismatchError)
    // kalau ternyata identitasnya memang sudah tidak cocok.
  } catch (err) {
    console.error('[admin] Gagal verifikasi sesi admin ke server:', err);
    // Gagal jaringan -- jangan blokir akses, lanjut dengan sessionStorage yang ada.
  }

  // Render based on the current page context
  if (document.getElementById('admin-view-dashboard')) {
    initAdminDateFilter();
    renderAdminStats();
  }
  if (document.getElementById('admin-view-users')) {
    renderAdminUsers();
  }
  if (document.getElementById('admin-view-projects')) {
    renderAdminProjects();
  }
  if (document.getElementById('admin-view-umkm')) {
    renderAdminUMKM();
  }

  // Aksi di bawah pakai handleSessionMismatchError() (fungsi global, lihat
  // dekat fetchJsonSafe()) utk mendeteksi "Akses ditolak..." dari
  // requireRole() -- artinya SESSION PHP AKTIF (dibagi ke semua tab dalam
  // satu browser) BUKAN admin lagi, BUKAN berarti kode/tombolnya rusak.

  // Global event delegation for admin actions — semua aksi menembak API MySQL
  document.body.addEventListener('click', async (e) => {
    const API_BASE = (window.db && window.db.API_BASE) || 'api';

    const adminUser = window.db ? db.getCurrentUser() : null;

    // Suspend / aktifkan kembali akun (account_status)
    const suspendBtn = e.target.closest('.btn-suspend-user');
    if (suspendBtn) {
      suspendBtn.disabled = true;
      try {
        const res = await fetch(`${API_BASE}/users.php?action=suspend&id=${encodeURIComponent(suspendBtn.dataset.id)}&actor_id=${encodeURIComponent(adminUser ? adminUser.id : '')}`, { method: 'POST' });
        const data = await res.json();
        console.log('[admin] suspend response:', res.status, data);
        if (!res.ok || !data.success) throw new Error(data.message || `HTTP ${res.status}`);
        showSuccessToast(data.message || 'Status akun diperbarui.');
      } catch (err) {
        console.error('[admin] Gagal suspend/aktifkan akun:', err);
        if (!handleSessionMismatchError(err.message)) {
          alert(err.message || 'Gagal mengubah status akun. Buka Console (F12) untuk detail.');
        }
      }
      renderAdminUsers();
      return;
    }

    // Hapus pengguna permanen
    const deleteUserBtn = e.target.closest('.btn-delete-user');
    if (deleteUserBtn) {
      if (!confirm('Hapus pengguna ini secara permanen? Data terkait (skill, lamaran, chat) ikut terhapus.')) return;
      try {
        const res = await fetch(`${API_BASE}/users.php?id=${encodeURIComponent(deleteUserBtn.dataset.id)}&actor_id=${encodeURIComponent(adminUser ? adminUser.id : '')}`, { method: 'DELETE' });
        const data = await res.json();
        console.log('[admin] delete response:', res.status, data);
        if (!res.ok || !data.success) throw new Error(data.message || `HTTP ${res.status}`);
        showSuccessToast(data.message || 'Pengguna berhasil dihapus.');
      } catch (err) {
        console.error('[admin] Gagal menghapus pengguna:', err);
        if (!handleSessionMismatchError(err.message)) {
          alert(err.message || 'Gagal menghapus pengguna. Buka Console (F12) untuk detail.');
        }
      }
      renderAdminUsers();
      return;
    }

    // Tutup paksa proyek (project_status → Closed)
    const closeProjectBtn = e.target.closest('.btn-close-project');
    if (closeProjectBtn) {
      if (!confirm('Tutup paksa proyek ini? Status akan menjadi Closed.')) return;
      try {
        const res = await fetch(`${API_BASE}/projects.php?action=update_status`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: Number(closeProjectBtn.dataset.id), status: 'Closed', actor_id: adminUser ? adminUser.id : null }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message);
        showSuccessToast('Proyek berhasil ditutup (Closed).');
      } catch (err) {
        if (!handleSessionMismatchError(err.message)) {
          alert(err.message || 'Gagal menutup proyek.');
        }
      }
      if (window.db && typeof db.loadServerData === 'function') await db.loadServerData();
      renderAdminProjects();
      return;
    }

    // Hapus proyek permanen
    const deleteProjectBtn = e.target.closest('.btn-delete-project');
    if (deleteProjectBtn) {
      if (!confirm('Hapus proyek ini secara permanen dari database?')) return;
      try {
        const res = await fetch(`${API_BASE}/projects.php?id=${encodeURIComponent(deleteProjectBtn.dataset.id)}&actor_id=${encodeURIComponent(adminUser ? adminUser.id : '')}`, { method: 'DELETE' });
        const data = await res.json();
        if (!data.success) throw new Error(data.message);
        showSuccessToast('Proyek berhasil dihapus.');
      } catch (err) {
        if (!handleSessionMismatchError(err.message)) {
          alert(err.message || 'Gagal menghapus proyek.');
        }
      }
      if (window.db && typeof db.loadServerData === 'function') await db.loadServerData();
      renderAdminProjects();
      return;
    }

    // Detail proyek (menampilkan freelancer yang mengerjakan)
    const viewProjectBtn = e.target.closest('.btn-view-project');
    if (viewProjectBtn) {
      const id = viewProjectBtn.dataset.id;
      let data = window.db ? db.getProjects().find(p => String(p.id) === String(id)) : null;
      if (!data) {
        // Cache belum termuat → ambil langsung dari API
        try {
          const res = await fetch(`${API_BASE}/projects.php?id=${encodeURIComponent(id)}`);
          const json = await res.json();
          if (json.success && json.project) {
            data = (window.db && typeof db.mapProjectRow === 'function')
              ? db.mapProjectRow(json.project)
              : json.project;
          }
        } catch (err) {
          console.error(err);
        }
      }
      if (data && typeof populateAndOpenProjectModal === 'function') {
        populateAndOpenProjectModal(data, window.openModal);
      }
      return;
    }
  });

  // Search and Filter Listeners
  const searchUsers = document.getElementById('admin-search-users');
  const filterRole = document.getElementById('admin-filter-role');
  if(searchUsers) searchUsers.addEventListener('input', renderAdminUsers);
  if(filterRole) filterRole.addEventListener('change', renderAdminUsers);

  const searchProjects = document.getElementById('admin-search-projects');
  const filterStatus = document.getElementById('admin-filter-status');
  if(searchProjects) searchProjects.addEventListener('input', renderAdminProjects);
  if(filterStatus) filterStatus.addEventListener('change', renderAdminProjects);

  const searchUmkm = document.getElementById('admin-search-umkm');
  if(searchUmkm) searchUmkm.addEventListener('input', renderAdminUMKM);
}

// ── Filter tanggal Dashboard Admin ────────────────────────────
// Default: 6 bulan terakhir. Klik "Terapkan" → kartu statistik + grafik
// dimuat ulang dengan parameter start_date & end_date.
function initAdminDateFilter() {
  const startInput = document.getElementById('admin-date-start');
  const endInput = document.getElementById('admin-date-end');
  const applyBtn = document.getElementById('admin-date-apply');
  if (!startInput || !endInput || !applyBtn) return;

  // Nilai awal: awal bulan (5 bulan lalu) s/d hari ini
  const now = new Date();
  const startDefault = new Date(now.getFullYear(), now.getMonth() - 5, 1);
  const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  if (!startInput.value) startInput.value = fmt(startDefault);
  if (!endInput.value) endInput.value = fmt(now);

  applyBtn.addEventListener('click', () => {
    if (startInput.value && endInput.value && startInput.value > endInput.value) {
      alert('Tanggal mulai tidak boleh melebihi tanggal akhir.');
      return;
    }
    renderAdminStats();
    renderAdminDashboardChart();
  });
}

// Query string ?start_date=&end_date= dari input filter (kosong jika tidak diisi)
function adminDateRangeQuery() {
  const start = document.getElementById('admin-date-start')?.value || '';
  const end = document.getElementById('admin-date-end')?.value || '';
  if (!start || !end) return '';
  return `?start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}`;
}

async function renderAdminStats() {
  const statValues = document.querySelectorAll('.admin-stat-card .admin-stat-value');
  const statTitles = document.querySelectorAll('.admin-stat-card .admin-stat-header');
  if (statValues.length < 7) return;

  // Statistik diambil dari dashboard_stats.php — mendukung filter
  // start_date/end_date dari date picker di header dashboard.
  const API_BASE = (window.db && window.db.API_BASE) || 'api';
  let totals = null;

  try {
    const res = await fetch(`${API_BASE}/dashboard_stats.php${adminDateRangeQuery()}`);
    if (res.ok) {
      const data = await res.json();
      if (data.success) totals = data.totals;
    }
  } catch (e) {
    console.error('[admin-stats] Gagal memuat statistik:', e);
  }
  if (!totals) return;

  statTitles[0].innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> TOTAL PENGGUNA`;
  statValues[0].textContent = totals.users;

  statTitles[1].innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg> TOTAL PROYEK`;
  statValues[1].textContent = totals.projects;

  statTitles[2].innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> TOTAL FREELANCER`;
  statValues[2].textContent = totals.freelancers;

  statTitles[3].innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> TOTAL UMKM`;
  statValues[3].textContent = totals.umkm;

  statTitles[4].innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg> PROYEK OPEN`;
  statValues[4].textContent = totals.open;

  statTitles[5].innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> PROYEK BERJALAN`;
  statValues[5].textContent = totals.inProgress;

  statTitles[6].innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg> PROYEK COMPLETED`;
  statValues[6].textContent = totals.completed;
}

async function renderAdminUsers() {
  const tbody = document.getElementById('admin-users-table');
  if (!tbody) return;

  // Show loading state
  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem;">Memuat data...</td></tr>';

  let users = [];

  // On server: fetch users directly from MySQL API (cache-busting seperti
  // renderAdminProjects()/renderAdminUMKM(), supaya data selalu terkini
  // setelah aksi suspend/hapus).
  if (window.location.protocol.startsWith('http')) {
    try {
      const res = await fetch('api/users.php?' + Date.now());
      const data = await res.json();
      if (!res.ok || !data.success) {
        console.error('[renderAdminUsers] Respons gagal:', res.status, data);
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:red;">Gagal memuat daftar pengguna (${escapeHtml(data.message || ('HTTP ' + res.status))}). Buka Console (F12) untuk detail.</td></tr>`;
        return;
      }
      users = data.users || [];
    } catch (e) {
      console.error('[renderAdminUsers] Gagal fetch dari API:', e);
      tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:red;">Gagal memuat daftar pengguna. Buka Console (F12) untuk detail error.</td></tr>`;
      return;
    }
  } else {
    users = window.db ? db.getUsers() : [];
  }
  
  const query = (document.getElementById('admin-search-users')?.value || '').toLowerCase();
  const role = document.getElementById('admin-filter-role')?.value || '';
  
  const filtered = users.filter(u => {
    const matchQ = (u.name || '').toLowerCase().includes(query) || (u.email || '').toLowerCase().includes(query);
    const matchR = !role || u.role === role;
    return matchQ && matchR;
  });

  if (filtered.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem;">Tidak ada pengguna ditemukan.</td></tr>';
    return;
  }
  
  tbody.innerHTML = filtered.map(u => {
    // account_status (sistem baru) dengan fallback kolom status lama
    const isSuspended = ((u.account_status || u.status || '')).toLowerCase() === 'suspended';
    const statusLabel = isSuspended ? 'Suspended' : 'Active';
    const statusBadge = isSuspended ? 'badge-suspended' : 'badge-active';
    const isAdmin = u.role === 'admin';
    const bizLine = (u.role === 'umkm' && u.business_name)
      ? `<br><span style="font-size:0.75rem; color:var(--text-muted); font-weight:400;">${escapeHtml(u.business_name)}</span>`
      : '';

    // Urutan aksi: Tangguhkan · Profil · Hapus (akun admin tidak bisa diubah)
    const actions = isAdmin
      ? '<span style="color:var(--text-muted); font-size:0.8rem;">—</span>'
      : `
            <button class="admin-btn-action admin-btn-warning btn-suspend-user" data-id="${u.id}">${isSuspended ? 'Aktifkan' : 'Tangguhkan'}</button>
            <button class="admin-btn-action admin-btn-view-profile" data-id="${u.id}">Profil</button>
            <button class="admin-btn-action admin-btn-danger btn-delete-user" data-id="${u.id}">Hapus</button>`;

    return `
      <tr data-id="${u.id}">
        <td><strong>${escapeHtml(u.name || '-')}</strong>${bizLine}</td>
        <td>${escapeHtml(u.email)}</td>
        <td style="text-transform:capitalize;">${escapeHtml(u.role)}</td>
        <td><span class="admin-badge ${statusBadge}">${statusLabel}</span></td>
        <td>
          <div class="admin-table-actions">
            ${actions}
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

async function renderAdminProjects() {
  const tbody = document.getElementById('admin-projects-table');
  if (!tbody) return;

  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:1rem;">Memuat data...</td></tr>';

  let projects = [];
  let users = [];

  try {
    const API_BASE = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1) + 'api';
    const [projectsRes, usersRes] = await Promise.all([
      fetch(API_BASE + '/projects.php?' + Date.now()).then(r => r.json()).catch(() => ({ projects: [] })),
      fetch(API_BASE + '/users.php?' + Date.now()).then(r => r.json()).catch(() => ({ users: [] }))
    ]);
    projects = projectsRes.projects || [];
    users    = usersRes.users    || [];
  } catch(err) {
    console.error('[admin] Gagal fetch projects:', err);
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:red;">Gagal memuat data.</td></tr>';
    return;
  }

  const query        = (document.getElementById('admin-search-projects')?.value || '').toLowerCase();
  const statusFilter = document.getElementById('admin-filter-status')?.value || '';

  const filtered = projects.filter(p => {
    const matchQ = (p.title || '').toLowerCase().includes(query);
    const matchS = !statusFilter || p.status === statusFilter;
    return matchQ && matchS;
  });

  if (filtered.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#888;">Tidak ada data proyek.</td></tr>';
    return;
  }

  tbody.innerHTML = filtered.map(p => {
    const creatorId = p.created_by || p.createdBy;
    const creator   = users.find(u => String(u.id) === String(creatorId));
    const creatorName = creator
      ? (creator.business_name || creator.name)
      : (creatorId ? `#${creatorId}` : 'Unknown');

    // status = project_status (sistem baru) — badge via helper bersama
    const badgeClass = statusBadgeClass(p.status);
    const applicantsCount = Array.isArray(p.applicants) ? p.applicants.length : 0;
    const freelancerLine = p.freelancer_name
      ? `<br><span style="font-size:0.75rem; color:var(--text-muted); font-weight:400;">Freelancer: ${escapeHtml(p.freelancer_name)}</span>`
      : '';

    // Urutan aksi: Detail · Tutup · Hapus
    return `
      <tr data-id="${p.id}">
        <td><strong>${escapeHtml(p.title)}</strong>${freelancerLine}</td>
        <td>${escapeHtml(creatorName)}</td>
        <td><span class="admin-badge ${badgeClass}">${p.status}</span></td>
        <td>${applicantsCount}</td>
        <td>
          <div class="admin-table-actions">
            <button class="admin-btn-action btn-view-project" data-id="${p.id}">Detail</button>
            ${p.status !== 'Closed' ? `<button class="admin-btn-action admin-btn-warning btn-close-project" data-id="${p.id}">Tutup</button>` : ''}
            <button class="admin-btn-action admin-btn-danger btn-delete-project" data-id="${p.id}">Hapus</button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

async function renderAdminUMKM() {
  const tbody = document.getElementById('admin-umkm-table');
  if (!tbody) return;

  tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:1rem;">Memuat data...</td></tr>';

  let umkms = [];
  let projects = [];

  try {
    const API_BASE = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1) + 'api';
    const [usersRes, projectsRes] = await Promise.all([
      fetch(API_BASE + '/users.php?' + Date.now()).then(r => r.json()),
      fetch(API_BASE + '/projects.php?' + Date.now()).then(r => r.json()).catch(() => ({ projects: [] }))
    ]);

    umkms    = (usersRes.users    || []).filter(u => u.role === 'umkm');
    projects = projectsRes.projects || [];

  } catch (err) {
    console.error('[admin] Gagal fetch UMKM:', err);
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:red;">Gagal memuat data. Pastikan server aktif.</td></tr>';
    return;
  }

  const query = (document.getElementById('admin-search-umkm')?.value || '').toLowerCase();

  const filtered = umkms.filter(u => {
    const displayName = u.business_name || u.name || '';
    return displayName.toLowerCase().includes(query);
  });

  if (filtered.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;">Tidak ada data UMKM.</td></tr>';
    return;
  }

  const rows = [];
  filtered.forEach(u => {
    const uProjects     = projects.filter(p => String(p.created_by || p.createdBy) === String(u.id));
    const approvedCount = uProjects.filter(p => p.assigned_to || p.assignedTo).length;
    const displayName   = escapeHtml(u.business_name || u.name || '-');
    const category      = escapeHtml(u.business_category || '-');
    const expandId      = `umkm-expand-${u.id}`;

    // Main UMKM row — clicking name toggles project list
    rows.push(`
      <tr data-id="${u.id}">
        <td>
          <button
            class="admin-umkm-name-btn"
            onclick="toggleUmkmProjects('${expandId}')"
            style="background:none;border:none;padding:0;cursor:pointer;font-weight:700;color:var(--accent-green);text-align:left;text-decoration:underline dotted;"
            title="Klik untuk lihat proyek"
          >${displayName} <span style="font-size:0.75rem;font-weight:400;color:#888;">(${uProjects.length} proyek)</span></button>
        </td>
        <td>${category}</td>
        <td>${uProjects.length}</td>
        <td>${approvedCount}</td>
      </tr>
      <tr id="${expandId}" style="display:none;">
        <td colspan="4" style="padding:0;">
          <div style="background:#f8faf8;border-top:1px solid #e2e8e2;padding:1rem;">
            ${
              uProjects.length === 0
                ? '<p style="color:#888;margin:0;">Belum ada proyek.</p>'
                : `<table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                    <thead><tr style="color:#666;border-bottom:1px solid #ddd;">
                      <th style="text-align:left;padding:0.4rem 0.5rem;">Judul Proyek</th>
                      <th style="text-align:left;padding:0.4rem 0.5rem;">Status</th>
                      <th style="text-align:left;padding:0.4rem 0.5rem;">Deadline</th>
                      <th style="text-align:left;padding:0.4rem 0.5rem;">Budget</th>
                    </tr></thead>
                    <tbody>
                      ${uProjects.map(p => `
                        <tr style="border-bottom:1px solid #eee;">
                          <td style="padding:0.4rem 0.5rem;"><strong>${escapeHtml(p.title || '-')}</strong></td>
                          <td style="padding:0.4rem 0.5rem;"><span class="admin-badge ${statusBadgeClass(p.status)}">${escapeHtml(p.status)}</span></td>
                          <td style="padding:0.4rem 0.5rem;">${window.formatDateIndo ? window.formatDateIndo(p.deadline) : escapeHtml(p.deadline || '-')}</td>
                          <td style="padding:0.4rem 0.5rem;">${window.formatCurrencyIndo ? window.formatCurrencyIndo(p.budget || p.prize) : escapeHtml(p.budget || p.prize || '-')}</td>
                        </tr>`).join('')}
                    </tbody>
                   </table>`
            }
          </div>
        </td>
      </tr>
    `);
  });

  tbody.innerHTML = rows.join('');
}

function toggleUmkmProjects(rowId) {
  const row = document.getElementById(rowId);
  if (!row) return;
  row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

// Attach init to DOMContentLoaded if we are on the admin page
document.addEventListener('DOMContentLoaded', async () => {
  if (document.querySelector('.admin-layout')) {
    // db.whenReady() (bukan cuma loadServerData()) -- whenReady() juga
    // menunggu rehydrateSessionFromServer() selesai, yang dibutuhkan
    // initAdminDashboard() sebelum memutuskan redirect ke login.html kalau
    // sessionStorage tab ini masih kosong padahal sesi PHP-nya valid.
    if (window.db && typeof window.db.whenReady === 'function') {
      await window.db.whenReady();
    }
    initAdminDashboard();
    initAdminMobileSidebar();
  }
});

// Sidebar admin di-hide penuh lewat CSS pada layar <=768px tanpa cara lain
// untuk membukanya -- offcanvas + tombol hamburger ini mengembalikan akses navigasi di mobile/tablet.
function initAdminMobileSidebar() {
  const layout   = document.querySelector('.admin-layout');
  const sidebar  = document.querySelector('.admin-sidebar');
  const toggleBtn = document.getElementById('admin-sidebar-toggle');
  if (!layout || !sidebar || !toggleBtn) return;

  let overlay = layout.querySelector('.admin-sidebar-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'admin-sidebar-overlay';
    layout.appendChild(overlay);
  }

  function closeSidebar() {
    sidebar.classList.remove('is-open');
    overlay.classList.remove('is-visible');
    toggleBtn.setAttribute('aria-expanded', 'false');
  }
  function openSidebar() {
    sidebar.classList.add('is-open');
    overlay.classList.add('is-visible');
    toggleBtn.setAttribute('aria-expanded', 'true');
  }

  toggleBtn.addEventListener('click', () => {
    sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar();
  });
  overlay.addEventListener('click', closeSidebar);
  sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', closeSidebar));
}

// ============================================================
// ADMIN USER PROFILE VIEWER
// ============================================================

function injectAdminUserProfileModal() {
  let modal = document.getElementById('admin-user-profile-modal');
  if (modal) return modal;

  modal = document.createElement('div');
  modal.className = 'detail-backdrop';
  modal.id = 'admin-user-profile-modal';
  modal.hidden = true;
  modal.setAttribute('aria-modal', 'true');
  modal.setAttribute('role', 'dialog');
  modal.setAttribute('aria-labelledby', 'admin-pm-name');
  
  modal.innerHTML = `
    <div class="detail-modal">
      <div class="dm-header">
        <div style="display: flex; gap: 16px; align-items: center;">
          <div class="user-avatar" id="admin-pm-avatar" style="width: 40px; height: 40px; font-size: 1rem;">U</div>
          <div>
            <h2 class="dm-title" id="admin-pm-name" style="margin:0; font-size: 1.1rem;">User Name</h2>
            <span class="badge" id="admin-pm-role" style="font-size: 0.7rem; text-transform: uppercase;">Role</span>
          </div>
        </div>
        <button class="dm-close-btn" id="admin-pm-close-btn" aria-label="Tutup modal">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="dm-body">
        <div class="dm-section" id="admin-pm-section-business" style="display:none;">
          <span class="dm-section-label">Info Bisnis (UMKM)</span>
          <p class="dm-section-text">
            <strong>Nama Bisnis:</strong> <span id="admin-pm-business-name">—</span><br>
            <strong>Kategori:</strong> <span id="admin-pm-business-category">—</span>
          </p>
        </div>
        <div class="dm-section" id="admin-pm-section-bio">
          <span class="dm-section-label">Tentang</span>
          <p class="dm-section-text" id="admin-pm-bio">—</p>
        </div>
        <div class="dm-section" id="admin-pm-section-skills">
          <span class="dm-section-label">Keahlian (Skill)</span>
          <div class="dm-pills" id="admin-pm-skills"></div>
        </div>
        <div class="dm-section" id="admin-pm-section-interests">
          <span class="dm-section-label">Minat &amp; Bidang</span>
          <div class="dm-pills" id="admin-pm-interests"></div>
          <p class="dm-section-text" id="admin-pm-field" style="margin-top:6px;"></p>
        </div>
        <div class="dm-section" id="admin-pm-section-portfolio">
          <span class="dm-section-label">Portfolio</span>
          <p class="dm-section-text"><a href="#" id="admin-pm-portfolio" target="_blank" style="color: var(--accent-green); text-decoration: none; word-break: break-all;"></a></p>
        </div>
        <div class="dm-section" id="admin-pm-section-contact">
          <span class="dm-section-label">Kontak</span>
          <p class="dm-section-text" id="admin-pm-contact"></p>
        </div>
      </div>
      <div class="dm-footer">
        <button type="button" class="dm-btn-outline" id="admin-pm-cancel-btn">Batal</button>
        <button type="button" class="dm-btn-primary" id="admin-pm-save-btn">Simpan</button>
      </div>
    </div>
  `;

  document.body.appendChild(modal);

  const closeBtn = modal.querySelector('#admin-pm-close-btn');
  const cancelBtn = modal.querySelector('#admin-pm-cancel-btn');
  const saveBtn = modal.querySelector('#admin-pm-save-btn');
  const doClose = () => {
    if (typeof window.closeModal === 'function') {
      window.closeModal(modal);
    }
  };
  closeBtn.addEventListener('click', doClose);
  cancelBtn.addEventListener('click', doClose);
  // Modal ini masih view-only (belum ada field yang bisa diedit), jadi
  // "Simpan" untuk sekarang sama seperti "Batal" -- cuma menutup modal.
  // Sambungkan ke API update profil di sini kalau modal ini nanti dibuat editable.
  saveBtn.addEventListener('click', doClose);

  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      if (typeof window.closeModal === 'function') {
        window.closeModal(modal);
      }
    }
  });

  return modal;
}

window.showAdminUserProfileModal = async function(userId) {
  const modal = injectAdminUserProfileModal();
  
  // Cache lokal sebagai data awal; detail lengkap tetap di-fetch dari server
  let user = window.db ? db.getUserById(userId) : null;

  // Jika berjalan di server, fetch detail profil lengkap dari MySQL API
  // (api/get_user_profile.php ikut menarik skills, interests, dan field)
  if (window.location.protocol.startsWith('http')) {
    try {
      const res = await fetch(`api/get_user_profile.php?id=${userId}`);
      if (res.ok) {
        const data = await res.json();
        if (data.status === 'success' && data.data) {
          const u = data.data;
          user = {
            id: u.id,
            name: u.name,
            email: u.email,
            role: u.role,
            status: u.account_status || 'Active',
            bio: u.bio || "",
            location: u.location || "",
            portfolio: u.portfolio_url || "",
            businessName: u.business_name || "",
            businessCategory: u.business_category || "",
            field: u.field || "",
            skills: u.skills || [],
            interests: u.interests || [],
            // Struktur kontak baru: Email Publik (public_email, terpisah dari
            // email login), WA, Sosial Media (reuse kolom instagram, cukup
            // label UI-nya diubah) -- LinkedIn/Website/Alamat sudah tidak
            // ditampilkan di modal ini sejak form Profil menghapus inputannya.
            contact: {
              email: u.public_email || "",
              whatsapp: u.whatsapp || "",
              instagram: u.instagram || ""
            }
          };
        }
      }
    } catch (e) {
      console.error('[showAdminUserProfileModal] Gagal fetch detail user:', e);
    }
  }

  if (!user) {
    alert('Data pengguna tidak ditemukan.');
    return;
  }

  const avatar = modal.querySelector('#admin-pm-avatar');
  const name = modal.querySelector('#admin-pm-name');
  const role = modal.querySelector('#admin-pm-role');
  
  const bioSec = modal.querySelector('#admin-pm-section-bio');
  const bioTxt = modal.querySelector('#admin-pm-bio');
  const skillsSec = modal.querySelector('#admin-pm-section-skills');
  const interestsSec = modal.querySelector('#admin-pm-section-interests');
  const skillsContainer = modal.querySelector('#admin-pm-skills');
  const interestsContainer = modal.querySelector('#admin-pm-interests');
  const portSec = modal.querySelector('#admin-pm-section-portfolio');
  const portLnk = modal.querySelector('#admin-pm-portfolio');
  const contSec = modal.querySelector('#admin-pm-section-contact');
  const contTxt = modal.querySelector('#admin-pm-contact');

  if (avatar) avatar.textContent = user.name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
  if (name) name.textContent = user.name;
  if (role) role.textContent = user.role || 'User';

  // LOGIKA KONDISIONAL: Keahlian (Skill), Minat & Bidang, dan Portfolio HANYA
  // relevan untuk profil Freelancer -- UMKM tidak punya form untuk data ini
  // (lihat profile.html, field skill/minat disembunyikan utk role umkm).
  // Disembunyikan total (bukan cuma dikosongkan) kalau yang dilihat admin
  // adalah profil UMKM.
  const isFreelancerProfile = user.role === 'freelancer';
  if (skillsSec) skillsSec.style.display = isFreelancerProfile ? '' : 'none';
  if (interestsSec) interestsSec.style.display = isFreelancerProfile ? '' : 'none';

  // Info bisnis — tampil hanya untuk akun UMKM
  const bizSec = modal.querySelector('#admin-pm-section-business');
  if (bizSec) {
    if (user.role === 'umkm') {
      bizSec.style.display = '';
      const bizName = modal.querySelector('#admin-pm-business-name');
      const bizCat  = modal.querySelector('#admin-pm-business-category');
      if (bizName) bizName.textContent = user.businessName || '—';
      if (bizCat)  bizCat.textContent  = user.businessCategory || '—';
    } else {
      bizSec.style.display = 'none';
    }
  }

  if (bioSec && bioTxt) {
    if (user.bio) {
      bioSec.style.display = '';
      bioTxt.textContent = user.bio;
    } else {
      bioSec.style.display = 'none';
    }
  }

  if (skillsContainer) {
    if (user.skills && user.skills.length > 0) {
      skillsContainer.innerHTML = user.skills.map(s => `<span class="dm-pill">${escapeHtml(s)}</span>`).join('');
    } else {
      skillsContainer.innerHTML = '<span style="color:var(--text-muted); font-size:var(--fs-sm);">Belum ada data</span>';
    }
  }

  if (interestsContainer) {
    if (user.interests && user.interests.length > 0) {
      interestsContainer.innerHTML = user.interests.map(i => `<span class="dm-pill">${escapeHtml(i)}</span>`).join('');
    } else {
      interestsContainer.innerHTML = '<span style="color:var(--text-muted); font-size:var(--fs-sm);">Belum ada data</span>';
    }
  }

  // Bidang (users.field) digabung ke baris/label yang sama dengan Minat
  // ("Minat & Bidang") — hanya tampil kalau ada isinya, bukan baris terpisah
  // yang kosong.
  const fieldTxt = modal.querySelector('#admin-pm-field');
  if (fieldTxt) {
    if (user.field) {
      fieldTxt.style.display = '';
      fieldTxt.innerHTML = `<strong style="color:var(--text-muted); font-size:var(--fs-sm); font-weight:600;">Bidang:</strong> ${escapeHtml(user.field)}`;
    } else {
      fieldTxt.style.display = 'none';
      fieldTxt.innerHTML = '';
    }
  }

  if (portSec && portLnk) {
    if (isFreelancerProfile && user.portfolio) {
      portSec.style.display = '';
      portLnk.href = user.portfolio;
      portLnk.textContent = user.portfolio;
    } else {
      portSec.style.display = 'none';
    }
  }

  if (contSec && contTxt) {
    if (user.contact) {
      contSec.style.display = '';
      if (typeof user.contact === 'object') {
        // Struktur baru: Email Publik, WA, Sosial Media (reuse kolom
        // instagram, label UI saja yang berubah) -- LinkedIn/Website/Alamat
        // tidak lagi ditampilkan di sini (inputannya sudah dihapus dari form
        // Profil UMKM & Freelancer).
        const c = user.contact;
        let html = '<div style="display: flex; flex-direction: column; gap: 0.5rem;">';
        if (c.email) html += `<div><strong style="color:var(--text-muted); font-size:var(--fs-sm);">Email Publik:</strong><br>${escapeHtml(c.email)}</div>`;
        if (c.whatsapp) html += `<div><strong style="color:var(--text-muted); font-size:var(--fs-sm);">WhatsApp:</strong><br>${escapeHtml(c.whatsapp)}</div>`;
        if (c.instagram) html += `<div><strong style="color:var(--text-muted); font-size:var(--fs-sm);">Sosial Media:</strong><br>${escapeHtml(c.instagram)}</div>`;
        html += '</div>';

        if (html === '<div style="display: flex; flex-direction: column; gap: 0.5rem;"></div>') {
          contTxt.innerHTML = '<span style="color:var(--text-muted); font-size:var(--fs-sm);">Belum ada kontak</span>';
        } else {
          contTxt.innerHTML = html;
        }
      } else {
        contTxt.textContent = user.contact;
      }
    } else {
      contSec.style.display = 'none';
    }
  }

  document.body.style.overflow = 'hidden';
  modal.hidden = false;
  void modal.offsetWidth;
  modal.classList.add('dm-visible');
};

document.body.addEventListener('click', (e) => {
  const adminViewProfileBtn = e.target.closest('.admin-btn-view-profile');
  if (adminViewProfileBtn) {
    const userId = adminViewProfileBtn.dataset.id;
    if (typeof window.showAdminUserProfileModal === 'function') {
      window.showAdminUserProfileModal(userId);
    }
  }
});

// ============================================================
// WORKFLOW BARU: SUBMIT PROYEK, REVIEW UMKM, & CHAT PROYEK
// (project_status: Open → In Progress → Submitted → Completed/Closed)
// ============================================================

// ── Submit Proyek (Freelancer) ───────────────────────────────
// Membuka modal formulir pengumpulan: freelancer wajib mengisi tautan/link
// hasil pekerjaan, lalu POST ke api/submit_project.php →
// submission_link tersimpan dan project_status berubah 'In Progress' → 'Submitted'.
function ensureSubmitProjectModal() {
  let backdrop = document.getElementById('submit-project-backdrop');
  if (backdrop) return backdrop;

  backdrop = document.createElement('div');
  backdrop.className = 'detail-backdrop';
  backdrop.id = 'submit-project-backdrop';
  backdrop.hidden = true;
  backdrop.setAttribute('aria-modal', 'true');
  backdrop.setAttribute('role', 'dialog');
  backdrop.innerHTML = `
    <div class="detail-modal" style="max-width: 460px;">
      <div class="dm-header">
        <div class="dm-header-info">
          <h2 class="dm-title" style="font-size:1.05rem;">Kumpulkan Hasil Pekerjaan</h2>
          <div class="dm-subtitle">Kirim tautan hasil kerja Anda ke client (UMKM)</div>
        </div>
        <button class="dm-close-btn" id="submit-project-close" aria-label="Tutup formulir">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="dm-body">
        <label for="submit-project-link" style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-color); margin-bottom:6px;">
          Tautan/Link Hasil Pekerjaan <span style="color:#c0392b;">*</span>
        </label>
        <input type="url" id="submit-project-link" maxlength="2000"
               placeholder="Contoh: https://drive.google.com/... atau https://github.com/..."
               style="width:100%; padding:0.6rem 0.75rem; border:1px solid var(--border-color); border-radius:var(--radius-md); font: inherit;">
        <p style="font-size:0.78rem; color:var(--text-muted); margin:6px 0 0;">
          Pastikan tautan dapat diakses oleh client (Google Drive, GitHub, Figma, dsb).
        </p>
        <div style="display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1rem;">
          <button type="button" class="btn btn-outline btn-sm" id="submit-project-cancel">Batal</button>
          <button type="button" class="btn btn-primary btn-sm" id="submit-project-send">Kirim Hasil Pekerjaan</button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(backdrop);

  backdrop.querySelector('#submit-project-close').addEventListener('click', () => window.closeModal(backdrop));
  backdrop.querySelector('#submit-project-cancel').addEventListener('click', () => window.closeModal(backdrop));
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) window.closeModal(backdrop);
  });

  return backdrop;
}

function submitProject(projectId, btnEl) {
  const currentUser = window.db ? db.getCurrentUser() : null;
  if (!currentUser || currentUser.role !== 'freelancer') {
    alert('Silakan login sebagai freelancer.');
    return;
  }

  const backdrop = ensureSubmitProjectModal();
  const linkInput = backdrop.querySelector('#submit-project-link');
  const sendBtn = backdrop.querySelector('#submit-project-send');

  linkInput.value = '';
  sendBtn.disabled = false;
  sendBtn.textContent = 'Kirim Hasil Pekerjaan';
  window.openModal(backdrop);
  setTimeout(() => linkInput.focus(), 100);

  // .onclick agar tidak menumpuk listener saat modal dibuka berulang kali
  sendBtn.onclick = () => {
    const link = linkInput.value.trim();
    if (!link) {
      alert('Tautan/link hasil pekerjaan wajib diisi sebelum mengumpulkan proyek!');
      linkInput.focus();
      return;
    }
    doSubmitProject(projectId, link, btnEl, backdrop, sendBtn);
  };
  linkInput.onkeydown = (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      sendBtn.onclick();
    }
  };
}

function doSubmitProject(projectId, submissionLink, btnEl, backdrop, sendBtn) {
  const currentUser = window.db ? db.getCurrentUser() : null;
  if (!currentUser) return;

  let originalLabel;
  if (btnEl) {
    originalLabel = btnEl.textContent;
    btnEl.disabled = true;
    btnEl.textContent = 'Mengirim...';
  }
  if (sendBtn) {
    sendBtn.disabled = true;
    sendBtn.textContent = 'Mengirim...';
  }

  const API_BASE = (window.db && window.db.API_BASE) || 'api';

  fetchJsonSafe(`${API_BASE}/submit_project.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      project_id: Number(projectId),
      freelancer_id: currentUser.id,
      submission_link: submissionLink,
    }),
  })
    .then((res) => {
      // Tutup modal formulir submit dengan aman (parameter `backdrop`, bukan
      // di-shadow oleh variabel lain — sebelumnya deklarasi ulang `const backdrop`
      // di bawah menyebabkan "cannot access 'backdrop' before initialization").
      if (backdrop) window.closeModal(backdrop);
      showSuccessToast(res.message || 'Hasil pekerjaan berhasil dikumpulkan!');
      if (btnEl) btnEl.textContent = 'Menunggu Review Client';

      // Sinkronkan cache lokal + badge modal (jika modal sedang terbuka)
      if (window.db && typeof window.db.updateProject === 'function') {
        window.db.updateProject(projectId, { status: 'Submitted', projectStatus: 'Submitted' });
      }
      const projectDetailBackdrop = document.getElementById('project-detail-backdrop');
      if (projectDetailBackdrop && String(projectDetailBackdrop.getAttribute('data-current-project-id')) === String(projectId)) {
        const badge = projectDetailBackdrop.querySelector('#pdm-status');
        if (badge) {
          badge.textContent = 'Submitted';
          badge.className = 'badge ' + statusBadgeClass('Submitted');
        }
      }

      if (typeof renderMyProjects === 'function' && document.getElementById('my-projects-grid')) {
        renderMyProjects();
      }
    })
    .catch((err) => {
      console.error(err);
      if (err.code === 'already_submitted') {
        if (backdrop) window.closeModal(backdrop);
        if (btnEl) btnEl.textContent = 'Menunggu Review Client';
        return;
      }
      alert(err.message || 'Gagal mengumpulkan hasil pekerjaan. Coba lagi.');
      if (btnEl) {
        btnEl.disabled = false;
        btnEl.textContent = originalLabel;
      }
      if (sendBtn) {
        sendBtn.disabled = false;
        sendBtn.textContent = 'Kirim Hasil Pekerjaan';
      }
    });
}
window.submitProject = submitProject;

// ── Review hasil submit (UMKM) ───────────────────────────────
// decision: 'accept' → Completed | 'revise' → In Progress | 'reject' → Closed
function reviewSubmission(decision, btnEl) {
  const backdrop = document.getElementById('project-detail-backdrop');
  const projectId = backdrop ? backdrop.getAttribute('data-current-project-id') : null;
  const currentUser = window.db ? db.getCurrentUser() : null;

  if (!projectId || !currentUser) {
    alert('Sesi tidak valid. Silakan muat ulang halaman.');
    return;
  }

  // Rating wajib saat menerima hasil; ulasan teks opsional
  const rating = typeof getPdmRating === 'function' ? getPdmRating() : 0;
  const reviewTextEl = document.getElementById('pdm-review-text');
  const reviewText = reviewTextEl ? reviewTextEl.value.trim() : '';

  if (decision === 'accept' && (rating < 1 || rating > 5)) {
    alert('Silakan pilih rating (bintang 1 sampai 5) terlebih dahulu sebelum menerima hasil pekerjaan.');
    return;
  }

  const confirmMsg = {
    accept: `Terima hasil pekerjaan freelancer dengan rating ${rating} bintang? Proyek akan ditandai Completed.`,
    revise: 'Kembalikan pekerjaan ke freelancer untuk direvisi? Status kembali ke In Progress.',
    reject: 'Tolak hasil dan batalkan proyek? Status akan menjadi Closed.',
  }[decision];
  if (!confirmMsg || !confirm(confirmMsg)) return;

  const actionsRow = document.getElementById('pdm-review-actions');
  if (actionsRow) actionsRow.querySelectorAll('button').forEach((b) => { b.disabled = true; });

  const API_BASE = (window.db && window.db.API_BASE) || 'api';

  fetchJsonSafe(`${API_BASE}/review_submission.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      project_id: Number(projectId),
      umkm_id: currentUser.id,
      decision: decision,
      rating: rating,
      review_text: reviewText,
    }),
  })
    .then((res) => {
      const newStatus = res.data && res.data.project_status;
      showSuccessToast(res.message || 'Keputusan berhasil disimpan.');

      const badge = backdrop.querySelector('#pdm-status');
      if (badge && newStatus) {
        badge.textContent = newStatus;
        badge.className = 'badge ' + statusBadgeClass(newStatus);
      }
      if (window.db && typeof window.db.updateProject === 'function' && newStatus) {
        window.db.updateProject(projectId, { status: newStatus, projectStatus: newStatus });
      }

      const section = document.getElementById('pdm-review-section');
      if (section) section.style.display = 'none';

      if (typeof renderUMKMProjects === 'function' && document.getElementById('umkm-projects-grid')) {
        renderUMKMProjects();
      }
      if (typeof renderUMKMDashboard === 'function' && document.getElementById('stat-total')) {
        renderUMKMDashboard();
      }
    })
    .catch((err) => {
      console.error(err);
      alert(err.message || 'Gagal menyimpan keputusan. Coba lagi.');
    })
    .finally(() => {
      if (actionsRow) actionsRow.querySelectorAll('button').forEach((b) => { b.disabled = false; });
    });
}
window.reviewSubmission = reviewSubmission;

// ── Mesin chat bersama (dipakai modal chat & panel chat UMKM) ─
// Merender pesan dari tabel `chats` berdasarkan project_id, polling tiap 4 detik.
function initChatBox(cfg) {
  const { projectId, messagesEl, inputEl, sendBtn } = cfg;
  if (!messagesEl || !projectId) return;

  const currentUser = window.db ? db.getCurrentUser() : null;
  if (!currentUser) {
    messagesEl.innerHTML = '<p style="color:var(--text-muted); font-size:0.85rem;">Silakan login untuk menggunakan chat.</p>';
    return;
  }

  // Penjagaan tambahan: proyek yang sudah Completed tidak lagi bisa di-chat,
  // dari jalur mana pun modal ini dibuka (termasuk dropdown "Pesan Klien").
  const cachedProject = window.db && typeof db.getProjectById === 'function' ? db.getProjectById(projectId) : null;
  const projectStatus = cachedProject ? (cachedProject.status || cachedProject.project_status) : null;
  if (projectStatus === 'Completed') {
    if (messagesEl.__chatTimer) { clearInterval(messagesEl.__chatTimer); messagesEl.__chatTimer = null; }
    messagesEl.innerHTML = '<p style="color:var(--text-muted); font-size:0.85rem;">Proyek ini sudah selesai — chat tidak lagi tersedia.</p>';
    if (inputEl) { inputEl.value = ''; inputEl.disabled = true; inputEl.placeholder = 'Chat ditutup (proyek selesai)'; }
    if (sendBtn) sendBtn.disabled = true;
    return;
  }
  if (inputEl) inputEl.disabled = false;
  if (sendBtn) sendBtn.disabled = false;

  const API_BASE = (window.db && window.db.API_BASE) || 'api';

  // Hentikan polling sebelumnya (mis. modal berpindah proyek)
  if (messagesEl.__chatTimer) {
    clearInterval(messagesEl.__chatTimer);
    messagesEl.__chatTimer = null;
  }

  messagesEl.dataset.projectId = String(projectId);
  messagesEl.innerHTML = '<p style="color:var(--text-muted); font-size:0.85rem;">Memuat pesan...</p>';

  let forceScroll = true;
  let hiddenTicks = 0;

  function doFetch() {
    fetchJsonSafe(`${API_BASE}/chats.php?project_id=${encodeURIComponent(projectId)}&user_id=${encodeURIComponent(currentUser.id)}`)
      .then((res) => {
        if (String(messagesEl.dataset.projectId) !== String(projectId)) return;
        renderChatMessages(messagesEl, res.data || [], currentUser.id, forceScroll);
        forceScroll = false;
      })
      .catch((err) => {
        console.error('[chat]', err);
        if (forceScroll) {
          messagesEl.innerHTML = `<p style="color:#c0392b; font-size:0.85rem;">${escapeHtml(err.message || 'Gagal memuat pesan.')}</p>`;
        }
      });
  }

  function tick() {
    if (!document.body.contains(messagesEl)) {
      clearInterval(messagesEl.__chatTimer);
      messagesEl.__chatTimer = null;
      return;
    }
    // Modal/panel sedang tidak tampil → jeda; berhenti total setelah ~12 dtk
    if (messagesEl.offsetParent === null) {
      hiddenTicks++;
      if (hiddenTicks > 3) {
        clearInterval(messagesEl.__chatTimer);
        messagesEl.__chatTimer = null;
      }
      return;
    }
    hiddenTicks = 0;
    doFetch();
  }

  function send() {
    const text = (inputEl && inputEl.value || '').trim();
    if (!text) return;
    if (sendBtn) sendBtn.disabled = true;

    fetchJsonSafe(`${API_BASE}/chats.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ project_id: Number(projectId), sender_id: currentUser.id, message: text }),
    })
      .then(() => {
        if (inputEl) inputEl.value = '';
        forceScroll = true;
        doFetch();
      })
      .catch((err) => {
        console.error('[chat:send]', err);
        if (!handleSessionMismatchError(err.message)) {
          alert(err.message || 'Gagal mengirim pesan.');
        }
      })
      .finally(() => {
        if (sendBtn) sendBtn.disabled = false;
        if (inputEl) inputEl.focus();
      });
  }

  // .onclick/.onkeydown (bukan addEventListener) agar re-init tidak menumpuk listener
  if (sendBtn) sendBtn.onclick = send;
  if (inputEl) inputEl.onkeydown = (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      send();
    }
  };

  doFetch();
  messagesEl.__chatTimer = setInterval(tick, 4000);
}
window.initChatBox = initChatBox;

function renderChatMessages(el, messages, meId, forceScroll) {
  const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;

  if (!messages.length) {
    el.innerHTML = '<p style="color:var(--text-muted); font-size:0.85rem; text-align:center; margin:1.25rem 0;">Belum ada pesan. Mulai percakapan!</p>';
    return;
  }

  el.innerHTML = messages.map((m) => {
    const mine = String(m.sender_id) === String(meId);
    const name = mine ? 'Anda' : escapeHtml(m.sender_business_name || m.sender_name || 'User');
    const time = formatChatTime(m.created_at);
    const bubbleStyle = mine
      ? 'background: var(--accent-green, #2D6A4F); color: #fff; border-bottom-right-radius: 4px;'
      : 'background: var(--card-bg, #f1f3f1); color: var(--text-color, #212529); border: 1px solid var(--border-color, #e2e2e2); border-bottom-left-radius: 4px;';
    return `
      <div style="display:flex; justify-content:${mine ? 'flex-end' : 'flex-start'}; margin-bottom:0.5rem;">
        <div style="max-width:75%; padding:0.5rem 0.75rem; border-radius:12px; font-size:0.875rem; line-height:1.45; ${bubbleStyle}">
          <div style="font-size:0.7rem; opacity:0.75; margin-bottom:2px;">${name} · ${time}</div>
          <div style="white-space:pre-wrap; word-break:break-word;">${escapeHtml(m.message)}</div>
        </div>
      </div>`;
  }).join('');

  if (forceScroll || nearBottom) el.scrollTop = el.scrollHeight;
}

function formatChatTime(dateStr) {
  if (!dateStr) return '';
  const d = new Date(String(dateStr).replace(' ', 'T'));
  if (isNaN(d.getTime())) return dateStr;
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  return `${d.getDate()} ${months[d.getMonth()]} ${hh}:${mm}`;
}

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const d = new Date(String(dateStr).replace(' ', 'T'));
  if (isNaN(d.getTime())) return dateStr;
  const diff = Math.floor((Date.now() - d.getTime()) / 1000);
  if (diff < 60) return 'Baru saja';
  if (diff < 3600) return `${Math.floor(diff / 60)} menit lalu`;
  if (diff < 86400) return `${Math.floor(diff / 3600)} jam lalu`;
  return `${Math.floor(diff / 86400)} hari lalu`;
}

// ── Modal chat mandiri (dipakai freelancer dari kartu "Proyek Saya") ─
function ensureChatModal() {
  let backdrop = document.getElementById('chat-modal-backdrop');
  if (backdrop) return backdrop;

  backdrop = document.createElement('div');
  backdrop.className = 'detail-backdrop';
  backdrop.id = 'chat-modal-backdrop';
  backdrop.hidden = true;
  backdrop.setAttribute('aria-modal', 'true');
  backdrop.setAttribute('role', 'dialog');
  backdrop.innerHTML = `
    <div class="detail-modal" style="max-width: 480px;">
      <div class="dm-header">
        <div class="dm-header-info">
          <h2 class="dm-title" style="font-size:1.05rem;">Chat Proyek</h2>
          <div class="dm-subtitle" id="chat-modal-partner">—</div>
        </div>
        <button class="dm-close-btn" id="chat-modal-close" aria-label="Tutup chat">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="dm-body" style="padding-top:0.75rem;">
        <div id="chat-modal-messages" style="height:300px; overflow-y:auto; padding:0.5rem; border:1px solid var(--border-color, #e2e2e2); border-radius:var(--radius-md, 8px); background: var(--bg-color, #fff);"></div>
        <div style="display:flex; gap:0.5rem; margin-top:0.75rem;">
          <input type="text" id="chat-modal-input" placeholder="Tulis pesan..." maxlength="2000"
                 style="flex:1; padding:0.5rem 0.75rem; border:1px solid var(--border-color, #e2e2e2); border-radius:var(--radius-md, 8px);">
          <button type="button" id="chat-modal-send" class="btn btn-primary btn-sm">Kirim</button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(backdrop);

  backdrop.querySelector('#chat-modal-close').addEventListener('click', () => window.closeModal(backdrop));
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) window.closeModal(backdrop);
  });

  return backdrop;
}

function openProjectChat(projectId, partnerName) {
  const currentUser = window.db ? db.getCurrentUser() : null;
  if (!currentUser) {
    alert('Silakan login terlebih dahulu.');
    return;
  }

  const backdrop = ensureChatModal();
  backdrop.querySelector('#chat-modal-partner').textContent = partnerName
    ? `dengan ${partnerName}`
    : 'Diskusi proyek';

  window.openModal(backdrop);

  initChatBox({
    projectId: projectId,
    messagesEl: backdrop.querySelector('#chat-modal-messages'),
    inputEl: backdrop.querySelector('#chat-modal-input'),
    sendBtn: backdrop.querySelector('#chat-modal-send'),
  });
}
window.openProjectChat = openProjectChat;

// ── Inbox "Pesan Klien" di dropdown notifikasi (data live, bukan dummy) ─
function loadClientInbox() {
  const listEl = document.getElementById('nd-pesan-list');
  if (!listEl) return;

  const currentUser = window.db ? db.getCurrentUser() : null;
  const isServer = window.location.protocol === 'http:' || window.location.protocol === 'https:';

  const emptyItem = (text) => `
    <div class="nd-item">
      <div class="nd-item-body"><p class="nd-item-text">${escapeHtml(text)}</p></div>
    </div>`;

  if (!currentUser || !isServer) {
    listEl.innerHTML = emptyItem('Belum ada pesan.');
    return;
  }

  const API_BASE = (window.db && window.db.API_BASE) || 'api';

  fetchJsonSafe(`${API_BASE}/chats.php?inbox=${encodeURIComponent(currentUser.id)}`)
    .then((res) => {
      const msgs = res.data || [];

      const badge = document.querySelector('.notif-badge');
      if (badge) badge.textContent = String(msgs.length > 9 ? '9+' : msgs.length);

      if (!msgs.length) {
        listEl.innerHTML = emptyItem('Belum ada pesan masuk.');
        return;
      }

      listEl.innerHTML = msgs.map((m) => {
        const sender = escapeHtml(m.sender_business_name || m.sender_name || 'User');
        const excerpt = escapeHtml(String(m.message).substring(0, 80));
        return `
        <button type="button" class="nd-item btn-chat-project"
                data-project-id="${Number(m.project_id)}" data-partner="${sender}"
                style="width:100%; text-align:left; background:none; border:none; cursor:pointer; font: inherit;">
          <div class="nd-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          </div>
          <div class="nd-item-body">
            <p class="nd-item-text"><strong>${sender}</strong> · ${escapeHtml(m.project_title || '')}<br>"${excerpt}"</p>
            <span class="nd-item-time">${timeAgo(m.created_at)}</span>
          </div>
        </button>`;
      }).join('');
    })
    .catch((err) => {
      console.error('[inbox]', err);
      listEl.innerHTML = emptyItem('Gagal memuat pesan.');
    });
}
window.loadClientInbox = loadClientInbox;

document.addEventListener('DOMContentLoaded', () => {
  if (window.db && typeof window.db.whenReady === 'function') {
    window.db.whenReady().then(loadClientInbox);
  } else {
    loadClientInbox();
  }
});

// ── Delegasi klik global untuk tombol workflow baru ──────────
document.body.addEventListener('click', (e) => {
  const submitBtn = e.target.closest('.btn-submit-project');
  if (submitBtn) {
    submitProject(submitBtn.dataset.projectId, submitBtn);
    return;
  }

  const chatBtn = e.target.closest('.btn-chat-project');
  if (chatBtn) {
    openProjectChat(chatBtn.dataset.projectId, chatBtn.dataset.partner || '');
    return;
  }

  const reviewBtn = e.target.closest('[data-review-action]');
  if (reviewBtn) {
    reviewSubmission(reviewBtn.dataset.reviewAction, reviewBtn);
    return;
  }

  // Tombol "Lamar Sekarang" pada kartu Rekomendasi Proyek Pintar (AI Matcher)
  const aiRecoApplyBtn = e.target.closest('.btn-ai-reco-apply');
  if (aiRecoApplyBtn) {
    const currentUser = window.db ? db.getCurrentUser() : null;
    if (!currentUser) {
      alert('Anda harus login terlebih dahulu untuk melamar proyek.');
      return;
    }
    applyProject(aiRecoApplyBtn.dataset.projectId, currentUser.id, aiRecoApplyBtn).catch(() => {});
    return;
  }

  // Tombol "Lamar Sekarang" pada card Eksplorasi Proyek (marketplace.html)
  const marketApplyBtn = e.target.closest('.btn-marketplace-apply');
  if (marketApplyBtn) {
    const currentUser = window.db ? db.getCurrentUser() : null;
    if (!currentUser) {
      alert('Anda harus login terlebih dahulu untuk melamar proyek.');
      return;
    }
    applyProject(marketApplyBtn.dataset.projectId, currentUser.id, marketApplyBtn).catch(() => {});
    return;
  }

  // Tombol "Lihat Detail" pada card Eksplorasi Proyek — tetap bisa dibuka di
  // semua status (Open/Review/Close) supaya freelancer bisa baca deskripsi lengkap.
  const marketDetailBtn = e.target.closest('.btn-marketplace-detail');
  if (marketDetailBtn) {
    const card = marketDetailBtn.closest('.card-marketplace');
    const id = card ? parseInt(card.dataset.id, 10) : NaN;
    const data = window.db ? db.getProjects().find((p) => String(p.id) === String(id)) : null;
    if (data) populateAndOpenProjectModal(data, window.openModal);
    return;
  }
});
