<?php
/**
 * tests/e2e_review_link_test.php — Pengujian E2E siklus proyek Bantul Creative.
 *
 * Menjalankan siklus penuh: apply_project → submit_project → review_submission
 * (accept & revise) → get_freelancer_history → freelancer_stats, dan memverifikasi
 * bahwa submission_link TETAP ADA setelah "Terima Hasil" dan DIKOSONGKAN setelah
 * "Revisi" — termasuk memastikan endpoint portofolio ikut mengembalikan link itu.
 *
 * Sejak endpoint mutasi dipindah ke session PHP asli (bukan lagi
 * freelancer_id/umkm_id yang dikirim client), skrip ini login sungguhan
 * sebagai freelancer & UMKM uji lewat api/login.php dan memakai cookie
 * jar (curl) untuk request selanjutnya.
 *
 * Cara pakai:
 *   php tests/e2e_review_link_test.php [BASE_URL]
 *   (default BASE_URL: http://127.0.0.1/travelfix-main/api — sesuaikan bila perlu)
 *
 * Catatan WSL: WSL2 tidak bisa mengakses localhost Windows secara langsung.
 * Jalankan skrip ini dengan php.exe (Windows) dari WSL, contoh:
 *   /mnt/c/xampp/php/php.exe tests/e2e_review_link_test.php http://127.0.0.1:8177/api
 * setelah menyalakan server dev: php.exe -S 0.0.0.0:8177 -t <folder proyek>
 *
 * Skrip ini membuat & menghapus sendiri data ujinya (aman dijalankan berulang).
 */

// Hanya boleh dijalankan lewat CLI — file ini ada di dalam document root,
// jadi tanpa penjagaan ini siapa pun bisa memicunya lewat browser dan
// membuat/menghapus data langsung dari HTTP request.
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
    $jar = tempnam(sys_get_temp_dir(), 'e2e_link_');
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

// ── Bersihkan sisa uji sebelumnya & siapkan data ─────────────────────
$pdo->exec("DELETE FROM project_applicants WHERE project_id IN (SELECT id FROM (SELECT id FROM projects WHERE title = '[TEST-LINK] Proyek Uji Link') t)");
$pdo->exec("DELETE FROM projects WHERE title = '[TEST-LINK] Proyek Uji Link'");
$pdo->exec("DELETE FROM users WHERE email IN ('test_link_fl@test.local', 'test_link_umkm@test.local')");

