<?php
/**
 * tests/e2e_freelancer_deep_test.php — Verifikasi mendalam sisi Freelancer:
 *  1. ai_talent_match.php & get_freelancer_applications.php WAJIB login DAN
 *     wajib pemilik data sendiri/admin (perbaikan IDOR 2026-07-05 --
 *     sebelumnya bisa diakses siapa saja tanpa login sama sekali dgn
 *     menebak freelancer_id, bocorkan rekomendasi proyek & riwayat lamaran).
 *  2. Reproduksi & verifikasi perbaikan bug "Hanya akun freelancer yang
 *     dapat melamar proyek" -- lihat tests/e2e_session_mismatch_test.php
 *     untuk skenario detailnya; di sini fokus jalur normal (bukan sesi
 *     ganda) tetap mulus dari precheck api/me.php sampai submit.
 *
 * Cara pakai: php.exe tests/e2e_freelancer_deep_test.php http://127.0.0.1:PORT/api
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

function loginAs($base, $email, $password) {
    $jar = tempnam(sys_get_temp_dir(), 'e2e_fl_deep_');
    [$code, $j] = http('POST', "$base/login.php", ['email' => $email, 'password' => $password], $jar);
    if ($code !== 200 || !($j['success'] ?? false)) {
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
$suffix = time();

$flEmail  = "e2e_fldeep_fl_{$suffix}@test.local";
$fl2Email = "e2e_fldeep_fl2_{$suffix}@test.local";
$umkmEmail = "e2e_fldeep_umkm_{$suffix}@test.local";
$password = 'testfldeep123';

http('POST', "$BASE/register.php", ['name' => 'Deep FL', 'email' => $flEmail, 'password' => $password, 'role' => 'freelancer']);
http('POST', "$BASE/register.php", ['name' => 'Deep FL 2', 'email' => $fl2Email, 'password' => $password, 'role' => 'freelancer']);
http('POST', "$BASE/register.php", ['name' => 'Deep UMKM', 'email' => $umkmEmail, 'password' => $password, 'role' => 'umkm', 'businessName' => 'Deep FL Biz', 'businessCategory' => 'Kriya']);

$jarFl   = loginAs($BASE, $flEmail, $password);
$jarFl2  = loginAs($BASE, $fl2Email, $password);
$jarUmkm = loginAs($BASE, $umkmEmail, $password);
$jarAdmin = loginAs($BASE, 'admin123@gmail.com', 'admin123');

[, $j] = http('GET', "$BASE/me.php", null, $jarFl);   $flId   = $j['data']['id'];
[, $j] = http('GET', "$BASE/me.php", null, $jarFl2);  $fl2Id  = $j['data']['id'];
[, $j] = http('GET', "$BASE/me.php", null, $jarUmkm); $umkmId = $j['data']['id'];

http('PUT', "$BASE/users.php?id=$flId", ['location' => 'Bantul, DIY', 'latitude' => -7.9, 'longitude' => 110.3, 'skills' => ['Fotografi'], 'interests' => ['Kuliner']], $jarFl);
http('PUT', "$BASE/users.php?id=$umkmId", ['location' => 'Bantul, DIY'], $jarUmkm);

[, $j] = http('POST', "$BASE/projects.php?action=create", [
    'title' => '[E2E-FLDEEP] Proyek', 'description' => 'x', 'categories' => ['Fotografi'],
    'location' => 'Bantul', 'prize' => 150000, 'deadline' => '2026-12-31', 'requirements' => 'x',
], $jarUmkm);
$projId = (int) ($j['id'] ?? 0);
check('setup: proyek dibuat', $projId > 0, json_encode($j));

echo "=== 1. ai_talent_match.php: ditolak tanpa login ===\n";
[$c, $j] = http('GET', "$BASE/ai_talent_match.php?freelancer_id=$flId");
check('tanpa login -> 401', $c === 401, json_encode($j));

echo "=== 2. ai_talent_match.php: ditolak untuk freelancer LAIN (bukan diri sendiri) ===\n";
[$c, $j] = http('GET', "$BASE/ai_talent_match.php?freelancer_id=$flId", null, $jarFl2);
check('freelancer lain -> 403 (bukan pemilik data)', $c === 403, json_encode($j));

echo "=== 3. ai_talent_match.php: pemilik data sendiri tetap bisa akses ===\n";
[$c, $j] = http('GET', "$BASE/ai_talent_match.php?freelancer_id=$flId", null, $jarFl);
check('milik sendiri -> 200 sukses', $c === 200 && ($j['status'] ?? '') === 'success', json_encode($j));

echo "=== 4. ai_talent_match.php: admin tetap bisa akses data siapa pun (untuk keperluan support) ===\n";
[$c, $j] = http('GET', "$BASE/ai_talent_match.php?freelancer_id=$flId", null, $jarAdmin);
check('admin -> 200 sukses', $c === 200 && ($j['status'] ?? '') === 'success', json_encode($j));

echo "=== 5. get_freelancer_applications.php: ditolak tanpa login ===\n";
[$c, $j] = http('GET', "$BASE/get_freelancer_applications.php?freelancer_id=$flId");
check('tanpa login -> 401', $c === 401, json_encode($j));

echo "=== 6. get_freelancer_applications.php: ditolak untuk freelancer lain ===\n";
[$c, $j] = http('GET', "$BASE/get_freelancer_applications.php?freelancer_id=$flId", null, $jarFl2);
check('freelancer lain -> 403', $c === 403, json_encode($j));

echo "=== 7. get_freelancer_applications.php: pemilik data sendiri tetap bisa akses ===\n";
[$c, $j] = http('GET', "$BASE/get_freelancer_applications.php?freelancer_id=$flId", null, $jarFl);
check('milik sendiri -> 200 sukses', $c === 200 && ($j['status'] ?? '') === 'success', json_encode($j));

echo "=== 8. Alur normal (satu sesi konsisten): apply_project.php via precheck me.php ===\n";
[$c, $j] = http('GET', "$BASE/me.php", null, $jarFl);
check('me.php: identitas benar sebelum melamar', ($j['data']['role'] ?? '') === 'freelancer' && (int)($j['data']['id'] ?? 0) === $flId);
check('me.php: skills/interests/location lengkap (precheck applyProject() akan lolos)',
    !empty($j['data']['skills']) && !empty($j['data']['interests']) && !empty($j['data']['location'])
    && $j['data']['latitude'] !== null && $j['data']['longitude'] !== null, json_encode($j['data']));

[$c, $j] = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $jarFl);
check('lamaran berhasil dalam sesi yang konsisten', $c === 201 && ($j['status'] ?? '') === 'success', json_encode($j));

echo "=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM project_applicants WHERE project_id = $projId");
$pdo->exec("DELETE FROM project_categories WHERE project_id = $projId");
$pdo->exec("DELETE FROM projects WHERE id = $projId");
$pdo->exec("DELETE FROM user_skills WHERE user_id = $flId");
$pdo->exec("DELETE FROM user_interests WHERE user_id = $flId");
$pdo->exec("DELETE FROM users WHERE id IN ($flId, $fl2Id, $umkmId)");
@unlink($jarFl); @unlink($jarFl2); @unlink($jarUmkm); @unlink($jarAdmin);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
