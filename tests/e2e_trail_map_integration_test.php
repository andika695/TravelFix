<?php
/**
 * tests/e2e_trail_map_integration_test.php — Verifikasi integrasi Map Picker
 * (form Buat Proyek UMKM) <-> Creative Trail Map end-to-end:
 *   1. Buat proyek dengan latitude/longitude (mensimulasikan hidden input
 *      #lat-input/#lng-input terisi dari marker Map Picker)
 *   2. Proyek itu HARUS muncul di api/get_projects_map.php dengan field
 *      id_proyek, judul_proyek, nama_umkm, kategori, latitude, longitude,
 *      lokasi_teks
 *   3. Proyek TANPA koordinat TIDAK BOLEH muncul di get_projects_map.php
 *   4. Proyek yang sudah Completed TIDAK BOLEH muncul (bukan "aktif/berjalan")
 *   5. api/projects.php?id=N (dipakai openProjectFromUrlParam di marketplace)
 *      HARUS mengembalikan latitude/longitude yang sama
 *   6. Koordinat di luar wilayah Bantul HARUS ditolak (jadi NULL)
 *
 * Sejak action=create di projects.php mewajibkan session UMKM/admin (bukan
 * lagi created_by yang dipercaya dari body), skrip ini login sungguhan
 * sebagai UMKM uji lewat api/login.php dan memakai cookie jar (curl).
 *
 * Cara pakai: php.exe tests/e2e_trail_map_integration_test.php http://127.0.0.1:PORT/api
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: skrip ini hanya boleh dijalankan lewat CLI.');
}

$BASE = $argv[1] ?? 'http://127.0.0.1/travelfix-main/api';
$pass = 0; $fail = 0;

/** Request dengan dukungan cookie jar (session PHP) via curl. $cookieJar boleh null (tanpa session). */
function http($method, $url, $body = null, $cookieJar = null) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($cookieJar !== null) {
        $opts[CURLOPT_COOKIEJAR]  = $cookieJar;
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) $raw, true);
}

function loginAs($base, $email, $password) {
    $jar = tempnam(sys_get_temp_dir(), 'e2e_trailmap_');
    $j = http('POST', "$base/login.php", ['email' => $email, 'password' => $password], $jar);
    if (!($j['success'] ?? false)) {
        fwrite(STDERR, "Login gagal untuk $email: " . json_encode($j) . "\n");
        exit(1);
    }
    return $jar;
}

function check($label, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [OK]   $label\n"; }
    else       { $fail++; echo "  [FAIL] $label $extra\n"; }
}

echo "Base URL API: $BASE\n\n";

