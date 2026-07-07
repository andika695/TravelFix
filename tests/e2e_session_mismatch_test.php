<?php
/**
 * tests/e2e_session_mismatch_test.php — Reproduksi bug yang dilaporkan:
 * klik "Lamar Sekarang" di marketplace.html menampilkan alert "Hanya akun
 * freelancer yang dapat melamar proyek" walau yang mengeklik adalah akun
 * freelancer.
 *
 * Akar masalah: sessionStorage.currentUser di-cache PER TAB, sedangkan
 * session PHP ($_SESSION, sumber kebenaran backend) DIBAGI ke SEMUA tab
 * dalam satu browser. Kalau browser yang sama dipakai login sebagai akun
 * lain di tab lain (mis. sedang menguji akun UMKM), tab freelancer yang
 * masih terbuka tetap menampilkan UI freelancer (dari sessionStorage basi)
 * padahal session PHP browser itu sudah berpindah ke akun lain.
 *
 * Skrip ini mensimulasikan itu: SATU cookie jar (= satu browser) login
 * sebagai freelancer, lalu login lagi sebagai UMKM (menimpa session PHP,
 * persis seperti login di tab lain pada browser yang sama) -- lalu
 * memverifikasi (1) apply_project.php tetap ditolak dgn benar (backend
 * sudah benar dari awal, session yang menang bukan body), DAN (2) api/me.php
 * mengungkap ketidakcocokan ini SEBELUM submit -- inilah mekanisme yang
 * dipakai perbaikan di js/app.js applyProject()/initCreateProjectModal()/
 * profile.html untuk mendeteksi & memberi pesan yang jelas alih-alih error
 * generik yang membingungkan.
 *
 * Cara pakai: php.exe tests/e2e_session_mismatch_test.php http://127.0.0.1:PORT/api
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: skrip ini hanya boleh dijalankan lewat CLI.');
}

$BASE = $argv[1] ?? 'http://127.0.0.1/travelfix-main/api';
$pass = 0; $fail = 0;

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
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true)];
}

function check($label, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [OK]   $label\n"; }
    else       { $fail++; echo "  [FAIL] $label $extra\n"; }
}

echo "Base URL API: $BASE\n\n";

$pdo = new PDO('mysql:host=127.0.0.1;dbname=bantul_creative;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$suffix = time();
$flEmail   = "e2e_mismatch_fl_{$suffix}@test.local";
$umkmEmail = "e2e_mismatch_umkm_{$suffix}@test.local";
$password  = 'testmismatch123';

// ── Setup: satu freelancer (profil LENGKAP: skill/minat/lokasi), satu UMKM,
//    satu proyek Open siap dilamar ───────────────────────────────────────
$setupJar = tempnam(sys_get_temp_dir(), 'e2e_mismatch_setup_');
http('POST', "$BASE/register.php", ['name' => 'Mismatch FL', 'email' => $flEmail, 'password' => $password, 'role' => 'freelancer']);
http('POST', "$BASE/register.php", ['name' => 'Mismatch UMKM', 'email' => $umkmEmail, 'password' => $password, 'role' => 'umkm', 'businessName' => 'Mismatch Biz', 'businessCategory' => 'Kriya']);

[, $j] = http('POST', "$BASE/login.php", ['email' => $flEmail, 'password' => $password], $setupJar);
$flId = $j['user']['id'];
http('PUT', "$BASE/users.php?id=$flId", ['location' => 'Bantul, DIY', 'latitude' => -7.9, 'longitude' => 110.3, 'skills' => ['Fotografi'], 'interests' => ['Kuliner']], $setupJar);

[, $j] = http('POST', "$BASE/login.php", ['email' => $umkmEmail, 'password' => $password], $setupJar);
$umkmId = $j['user']['id'];
http('PUT', "$BASE/users.php?id=$umkmId", ['location' => 'Bantul, DIY'], $setupJar);
[, $j] = http('POST', "$BASE/projects.php?action=create", [
    'title' => '[E2E-MISMATCH] Proyek', 'description' => 'x', 'categories' => ['Fotografi'],
    'location' => 'Bantul', 'prize' => 100000, 'deadline' => '2026-12-31', 'requirements' => 'x',
], $setupJar);
$projId = (int) ($j['id'] ?? 0);
check('setup: proyek dibuat', $projId > 0, json_encode($j));
@unlink($setupJar);

// ── Simulasi bug: SATU browser (satu cookie jar) ────────────────────────
echo "\n=== Simulasi: satu browser, login freelancer lalu login UMKM di 'tab lain' ===\n";
$browserJar = tempnam(sys_get_temp_dir(), 'e2e_mismatch_browser_');

// "Tab freelancer" login duluan
[$c, $j] = http('POST', "$BASE/login.php", ['email' => $flEmail, 'password' => $password], $browserJar);
check('login sebagai freelancer di "tab 1" sukses', $c === 200 && ($j['success'] ?? false) === true);

// "Tab lain" (browser SAMA, cookie jar sama) login sebagai UMKM -- ini yang
// menimpa session PHP milik browser tsb, PERSIS seperti membuka tab baru
// dan login akun berbeda.
[$c, $j] = http('POST', "$BASE/login.php", ['email' => $umkmEmail, 'password' => $password], $browserJar);
check('login sebagai UMKM di "tab 2" (browser sama) sukses -- session PHP browser ini sekarang UMKM', $c === 200 && ($j['success'] ?? false) === true);

// Balik ke "tab freelancer" (sessionStorage tab ini MASIH mengira dia freelancer
// $flId, tapi cookie session PHP browser ini sekarang milik UMKM) -- klik
// "Lamar Sekarang" dengan payload freelancer_id lama seperti dikirim frontend.
echo "\n=== 1. apply_project.php: backend HARUS tetap benar (pakai session, bukan body) ===\n";
[$c, $j] = http('POST', "$BASE/apply_project.php", ['project_id' => $projId, 'freelancer_id' => $flId], $browserJar);
check('ditolak dgn pesan role, BUKAN diam-diam melamar atas nama freelancer_id lama', $c === 403 && strpos($j['message'] ?? '', 'freelancer') !== false, json_encode($j));
check('TIDAK ada lamaran nyasar tersimpan atas nama freelancer', (int) $pdo->query("SELECT COUNT(*) FROM project_applicants WHERE project_id=$projId AND freelancer_id=$flId")->fetchColumn() === 0);

echo "\n=== 2. api/me.php (dipakai precheck baru applyProject()): mengungkap ketidakcocokan SEBELUM submit ===\n";
[$c, $j] = http('GET', "$BASE/me.php", null, $browserJar);
check('me.php membalas sukses', $c === 200 && ($j['status'] ?? '') === 'success');
check('me.php mengungkap sesi SEBENARNYA adalah umkm (bukan freelancer seperti dikira sessionStorage tab)', ($j['data']['role'] ?? '') === 'umkm', json_encode($j['data'] ?? null));
check('id sesi = id UMKM (bukan id freelancer yang dikira sessionStorage tab)', (int) ($j['data']['id'] ?? 0) === (int) $umkmId);

echo "\n=== 3. Setelah 'tab freelancer' login ULANG (skenario normal, bukan multi-tab), semua konsisten lagi ===\n";
[$c, $j] = http('POST', "$BASE/login.php", ['email' => $flEmail, 'password' => $password], $browserJar);
check('login ulang sebagai freelancer sukses', $c === 200);
[$c, $j] = http('GET', "$BASE/me.php", null, $browserJar);
check('me.php sekarang konsisten kembali (role=freelancer, id sesuai)', ($j['data']['role'] ?? '') === 'freelancer' && (int) ($j['data']['id'] ?? 0) === (int) $flId);
[$c, $j] = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $browserJar);
check('lamaran sekarang berhasil (sesi sudah konsisten)', $c === 201 && ($j['status'] ?? '') === 'success', json_encode($j));

@unlink($browserJar);

echo "\n=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM project_applicants WHERE project_id = $projId");
$pdo->exec("DELETE FROM project_categories WHERE project_id = $projId");
$pdo->exec("DELETE FROM projects WHERE id = $projId");
$pdo->exec("DELETE FROM user_skills WHERE user_id = $flId");
$pdo->exec("DELETE FROM user_interests WHERE user_id = $flId");
$pdo->exec("DELETE FROM users WHERE id IN ($flId, $umkmId)");
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
