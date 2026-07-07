# 🌿 Bantul Creative — Village Freelancer Trail

> **Prototipe Platform Digital** yang menghubungkan talenta kreatif lokal dengan UMKM warisan budaya dan desa wisata di Kabupaten Bantul, Daerah Istimewa Yogyakarta.

---

## 📋 Deskripsi Proyek

**Bantul Creative — Village Freelancer Trail** adalah sebuah prototipe platform digital berbasis web yang dirancang dan dikembangkan sebagai solusi terhadap kesenjangan ekosistem antara **freelancer kreatif lokal** dengan **pelaku Usaha Mikro, Kecil, dan Menengah (UMKM) berbasis warisan budaya** serta **desa-desa wisata** di Kabupaten Bantul.

Platform ini memvisualisasikan sebuah *marketplace* freelance yang kontekstual secara geografis dan kultural — di mana UMKM pengrajin gerabah Kasongan, perajin batik tulis Imogiri, sentra kulit Manding, dan komunitas seni desa Dlingo dapat memposting kebutuhan proyek kreatif mereka; sementara para freelancer lokal dapat menemukan, melamar, dan mengambil proyek-proyek tersebut berdasarkan keahlian dan kecocokan profil mereka.

Dalam konteks **portofolio akademik Teknik Informatika**, proyek ini mendemonstrasikan kemampuan implementasi:

- Arsitektur aplikasi web multi-halaman (*Multi-Page Application* / MPA) yang terstruktur dan modular
- Simulasi sistem autentikasi berbasis peran (*Role-Based Access Control* / RBAC) tanpa ketergantungan server
- Pengelolaan *state* dan *session* pengguna menggunakan mekanisme *Data Access Layer* di atas `localStorage`
- Teknik interaktivitas DOM modern menggunakan *Event Delegation* untuk efisiensi dan keandalan lintas halaman
- Desain antarmuka yang responsif, estetis, dan aksesibel mengikuti prinsip-prinsip *Human-Computer Interaction* (HCI)

---

## 🏗️ Arsitektur & Teknologi

### Frontend Stack

| Lapisan | Teknologi | Keterangan |
|---|---|---|
| **Struktur** | HTML5 Semantik | Menggunakan elemen semantik (`<article>`, `<section>`, `<nav>`) untuk aksesibilitas dan SEO |
| **Presentasi** | CSS3 Murni | Custom Properties (CSS Variables), CSS Grid, Flexbox, Transisi & Animasi CSS |
| **Perilaku** | Vanilla JavaScript ES6+ | IIFE, Arrow Functions, Template Literals, Destructuring, `Array.prototype` |
| **Persistensi Data** | Browser `localStorage` | Dibungkus dalam *Data Access Layer* di `js/db.js` untuk simulasi operasi CRUD |
| **Ikon** | SVG Inline | Seluruh ikon dirender secara dinamis dari pustaka SVG bawaan di `js/app.js` |

### Keputusan Arsitektur Kunci

Proyek ini **secara sengaja tidak menggunakan framework atau library eksternal** (seperti React, Vue, Bootstrap, atau jQuery). Keputusan ini diambil untuk mendemonstrasi pemahaman fundamental terhadap mekanisme platform web secara menyeluruh (*low-level web platform APIs*), yang merupakan kompetensi inti dalam disiplin Teknik Informatika.

Simulasi backend dilakukan sepenuhnya di sisi klien melalui `localStorage`, yang berperan sebagai *persistent store* untuk data sesi pengguna dan registrasi akun baru.

---

## 📁 Struktur Folder

```
Travelfix/                          ← Root direktori proyek
│
├── index.html                      ← Halaman Landing Page publik (pintu masuk utama)
├── login.html                      ← Halaman autentikasi pengguna (form login + toggle password)
├── register.html                   ← Halaman registrasi akun freelancer baru
├── marketplace.html                ← Halaman utama daftar proyek kreatif (post-login, role: freelancer)
├── ai-match.html                   ← Halaman rekomendasi proyek berbasis persentase kecocokan AI
├── trail-map.html                  ← Halaman peta interaktif lokasi-lokasi UMKM & desa wisata
├── portfolio.html                  ← Halaman showcase dampak/impact proyek yang telah selesai
├── admin-dashboard.html            ← Halaman dasbor administrasi (post-login, role: admin)
│
├── css/
│   ├── style.css                   ← Stylesheet global: Design tokens, CSS variables, layout dasar
│   ├── landing.css                 ← Gaya khusus untuk Landing Page (index.html)
│   ├── login.css                   ← Gaya khusus untuk halaman Login & Register
│   ├── components.css              ← Komponen UI yang dapat digunakan ulang: kartu, modal, badge, pil
│   └── admin.css                   ← Gaya khusus untuk Admin Dashboard
│
└── js/
    ├── db.js                       ← Data Access Layer: Simulasi database CRUD di atas localStorage
    ├── auth.js                     ← Handler form registrasi & login dengan validasi client-side
    ├── login.js                    ← Logika autentikasi hardcoded (RBAC) untuk halaman login.html
    ├── data.js                     ← Sumber data statis: dataset proyek marketplace, AI match, trail & portofolio
    ├── app.js                      ← Controller utama: rendering DOM, event delegation, manajemen modal
    ├── profile-modal.js            ← Modul khusus untuk toggle & aksi dropdown profil pengguna
    └── skill-modal.js              ← Berkas legacy (no-op); logika skill modal telah dipindahkan ke app.js
```

