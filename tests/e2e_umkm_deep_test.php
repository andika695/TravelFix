<?php
/**
 * tests/e2e_umkm_deep_test.php — Verifikasi mendalam sisi UMKM:
 *  1. get_applicants.php WAJIB login DAN wajib pemilik proyek/admin (perbaikan
 *     IDOR 2026-07-04 -- sebelumnya bisa diakses siapa saja tanpa login sama
 *     sekali dan bocorkan nama/email/bio/skill pelamar).
 *  2. get_user_profile.php / get_freelancer_history.php / freelancer_stats.php
 *     WAJIB login (perbaikan IDOR yang sama).
 *  3. review_submission.php decision "reject" (belum pernah diuji sebelumnya
 *     -- hanya accept & revise yang ada di e2e_review_link_test.php).
 *
 * Cara pakai: php.exe tests/e2e_umkm_deep_test.php http://127.0.0.1:PORT/api
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
    $jar = tempnam(sys_get_temp_dir(), 'e2e_umkm_deep_');
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

$flEmail    = "e2e_umkmdeep_fl_{$suffix}@test.local";
$umkmEmail  = "e2e_umkmdeep_umkm_{$suffix}@test.local";
$umkm2Email = "e2e_umkmdeep_umkm2_{$suffix}@test.local";
$password   = 'testdeep123';

http('POST', "$BASE/register.php", ['name' => 'Deep FL', 'email' => $flEmail, 'password' => $password, 'role' => 'freelancer']);
http('POST', "$BASE/register.php", ['name' => 'Deep UMKM', 'email' => $umkmEmail, 'password' => $password, 'role' => 'umkm', 'businessName' => 'Deep Biz', 'businessCategory' => 'Kriya']);
http('POST', "$BASE/register.php", ['name' => 'Deep UMKM 2', 'email' => $umkm2Email, 'password' => $password, 'role' => 'umkm', 'businessName' => 'Deep Biz 2', 'businessCategory' => 'Kuliner']);

$jarFl    = loginAs($BASE, $flEmail, $password);
$jarUmkm  = loginAs($BASE, $umkmEmail, $password);
$jarUmkm2 = loginAs($BASE, $umkm2Email, $password);

[$c, $j] = http('GET', "$BASE/me.php", null, $jarFl);       $flId    = $j['data']['id'];
[$c, $j] = http('GET', "$BASE/me.php", null, $jarUmkm);     $umkmId  = $j['data']['id'];
[$c, $j] = http('GET', "$BASE/me.php", null, $jarUmkm2);    $umkm2Id = $j['data']['id'];

http('PUT', "$BASE/users.php?id=$flId", ['location' => 'Bantul, DIY', 'latitude' => -7.9, 'longitude' => 110.3, 'skills' => ['Fotografi'], 'interests' => ['Kuliner']], $jarFl);
http('PUT', "$BASE/users.php?id=$umkmId", ['location' => 'Bantul, DIY'], $jarUmkm);

[$c, $j] = http('POST', "$BASE/projects.php?action=create", [
    'title' => '[E2E-UMKMDEEP] Proyek Utama', 'description' => 'x', 'categories' => ['Fotografi'],
    'location' => 'Bantul', 'prize' => 200000, 'deadline' => '2026-12-31', 'requirements' => 'x',
], $jarUmkm);
$projId = (int) ($j['id'] ?? 0);
check('proyek dibuat', $projId > 0, json_encode($j));

echo "=== 1. get_applicants.php: harus DITOLAK tanpa login ===\n";
[$c, $j] = http('GET', "$BASE/get_applicants.php?project_id=$projId");
check('tanpa login -> 401/403 (bukan 200 bocor data)', in_array($c, [401, 403], true), json_encode($j));

echo "=== 2. get_applicants.php: harus DITOLAK untuk UMKM lain (bukan pemilik) ===\n";
[$c, $j] = http('GET', "$BASE/get_applicants.php?project_id=$projId", null, $jarUmkm2);
check('UMKM lain -> 403 (bukan pemilik proyek)', $c === 403, json_encode($j));

echo "=== 3. get_applicants.php: pemilik proyek TETAP BISA akses normal ===\n";
[$c, $j] = http('GET', "$BASE/get_applicants.php?project_id=$projId", null, $jarUmkm);
check('pemilik proyek -> 200 sukses', $c === 200 && ($j['status'] ?? '') === 'success', json_encode($j));

echo "=== 4. get_user_profile.php / get_freelancer_history.php / freelancer_stats.php: ditolak tanpa login ===\n";
[$c, $j] = http('GET', "$BASE/get_user_profile.php?id=$flId");
check('get_user_profile.php tanpa login -> 401', $c === 401, json_encode($j));
[$c, $j] = http('GET', "$BASE/get_freelancer_history.php?freelancer_id=$flId");
check('get_freelancer_history.php tanpa login -> 401', $c === 401, json_encode($j));
[$c, $j] = http('GET', "$BASE/freelancer_stats.php?freelancer_id=$flId");
check('freelancer_stats.php tanpa login -> 401', $c === 401, json_encode($j));

echo "=== 5. Endpoint yang sama TETAP BISA diakses user lain yang sudah login (bukan cuma pemilik) ===\n";
[$c, $j] = http('GET', "$BASE/get_user_profile.php?id=$flId", null, $jarUmkm2);
check('UMKM lain yg login tetap bisa lihat profil freelancer (fitur cari talenta)', $c === 200 && ($j['status'] ?? '') === 'success', json_encode($j));

echo "=== 6. Alur lamar -> terima -> submit -> REJECT (belum pernah diuji) ===\n";
[$c, $j] = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $jarFl);
check('freelancer melamar', $c === 201, json_encode($j));

[$c, $j] = http('GET', "$BASE/get_applicants.php?project_id=$projId", null, $jarUmkm);
$applicantId = (int) ($j['data'][0]['applicant_id'] ?? 0);

[$c, $j] = http('POST', "$BASE/update_applicant_status.php", ['applicant_id' => $applicantId, 'status' => 'Diterima'], $jarUmkm);
check('diterima -> In Progress', ($j['data']['project_status'] ?? '') === 'In Progress', json_encode($j));

[$c, $j] = http('POST', "$BASE/submit_project.php", ['project_id' => $projId, 'submission_link' => 'https://drive.test/deep-hasil'], $jarFl);
check('submit sukses', ($j['status'] ?? '') === 'success', json_encode($j));

[$c, $j] = http('POST', "$BASE/review_submission.php", ['project_id' => $projId, 'decision' => 'reject'], $jarUmkm);
check('reject sukses', ($j['status'] ?? '') === 'success', json_encode($j));
check('project_status -> Closed', ($j['data']['project_status'] ?? '') === 'Closed', json_encode($j));

$row = $pdo->query("SELECT project_status, submission_link FROM projects WHERE id=$projId")->fetch(PDO::FETCH_ASSOC);
check('DB: project_status benar-benar Closed', $row['project_status'] === 'Closed', json_encode($row));
check('DB: submission_link tetap tersimpan sbg arsip (tidak ikut dihapus saat reject)', $row['submission_link'] === 'https://drive.test/deep-hasil', json_encode($row));

// Proyek Closed tidak boleh direview lagi
[$c, $j] = http('POST', "$BASE/review_submission.php", ['project_id' => $projId, 'decision' => 'accept', 'rating' => 5], $jarUmkm);
check('review_submission ditolak untuk proyek yg sudah Closed (409)', $c === 409, json_encode($j));

echo "=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM project_applicants WHERE project_id = $projId");
$pdo->exec("DELETE FROM project_categories WHERE project_id = $projId");
$pdo->exec("DELETE FROM projects WHERE id = $projId");
$pdo->exec("DELETE FROM user_skills WHERE user_id = $flId");
$pdo->exec("DELETE FROM user_interests WHERE user_id = $flId");
$pdo->exec("DELETE FROM users WHERE id IN ($flId, $umkmId, $umkm2Id)");
@unlink($jarFl); @unlink($jarUmkm); @unlink($jarUmkm2);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