$pdo = new PDO('mysql:host=127.0.0.1;dbname=bantul_creative;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec("DELETE FROM projects WHERE title IN ('[TEST-MAP] Dengan Koordinat', '[TEST-MAP] Tanpa Koordinat', '[TEST-MAP] Sudah Selesai', '[TEST-MAP] Koordinat Nakal')");
$pdo->exec("DELETE FROM users WHERE email = 'test_trailmap_umkm@test.local'");

// UMKM uji sendiri (password diketahui) supaya bisa login sungguhan lewat api/login.php.
// location (Lokasi Bisnis di profil) WAJIB diisi -- api/projects.php sekarang
// menolak pembuatan proyek dari akun umkm yang belum melengkapi field ini.
$umkmPassword = 'testtrailmap123';
$pdo->exec("INSERT INTO users (name, email, password, role, account_status, business_name, location, created_at)
            VALUES ('Test Trail Map UMKM', 'test_trailmap_umkm@test.local', '" . password_hash($umkmPassword, PASSWORD_DEFAULT) . "', 'umkm', 'Active', 'Test Trail Map Business', 'Bantul, DIY', NOW())");
$umkmId = (int) $pdo->lastInsertId();
$jarUmkm = loginAs($BASE, 'test_trailmap_umkm@test.local', $umkmPassword);

// ── 1) Buat proyek DENGAN koordinat (mensimulasikan Map Picker) ─────────
echo "=== 1. Buat proyek dengan latitude/longitude (Kasongan, Bantul) ===\n";
$j = http('POST', "$BASE/projects.php", [
    'title' => '[TEST-MAP] Dengan Koordinat',
    'description' => 'uji integrasi peta', 'categories' => ['Kerajinan'],
    'location' => 'Kasongan, Kasihan, Bantul', 'prize' => 500000, 'deadline' => '2026-09-01',
    'requirements' => 'uji', 'icon' => 'palette',
    'latitude' => -7.8449198, 'longitude' => 110.3349590,
], $jarUmkm);
check('proyek dgn koordinat berhasil dibuat', ($j['success'] ?? false) === true, json_encode($j));
$projIdWithCoords = (int) ($j['id'] ?? 0);

// ── 2) Buat proyek TANPA koordinat ───────────────────────────────────────
echo "=== 2. Buat proyek TANPA koordinat ===\n";
$j = http('POST', "$BASE/projects.php", [
    'title' => '[TEST-MAP] Tanpa Koordinat',
    'description' => 'uji tanpa koordinat', 'categories' => ['Kerajinan'],
    'location' => 'Sewon, Bantul', 'prize' => 300000, 'deadline' => '2026-09-01',
    'requirements' => 'uji', 'icon' => 'palette',
], $jarUmkm);
check('proyek tanpa koordinat berhasil dibuat', ($j['success'] ?? false) === true, json_encode($j));
$projIdNoCoords = (int) ($j['id'] ?? 0);

// ── 3) Buat proyek dengan koordinat NAKAL (jauh di luar Bantul, mis. Jakarta) ──
echo "=== 3. Buat proyek dengan koordinat di luar Bantul (harus ditolak jadi NULL) ===\n";
$j = http('POST', "$BASE/projects.php", [
    'title' => '[TEST-MAP] Koordinat Nakal',
    'description' => 'uji koordinat luar area', 'categories' => ['Kerajinan'],
    'location' => 'Jakarta (nakal)', 'prize' => 300000, 'deadline' => '2026-09-01',
    'requirements' => 'uji', 'icon' => 'palette',
    'latitude' => -6.2, 'longitude' => 106.8, // Jakarta, jauh di luar Bantul
], $jarUmkm);
$projIdNaughty = (int) ($j['id'] ?? 0);
$row = $pdo->query("SELECT latitude, longitude FROM projects WHERE id=$projIdNaughty")->fetch(PDO::FETCH_ASSOC);
check('koordinat luar Bantul ditolak (NULL di DB)', $row['latitude'] === null && $row['longitude'] === null, json_encode($row));

// ── 4) Proyek Completed dengan koordinat (tidak boleh muncul di peta) ───
echo "=== 4. Proyek Completed dengan koordinat (harus TIDAK muncul di peta) ===\n";
$pdo->exec("INSERT INTO projects (created_by, title, description, budget, project_status, latitude, longitude, created_at, updated_at)
            VALUES ($umkmId, '[TEST-MAP] Sudah Selesai', 'uji', 400000, 'Completed', -7.85, 110.35, NOW(), NOW())");
$projIdCompleted = (int) $pdo->lastInsertId();
check('data uji Completed tersimpan', $projIdCompleted > 0);

// ── 5) Cek get_projects_map.php (GET publik, tanpa session) ─────────────
echo "=== 5. api/get_projects_map.php ===\n";
$j = http('GET', "$BASE/get_projects_map.php");
check('status success', ($j['status'] ?? '') === 'success', json_encode($j));
$data = $j['data'] ?? [];
$ids = array_column($data, 'id_proyek');

check('proyek DENGAN koordinat MUNCUL di peta', in_array($projIdWithCoords, $ids, true));
check('proyek TANPA koordinat TIDAK muncul di peta', !in_array($projIdNoCoords, $ids, true));
check('proyek koordinat nakal (NULL) TIDAK muncul di peta', !in_array($projIdNaughty, $ids, true));
check('proyek Completed TIDAK muncul di peta (bukan aktif/berjalan)', !in_array($projIdCompleted, $ids, true));

$found = null;
foreach ($data as $row) { if ($row['id_proyek'] === $projIdWithCoords) { $found = $row; break; } }
check('field lengkap: id_proyek, judul_proyek, nama_umkm, kategori, latitude, longitude, lokasi_teks',
    $found !== null
    && array_key_exists('judul_proyek', $found) && $found['judul_proyek'] === '[TEST-MAP] Dengan Koordinat'
    && array_key_exists('nama_umkm', $found)
    && array_key_exists('kategori', $found) && $found['kategori'] === 'Kerajinan'
    && array_key_exists('latitude', $found) && abs($found['latitude'] - (-7.8449198)) < 0.0001
    && array_key_exists('longitude', $found) && abs($found['longitude'] - 110.3349590) < 0.0001
    && array_key_exists('lokasi_teks', $found) && $found['lokasi_teks'] === 'Kasongan, Kasihan, Bantul',
    json_encode($found));

// ── 6) Cek api/projects.php?id=N ikut mengembalikan latitude/longitude (GET publik) ──
echo "=== 6. api/projects.php?id=$projIdWithCoords (dipakai tautan 'Lihat Detail Proyek') ===\n";
$j = http('GET', "$BASE/projects.php?id=$projIdWithCoords");
check('success', ($j['success'] ?? false) === true, json_encode($j));
$p = $j['project'] ?? [];
check('latitude/longitude ikut dikembalikan', isset($p['latitude']) && isset($p['longitude']) && abs((float)$p['latitude'] - (-7.8449198)) < 0.0001, json_encode($p['latitude'] ?? null));

// ── Bersih-bersih ─────────────────────────────────────────────────────
echo "=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM project_categories WHERE project_id IN ($projIdWithCoords, $projIdNoCoords, $projIdNaughty, $projIdCompleted)");
$pdo->exec("DELETE FROM projects WHERE id IN ($projIdWithCoords, $projIdNoCoords, $projIdNaughty, $projIdCompleted)");
$pdo->exec("DELETE FROM users WHERE id = $umkmId");
@unlink($jarUmkm);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
