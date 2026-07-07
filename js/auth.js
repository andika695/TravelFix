// js/auth.js — Authentication Form Handlers
// Mode operasi:
//   • http:// / https:// → wajib pakai PHP + MySQL (tidak ada fallback)
//   • file://             → pakai localStorage (untuk buka langsung tanpa server)
(function () {
  'use strict';

  // Deteksi apakah berjalan di server (XAMPP) atau file lokal
  const IS_SERVER = (
    window.location.protocol === 'http:' ||
    window.location.protocol === 'https:'
  );

  // Path API relatif dari halaman yang sedang dibuka
  // Otomatis menyesuaikan subfolder (misal: /travelfix-main/api/)
  const API_BASE = (function () {
    const path = window.location.pathname; // mis: /travelfix-main/register.html
    const dir  = path.substring(0, path.lastIndexOf('/') + 1); // /travelfix-main/
    return dir + 'api';
  })();

  // ── Helper: POST JSON ke endpoint PHP ───────────────────────
  async function apiPost(endpoint, payload) {
    const url = `${API_BASE}/${endpoint}`;
    const res = await fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload)
    });

    if (!res.ok && res.status !== 409 && res.status !== 422 && res.status !== 401 && res.status !== 403 && res.status !== 429) {
      const text = await res.text();
      throw new Error(`HTTP ${res.status}: ${text.substring(0, 200)}`);
    }

    return res.json();
  }

  // ── Helper: simpan sesi di sessionStorage ────────────────────
  function saveSession(user) {
    sessionStorage.setItem('currentUser', JSON.stringify(user));
  }

  // ════════════════════════════════════════════════════════════
  // REGISTRATION FORM
  // ════════════════════════════════════════════════════════════
  const registerForm = document.getElementById('register-form');
  if (registerForm) {
    registerForm.addEventListener('submit', async function (e) {
      e.preventDefault();

      const nameInput    = document.getElementById('reg-name');
      const emailInput   = document.getElementById('reg-email');
      const passInput    = document.getElementById('reg-password');
      const confirmInput = document.getElementById('reg-confirm');
      const roleInput    = document.getElementById('reg-role');

      const name            = nameInput.value.trim();
      const email           = emailInput.value.trim();
      const password        = passInput.value;
      const confirmPassword = confirmInput.value;
      const role            = roleInput ? roleInput.value : 'freelancer';

      // Ambil field UMKM (jika ada)
      const businessNameInput     = document.getElementById('reg-business-name');
      const businessCategoryInput = document.getElementById('reg-business-category');
      const businessName          = businessNameInput     ? businessNameInput.value.trim()     : '';
      const businessCategory      = businessCategoryInput ? businessCategoryInput.value.trim() : '';

      // Reset error styles
      [nameInput, emailInput, passInput, confirmInput].forEach(el =>
        el.classList.remove('error')
      );

      // Validasi sisi klien
      if (!name) {
        alert('Nama Lengkap wajib diisi.');
        nameInput.classList.add('error');
        nameInput.focus();
        return;
      }
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('Format email tidak valid.');
        emailInput.classList.add('error');
        emailInput.focus();
        return;
      }
      if (password.length < 6) {
        alert('Password minimal 6 karakter.');
        passInput.classList.add('error');
        passInput.focus();
        return;
      }
      if (password !== confirmPassword) {
        alert('Password dan Konfirmasi Password tidak cocok!');
        passInput.classList.add('error');
        confirmInput.classList.add('error');
        confirmInput.focus();
        return;
      }

      // Validasi tambahan untuk role UMKM
      if (role === 'umkm') {
        if (!businessName) {
          alert('Nama Bisnis wajib diisi untuk akun UMKM.');
          if (businessNameInput) { businessNameInput.classList.add('error'); businessNameInput.focus(); }
          return;
        }
        if (!businessCategory) {
          alert('Kategori Bisnis wajib dipilih untuk akun UMKM.');
          if (businessCategoryInput) { businessCategoryInput.classList.add('error'); businessCategoryInput.focus(); }
          return;
        }
      }

      // Nonaktifkan tombol
      const btnSubmit = document.getElementById('btn-submit-reg');
      if (btnSubmit) {
        btnSubmit.disabled    = true;
        btnSubmit.textContent = 'Mendaftarkan...';
      }

      let result;

      try {
        if (IS_SERVER) {
          // ── Mode MySQL via PHP ──────────────────────────────
          console.log('[auth] Mengirim ke MySQL API:', API_BASE + '/register.php');
          result = await apiPost('register.php', { name, email, password, role, businessName, businessCategory });

        } else {
          // ── Mode file:// → pakai localStorage ──────────────
          console.warn('[auth] Berjalan sebagai file:// — menggunakan localStorage.');
          result = window.db
            ? window.db.registerUser(name, email, password, role)
            : { success: false, message: 'Database tidak tersedia.' };
        }

      } catch (err) {
        console.error('[auth] Gagal register:', err);
        result = {
          success: false,
          message: 'Gagal terhubung ke server. Pastikan XAMPP aktif dan buka via http://localhost/...\n\nDetail: ' + err.message
        };
      }

      // Kembalikan tombol
      if (btnSubmit) {
        btnSubmit.disabled    = false;
        btnSubmit.textContent = 'Daftar Sekarang';
      }

      if (result.success) {
        alert('Registrasi berhasil! Silakan login.');
        window.location.href = 'login.html';
      } else {
        alert(result.message || 'Registrasi gagal. Coba lagi.');
        emailInput.classList.add('error');
        emailInput.focus();
      }
    });
  }

  // ════════════════════════════════════════════════════════════
  // LOGIN FORM
  // ════════════════════════════════════════════════════════════
  const loginForm = document.getElementById('login-form');
  if (loginForm) {
    const emailInput = document.getElementById('email');
    const passInput  = document.getElementById('password');
    const eyeToggle  = document.getElementById('eye-toggle');

    // Toggle tampilkan/sembunyikan password
    if (eyeToggle) {
      eyeToggle.addEventListener('click', function () {
        const isPassword = passInput.type === 'password';
        passInput.type = isPassword ? 'text' : 'password';

        const eyeIcon    = document.getElementById('icon-eye');
        const eyeOffIcon = document.getElementById('icon-eye-off');
        if (eyeIcon)    eyeIcon.style.display    = isPassword ? 'none'  : 'block';
        if (eyeOffIcon) eyeOffIcon.style.display = isPassword ? 'block' : 'none';
      });
    }

    loginForm.addEventListener('submit', async function (e) {
      e.preventDefault();

      const email    = emailInput.value.trim();
      const password = passInput.value;

      emailInput.classList.remove('error');
      passInput.classList.remove('error');

      if (!email || !password) {
        alert('Email/Nama Bisnis dan password wajib diisi.');
        return;
      }

      const btnLogin = loginForm.querySelector('button[type="submit"]');
      if (btnLogin) {
        btnLogin.disabled    = true;
        btnLogin.textContent = 'Masuk...';
      }

      let result;

      try {
        if (IS_SERVER) {
          // ── Mode MySQL via PHP ──────────────────────────────
          console.log('[auth] Login via MySQL API:', API_BASE + '/login.php');
          result = await apiPost('login.php', { email, password });

          if (result.success && result.user) {
            // login.php mengembalikan baris mentah (snake_case: business_name,
            // business_category, dst.) -- tapi seluruh app (profile.html,
            // talent-ai.html, nav index.html, dst.) membaca bentuk camelCase
            // (businessName/businessCategory) seperti yang dihasilkan
            // db.js mapUserRow()/loadServerData(). Tanpa normalisasi ini,
            // currentUser.businessName/businessCategory selalu undefined
            // tepat setelah login (baru benar lagi setelah profil dibuka &
            // di-refresh, atau lewat rehydrateSessionFromServer() di tab baru).
            const normalizedUser = (window.db && typeof window.db.mapUserRow === 'function')
              ? window.db.mapUserRow(result.user)
              : result.user;
            saveSession(normalizedUser);
            result.role = normalizedUser.role;

            // Simpan id sesi PHP EKSPLISIT ke sessionStorage tab ini (lihat
            // js/db.js) -- inilah yang membuat tab ini punya identitas
            // sendiri, independen dari tab lain pada browser yang sama,
            // walau cookie PHPSESSID sebenarnya dibagi ke semua tab.
            if (result.session_id && typeof window.__bantulSaveSessionId === 'function') {
              window.__bantulSaveSessionId(result.session_id);
            }
          }

        } else {
          // ── Mode file:// → pakai localStorage ──────────────
          console.warn('[auth] Berjalan sebagai file:// — menggunakan localStorage.');
          result = window.db
            ? window.db.loginUser(email, password)
            : { success: false, message: 'Database tidak tersedia.' };
        }

      } catch (err) {
        console.error('[auth] Gagal login:', err);
        result = {
          success: false,
          message: 'Gagal terhubung ke server. Pastikan XAMPP aktif dan buka via http://localhost/...\n\nDetail: ' + err.message
        };
      }

      if (btnLogin) {
        btnLogin.disabled    = false;
        btnLogin.textContent = 'Masuk';
      }

      if (result.success) {
        const role = result.role || (result.user && result.user.role);
        if (role === 'admin') {
          window.location.href = 'admin-dashboard.html';
        } else if (role === 'umkm') {
          window.location.href = 'umkm-dashboard.html';
        } else {
          window.location.href = 'marketplace.html';
        }
      } else {
        emailInput.classList.add('error');
        passInput.classList.add('error');
        alert(result.message || 'Email atau password salah!');
        passInput.value = '';
        passInput.focus();
      }
    });
  }

})();
