<?php
/**
 * tests/e2e_talent_ai_profile_history_test.php — Verifikasi fitur BARU:
 * halaman Talent AI (UMKM) → tombol "Lihat Profil" pada kartu freelancer
 * sekarang menampilkan profil DAN riwayat proyek dalam satu modal yang sama
 * (sebelumnya riwayat cuma ada di modal terpisah "Lihat Riwayat" pada daftar
 * pelamar per-proyek, tidak tersedia sama sekali dari Talent AI).
 *
 * Diverifikasi:
 *  1. Data yang dipakai algoritma pencocokan (users.php/me.php: skills,
 *     interests, businessCategory) berbentuk benar -- ini murni perhitungan
 *     JS di renderTalentAI(), jadi yang diuji di sini adalah DATA MASUKANNYA.
 *  2. get_user_profile.php (dipakai showFreelancerProfileModal utk bagian
 *     profil) bisa diakses UMKM utk freelancer manapun (bukan cuma diri
 *     sendiri) -- match dgn Talent AI: UMKM menjelajah BANYAK freelancer.
 *  3. get_freelancer_history.php (dipakai section riwayat BARU) bisa
 *     diakses UMKM yang SAMA, utk freelancer DENGAN riwayat (proyek
 *     Completed + rating + review) DAN freelancer TANPA riwayat sama
 *     sekali -- keduanya harus sukses (bukan error), bedanya cuma isi
 *     array `projects`.
 *  4. Kedua panggilan (profil + riwayat) utk freelancer yang SAMA tidak
 *     saling mengganggu (independen, endpoint beda).
 *
 * Cara pakai: php.exe tests/e2e_talent_ai_profile_history_test.php http://127.0.0.1:PORT/api
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
    $jar = tempnam(sys_get_temp_dir(), 'e2e_talentai_');
    [$code, $j] = http('POST', "$base/login.php", ['email' => $email, 'password' => $password], $jar);
    if ($code !== 200 || !($j['success'] ?? false)) {
        fwrite(STDERR, "Login gagal untuk $email: " . json_encode($j) . "\n");
        exit(1);
    }
    return [$jar, $j['user']['id']];
}

function check($label, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [OK]   $label\n"; }
    else       { $fail++; echo "  [FAIL] $label $extra\n"; }
}

echo "Base URL API: $BASE\n\n";

$pdo = new PDO('mysql:host=127.0.0.1;dbname=bantul_creative;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$suffix = time();

$umkmEmail      = "e2e_talentai_umkm_{$suffix}@test.local";
$umkm2Email     = "e2e_talentai_umkm2_{$suffix}@test.local";
$flMatchEmail   = "e2e_talentai_flmatch_{$suffix}@test.local"; // riwayat KOSONG
$flHistoryEmail = "e2e_talentai_flhist_{$suffix}@test.local";  // riwayat ADA
$password = 'testtalentai123';

http('POST', "$BASE/register.php", ['name' => 'TalentAI UMKM', 'email' => $umkmEmail, 'password' => $password, 'role' => 'umkm', 'businessName' => 'TalentAI Biz', 'businessCategory' => 'Kriya']);
http('POST', "$BASE/register.php", ['name' => 'TalentAI UMKM2', 'email' => $umkm2Email, 'password' => $password, 'role' => 'umkm', 'businessName' => 'TalentAI Biz2', 'businessCategory' => 'Kriya']);
http('POST', "$BASE/register.php", ['name' => 'TalentAI FL Match', 'email' => $flMatchEmail, 'password' => $password, 'role' => 'freelancer']);
http('POST', "$BASE/register.php", ['name' => 'TalentAI FL History', 'email' => $flHistoryEmail, 'password' => $password, 'role' => 'freelancer']);

[$jarUmkm, $umkmId]   = loginAs($BASE, $umkmEmail, $password);
[$jarUmkm2, $umkm2Id] = loginAs($BASE, $umkm2Email, $password);
[$jarFlMatch, $flMatchId]     = loginAs($BASE, $flMatchEmail, $password);
[$jarFlHistory, $flHistoryId] = loginAs($BASE, $flHistoryEmail, $password);

http('PUT', "$BASE/users.php?id=$flMatchId", ['location' => 'Bantul, DIY', 'latitude' => -7.9, 'longitude' => 110.3, 'skills' => ['Kriya Anyaman', 'Fotografi Produk'], 'interests' => ['Kriya']], $jarFlMatch);
http('PUT', "$BASE/users.php?id=$flHistoryId", ['location' => 'Bantul, DIY', 'latitude' => -7.9, 'longitude' => 110.3, 'skills' => ['Videografi'], 'interests' => ['Kriya']], $jarFlHistory);
http('PUT', "$BASE/users.php?id=$umkmId", ['location' => 'Bantul, DIY'], $jarUmkm);
http('PUT', "$BASE/users.php?id=$umkm2Id", ['location' => 'Bantul, DIY'], $jarUmkm2);

echo "=== 1. Data masukan algoritma pencocokan (users.php) berbentuk benar ===\n";
[$c, $j] = http('GET', "$BASE/users.php", null, $jarUmkm);
$flMatchRow = null;
foreach (($j['users'] ?? []) as $u) { if ((int)($u['id'] ?? 0) === $flMatchId) { $flMatchRow = $u; break; } }
check('freelancer match ditemukan di daftar users.php', $flMatchRow !== null);
if ($flMatchRow) {
    check('field skills berbentuk array & terisi', is_array($flMatchRow['skills'] ?? null) && in_array('Kriya Anyaman', $flMatchRow['skills']), json_encode($flMatchRow['skills'] ?? null));
    check('field interests berbentuk array & terisi', is_array($flMatchRow['interests'] ?? null) && in_array('Kriya', $flMatchRow['interests']), json_encode($flMatchRow['interests'] ?? null));
}

echo "\n=== 2. UMKM buat proyek Open butuh skill yg sama, lalu proyek KEDUA (utk riwayat freelancer lain) ===\n";
[$c, $j] = http('POST', "$BASE/projects.php?action=create", [
    'title' => '[E2E-TALENTAI] Anyaman Bambu', 'description' => 'x', 'categories' => ['Kriya Anyaman'],
    'location' => 'Bantul', 'prize' => 200000, 'deadline' => '2026-12-31', 'requirements' => 'x',
], $jarUmkm);
$projOpenId = (int) ($j['id'] ?? 0);
check('proyek Open dibuat (dipakai basis kebutuhan skill Talent AI)', $projOpenId > 0, json_encode($j));

// Proyek KEDUA milik UMKM2, dikerjakan & diselesaikan oleh flHistory, diberi rating+review
[$c, $j] = http('POST', "$BASE/projects.php?action=create", [
    'title' => '[E2E-TALENTAI] Video Company Profile', 'description' => 'x', 'categories' => ['Videografi'],
    'location' => 'Bantul', 'prize' => 500000, 'deadline' => '2026-12-31', 'requirements' => 'x',
], $jarUmkm2);
$projHistId = (int) ($j['id'] ?? 0);
check('proyek ke-2 dibuat (akan diselesaikan utk uji riwayat)', $projHistId > 0, json_encode($j));

http('POST', "$BASE/apply_project.php", ['project_id' => $projHistId], $jarFlHistory);
[$c, $j] = http('GET', "$BASE/get_applicants.php?project_id=$projHistId", null, $jarUmkm2);
$applicantId = (int) ($j['data'][0]['applicant_id'] ?? 0);
http('POST', "$BASE/update_applicant_status.php", ['applicant_id' => $applicantId, 'status' => 'Diterima'], $jarUmkm2);
http('POST', "$BASE/submit_project.php", ['project_id' => $projHistId, 'submission_link' => 'https://example.com/hasil'], $jarFlHistory);
[$c, $j] = http('POST', "$BASE/review_submission.php", ['project_id' => $projHistId, 'decision' => 'accept', 'rating' => 5, 'review_text' => 'Hasil videonya sangat memuaskan!'], $jarUmkm2);
check('proyek ke-2 selesai (Completed) dgn rating 5', ($j['data']['project_status'] ?? '') === 'Completed', json_encode($j));

echo "\n=== 3. \"Lihat Profil\" dari Talent AI: UMKM (pihak PERTAMA, tak terlibat proyek riwayat) lihat profil flHistory ===\n";
[$c, $j] = http('GET', "$BASE/get_user_profile.php?id=$flHistoryId", null, $jarUmkm);
check('UMKM (pihak lain) bisa lihat profil freelancer manapun', $c === 200 && ($j['status'] ?? '') === 'success', json_encode($j));
check('profil memuat skills sesuai isian', in_array('Videografi', $j['data']['skills'] ?? []), json_encode($j['data']['skills'] ?? null));

echo "\n=== 4. Section \"Riwayat Proyek\" BARU: UMKM (pihak lain) lihat riwayat flHistory -- HARUS ada 1 proyek Completed rating 5 ===\n";
[$c, $j] = http('GET', "$BASE/get_freelancer_history.php?freelancer_id=$flHistoryId", null, $jarUmkm);
check('endpoint riwayat sukses diakses UMKM pihak lain (bukan cuma UMKM pemilik proyek riwayat)', $c === 200 && ($j['status'] ?? '') === 'success', json_encode($j));
$summary = $j['data']['summary'] ?? [];
$projects = $j['data']['projects'] ?? [];
check('summary.total_completed = 1', ($summary['total_completed'] ?? 0) === 1, json_encode($summary));
check('summary.avg_rating = 5', (float)($summary['avg_rating'] ?? 0) === 5.0, json_encode($summary));
check('projects[0] memuat field yg dipakai renderFreelancerHistoryList (title/rating/client_name/completed_at/budget/review_text)',
    isset($projects[0]['title'], $projects[0]['rating'], $projects[0]['client_name'], $projects[0]['completed_at'], $projects[0]['budget'], $projects[0]['review_text'])
    && $projects[0]['rating'] === 5 && $projects[0]['review_text'] === 'Hasil videonya sangat memuaskan!',
    json_encode($projects[0] ?? null));
check('client_name pada riwayat = nama bisnis UMKM2 (bukan UMKM yg sedang menonton)', $projects[0]['client_name'] === 'TalentAI Biz2', json_encode($projects[0]['client_name'] ?? null));

echo "\n=== 5. Freelancer TANPA riwayat sama sekali (flMatch): endpoint harus tetap sukses, projects=[] (bukan error) ===\n";
[$c, $j] = http('GET', "$BASE/get_freelancer_history.php?freelancer_id=$flMatchId", null, $jarUmkm);
check('sukses (bukan error) walau belum ada riwayat', $c === 200 && ($j['status'] ?? '') === 'success', json_encode($j));
check('projects = array kosong', is_array($j['data']['projects'] ?? null) && count($j['data']['projects']) === 0, json_encode($j['data']['projects'] ?? null));
$summary5 = $j['data']['summary'] ?? [];
check('summary.total_completed = 0, avg_rating = null', array_key_exists('total_completed', $summary5) && $summary5['total_completed'] === 0 && array_key_exists('avg_rating', $summary5) && $summary5['avg_rating'] === null, json_encode($summary5));

echo "\n=== 6. Profil & riwayat utk freelancer YANG SAMA independen satu sama lain (tidak saling tabrakan) ===\n";
[$c1, $jProfil]  = http('GET', "$BASE/get_user_profile.php?id=$flHistoryId", null, $jarUmkm);
[$c2, $jRiwayat] = http('GET', "$BASE/get_freelancer_history.php?freelancer_id=$flHistoryId", null, $jarUmkm);
check('kedua panggilan sukses independen utk freelancer yg sama', $c1 === 200 && $c2 === 200 && $jProfil['data']['id'] == $flHistoryId && $jRiwayat['data']['freelancer']['id'] == $flHistoryId);

echo "\n=== Bersih-bersih ===\n";
foreach ([$projOpenId, $projHistId] as $pid) {
    $pdo->exec("DELETE FROM chats WHERE project_id = $pid");
    $pdo->exec("DELETE FROM project_applicants WHERE project_id = $pid");
    $pdo->exec("DELETE FROM project_categories WHERE project_id = $pid");
    $pdo->exec("DELETE FROM projects WHERE id = $pid");
}
foreach ([$flMatchId, $flHistoryId] as $uid) {
    $pdo->exec("DELETE FROM user_skills WHERE user_id = $uid");
    $pdo->exec("DELETE FROM user_interests WHERE user_id = $uid");
}
$pdo->exec("DELETE FROM users WHERE id IN ($umkmId, $umkm2Id, $flMatchId, $flHistoryId)");
foreach ([$jarUmkm, $jarUmkm2, $jarFlMatch, $jarFlHistory] as $jar) { @unlink($jar); }
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