### Penjelasan Peran Setiap File Inti

#### `index.html` — Landing Page
Halaman publik yang berfungsi sebagai *entry point* utama. Menampilkan proposisi nilai platform, fitur unggulan, dan *call-to-action* menuju halaman login dan registrasi. Tidak memerlukan autentikasi untuk diakses.

#### `login.html` & `register.html` — Autentikasi
Halaman formulir autentikasi. `login.html` memuat `js/db.js` dan `js/auth.js` (atau `js/login.js`) untuk memvalidasi kredensial dan mengarahkan pengguna ke halaman yang sesuai berdasarkan perannya. `register.html` menyediakan formulir pendaftaran akun freelancer baru yang datanya langsung disimpan ke `localStorage`.

#### `marketplace.html` — Pasar Proyek
Halaman inti pasca-login untuk peran *freelancer*. Menampilkan daftar proyek kreatif dari UMKM dan desa wisata dalam format kartu dinamis yang di-render oleh `js/app.js`. Dilengkapi filter pencarian berdasarkan kata kunci, status proyek (*Open/In Review/Closed*), dan kategori keahlian.

#### `ai-match.html` — Rekomendasi Proyek
Menampilkan daftar proyek yang diurutkan dan diberi label persentase *kecocokan* (match score) berdasarkan profil keahlian pengguna yang tersimpan. Persentase tertinggi (≥85%) ditampilkan dengan badge hijau (*high match*), 75–84% dengan badge kuning (*mid match*), dan di bawah 75% dengan badge merah (*low match*).

#### `trail-map.html` — Peta Wisata Kreatif
Menampilkan peta visual interaktif lokasi-lokasi UMKM dan desa wisata di Bantul. Setiap pin pada peta dapat diklik untuk menyoroti (*highlight*) kartu lokasi yang bersangkutan di panel bawah dengan efek *smooth scroll*.

#### `portfolio.html` — Dampak & Portofolio
Menampilkan showcase proyek-proyek yang telah berhasil diselesaikan, lengkap dengan metrik dampak nyata (peningkatan engagement, pendapatan, jumlah unduhan), nama freelancer, dan rating. Berfungsi sebagai *social proof* dan bukti nilai platform.

#### `admin-dashboard.html` — Dasbor Administrasi
Halaman eksklusif yang hanya dapat diakses oleh pengguna dengan peran `admin`. Menyediakan antarmuka untuk pemantauan statistik platform secara keseluruhan.

---

## ⚙️ Penjelasan Modul & Cara Kerja Kode

### 1. Autentikasi & RBAC (Role-Based Access Control)

Sistem autentikasi diimplementasikan melalui dua lapisan yang bekerja secara bersama:

**Lapisan 1 — `js/db.js` (Data Access Layer):**
`db.js` dibungkus dalam sebuah *Immediately Invoked Function Expression* (IIFE) dengan referensi ke objek `window` sebagai parameter, sehingga API-nya terekspos secara global melalui `window.db`. Saat halaman pertama kali dimuat, fungsi `initDB()` dipanggil secara otomatis untuk memeriksa keberadaan data di `localStorage`. Jika belum ada, ia melakukan *seeding* dua akun bawaan:

```javascript
// Dieksekusi otomatis saat skrip dimuat (auto-init)
users = [
  { name: "User Reguler",  email: "user123@gmail.com",  password: "user123",  role: "freelancer" },
  { name: "Admin System",  email: "admin123@gmail.com", password: "admin123", role: "admin" }
];
localStorage.setItem('bantul_users', JSON.stringify(users));
```

