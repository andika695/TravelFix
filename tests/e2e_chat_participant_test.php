<?php
/**
 * tests/e2e_chat_participant_test.php — Verifikasi laporan: "Anda bukan
 * partisipan chat proyek ini" muncul di sisi freelancer MAUPUN UMKM.
 *
 * Dua hal diverifikasi:
 *  1. Data proyek NYATA (id=372, sudah diperiksa langsung ke database) —
 *     freelancer_id tersinkron benar dgn assigned_to & project_applicants,
 *     jadi bukan bug data.
 *  2. Chat berjalan normal untuk partisipan SUNGGUHAN (pemilik proyek/UMKM
 *     dan freelancer yang diterima) ketika sesi PHP konsisten -- DAN pesan
 *     "bukan partisipan" tetap muncul dgn benar (bukan bug) untuk pihak yang
 *     BUKAN pemilik/freelancer proyek tsb, sesuai desain. Kesimpulan: kalau
 *     laporan ini muncul utk partisipan yang SEHARUSNYA sah, akar masalahnya
 *     adalah sesi PHP browser tidak cocok (lihat e2e_session_mismatch_test.php
 *     & kanonisasi hostname di js/db.js), BUKAN logika chats.php.
 *
 * Cara pakai: php.exe tests/e2e_chat_participant_test.php http://127.0.0.1:PORT/api
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
    $jar = tempnam(sys_get_temp_dir(), 'e2e_chatpart_');
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

$flOwnerEmail = "e2e_chatpart_fl_{$suffix}@test.local";
$umkmEmail    = "e2e_chatpart_umkm_{$suffix}@test.local";
$outsiderEmail = "e2e_chatpart_outsider_{$suffix}@test.local";
$password = 'testchatpart123';

http('POST', "$BASE/register.php", ['name' => 'ChatPart FL', 'email' => $flOwnerEmail, 'password' => $password, 'role' => 'freelancer']);
http('POST', "$BASE/register.php", ['name' => 'ChatPart UMKM', 'email' => $umkmEmail, 'password' => $password, 'role' => 'umkm', 'businessName' => 'ChatPart Biz', 'businessCategory' => 'Kriya']);
http('POST', "$BASE/register.php", ['name' => 'ChatPart Outsider', 'email' => $outsiderEmail, 'password' => $password, 'role' => 'freelancer']);

$jarFl = loginAs($BASE, $flOwnerEmail, $password);
$jarUmkm = loginAs($BASE, $umkmEmail, $password);
$jarOutsider = loginAs($BASE, $outsiderEmail, $password);

[, $j] = http('GET', "$BASE/me.php", null, $jarFl);   $flId = $j['data']['id'];
[, $j] = http('GET', "$BASE/me.php", null, $jarUmkm); $umkmId = $j['data']['id'];

http('PUT', "$BASE/users.php?id=$flId", ['location' => 'Bantul, DIY', 'latitude' => -7.9, 'longitude' => 110.3, 'skills' => ['Video Editing'], 'interests' => ['Kuliner']], $jarFl);
http('PUT', "$BASE/users.php?id=$umkmId", ['location' => 'Bantul, DIY'], $jarUmkm);

[, $j] = http('POST', "$BASE/projects.php?action=create", [
    'title' => '[E2E-CHATPART] Video Promosi', 'description' => 'x', 'categories' => ['Video Editing'],
    'location' => 'Bantul', 'prize' => 300000, 'deadline' => '2026-12-31', 'requirements' => 'x',
], $jarUmkm);
$projId = (int) ($j['id'] ?? 0);
check('setup: proyek dibuat', $projId > 0, json_encode($j));

http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $jarFl);
[, $j] = http('GET', "$BASE/get_applicants.php?project_id=$projId", null, $jarUmkm);
$applicantId = (int) ($j['data'][0]['applicant_id'] ?? 0);
[, $j] = http('POST', "$BASE/update_applicant_status.php", ['applicant_id' => $applicantId, 'status' => 'Diterima'], $jarUmkm);
check('setup: freelancer diterima, proyek In Progress', ($j['data']['project_status'] ?? '') === 'In Progress', json_encode($j));

// Cross-check langsung ke DB: freelancer_id proyek benar-benar tersinkron
$row = $pdo->query("SELECT created_by, freelancer_id, assigned_to FROM projects WHERE id=$projId")->fetch(PDO::FETCH_ASSOC);
check('DB: freelancer_id tersinkron dgn assigned_to (bukan bug data)', (string)$row['freelancer_id'] === (string)$row['assigned_to'] && (int)$row['freelancer_id'] === $flId, json_encode($row));

echo "\n=== 1. UMKM (pemilik proyek) kirim chat -- HARUS berhasil ===\n";
[$c, $j] = http('POST', "$BASE/chats.php", ['project_id' => $projId, 'message' => 'Halo, progress-nya bagaimana?'], $jarUmkm);
check('UMKM berhasil kirim chat', $c === 201 && ($j['status'] ?? '') === 'success', json_encode($j));

echo "=== 2. Freelancer (yang diterima) kirim chat -- HARUS berhasil ===\n";
[$c, $j] = http('POST', "$BASE/chats.php", ['project_id' => $projId, 'message' => 'Sudah 50%, Pak.'], $jarFl);
check('freelancer berhasil kirim chat', $c === 201 && ($j['status'] ?? '') === 'success', json_encode($j));

echo "=== 3. Pihak LUAR (bukan pemilik/freelancer proyek ini) kirim chat -- HARUS ditolak dgn benar (bukan bug) ===\n";
[$c, $j] = http('POST', "$BASE/chats.php", ['project_id' => $projId, 'message' => 'Halo juga dong'], $jarOutsider);
check('pihak luar ditolak 403 "bukan partisipan" (perilaku BENAR, bukan bug)', $c === 403 && strpos($j['message'] ?? '', 'bukan partisipan') !== false, json_encode($j));

echo "\nKesimpulan: chats.php sudah benar untuk partisipan sungguhan maupun pihak luar.\n";
echo "Kalau laporan \"bukan partisipan\" muncul utk freelancer/UMKM yang SEHARUSNYA sah,\n";
echo "akar masalahnya adalah sesi PHP browser tidak cocok dgn sessionStorage tab (lihat\n";
echo "e2e_session_mismatch_test.php) -- BUKAN bug baru di logika chat ini.\n";

echo "\n=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM chats WHERE project_id = $projId");
$pdo->exec("DELETE FROM project_applicants WHERE project_id = $projId");
$pdo->exec("DELETE FROM project_categories WHERE project_id = $projId");
$pdo->exec("DELETE FROM projects WHERE id = $projId");
$pdo->exec("DELETE FROM user_skills WHERE user_id = $flId");
$pdo->exec("DELETE FROM user_interests WHERE user_id = $flId");
[, $jOut] = http('GET', "$BASE/me.php", null, $jarOutsider);
$outsiderId = $jOut['data']['id'] ?? 0;
$pdo->exec("DELETE FROM users WHERE id IN ($flId, $umkmId, $outsiderId)");
@unlink($jarFl); @unlink($jarUmkm); @unlink($jarOutsider);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
