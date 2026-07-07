<?php
/**
 * tests/e2e_per_tab_isolation_test.php — Membuktikan perbaikan ISOLASI SESI
 * PER TAB (2026-07-05): membuka "tab" lain dengan role berbeda pada
 * BROWSER YANG SAMA (satu cookie jar) TIDAK LAGI membuat tab pertama ikut
 * "berpindah akun" -- selama masing-masing "tab" mengirim header
 * X-Session-Id miliknya sendiri (persis seperti js/db.js sekarang mem-
 * patch window.fetch() untuk melakukannya otomatis di browser sungguhan).
 *
 * Skenario: SATU cookie jar (= satu browser) dipakai login sebagai
 * freelancer ("tab 1") DAN sebagai UMKM ("tab 2") -- kalau tanpa perbaikan
 * ini, cookie PHPSESSID browser tsb akan "berpindah" ke UMKM stlh login
 * ke-2, dan tab 1 kehilangan identitasnya (lihat e2e_session_mismatch_test.php
 * utk bukti masalah lamanya). Dengan perbaikan ini, masing-masing "tab"
 * punya X-Session-Id sendiri dari respons login.php, dan tab 1 tetap bisa
 * beraksi sebagai freelancer walau "tab 2" (browser/cookie jar yang sama)
 * sudah login sebagai UMKM setelahnya.
 *
 * Cara pakai: php.exe tests/e2e_per_tab_isolation_test.php http://127.0.0.1:PORT/api
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: skrip ini hanya boleh dijalankan lewat CLI.');
}

$BASE = $argv[1] ?? 'http://127.0.0.1/travelfix-main/api';
$pass = 0; $fail = 0;

// $sessionId opsional: kalau diisi, dikirim sbg header X-Session-Id --
// inilah yang mensimulasikan "tab" mengirim id sesinya sendiri secara
// eksplisit (persis seperti window.fetch yang di-patch js/db.js).
function http($method, $url, $body = null, $cookieJar = null, $sessionId = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($sessionId) $headers[] = 'X-Session-Id: ' . $sessionId;
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
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
$flEmail = "e2e_isolasi_fl_{$suffix}@test.local";
$umkmEmail = "e2e_isolasi_umkm_{$suffix}@test.local";
$password = 'testisolasi123';

http('POST', "$BASE/register.php", ['name' => 'Isolasi FL', 'email' => $flEmail, 'password' => $password, 'role' => 'freelancer']);
http('POST', "$BASE/register.php", ['name' => 'Isolasi UMKM', 'email' => $umkmEmail, 'password' => $password, 'role' => 'umkm', 'businessName' => 'Isolasi Biz', 'businessCategory' => 'Kriya']);

// SATU cookie jar = SATU browser, dipakai kedua "tab".
$browserJar = tempnam(sys_get_temp_dir(), 'e2e_isolasi_browser_');

echo "=== 1. \"Tab 1\": login sebagai freelancer, simpan session_id-nya ===\n";
[$c, $j] = http('POST', "$BASE/login.php", ['email' => $flEmail, 'password' => $password], $browserJar);
check('login freelancer sukses', $c === 200 && ($j['success'] ?? false) === true);
check('response memuat session_id', !empty($j['session_id']), json_encode(array_keys($j)));
$tab1SessionId = $j['session_id'];
$flId = $j['user']['id'];

echo "\n=== 2. \"Tab 2\" (browser SAMA, cookie jar SAMA): login sebagai UMKM, simpan session_id-nya SENDIRI ===\n";
[$c, $j] = http('POST', "$BASE/login.php", ['email' => $umkmEmail, 'password' => $password], $browserJar);
check('login umkm sukses -- cookie PHPSESSID browser ini sekarang "berpindah" ke UMKM di level cookie', $c === 200 && ($j['success'] ?? false) === true);
$tab2SessionId = $j['session_id'];
$umkmId = $j['user']['id'];
check('session_id tab 2 BERBEDA dari tab 1 (regenerasi per login)', $tab2SessionId !== $tab1SessionId);

echo "\n=== 3. TANPA perbaikan (andalkan cookie jar biasa, spt js/app.js versi lama): \"tab 1\" akan salah jadi UMKM ===\n";
[$c, $j] = http('GET', "$BASE/me.php", null, $browserJar); // TANPA X-Session-Id -- pola LAMA
check('(demonstrasi bug lama) tanpa header eksplisit, cookie jar mengarah ke UMKM -- BUKAN freelancer', ($j['data']['role'] ?? '') === 'umkm', json_encode($j['data'] ?? null));

echo "\n=== 4. DENGAN perbaikan: \"tab 1\" kirim X-Session-Id miliknya sendiri -- HARUS tetap freelancer ===\n";
[$c, $j] = http('GET', "$BASE/me.php", null, $browserJar, $tab1SessionId);
check('tab 1 (pakai X-Session-Id sendiri) tetap terbaca freelancer', $c === 200 && ($j['data']['role'] ?? '') === 'freelancer' && (int)($j['data']['id'] ?? 0) === $flId, json_encode($j['data'] ?? null));

echo "\n=== 5. \"Tab 2\" kirim X-Session-Id miliknya sendiri -- HARUS tetap umkm ===\n";
[$c, $j] = http('GET', "$BASE/me.php", null, $browserJar, $tab2SessionId);
check('tab 2 (pakai X-Session-Id sendiri) tetap terbaca umkm', $c === 200 && ($j['data']['role'] ?? '') === 'umkm' && (int)($j['data']['id'] ?? 0) === $umkmId, json_encode($j['data'] ?? null));

echo "\n=== 6. Aksi nyata: \"tab 1\" (freelancer) bikin proyek UMKM dulu, lalu apply_project.php pakai X-Session-Id tab 1 -- HARUS berhasil sbg freelancer walau cookie jar 'milik' UMKM ===\n";
http('PUT', "$BASE/users.php?id=$flId", ['location' => 'Bantul, DIY', 'latitude' => -7.9, 'longitude' => 110.3, 'skills' => ['Fotografi'], 'interests' => ['Kuliner']], $browserJar, $tab1SessionId);
http('PUT', "$BASE/users.php?id=$umkmId", ['location' => 'Bantul, DIY'], $browserJar, $tab2SessionId);
[$c, $j] = http('POST', "$BASE/projects.php?action=create", [
    'title' => '[E2E-ISOLASI] Proyek', 'description' => 'x', 'categories' => ['Fotografi'],
    'location' => 'Bantul', 'prize' => 100000, 'deadline' => '2026-12-31', 'requirements' => 'x',
], $browserJar, $tab2SessionId);
$projId = (int) ($j['id'] ?? 0);
check('"tab 2" (umkm, X-Session-Id sendiri) berhasil buat proyek', $projId > 0, json_encode($j));

[$c, $j] = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $browserJar, $tab1SessionId);
check('"tab 1" (freelancer, X-Session-Id sendiri) berhasil melamar -- TIDAK terpengaruh cookie jar yg dipakai bersama', $c === 201 && ($j['status'] ?? '') === 'success', json_encode($j));

echo "\n=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM project_applicants WHERE project_id = $projId");
$pdo->exec("DELETE FROM project_categories WHERE project_id = $projId");
$pdo->exec("DELETE FROM projects WHERE id = $projId");
$pdo->exec("DELETE FROM user_skills WHERE user_id = $flId");
$pdo->exec("DELETE FROM user_interests WHERE user_id = $flId");
$pdo->exec("DELETE FROM users WHERE id IN ($flId, $umkmId)");
@unlink($browserJar);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
