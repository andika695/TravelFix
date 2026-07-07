<?php
/**
 * tests/e2e_authz_test.php — Verifikasi otorisasi berbasis SESSION PHP
 * (users.php suspend/PUT/DELETE, projects.php update_status/DELETE) yang
 * menggantikan model lama (actor_id dipercaya mentah dari body/query request),
 * sekaligus memastikan alur sah (self-edit profil, admin sungguhan) tidak
 * ikut terblokir, DAN memastikan actor_id yang di-spoof di body/query
 * benar-benar diabaikan oleh server (session yang menang).
 *
 * Cara pakai: php.exe tests/e2e_authz_test.php http://127.0.0.1:PORT/api
 */

// Hanya boleh dijalankan lewat CLI — lihat catatan yang sama di
// tests/e2e_review_link_test.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: skrip ini hanya boleh dijalankan lewat CLI.');
}

$BASE = $argv[1] ?? 'http://127.0.0.1/travelfix-main/api';
$pass = 0; $fail = 0;

/** Request dengan dukungan cookie jar (session PHP) via curl. */
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

/** Login lalu kembalikan path cookie jar (session) untuk dipakai request berikutnya. */
function loginAs($base, $email, $password) {
    $jar = tempnam(sys_get_temp_dir(), 'e2e_authz_');
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

// Akun admin seed (dibuat oleh database/bantul_creative.sql) -- password
// plaintext-nya diketahui, dipakai untuk login sungguhan di tes ini.
$adminEmail = 'admin123@gmail.com';
$adminPassword = 'admin123';
$adminId = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($adminEmail))->fetchColumn();
if (!$adminId) { fwrite(STDERR, "Akun admin seed ($adminEmail) tidak ditemukan di database.\n"); exit(1); }

// ── Data uji: freelancer & UMKM dengan password diketahui, supaya bisa login sungguhan ──
$pdo->exec("DELETE FROM users WHERE email = 'test_authz_fl@test.local'");
$pdo->exec("DELETE FROM projects WHERE title = '[TEST-AUTHZ] Proyek Uji'");
$flPassword = 'testauthz123';
$pdo->exec("INSERT INTO users (name, email, password, role, account_status, created_at)
            VALUES ('Test Authz Freelancer', 'test_authz_fl@test.local', '" . password_hash($flPassword, PASSWORD_DEFAULT) . "', 'freelancer', 'Active', NOW())");
$flId = (int) $pdo->lastInsertId();

$umkmRow = $pdo->query("SELECT id, email FROM users WHERE role='umkm' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$umkmId  = (int) $umkmRow['id'];
$stmt = $pdo->prepare("INSERT INTO projects (created_by, title, description, budget, project_status, created_at, updated_at)
                        VALUES (?, '[TEST-AUTHZ] Proyek Uji', 'uji otorisasi', 100000, 'Open', NOW(), NOW())");
$stmt->execute([$umkmId]);
$projId = (int) $pdo->lastInsertId();

// ── Login sungguhan sebagai masing-masing role (session PHP asli) ──────────
$jarFreelancer = loginAs($BASE, 'test_authz_fl@test.local', $flPassword);
$jarAdmin      = loginAs($BASE, $adminEmail, $adminPassword);

// ── 1) users.php PUT — self-edit profil (session freelancer, actor_id di body di-spoof) ──
echo "=== 1. users.php PUT (self-edit profil via session, actor_id body di-spoof) ===\n";
[$code, $j] = http('PUT', "$BASE/users.php?id=$flId", ['bio' => 'Uji bio', 'actor_id' => 999999], $jarFreelancer);
check('self-edit profil berhasil (actor_id spoof diabaikan, session yang dipakai)', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));

// ── 2) users.php PUT — session freelancer coba edit profil ORANG LAIN -> ditolak ──
echo "=== 2. users.php PUT (session freelancer coba edit profil UMKM lain) ===\n";
[$code, $j] = http('PUT', "$BASE/users.php?id=$umkmId", ['bio' => 'Diretas!'], $jarFreelancer);
check('ditolak 403 (freelancer coba edit profil UMKM)', $code === 403 && ($j['success'] ?? true) === false, json_encode($j));

// ── 3) users.php suspend TANPA login sama sekali -> ditolak ────────────────
echo "=== 3. users.php suspend TANPA session/login ===\n";
[$code, $j] = http('POST', "$BASE/users.php?action=suspend&id=$flId", null, null);
check('ditolak (tanpa session)', in_array($code, [401, 403], true), json_encode($j));

// ── 4) users.php suspend dgn session freelancer (bukan admin) -> ditolak ───
echo "=== 4. users.php suspend dgn session freelancer (bukan admin) ===\n";
[$code, $j] = http('POST', "$BASE/users.php?action=suspend&id=$flId", null, $jarFreelancer);
check('ditolak 403 (session bukan admin)', $code === 403, json_encode($j));

// ── 5) users.php suspend dgn session admin sungguhan -> berhasil ───────────
echo "=== 5. users.php suspend dgn session admin sungguhan ===\n";
[$code, $j] = http('POST', "$BASE/users.php?action=suspend&id=$flId", null, $jarAdmin);
check('berhasil (session admin)', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));
// Kembalikan ke Active lagi
http('POST', "$BASE/users.php?action=suspend&id=$flId", null, $jarAdmin);

// ── 6) projects.php update_status TANPA session -> ditolak ─────────────────
echo "=== 6. projects.php update_status TANPA session ===\n";
[$code, $j] = http('POST', "$BASE/projects.php?action=update_status", ['id' => $projId, 'status' => 'Closed'], null);
check('ditolak', in_array($code, [401, 403], true), json_encode($j));
$row = $pdo->query("SELECT project_status FROM projects WHERE id=$projId")->fetch(PDO::FETCH_ASSOC);
check('status TIDAK berubah (masih Open)', $row['project_status'] === 'Open', json_encode($row));

// ── 7) projects.php update_status dgn session UMKM (bukan admin) -> ditolak ──
echo "=== 7. projects.php update_status dgn session UMKM (bukan admin) ===\n";
// UMKM tidak punya password diketahui secara umum -- pakai session freelancer
// sebagai representasi "bukan admin" (cukup untuk membuktikan non-admin ditolak).
[$code, $j] = http('POST', "$BASE/projects.php?action=update_status", ['id' => $projId, 'status' => 'Closed'], $jarFreelancer);
check('ditolak 403', $code === 403, json_encode($j));

// ── 8) projects.php update_status dgn session admin -> berhasil ────────────
echo "=== 8. projects.php update_status dgn session admin sungguhan ===\n";
[$code, $j] = http('POST', "$BASE/projects.php?action=update_status", ['id' => $projId, 'status' => 'Closed'], $jarAdmin);
check('berhasil', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));