Fungsi `loginUser(email, password)` kemudian membandingkan kredensial yang dimasukkan dengan seluruh data pengguna yang tersimpan. Jika cocok, objek pengguna yang berhasil diautentikasi disimpan ke `localStorage` dengan kunci `currentUser` untuk manajemen sesi berikutnya.

**Lapisan 2 — `js/login.js` (Routing Berbasis Peran):**
Setelah validasi berhasil, logika RBAC diimplementasikan dengan pemeriksaan properti `role` pada data pengguna. Routing dilakukan secara deterministic:

```javascript
// Routing berdasarkan peran (role)
if (email === ROLES.admin.email && password === ROLES.admin.pass) {
    window.location.href = 'admin-dashboard.html'; // → Dasbor Admin
} else {
    window.location.href = 'marketplace.html';     // → Marketplace Freelancer
}
```

| Kredensial | Peran | Halaman Tujuan |
|---|---|---|
| `user123@gmail.com` / `user123` | `freelancer` | `marketplace.html` |
| `admin123@gmail.com` / `admin123` | `admin` | `admin-dashboard.html` |

### 2. Manajemen Sesi & Profil Dinamis

Setelah autentikasi berhasil, objek pengguna aktif dipersistensikan di `localStorage['currentUser']`. Fungsi `updateProfileUI()` dalam `js/app.js` kemudian membaca data ini setiap kali halaman dimuat untuk menyinkronkan tampilan antarmuka secara dinamis.

```javascript
function updateProfileUI() {
  const user = JSON.parse(localStorage.getItem('currentUser'));
  // Memperbarui nama di navbar, dropdown, dan avatar dengan inisial
  const initials = user.name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
  document.getElementById('nav-user-avatar').textContent = initials;
}
document.addEventListener('DOMContentLoaded', updateProfileUI);
```

Mekanisme ini memastikan bahwa setiap halaman selalu menampilkan informasi pengguna yang sedang aktif secara konsisten tanpa perlu *round-trip* ke server.

Untuk pembaruan profil, **Edit Profile Modal** (Skill & Minat) dikelola melalui sistem *Custom Event* antar modul. Tombol "Perbarui Skill" di dalam `profile-modal.js` mendispatch event `open-skill-modal` ke `document`, yang kemudian ditangkap oleh listener di `initSkillModal()` dalam `app.js`:

```javascript
// Di profile-modal.js — mengirim sinyal
document.dispatchEvent(new CustomEvent('open-skill-modal'));

// Di app.js — menerima sinyal
document.addEventListener('open-skill-modal', openModal);
```

Pola *Custom Event Bus* ini memungkinkan komunikasi antar modul tanpa ketergantungan langsung (*loose coupling*), menjaga kode tetap bersih dan *maintainable*.

### 3. Interaktivitas Halaman: Event Delegation

Alih-alih memasang event listener pada setiap kartu secara individual (yang akan menghasilkan ratusan listener dan rentan terhadap kesalahan ketika kartu di-render ulang secara dinamis), seluruh logika klik kartu diimplementasikan menggunakan **Event Delegation** pada elemen `document.body`:

```javascript
// Satu listener tunggal menangani klik untuk SEMUA jenis kartu
document.body.addEventListener('click', (e) => {
  // Cegah aksi jika yang diklik adalah tombol atau tautan di dalam kartu
  if (e.target.closest('button') || e.target.closest('a')) return;

  // Deteksi jenis kartu berdasarkan kelas CSS
  const projectCard = e.target.closest('.card-marketplace, .card-aimatch');
  if (projectCard) { /* → Buka modal detail proyek */ return; }

  const trailCard = e.target.closest('.card-trail');
  if (trailCard) { /* → Buka modal detail lokasi */ return; }

  const portfolioCard = e.target.closest('.card-portfolio');
  if (portfolioCard) { /* → Buka modal detail portofolio */ return; }
});
```

Keunggulan pendekatan ini:
- **Efisiensi Memori:** Hanya satu event listener yang terdaftar di DOM, bukan N listener untuk N kartu.
- **Keandalan Lintas Halaman:** Tidak ada risiko *null reference error* karena kartu tidak ada di halaman tertentu.
- **Mendukung Konten Dinamis:** Kartu yang di-render ulang secara dinamis (misalnya setelah filter diterapkan) tetap dapat diklik tanpa perlu *re-binding* listener.

Untuk aksi **"Ambil Proyek"**, pola yang sama diterapkan:

```javascript
document.body.addEventListener('click', (e) => {
  const ambilBtn = e.target.closest('#btn-ambil-proyek');
  if (ambilBtn) {
    closeModal(backdrop);
    setTimeout(() => alert('Proyek berhasil diambil.'), 300);
  }
});
```

