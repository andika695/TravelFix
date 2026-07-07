// js/db.js — Simulated Local Database Auth
(function (global) {
  'use strict';

  // Kanonisasi hostname ke "localhost" -- SANGAT PENTING: browser
  // memperlakukan "localhost" dan "127.0.0.1" sebagai origin BERBEDA TOTAL
  // (cookie session PHP TIDAK PERNAH dibagi di antara keduanya, walau server
  // & port-nya sama persis). Kalau kadang browsing lewat salah satu dan
  // kadang lewat yang lain (mis. autocomplete/riwayat browser, bookmark
  // lama campur baru), setiap kali "nyasar" ke hostname yang berbeda dari
  // yang dipakai login, sesi tampak hilang/salah akun terus-menerus --
  // gejala "harus login lagi tiap refresh" atau "sesi bukan akun yang
  // benar" padahal login-nya baik-baik saja. Paksa selalu ke satu hostname
  // supaya SATU cookie sesi konsisten dipakai di halaman mana pun.
  if (window.location.protocol === 'http:' && window.location.hostname === '127.0.0.1') {
    window.location.replace('http://localhost' + window.location.pathname + window.location.search + window.location.hash);
    return;
  }

  const DB_KEY = 'bantul_users';
  let dbInitialized = false;

  const IS_SERVER = (
    window.location.protocol === 'http:' ||
    window.location.protocol === 'https:'
  );

  const API_BASE = (function () {
    const path = window.location.pathname;
    const dir  = path.substring(0, path.lastIndexOf('/') + 1);
    return dir + 'api';
  })();

  // ── Isolasi sesi PER TAB (2026-07-05) ────────────────────────────────
  // Cookie PHPSESSID dibagi ke SEMUA TAB pada satu browser (scope cookie
  // per-origin, bukan per-tab) -- akar dari serangkaian bug "sesi bentrok"
  // ("Hanya akun freelancer...", "Akses ditolak...", "bukan partisipan
  // chat...") kalau tab lain pada browser yang sama login sebagai akun
  // berbeda (lihat api/config.php utk penjelasan lengkap & trade-off
  // keamanannya). Perbaikan: simpan id sesi di sessionStorage (MEMANG
  // ter-isolasi per tab, beda dari cookie) sejak login/registrasi, lalu
  // tempel eksplisit lewat header X-Session-Id ke SETIAP fetch() menuju
  // API kita sendiri. Dipasang SEKALI di sini (monkey-patch window.fetch)
  // supaya tidak ada satu pun pemanggilan fetch yang tersebar di banyak
  // file (app.js/auth.js/profile.html/dst.) yang lolos tanpa header ini.
  const SESSION_ID_KEY = 'bantul_session_id';

  function getStoredSessionId() {
    try { return sessionStorage.getItem(SESSION_ID_KEY) || ''; } catch (e) { return ''; }
  }

  function saveSessionId(id) {
    try {
      if (id) sessionStorage.setItem(SESSION_ID_KEY, id);
      else sessionStorage.removeItem(SESSION_ID_KEY);
    } catch (e) { /* sessionStorage tidak tersedia -- abaikan, fallback ke cookie biasa */ }
  }
  // Dipakai js/auth.js setelah login/registrasi sukses (file terpisah,
  // tidak berbagi closure ini) -- lihat pemanggilannya di sana.
  global.__bantulSaveSessionId = saveSessionId;

  if (IS_SERVER && typeof window.fetch === 'function' && !window.__bantulFetchPatched) {
    window.__bantulFetchPatched = true;
    const nativeFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      let sid = '';
      try {
        const rawUrl = typeof input === 'string' ? input : (input && input.url) || '';
        const resolved = new URL(rawUrl, window.location.href);
        // Hanya API origin sendiri yang dapat header ini -- API pihak
        // ketiga (mis. Nominatim geocoding) TIDAK PERNAH ikut ditempeli.
        if (resolved.origin === window.location.origin && resolved.pathname.indexOf('/api/') !== -1) {
          sid = getStoredSessionId();
        }
      } catch (e) { /* URL tak wajar -- anggap bukan API kita, aman diabaikan */ }

      if (sid && typeof input === 'string') {
        const headers = new Headers((init && init.headers) || undefined);
        headers.set('X-Session-Id', sid);
        init = Object.assign({}, init, { headers });
      }
      return nativeFetch(input, init);
    };
  }

  let cachedUsers     = null;
  let cachedProjects  = null;
  let dbReadyPromise  = null;

  // Bentuk "app-shape" satu user (camelCase, contact bersarang) dari satu baris
  // API mentah -- dipakai bersama oleh loadServerData() (daftar cachedUsers)
  // DAN rehydrateSessionFromServer() (lihat di bawah), supaya sessionStorage.
  // currentUser konsisten persis sama bentuknya di mana pun ia diisi.
  function mapUserRow(u) {
    return {
      id: u.id,
      name: u.name,
      email: u.email,
      role: u.role,
      status: u.account_status || u.status || 'Active',
      accountStatus: u.account_status || u.status || 'Active',
      bio: u.bio || "",
      location: u.location || "",
      latitude: u.latitude != null ? Number(u.latitude) : null,
      longitude: u.longitude != null ? Number(u.longitude) : null,
      portfolio: u.portfolio_url || "",
      businessName: u.business_name || "",
      businessCategory: u.business_category || "",
      skills: u.skills || [],
      interests: u.interests || [],
      contact: {
        // public_email ("Email Publik") beda dari email (login) -- fallback ke
        // email HANYA untuk akun yang belum pernah mengisi public_email sama
        // sekali (harusnya jarang terjadi setelah backfill migrasi V6, tapi
        // dijaga di sini juga untuk baris user yang entah kenapa null).
        email: u.public_email || u.email || "",
        whatsapp: u.whatsapp || "",
        instagram: u.instagram || "",
        linkedin: u.linkedin || "",
        website: u.website || "",
        address: u.address || ""
      }
    };
  }

  async function loadServerData() {
    if (!IS_SERVER) return;
    try {
      const [usersRes, projectsRes] = await Promise.all([
        fetch(`${API_BASE}/users.php`),
        fetch(`${API_BASE}/projects.php`)
      ]);

      if (usersRes.ok) {
        const data = await usersRes.json();
        if (data.success && data.users) {
          cachedUsers = data.users.map(mapUserRow);
        }
      }

      if (projectsRes.ok) {
        const data = await projectsRes.json();
        if (data.success && data.projects) {
          cachedProjects = data.projects.map(mapProjectRow);
        }
      }
    } catch (e) {
      console.error('[db] Gagal memuat data dari server:', e);
    }
  }

  // sessionStorage.currentUser hanya hidup selama TAB itu terbuka (beda dengan
  // localStorage) dan hanya diisi field terbatas oleh login.php. Kalau tab
  // baru dibuka (link dibuka di tab lain, browser restart tanpa restore
  // session, dst.) sessionStorage kosong padahal cookie session PHP ($_SESSION,
  // sumber kebenaran identitas sekarang -- lihat api/config.php) masih valid.
  // Tanpa ini, halaman manapun yang langsung membaca db.getCurrentUser() di
  // tab baru akan menganggap user belum login / salah role, padahal sudah.
  // Dipanggil sekali di initDB(); no-op kalau currentUser sudah ada di tab ini.
  function rehydrateSessionFromServer() {
    if (!IS_SERVER) return Promise.resolve();
    if (sessionStorage.getItem('currentUser')) return Promise.resolve();

    // PENTING: sejak isolasi sesi per-tab (lihat SESSION_ID_KEY di atas),
    // rehidrasi HANYA boleh jalan kalau TAB INI SENDIRI sudah pernah punya
    // id sesi eksplisit (mis. sessionStorage.currentUser kebetulan terhapus
    // padahal tab ini masih login). Kalau tab ini BENAR-BENAR baru (belum
    // pernah login sama sekali di tab ini), JANGAN coba tebak dari cookie
    // bersama browser -- itu justru mengembalikan bug lama "tab baru ikut
    // ke-login sebagai akun dari tab lain". Tab baru yang belum login harus
    // tetap tampil sebagai belum login, titik.
    if (!getStoredSessionId()) return Promise.resolve();

    return fetch(`${API_BASE}/me.php`)
      .then((res) => (res.ok ? res.json() : null))
      .then((json) => {
        if (json && json.status === 'success' && json.data) {
          sessionStorage.setItem('currentUser', JSON.stringify(mapUserRow(json.data)));
        }
      })
      .catch((e) => console.error('[db] Gagal memulihkan sesi dari server:', e));
  }

  // Normalisasi satu baris proyek dari API (kolom DB) ke bentuk yang dipakai
  // frontend (mis. budget -> prize). Dipakai bersama oleh loadServerData()
  // dan halaman lain yang fetch proyek dari endpoint berbeda (mis. marketplace).
  function mapProjectRow(p) {
    // Sistem status baru memakai kolom project_status; kolom status lama
    // dipakai sebagai fallback untuk data/endpoint yang belum dimigrasi.
    const status = p.project_status || p.status || "Open";
    const freelancerId = (p.freelancer_id !== undefined && p.freelancer_id !== null)
      ? p.freelancer_id
      : (p.assigned_to || null);
    return {
      id: p.id,
      title: p.title,
      description: p.description || "",
      prize: p.budget || "",
      deadline: p.deadline || "",
      status: status,
      projectStatus: status,
      icon: p.icon || "puzzle",
      location: p.location || "",
      requirements: p.requirements || "",
      createdBy: p.created_by,
      created_by: p.created_by,
      creatorName: p.creator_business_name || p.creator_name || "",
      categories: p.categories || [],
      applicants: p.applicants || [],
      assignedTo: freelancerId,
      assigned_to: freelancerId,
      freelancerId: freelancerId,
      freelancer_id: freelancerId,
      freelancerName: p.freelancer_name || "",
      submissionLink: p.submission_link || "",
      submission_link: p.submission_link || "",
      rating: p.rating != null ? Number(p.rating) : null,
      reviewText: p.review_text || "",
      review_text: p.review_text || "",
      latitude: p.latitude != null ? Number(p.latitude) : null,
      longitude: p.longitude != null ? Number(p.longitude) : null,
      createdAt: p.created_at,
      updatedAt: p.updated_at
    };
  }

  // --- Database Migration Helpers ---
  function migrateUsers() {
    const users = JSON.parse(localStorage.getItem(DB_KEY));
    if (!users) return;

    let maxId = users.reduce((max, u) => Math.max(max, Number(u.id) || 0), 0);
    let updated = false;

    users.forEach(u => {
      if (!u.id) {
        maxId++;
        u.id = maxId;
        updated = true;
      }
      if (!u.createdAt) {
        u.createdAt = new Date().toISOString();
        updated = true;
      }
      if (u.bio === undefined) { u.bio = ""; updated = true; }
      if (u.location === undefined) { u.location = ""; updated = true; }
      if (u.portfolio === undefined) { u.portfolio = ""; updated = true; }
      
      if (u.contact === undefined || u.contact === null || typeof u.contact === "string") {
        const oldContact = typeof u.contact === "string" ? u.contact : "";
        u.contact = {
          email: u.email || "",
          whatsapp: oldContact,
          instagram: "",
          linkedin: "",
          website: "",
          address: ""
        };
        updated = true;
      }

      if (u.skills === undefined) { u.skills = []; updated = true; }
      if (u.interests === undefined) { u.interests = []; updated = true; }
      if (u.businessCategory === undefined) { u.businessCategory = ""; updated = true; }
    });

    if (updated) {
      localStorage.setItem(DB_KEY, JSON.stringify(users));
    }
  }

  function migrateCurrentUser() {
    const currentUser = JSON.parse(sessionStorage.getItem('currentUser'));
    if (currentUser && !currentUser.id) {
      const users = JSON.parse(localStorage.getItem(DB_KEY)) || [];
      const match = users.find(u => u.email === currentUser.email);
      if (match && match.id) {
        currentUser.id = match.id;
        sessionStorage.setItem('currentUser', JSON.stringify(currentUser));
      }
    }
  }

  function migrateProjects() {
    const projects = JSON.parse(localStorage.getItem('bantul_projects'));
    if (!projects) return;

    let updated = false;

    projects.forEach(p => {
      if (p.createdBy === undefined) {
        p.createdBy = null;
        updated = true;
      }
      if (!p.applicants) {
        p.applicants = [];
        updated = true;
      }
      if (p.assignedTo === undefined) {
        p.assignedTo = null;
        updated = true;
      }
      if (!p.createdAt) {
        p.createdAt = new Date().toISOString();
        updated = true;
      }
      if (!p.updatedAt) {
        p.updatedAt = p.createdAt;
        updated = true;
      }
      
      if (p.categories === null || p.categories === undefined) {
        p.categories = [];
        updated = true;
      } else if (typeof p.categories === 'string') {
        p.categories = [p.categories];
        updated = true;
      }
    });

    if (updated) {
      localStorage.setItem('bantul_projects', JSON.stringify(projects));
    }
  }

  // Task 2.1: Initialization (initDB)
  function initDB() {
    if (dbInitialized) return dbReadyPromise;

    if (IS_SERVER) {
      dbReadyPromise = Promise.all([loadServerData(), rehydrateSessionFromServer()]);
    } else {
      dbReadyPromise = Promise.resolve();
    }

    let users = JSON.parse(localStorage.getItem(DB_KEY));
    if (!users) {
      // Seed default accounts
      users = [
        { name: "User Reguler", email: "user123@gmail.com", password: "user123", role: "freelancer" },
        { name: "Admin System", email: "admin123@gmail.com", password: "admin123", role: "admin" },
        { name: "UMKM Kasongan", email: "umkm123@gmail.com", password: "umkm123", role: "umkm" }
      ];
      localStorage.setItem(DB_KEY, JSON.stringify(users));
    }

    if (!localStorage.getItem('bantul_projects') && typeof marketplaceProjects !== 'undefined') {
      localStorage.setItem('bantul_projects', JSON.stringify(marketplaceProjects));
    }
    if (!localStorage.getItem('bantul_portfolio') && typeof portfolioProjects !== 'undefined') {
      localStorage.setItem('bantul_portfolio', JSON.stringify(portfolioProjects));
    }
    if (!localStorage.getItem('bantul_villages') && typeof trailLocations !== 'undefined') {
      localStorage.setItem('bantul_villages', JSON.stringify(trailLocations));
    }

    // Run migrations
    migrateUsers();
    migrateCurrentUser();
    migrateProjects();

    dbInitialized = true;
    return dbReadyPromise;
  }

  // Resolves once server data (users + projects) has finished loading.
  // Safe to call before initDB() — it will trigger initialization itself.
  function whenReady() {
    return Promise.resolve(initDB());
  }

  // Task 2.2: Register User
  function registerUser(name, email, password, role = "freelancer") {
    const users = JSON.parse(localStorage.getItem(DB_KEY)) || [];
    
    // Check if email exists
    const emailExists = users.some(u => u.email === email);
    if (emailExists) {
      return { success: false, message: "Email sudah terdaftar!" };
    }

    // Generate unique incremental id
    const newId = users.length > 0 ? Math.max(...users.map(u => Number(u.id) || 0)) + 1 : 1;

    // Push new user
    users.push({ 
      id: newId,
      name, 
      email, 
      password, 
      role: role,
      createdAt: new Date().toISOString()
    });
    localStorage.setItem(DB_KEY, JSON.stringify(users));
    return { success: true };
  }

  // Task 2.3: Login User
  function loginUser(email, password) {
    const users = JSON.parse(localStorage.getItem(DB_KEY)) || [];
    
    const user = users.find(u => u.email === email && u.password === password);
    if (user) {
      // Save current session
      sessionStorage.setItem('currentUser', JSON.stringify(user));
      return { success: true, role: user.role };
    } else {
      return { success: false, message: "Email atau password salah!" };
    }
  }

  // Task 6.3: Change Password
  function changePassword(userId, currentPassword, newPassword) {
    const users = JSON.parse(localStorage.getItem(DB_KEY)) || [];
    const idx = users.findIndex(u => String(u.id) === String(userId));
    if (idx === -1) return { success: false, message: "User tidak ditemukan" };
    
    if (users[idx].password !== currentPassword) {
      return { success: false, message: "Password saat ini salah!" };
    }
    
    users[idx].password = newPassword;
    localStorage.setItem(DB_KEY, JSON.stringify(users));
    
    // update current session if it's the current user
    const currentUser = JSON.parse(sessionStorage.getItem('currentUser'));
    if (currentUser && String(currentUser.id) === String(userId)) {
      currentUser.password = newPassword;
      sessionStorage.setItem('currentUser', JSON.stringify(currentUser));
    }
    
    return { success: true };
  }

  // New Data Access Layer API
  function getUsers() {
    if (IS_SERVER) {
      return cachedUsers || [];
    }
    initDB();
    return JSON.parse(localStorage.getItem(DB_KEY)) || [];
  }

  function getProjects() {
    if (IS_SERVER && cachedProjects !== null) {
      return cachedProjects;
    }
    initDB();
    return JSON.parse(localStorage.getItem('bantul_projects')) || [];
  }

  function getPortfolio() {
    initDB();
    return JSON.parse(localStorage.getItem('bantul_portfolio')) || [];
  }

  function getVillages() {
    initDB();
    return JSON.parse(localStorage.getItem('bantul_villages')) || [];
  }

  function getCurrentUser() {
    // Dibungkus try/catch: kalau isi sessionStorage.currentUser pernah rusak
    // (bukan JSON valid, mis. gara-gara ekstensi browser atau devtools),
    // JSON.parse melempar SyntaxError yang TIDAK tertangkap di pemanggil
    // manapun -- itu menghentikan seluruh script halaman di titik itu juga
    // (profil tidak kebuka, Impact Portfolio/AI Match/Proyek Saya ikut mati
    // karena semuanya memanggil getCurrentUser() duluan sebelum render apa
    // pun). Self-heal: buang entri rusak, anggap belum login.
    try {
      return JSON.parse(sessionStorage.getItem('currentUser')) || null;
    } catch (e) {
      console.error('[db] sessionStorage.currentUser rusak, direset:', e);
      sessionStorage.removeItem('currentUser');
      return null;
    }
  }

  function saveUsers(users) {
    localStorage.setItem(DB_KEY, JSON.stringify(users));
  }

  function saveProjects(projects) {
    localStorage.setItem('bantul_projects', JSON.stringify(projects));
  }

  function logout() {
    sessionStorage.removeItem('currentUser');
    saveSessionId(''); // hapus id sesi tab ini juga -- lihat catatan di atas
  }

  // --- CRUD API ---

  // Terjemahkan bentuk "updates" gaya app (camelCase, contact bersarang --
  // lihat pemetaan cachedUsers di loadServerData()) ke bentuk flat yang
  // dipahami api/users.php PUT ($allowed: portfolio_url, business_category,
  // whatsapp, instagram, linkedin, website, address, dst). Tanpa ini, PUT
  // mengirim `portfolio`/`businessCategory`/`contact.whatsapp` yang TIDAK
  // dikenali backend (nama field beda) -- backend diam-diam mengabaikannya
  // (bukan error), jadi field-field itu kelihatan "hilang" tiap kali profil
  // dibuka lagi padahal sebenarnya memang tidak pernah tersimpan.
  function mapUserUpdatesToApi(updates) {
    const body = {};
    ['name', 'bio', 'location', 'businessName', 'field', 'latitude', 'longitude', 'skills', 'interests'].forEach((key) => {
      if (key in updates) body[key === 'businessName' ? 'business_name' : key] = updates[key];
    });
    if ('portfolio' in updates) body.portfolio_url = updates.portfolio;
    if ('businessCategory' in updates) body.business_category = updates.businessCategory;

    if (updates.contact && typeof updates.contact === 'object') {
      ['whatsapp', 'instagram', 'linkedin', 'website', 'address'].forEach((key) => {
        if (key in updates.contact) body[key] = updates.contact[key];
      });
      // contact.email ("Email Publik") -> kolom users.public_email (terpisah
      // dari users.email yang dipakai login/otentikasi -- lihat migrasi V6).
      if ('email' in updates.contact) body.public_email = updates.contact.email;
    }
    return body;
  }

  // User CRUD
  function getUserById(id) {
    const users = getUsers();
    const user = users.find(u => String(u.id) === String(id));
    return user || null;
  }

  function updateUser(id, updates) {
    const users = getUsers();
    const index = users.findIndex(u => String(u.id) === String(id));

    if (index === -1) {
      // cachedUsers belum selesai dimuat (loadServerData() masih berjalan) --
      // tunggu lalu coba lagi, alih-alih langsung gagal dengan "Pengguna tidak
      // ditemukan" padahal usernya jelas ada (race ini nyata: form profil
      // bisa saja disubmit sebelum fetch awal users.php selesai).
      if (IS_SERVER && cachedUsers === null) {
        return whenReady().then(() => updateUser(id, updates));
      }
      return IS_SERVER ? Promise.reject(new Error('Pengguna tidak ditemukan.')) : null;
    }

    const previous = users[index];
    users[index] = { ...previous, ...updates };
    saveUsers(users);

    if (!IS_SERVER) {
      return users[index];
    }

    // Mode server: simpan ke MySQL (session PHP yang menentukan izin, bukan
    // actor_id yang dikirim di sini -- lihat api/users.php). Request ini
    // HARUS ditunggu (bukan fire-and-forget seperti sebelumnya): tanpa
    // menunggu, UI langsung menampilkan "berhasil" walau validasi backend
    // (mis. koordinat di luar Bantul, atau sesi tidak valid) menolak
    // perubahan -- caller lalu percaya data sudah tersimpan padahal belum.
    return fetch(`${API_BASE}/users.php?id=${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...mapUserUpdatesToApi(updates), actor_id: id })
    })
      .then(res => res.json().then(json => ({ ok: res.ok, json })))
      .then(({ ok, json }) => {
        if (!ok || !json.success) {
          throw new Error((json && json.message) || 'Gagal menyimpan profil ke server.');
        }
        return users[index];
      })
      .catch(e => {
        console.error('[db] Gagal sinkron profil ke server:', e);
        // Batalkan mutasi lokal supaya cache tidak menampilkan data yang
        // sebenarnya gagal disimpan di server.
        users[index] = previous;
        saveUsers(users);
        throw e;
      });
  }

  function deleteUser(id) {
    if (IS_SERVER) {
      if (cachedUsers) {
        cachedUsers = cachedUsers.filter(u => String(u.id) !== String(id));
      }
      fetch(`${API_BASE}/users.php?id=${id}`, { method: 'DELETE' })
        .catch(e => console.error('[db] Gagal delete user di server:', e));
      return true;
    }

    const users = getUsers();
    const index = users.findIndex(u => String(u.id) === String(id));
    if (index !== -1) {
      users.splice(index, 1);
      saveUsers(users);
      return true;
    }
    return false;
  }

  function suspendUser(id) {
    if (IS_SERVER) {
      let updatedUser = null;
      if (cachedUsers) {
        const idx = cachedUsers.findIndex(u => String(u.id) === String(id));
        if (idx !== -1) {
          const isSuspended = cachedUsers[idx].status === 'suspended' || cachedUsers[idx].status === 'Suspended';
          cachedUsers[idx].status = isSuspended ? 'active' : 'suspended';
          updatedUser = cachedUsers[idx];
        }
      }
      fetch(`${API_BASE}/users.php?action=suspend&id=${id}`, { method: 'POST' })
        .catch(e => console.error('[db] Gagal suspend user di server:', e));
      return updatedUser;
    }

    const users = getUsers();
    const index = users.findIndex(u => String(u.id) === String(id));
    if (index !== -1) {
      // Toggle suspended status
      if (users[index].status === 'Suspended') {
        users[index].status = 'Active';
      } else {
        users[index].status = 'Suspended';
      }
      saveUsers(users);
      return users[index];
    }
    return null;
  }

  // --- Project CRUD ---
  function getProjectById(id) {
    const projects = getProjects();
    const project = projects.find(p => String(p.id) === String(id));
    return project || null;
  }

  function createProject(project) {
    const currentUser = getCurrentUser();
    if (!currentUser) return null;

    if (IS_SERVER) {
      // Mode MySQL: tambah ke cache sementara agar UI langsung terasa responsif,
      // lalu POST ke API dan refresh cache dengan data resmi dari server.
      const payload = {
        ...project,
        created_by: currentUser.id,
        prize: project.prize || project.budget || '',
        location: project.location || '',
        requirements: project.requirements || '',
      };

      const tempProject = {
        id: `temp-${Date.now()}`,
        ...project,
        createdBy: currentUser.id,
        created_by: currentUser.id,
        applicants: [],
        assignedTo: null,
        status: 'Open',
        icon: project.icon || 'puzzle',
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString()
      };
      if (cachedProjects) cachedProjects.unshift(tempProject);

      return fetch(`${API_BASE}/projects.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(r => r.json()).then(data => {
        if (!data.success || !data.id) {
          throw new Error(data.message || 'Gagal membuat proyek.');
        }
        // Reload cached projects langsung dari server agar id & data akurat
        return loadServerData().then(() => getProjectById(data.id) || tempProject);
      }).catch(e => {
        console.error('[db] Gagal buat proyek di server:', e);
        // Buang entri sementara supaya UI tidak menampilkan data yang gagal disimpan
        if (cachedProjects) {
          cachedProjects = cachedProjects.filter(p => p.id !== tempProject.id);
        }
        throw e;
      });
    }

    const projects = getProjects();
    const newId = projects.length > 0 ? Math.max(...projects.map(p => Number(p.id) || 0)) + 1 : 1;
    const now = new Date().toISOString();

    let safeCategories = [];
    if (project.categories) {
      safeCategories = Array.isArray(project.categories) 
        ? project.categories 
        : (typeof project.categories === 'string' ? [project.categories] : []);
    }

    const newProject = { 
      id: newId, 
      createdBy: currentUser.id,
      applicants: [],
      assignedTo: null,
      createdAt: now,
      updatedAt: now,
      ...project,
      status: project.status || "Open",
      icon: project.icon || "puzzle",
      categories: safeCategories
    };
    projects.push(newProject);
    saveProjects(projects);
    return newProject;
  }

  function updateProject(id, updates) {
    const projects = getProjects();
    const index = projects.findIndex(p => String(p.id) === String(id));
    if (index !== -1) {
      projects[index] = { ...projects[index], ...updates };
      saveProjects(projects);
      return projects[index];
    }
    return null;
  }

  function deleteProject(id) {
    const projects = getProjects();
    const index = projects.findIndex(p => String(p.id) === String(id));
    if (index !== -1) {
      projects.splice(index, 1);
      saveProjects(projects);
      return true;
    }
    return false;
  }

  function forceCloseProject(id) {
    const projects = getProjects();
    const index = projects.findIndex(p => String(p.id) === String(id));
    if (index !== -1) {
      projects[index].status = "Closed";
      projects[index].updatedAt = new Date().toISOString();
      saveProjects(projects);
      return projects[index];
    }
    return null;
  }

  // --- Workflow Engine API ---

  function applyProject(projectId, freelancerId) {
    const project = getProjectById(projectId);
    if (!project || project.status !== "Open") return null;
    
    if (project.applicants.includes(freelancerId)) {
      return null;
    }

    const updates = {
      applicants: [...project.applicants, freelancerId],
      updatedAt: new Date().toISOString()
    };
    return updateProject(projectId, updates);
  }

  function approveApplicant(projectId, freelancerId) {
    projectId = Number(projectId);
    freelancerId = Number(freelancerId);

    const project = getProjectById(projectId);
    if (!project) return null;

    if (!project.applicants.includes(freelancerId)) {
      return null;
    }

    const updates = {
      assignedTo: freelancerId,
      status: "In Review",
      updatedAt: new Date().toISOString()
    };

    return updateProject(projectId, updates);
  }

  function finishProject(projectId) {
    const project = getProjectById(projectId);
    if (!project || project.status !== "In Progress") return null;

    const updates = {
      status: "Done",
      updatedAt: new Date().toISOString()
    };
    return updateProject(projectId, updates);
  }

  function getProjectsByCreator(userId) {
    return getProjects().filter(p => String(p.createdBy) === String(userId));
  }

  function getAppliedProjects(freelancerId) {
    return getProjects().filter(p => p.applicants.includes(freelancerId));
  }

  function getAssignedProjects(freelancerId) {
    return getProjects().filter(p => String(p.assignedTo) === String(freelancerId));
  }

  // Expose to global window object
  global.db = {
    initDB,
    registerUser,
    loginUser,
    changePassword,
    getUsers,
    getProjects,
    getPortfolio,
    getVillages,
    getCurrentUser,
    saveUsers,
    saveProjects,
    logout,
    getUserById,
    updateUser,
    deleteUser,
    suspendUser,
    getProjectById,
    createProject,
    updateProject,
    deleteProject,
    forceCloseProject,
    applyProject,
    approveApplicant,
    finishProject,
    getProjectsByCreator,
    getAppliedProjects,
    getAssignedProjects,
    loadServerData,
    whenReady,
    mapProjectRow,
    mapUserRow,
    API_BASE
  };

  // Auto-init on DOMContentLoaded to guarantee data.js is parsed
  document.addEventListener("DOMContentLoaded", initDB);

})(window);