// ── 9) users.php DELETE tanpa session -> ditolak, data tidak hilang ────────
echo "=== 9. users.php DELETE tanpa session ===\n";
[$code, $j] = http('DELETE', "$BASE/users.php?id=$flId", null, null);
check('ditolak', in_array($code, [401, 403], true), json_encode($j));
$stillThere = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id=$flId")->fetchColumn();
check('user TIDAK terhapus', $stillThere === 1);

// ── 10) projects.php DELETE dgn session admin -> berhasil ──────────────────
echo "=== 10. projects.php DELETE dgn session admin sungguhan ===\n";
[$code, $j] = http('DELETE', "$BASE/projects.php?id=$projId", null, $jarAdmin);
check('berhasil', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));

// ── 11) projects.php action=create dgn session admin, created_by di-spoof jadi orang lain -> diabaikan ──
echo "=== 11. projects.php create: created_by di body di-spoof, harus dipakai session ===\n";
[$code, $j] = http('POST', "$BASE/projects.php?action=create", [
    'created_by' => $umkmId, // spoof -- HARUS diabaikan, harus tercatat sbg $adminId (session asli)
    'title' => '[TEST-AUTHZ] Spoof created_by', 'budget' => 1000, 'deadline' => '2026-08-01',
], $jarAdmin);
check('berhasil dibuat', $code === 201 && ($j['success'] ?? false) === true, json_encode($j));
$projId2 = !empty($j['id']) ? (int) $j['id'] : 0;
if ($projId2) {
    $createdByReal = (int) $pdo->query("SELECT created_by FROM projects WHERE id=$projId2")->fetchColumn();
    check('created_by = session asli (' . $adminId . '), BUKAN nilai spoof (' . $umkmId . ')', $createdByReal === $adminId, "created_by tersimpan: $createdByReal");
}

// ── 12) apply_project.php: freelancer_id di body di-spoof -> tetap pakai session ──
echo "=== 12. apply_project.php: freelancer_id di body di-spoof, harus dipakai session ===\n";
$pdo->exec("UPDATE users SET skills='Desain', interests='Branding', location='Bantul', latitude=-7.9, longitude=110.3 WHERE id=$flId");
if ($projId2) {
    [$code, $j] = http('POST', "$BASE/apply_project.php", [
        'project_id' => $projId2,
        'freelancer_id' => $adminId, // spoof -- HARUS diabaikan
    ], $jarFreelancer);
    check('lamaran berhasil (bukan atas nama yang di-spoof)', $code === 201 && ($j['data']['freelancer_id'] ?? null) === $flId, json_encode($j));
    $appliedAsReal  = (int) $pdo->query("SELECT COUNT(*) FROM project_applicants WHERE project_id=$projId2 AND freelancer_id=$flId")->fetchColumn();
    $appliedAsSpoof = (int) $pdo->query("SELECT COUNT(*) FROM project_applicants WHERE project_id=$projId2 AND freelancer_id=$adminId")->fetchColumn();
    check('lamaran tercatat atas session asli, BUKAN id yang di-spoof', $appliedAsReal === 1 && $appliedAsSpoof === 0, "asli=$appliedAsReal spoof=$appliedAsSpoof");
} else {
    check('lamaran berhasil (bukan atas nama yang di-spoof)', false, 'proyek uji #11 gagal dibuat, tes #12 dilewati');
}

// ── Bersih-bersih ─────────────────────────────────────────────────────────
echo "=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM project_applicants WHERE freelancer_id = $flId");
$pdo->exec("DELETE FROM projects WHERE title LIKE '[TEST-AUTHZ]%'");
$pdo->exec("DELETE FROM users WHERE id = $flId");
@unlink($jarFreelancer);
@unlink($jarAdmin);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