### 4. Sistem Notifikasi Toast Global

Meskipun implementasi saat ini menggunakan dialog `alert()` bawaan browser untuk notifikasi validasi sederhana, sistem validasi terpusat diterapkan di `js/auth.js` dengan mekanisme umpan balik visual (*visual feedback*) yang ketat pada elemen formulir.

Setiap kali validasi gagal (misalnya: kolom kosong, format email tidak mengandung karakter `@`, atau password tidak cocok), sistem secara otomatis:
1. Menambahkan kelas CSS `.error` pada elemen input yang bermasalah untuk menampilkan garis tepi merah.
2. Menghapus kelas `.error` tersebut segera saat pengguna mulai mengetik kembali pada input tersebut (*real-time error clearing*).

```javascript
// Validasi registrasi: password tidak cocok
if (password !== confirmPassword) {
  passInput.classList.add('error');    // → Sorot input merah
  confirmInput.classList.add('error'); // → Sorot input merah
  return; // → Hentikan proses submit
}

// Pembersihan error secara real-time saat pengguna mengetik
emailInput.addEventListener('input', () => clearError(emailInput));
passInput.addEventListener('input',  () => clearError(passInput));
```

Sistem notifikasi berbasis *toast* yang ter-inject langsung ke DOM (tanpa `alert()`) merupakan item yang siap dikembangkan dalam iterasi berikutnya dari proyek ini, dengan arsitektur yang sudah dipersiapkan melalui pemisahan modul yang jelas.

---

## 🔑 Akun Demo (Default Credentials)

> Gunakan kredensial di bawah ini untuk mengeksplorasi seluruh fitur platform.

| Role | Email | Password | Akses |
|---|---|---|---|
| 👤 **Freelancer** | `user123@gmail.com` | `user123` | Marketplace, AI Match, Trail Map, Portofolio |
| 🛡️ **Admin** | `admin123@gmail.com` | `admin123` | Admin Dashboard |

---

## 🚀 Panduan Menjalankan Proyek

Proyek ini merupakan aplikasi web statis murni (*pure static web app*) yang **tidak memerlukan proses instalasi, build, atau server backend**. Cukup ikuti salah satu dari dua metode berikut:

### Metode 1: VS Code dengan Ekstensi Live Server *(Direkomendasikan)*

Live Server memberikan pengalaman pengembangan terbaik dengan fitur *hot-reload* otomatis dan menghindari masalah *CORS policy* pada beberapa browser.

**Langkah-langkah:**

1. Pastikan **Visual Studio Code** sudah terpasang di komputer Anda.
2. Buka VS Code, lalu buka folder proyek:
   ```
   File → Open Folder → [Pilih folder root proyek ini]
   ```
3. Buka panel **Extensions** (`Ctrl + Shift + X`), cari **"Live Server"** oleh *Ritwick Dey*, lalu klik **Install**.
4. Setelah ekstensi terpasang, klik kanan pada file `index.html` di panel **Explorer**.
5. Pilih opsi **"Open with Live Server"**.
6. Browser Anda akan terbuka secara otomatis dan menampilkan halaman utama platform di alamat `http://127.0.0.1:5500/` (atau port yang tersedia).

### Metode 2: Buka Langsung di Browser

1. Buka **File Explorer** Windows dan navigasikan ke folder root proyek ini.
2. Klik dua kali pada file **`index.html`**.
3. File akan terbuka di browser default Anda.
4. Navigasikan ke **halaman Login** melalui tombol yang tersedia, lalu masukkan salah satu dari akun demo di atas.

> **Catatan:** Metode ini berfungsi penuh untuk seluruh fitur. Data yang Anda masukkan (registrasi, sesi) akan tersimpan di `localStorage` browser Anda dan akan tetap ada di antara sesi, kecuali jika Anda menghapus data browser secara manual.

---

## 📄 Lisensi & Konteks Akademik

Proyek ini dikembangkan sebagai **karya portofolio akademik** dalam rangka pemenuhan tugas mata kuliah pengembangan web di program studi **Teknik Informatika**. Seluruh data proyek, nama lokasi, dan metrik dampak yang ditampilkan adalah **data simulasi (dummy data)** yang dibuat semata-mata untuk keperluan demonstrasi fungsionalitas platform.

---

*Dikembangkan dengan ❤️ untuk mendukung ekosistem kreatif lokal Kabupaten Bantul, DI Yogyakarta.*
