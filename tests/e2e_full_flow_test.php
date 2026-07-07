<?php
/**
 * tests/e2e_full_flow_test.php — Uji alur penuh end-to-end lewat register.php
 * sungguhan (bukan INSERT langsung): registrasi freelancer & UMKM, simpan
 * profil lengkap kedua role (bio/portfolio/kontak/kategori bisnis/skill/
 * minat), lengkapi Lokasi Bisnis, buat proyek, filter status marketplace,
 * lamar, terima pelamar, submit hasil, terima hasil (review), cek statistik
 * portfolio, dan operasi admin (suspend/unsuspend, tutup proyek, hapus).
 *
 * Cara pakai: php.exe tests/e2e_full_flow_test.php http://127.0.0.1:PORT/api
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
    $jar = tempnam(sys_get_temp_dir(), 'e2e_full_');
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
$flEmail  = "e2e_full_fl_{$suffix}@test.local";
$umkmEmail = "e2e_full_umkm_{$suffix}@test.local";
$password = 'testfull123';

$adminEmail = 'admin123@gmail.com';
$adminPassword = 'admin123';

// ── 1) Registrasi via register.php sungguhan ────────────────────────────
echo "=== 1. Registrasi freelancer & UMKM via register.php ===\n";
[$code, $j] = http('POST', "$BASE/register.php", ['name' => 'E2E Full Freelancer', 'email' => $flEmail, 'password' => $password, 'role' => 'freelancer']);
check('registrasi freelancer berhasil', $code === 201 && ($j['success'] ?? false) === true, json_encode($j));
$flId = (int) ($j['user']['id'] ?? 0);

[$code, $j] = http('POST', "$BASE/register.php", ['name' => 'E2E Full Pemilik', 'email' => $umkmEmail, 'password' => $password, 'role' => 'umkm', 'businessName' => 'E2E Full Business', 'businessCategory' => 'Kriya']);
check('registrasi umkm berhasil', $code === 201 && ($j['success'] ?? false) === true, json_encode($j));
$umkmId = (int) ($j['user']['id'] ?? 0);

if (!$flId || !$umkmId) { fwrite(STDERR, "Registrasi gagal, tes dihentikan.\n"); exit(1); }

$jarFl   = loginAs($BASE, $flEmail, $password);
$jarUmkm = loginAs($BASE, $umkmEmail, $password);
$jarAdmin = loginAs($BASE, $adminEmail, $adminPassword);

// ── 2) Simpan profil lengkap FREELANCER lewat users.php PUT (bentuk yang
//      dikirim js/db.js mapUserUpdatesToApi(), bukan camelCase mentah) ──────
echo "=== 2. Simpan profil lengkap freelancer ===\n";
[$code, $j] = http('PUT', "$BASE/users.php?id=$flId", [
    'bio' => 'Desainer grafis Bantul.', 'location' => 'Bantul, DIY',
    'latitude' => -7.88, 'longitude' => 110.33,
    'portfolio_url' => 'https://portofolio.test/e2e',
    'whatsapp' => '081200000001', 'instagram' => '@e2efull', 'linkedin' => 'linkedin.com/in/e2efull',
    'skills' => ['Desain Grafis', 'Ilustrasi'], 'interests' => ['Kuliner', 'Fashion'],
], $jarFl);
check('PUT profil freelancer sukses', $code === 200 && ($j['success'] ?? false) === true && ($j['status'] ?? '') === 'success', json_encode($j));

[$code, $j] = http('GET', "$BASE/get_user_profile.php?id=$flId", null, $jarFl);
$d = $j['data'] ?? [];
check('bio tersimpan', ($d['bio'] ?? null) === 'Desainer grafis Bantul.', json_encode($d));
check('location tersimpan', ($d['location'] ?? null) === 'Bantul, DIY');
check('portfolio_url tersimpan', ($d['portfolio_url'] ?? null) === 'https://portofolio.test/e2e');
check('whatsapp tersimpan', ($d['whatsapp'] ?? null) === '081200000001');
check('skills tersimpan', ($d['skills'] ?? []) === ['Desain Grafis', 'Ilustrasi']);
check('interests tersimpan', ($d['interests'] ?? []) === ['Kuliner', 'Fashion']);

// ── 3) api/me.php harus mencerminkan data yang sama (rehydrasi sesi) ────
echo "=== 3. api/me.php mencerminkan profil freelancer terkini ===\n";
[$code, $j] = http('GET', "$BASE/me.php", null, $jarFl);
$me = $j['data'] ?? [];
check('me.php status success', ($j['status'] ?? '') === 'success');
check('me.php role benar', ($me['role'] ?? '') === 'freelancer');
check('me.php bio ikut terbaru', ($me['bio'] ?? null) === 'Desainer grafis Bantul.');
check('me.php skills ikut terbaru', ($me['skills'] ?? []) === ['Desain Grafis', 'Ilustrasi']);

// ── 4) Simpan profil lengkap UMKM (termasuk business_name & business_category) ──
echo "=== 4. Simpan profil lengkap UMKM (Lokasi Bisnis + Nama Bisnis) ===\n";
[$code, $j] = http('PUT', "$BASE/users.php?id=$umkmId", [
    'business_name' => 'E2E Full Business Updated', 'business_category' => 'Kuliner',
    'bio' => 'UMKM kuliner khas Bantul.', 'location' => 'Bantul, DIY',
    'website' => 'https://e2efullbusiness.test', 'address' => 'Jl. Uji No. 1, Bantul',
], $jarUmkm);
check('PUT profil umkm sukses', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));

[$code, $j] = http('GET', "$BASE/get_user_profile.php?id=$umkmId", null, $jarUmkm);
$d = $j['data'] ?? [];
check('business_name tersimpan (bukan ke kolom name)', ($d['business_name'] ?? null) === 'E2E Full Business Updated');
check('name TIDAK ikut berubah (bukan field yang dikirim)', ($d['name'] ?? null) === 'E2E Full Pemilik');
check('business_category tersimpan', ($d['business_category'] ?? null) === 'Kuliner');
check('location (Lokasi Bisnis) tersimpan', ($d['location'] ?? null) === 'Bantul, DIY');
check('website tersimpan', ($d['website'] ?? null) === 'https://e2efullbusiness.test');

// ── 5) UMKM buat proyek (Lokasi Bisnis sudah lengkap -> harus berhasil) ─
echo "=== 5. UMKM membuat proyek (Lokasi Bisnis sudah lengkap) ===\n";
[$code, $j] = http('POST', "$BASE/projects.php?action=create", [
    'title' => '[E2E-FULL] Desain Kemasan Produk', 'description' => 'Butuh desain kemasan baru',
    'categories' => ['Desain Grafis'], 'location' => 'Bantul, DIY', 'prize' => 750000,
    'deadline' => '2026-12-01', 'requirements' => 'Portofolio wajib', 'icon' => 'palette',
], $jarUmkm);
check('proyek berhasil dibuat', $code === 201 && ($j['success'] ?? false) === true, json_encode($j));
$projId = (int) ($j['id'] ?? 0);

// ── 6) Filter status marketplace ────────────────────────────────────────
echo "=== 6. get_marketplace_projects.php filter status ===\n";
[$code, $j] = http('GET', "$BASE/get_marketplace_projects.php?status=open");
$openIds = array_column($j['data'] ?? [], 'id');
check('proyek baru muncul di filter open', in_array($projId, $openIds, true), json_encode($openIds));

[$code, $j] = http('GET', "$BASE/get_marketplace_projects.php?status=review");
$reviewIds = array_column($j['data'] ?? [], 'id');
check('proyek baru TIDAK muncul di filter review (masih Open)', !in_array($projId, $reviewIds, true));

[$code, $j] = http('GET', "$BASE/get_marketplace_projects.php?status=bukan_valid");
check('status filter tidak dikenal ditolak 422', $code === 422, json_encode($j));

// ── 7) Freelancer melamar ───────────────────────────────────────────────
echo "=== 7. Freelancer melamar proyek ===\n";
[$code, $j] = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $jarFl);
check('lamaran berhasil', $code === 201 && ($j['status'] ?? '') === 'success', json_encode($j));

// ── 8) UMKM lihat daftar pelamar ─────────────────────────────────────────
echo "=== 8. UMKM melihat daftar pelamar ===\n";
[$code, $j] = http('GET', "$BASE/get_applicants.php?project_id=$projId", null, $jarUmkm);
check('status success', ($j['status'] ?? '') === 'success', json_encode($j));
$applicants = $j['data'] ?? [];
check('freelancer muncul sebagai pelamar', count($applicants) === 1 && (int) $applicants[0]['freelancer_id'] === $flId);
$applicantId = (int) ($applicants[0]['applicant_id'] ?? 0);
check('skills pelamar ikut dikembalikan', ($applicants[0]['skills'] ?? []) === ['Desain Grafis', 'Ilustrasi']);

// ── 9) UMKM menerima pelamar ─────────────────────────────────────────────
echo "=== 9. UMKM menerima pelamar (project -> In Progress) ===\n";
[$code, $j] = http('POST', "$BASE/update_applicant_status.php", ['applicant_id' => $applicantId, 'status' => 'Diterima'], $jarUmkm);
check('diterima sukses', ($j['status'] ?? '') === 'success', json_encode($j));
check('project_status -> In Progress', ($j['data']['project_status'] ?? '') === 'In Progress');

[$code, $j] = http('GET', "$BASE/get_marketplace_projects.php?status=review");
$reviewIds = array_column($j['data'] ?? [], 'id');
check('proyek sekarang muncul di filter review (In Progress)', in_array($projId, $reviewIds, true));

// Freelancer lain (non-pemilik lamaran) TIDAK boleh submit atas nama proyek ini -- dicek lewat authz test sudah ada, di sini fokus alur normal.

// ── 10) Freelancer submit hasil kerja ───────────────────────────────────
echo "=== 10. Freelancer submit hasil kerja ===\n";
[$code, $j] = http('POST', "$BASE/submit_project.php", ['project_id' => $projId, 'submission_link' => 'https://drive.test/e2e-full-hasil'], $jarFl);
check('submit sukses', ($j['status'] ?? '') === 'success', json_encode($j));

// ── 11) UMKM menerima hasil (review_submission accept) ──────────────────
echo "=== 11. UMKM menerima hasil kerja ===\n";
[$code, $j] = http('POST', "$BASE/review_submission.php", [
    'project_id' => $projId, 'decision' => 'accept', 'rating' => 5, 'review_text' => 'Hasil memuaskan!',
], $jarUmkm);
check('review accept sukses', ($j['status'] ?? '') === 'success', json_encode($j));

[$code, $j] = http('GET', "$BASE/get_marketplace_projects.php?status=close");
$closeIds = array_column($j['data'] ?? [], 'id');
check('proyek sekarang muncul di filter close (Completed)', in_array($projId, $closeIds, true));

// ── 12) Freelancer cek Impact Portfolio ─────────────────────────────────
echo "=== 12. freelancer_stats.php (Impact Portfolio) ===\n";
[$code, $j] = http('GET', "$BASE/freelancer_stats.php?freelancer_id=$flId", null, $jarFl);
check('success', ($j['success'] ?? false) === true, json_encode($j));
$completedTitles = array_column($j['projects'] ?? [], 'title');
check('proyek baru muncul di portfolio', in_array('[E2E-FULL] Desain Kemasan Produk', $completedTitles, true));

// ── 13) Freelancer cek AI Match (proyek sudah Completed, harus TIDAK muncul lagi) ──
echo "=== 13. ai_talent_match.php tidak merekomendasikan proyek yang sudah Completed ===\n";
[$code, $j] = http('GET', "$BASE/ai_talent_match.php?freelancer_id=$flId", null, $jarFl);
$recoIds = array_column($j['data'] ?? [], 'project_id');
check('proyek Completed tidak direkomendasikan lagi', !in_array($projId, $recoIds, true));

// ── 14) Admin: suspend lalu unsuspend freelancer ────────────────────────
echo "=== 14. Admin suspend/unsuspend freelancer ===\n";
[$code, $j] = http('POST', "$BASE/users.php?action=suspend&id=$flId", null, $jarAdmin);
check('suspend sukses', ($j['success'] ?? false) === true && ($j['account_status'] ?? '') === 'Suspended', json_encode($j));

// Akun yang sudah di-suspend tidak boleh bisa login lagi
[$code, $j] = http('POST', "$BASE/login.php", ['email' => $flEmail, 'password' => $password]);
check('akun suspended ditolak login', $code === 403 && ($j['message'] ?? '') === 'Akun Anda Dibekukan.', json_encode($j));

[$code, $j] = http('POST', "$BASE/users.php?action=suspend&id=$flId", null, $jarAdmin);
check('unsuspend sukses', ($j['success'] ?? false) === true && ($j['account_status'] ?? '') === 'Active', json_encode($j));

[$code, $j] = http('POST', "$BASE/login.php", ['email' => $flEmail, 'password' => $password]);
check('akun aktif kembali bisa login', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));

// ── 15) Admin: dashboard_stats.php sanity ───────────────────────────────
echo "=== 15. Admin dashboard_stats.php ===\n";
[$code, $j] = http('GET', "$BASE/dashboard_stats.php", null, $jarAdmin);
check('success', ($j['success'] ?? false) === true, json_encode($j));

// ── 16) Non-admin TIDAK boleh akses dashboard_stats.php-protected actions (spot check reuse of authz pattern) ──
echo "=== 16. Cross-role authorization spot-check ===\n";
[$code, $j] = http('POST', "$BASE/users.php?action=suspend&id=$umkmId", null, $jarFl);
check('freelancer tidak bisa suspend user lain', in_array($code, [401, 403], true), json_encode($j));

// ── 17) Chat UMKM <-> Freelancer (proyek sudah Completed, chat tetap ada) ──
echo "=== 17. chats.php — kirim & baca pesan ===\n";
[$code, $j] = http('POST', "$BASE/chats.php", ['project_id' => $projId, 'message' => 'Halo, kapan bisa mulai revisi kecil?'], $jarUmkm);
check('UMKM kirim pesan sukses', $code === 201 && ($j['status'] ?? '') === 'success', json_encode($j));
check('receiver_id otomatis = freelancer', (int) ($j['data']['receiver_id'] ?? 0) === $flId);

[$code, $j] = http('POST', "$BASE/chats.php", ['project_id' => $projId, 'message' => 'Siap, besok saya kirim.'], $jarFl);
check('freelancer balas pesan sukses', $code === 201 && ($j['status'] ?? '') === 'success', json_encode($j));
check('receiver_id otomatis = UMKM', (int) ($j['data']['receiver_id'] ?? 0) === $umkmId);

[$code, $j] = http('GET', "$BASE/chats.php?project_id=$projId", null, $jarFl);
check('freelancer bisa baca riwayat chat proyeknya', ($j['status'] ?? '') === 'success' && count($j['data'] ?? []) === 2, json_encode($j));

[$code, $j] = http('GET', "$BASE/chats.php?inbox=$flId", null, $jarFl);
$inboxProjectIds = array_column($j['data'] ?? [], 'project_id');
check('pesan UMKM muncul di inbox freelancer', in_array($projId, $inboxProjectIds, true), json_encode($j));

// ── 18) Pihak ketiga (bukan pemilik/freelancer proyek) DITOLAK baca chat ──
echo "=== 18. chats.php ditolak untuk pihak yang bukan partisipan ===\n";
$outsiderEmail = "e2e_full_outsider_{$suffix}@test.local";
http('POST', "$BASE/register.php", ['name' => 'E2E Outsider', 'email' => $outsiderEmail, 'password' => $password, 'role' => 'freelancer']);
$jarOutsider = loginAs($BASE, $outsiderEmail, $password);
[$code, $j] = http('GET', "$BASE/chats.php?project_id=$projId", null, $jarOutsider);
check('pihak ketiga ditolak akses chat proyek', $code === 403, json_encode($j));
$outsiderId = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($outsiderEmail))->fetchColumn();

// ── 19) Admin menghapus akun user (freelancer outsider) ──────────────────
echo "=== 19. Admin menghapus akun user ===\n";
[$code, $j] = http('DELETE', "$BASE/users.php?id=$outsiderId", null, $jarAdmin);
check('admin berhasil hapus user', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));
$stillThere = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id=$outsiderId")->fetchColumn();
check('user benar-benar hilang dari DB', $stillThere === 0);

// ── Bersih-bersih ────────────────────────────────────────────────────────
echo "=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM chats WHERE project_id = $projId");
$pdo->exec("DELETE FROM project_applicants WHERE project_id = $projId");
$pdo->exec("DELETE FROM project_categories WHERE project_id = $projId");
$pdo->exec("DELETE FROM projects WHERE id = $projId");
$pdo->exec("DELETE FROM user_skills WHERE user_id IN ($flId, $umkmId)");
$pdo->exec("DELETE FROM user_interests WHERE user_id IN ($flId, $umkmId)");
$pdo->exec("DELETE FROM users WHERE id IN ($flId, $umkmId, $outsiderId)");
@unlink($jarFl);
@unlink($jarUmkm);
@unlink($jarAdmin);
@unlink($jarOutsider);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