$flPassword = 'rahasia123';
$pdo->exec("INSERT INTO users (name, email, password, role, account_status, location, latitude, longitude, created_at)
            VALUES ('Test Link Freelancer', 'test_link_fl@test.local', '" . password_hash($flPassword, PASSWORD_DEFAULT) . "', 'freelancer', 'Active', 'Sewon, Bantul', -7.85, 110.35, NOW())");
$flId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_skills (user_id, skill) VALUES (?, 'Copywriting')")->execute([$flId]);
$pdo->prepare("INSERT INTO user_interests (user_id, interest) VALUES (?, 'Konten Digital')")->execute([$flId]);

// UMKM uji sendiri (bukan pakai akun UMKM asli di DB) supaya passwordnya diketahui & bisa login sungguhan.
$umkmPassword = 'rahasiaumkm123';
$pdo->exec("INSERT INTO users (name, email, password, role, account_status, business_name, created_at)
            VALUES ('Test Link UMKM', 'test_link_umkm@test.local', '" . password_hash($umkmPassword, PASSWORD_DEFAULT) . "', 'umkm', 'Active', 'Test Link Business', NOW())");
$umkmId = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare("INSERT INTO projects (created_by, title, description, budget, project_status, created_at, updated_at)
                        VALUES (?, '[TEST-LINK] Proyek Uji Link', 'Uji siklus submission_link', 500000, 'Open', NOW(), NOW())");
$stmt->execute([$umkmId]);
$projId = (int) $pdo->lastInsertId();

// Session PHP asli untuk masing-masing peran uji
$jarFreelancer = loginAs($BASE, 'test_link_fl@test.local', $flPassword);
$jarUmkm       = loginAs($BASE, 'test_link_umkm@test.local', $umkmPassword);

// ── 1) apply_project.php ─────────────────────────────────────────────
echo "=== 1. apply_project.php ===\n";
$j = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $jarFreelancer);
check('lamaran berhasil (201/success)', ($j['status'] ?? '') === 'success', json_encode($j));
$applicantId = (int) ($j['data']['applicant_id'] ?? 0);

$j = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $jarFreelancer);
check('lamaran ganda ditolak (already_applied)', ($j['code'] ?? '') === 'already_applied', json_encode($j));

$j = http('POST', "$BASE/update_applicant_status.php", ['applicant_id' => $applicantId, 'status' => 'Diterima'], $jarUmkm);
check('pelamar diterima -> proyek In Progress', $j !== null, json_encode($j));

// ── 2) submit_project.php ────────────────────────────────────────────
echo "=== 2. submit_project.php ===\n";
$j = http('POST', "$BASE/submit_project.php", ['project_id' => $projId, 'submission_link' => ''], $jarFreelancer);
check('link kosong ditolak (422)', ($j['status'] ?? '') === 'error');

$linkAsli = 'https://drive.google.com/uji-hasil-pekerjaan-v1';
$j = http('POST', "$BASE/submit_project.php", ['project_id' => $projId, 'submission_link' => $linkAsli], $jarFreelancer);
check('submit sukses -> status Submitted', ($j['data']['project_status'] ?? '') === 'Submitted', json_encode($j));

// ── 3) review_submission.php — Revisi (submission_link HARUS dikosongkan) ──
echo "=== 3a. review_submission.php — decision: revise ===\n";
$j = http('POST', "$BASE/review_submission.php", ['project_id' => $projId, 'decision' => 'revise'], $jarUmkm);
check('revisi -> status In Progress', ($j['data']['project_status'] ?? '') === 'In Progress', json_encode($j));
$row = $pdo->query("SELECT submission_link FROM projects WHERE id=$projId")->fetch(PDO::FETCH_ASSOC);
check('submission_link DIKOSONGKAN setelah Revisi', $row['submission_link'] === null, json_encode($row));

// Submit ulang dengan link baru
$linkRevisi = 'https://drive.google.com/uji-hasil-pekerjaan-v2-revisi';
http('POST', "$BASE/submit_project.php", ['project_id' => $projId, 'submission_link' => $linkRevisi], $jarFreelancer);

// ── 3b) review_submission.php — Terima Hasil (submission_link HARUS TETAP ADA) ──
echo "=== 3b. review_submission.php — decision: accept (BUG UTAMA) ===\n";
$j = http('POST', "$BASE/review_submission.php", ['project_id' => $projId, 'decision' => 'accept', 'rating' => 5, 'review_text' => 'Hasil revisi sangat memuaskan!'], $jarUmkm);
check('terima -> status Completed', ($j['data']['project_status'] ?? '') === 'Completed', json_encode($j));
$row = $pdo->query("SELECT submission_link, rating, review_text FROM projects WHERE id=$projId")->fetch(PDO::FETCH_ASSOC);
check('submission_link TETAP ADA (tidak NULL) setelah Terima Hasil', $row['submission_link'] === $linkRevisi, json_encode($row));
check('rating & ulasan tersimpan', (int) $row['rating'] === 5 && $row['review_text'] === 'Hasil revisi sangat memuaskan!');

// ── 4) get_freelancer_history.php & freelancer_stats.php (wajib login sejak
//      perbaikan IDOR 2026-07-04 -- sebelumnya endpoint ini bisa diakses
//      tanpa session sama sekali, sekarang wajib requireAuth()) ──
echo "=== 4a. get_freelancer_history.php ===\n";
$j = http('GET', "$BASE/get_freelancer_history.php?freelancer_id=$flId", null, $jarUmkm);
check('status success', ($j['status'] ?? '') === 'success', json_encode($j));
$hist = ($j['data']['projects'] ?? [])[0] ?? [];
check('submission_link ikut dikembalikan di riwayat', ($hist['submission_link'] ?? null) === $linkRevisi, json_encode($hist));
check('rating & review_text ikut dikembalikan', ($hist['rating'] ?? null) === 5 && !empty($hist['review_text']));

echo "=== 4b. freelancer_stats.php (Impact Portofolio) ===\n";
$j = http('GET', "$BASE/freelancer_stats.php?freelancer_id=$flId", null, $jarFreelancer);
check('success = true', ($j['success'] ?? false) === true, json_encode($j));
$stat = ($j['projects'] ?? [])[0] ?? [];
check('submission_link ikut dikembalikan di portofolio', ($stat['submission_link'] ?? null) === $linkRevisi, json_encode($stat));
check('total_saldo mencerminkan budget proyek Completed', (float) ($j['stats']['total_saldo'] ?? 0) >= 500000.0);

// ── Bersih-bersih ─────────────────────────────────────────────────────
echo "=== Bersih-bersih data uji ===\n";
$pdo->exec("DELETE FROM project_applicants WHERE project_id = $projId");
$pdo->exec("DELETE FROM projects WHERE id = $projId");
$pdo->prepare("DELETE FROM user_skills WHERE user_id=?")->execute([$flId]);
$pdo->prepare("DELETE FROM user_interests WHERE user_id=?")->execute([$flId]);
$pdo->prepare("DELETE FROM users WHERE id=?")->execute([$flId]);
$pdo->prepare("DELETE FROM users WHERE id=?")->execute([$umkmId]);
@unlink($jarFreelancer);
@unlink($jarUmkm);
echo "  selesai dibersihkan\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
