<?php
/**
 * tests/e2e_apply_location_test.php — Verifikasi validasi Lokasi wajib
 * sebelum freelancer bisa melamar proyek (api/apply_project.php), lewat
 * session PHP asli (freelancer_id tidak lagi dikirim di body -- server
 * selalu memakai identitas dari session yang sedang login).
 *
 * Cara pakai: php.exe tests/e2e_apply_location_test.php http://127.0.0.1:PORT/api
 */

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

function loginAs($base, $email, $password) {
    $jar = tempnam(sys_get_temp_dir(), 'e2e_apply_loc_');
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
$umkmId = (int) $pdo->query("SELECT id FROM users WHERE role='umkm' LIMIT 1")->fetchColumn();

$pdo->exec("DELETE FROM users WHERE email = 'test_apply_loc_fl@test.local'");
$pdo->exec("DELETE FROM projects WHERE title = '[TEST-APPLY-LOC] Proyek Uji'");

// Freelancer SUDAH punya skill & minat, TAPI belum punya lokasi/koordinat
$flPassword = 'testapplyloc123';
$pdo->exec("INSERT INTO users (name, email, password, role, account_status, created_at)
            VALUES ('Test Apply Loc Freelancer', 'test_apply_loc_fl@test.local', '" . password_hash($flPassword, PASSWORD_DEFAULT) . "', 'freelancer', 'Active', NOW())");
$flId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_skills (user_id, skill) VALUES (?, 'Copywriting')")->execute([$flId]);
$pdo->prepare("INSERT INTO user_interests (user_id, interest) VALUES (?, 'Konten Digital')")->execute([$flId]);

$stmt = $pdo->prepare("INSERT INTO projects (created_by, title, description, budget, project_status, created_at, updated_at)
                        VALUES (?, '[TEST-APPLY-LOC] Proyek Uji', 'uji validasi lokasi sebelum melamar', 400000, 'Open', NOW(), NOW())");
$stmt->execute([$umkmId]);
$projId = (int) $pdo->lastInsertId();

// Session PHP asli sebagai freelancer uji -- dipakai di semua request di bawah
$jarFreelancer = loginAs($BASE, 'test_apply_loc_fl@test.local', $flPassword);

// ── 1) Lamar TANPA lokasi -> harus ditolak dengan code location_missing ──
echo "=== 1. Lamar proyek TANPA lokasi terisi (skill & minat SUDAH ada) ===\n";
[$code, $j] = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $jarFreelancer);
check('ditolak 422 dengan code location_missing', $code === 422 && ($j['code'] ?? '') === 'location_missing', json_encode($j));
check('pesan persis sesuai spesifikasi', ($j['message'] ?? '') === 'Anda diwajibkan mengisi Lokasi di profil Anda sebelum melamar proyek ini! Silakan lengkapi lewat halaman Profil Saya.', json_encode($j['message'] ?? ''));

$countBefore = (int) $pdo->query("SELECT COUNT(*) FROM project_applicants WHERE project_id=$projId AND freelancer_id=$flId")->fetchColumn();
check('lamaran TIDAK tersimpan ke database', $countBefore === 0);

// ── 2) Isi lokasi teks saja TANPA koordinat -> masih harus ditolak ───────
echo "=== 2. Lokasi teks terisi tapi koordinat masih kosong ===\n";
$pdo->exec("UPDATE users SET location = 'Sewon, Bantul' WHERE id = $flId"); // tanpa lat/lng
[$code, $j] = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $jarFreelancer);
check('masih ditolak (lokasi teks doang tidak cukup, koordinat wajib ada)', $code === 422 && ($j['code'] ?? '') === 'location_missing', json_encode($j));

// ── 3) Lengkapi lokasi + koordinat via users.php PUT (mensimulasikan Map Picker) ──
echo "=== 3. Lengkapi lokasi lewat api/users.php PUT (map picker, session freelancer) ===\n";
[$code, $j] = http('PUT', "$BASE/users.php?id=$flId", ['location' => 'Sewon, Bantul', 'latitude' => -7.85, 'longitude' => 110.35], $jarFreelancer);
check('profil berhasil diperbarui', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));

// ── 4) Lamar SETELAH lokasi lengkap -> harus berhasil ─────────────────────
echo "=== 4. Lamar proyek SETELAH lokasi & koordinat lengkap ===\n";
[$code, $j] = http('POST', "$BASE/apply_project.php", ['project_id' => $projId], $jarFreelancer);
check('berhasil melamar (201/success)', $code === 201 && ($j['status'] ?? '') === 'success', json_encode($j));
$countAfter = (int) $pdo->query("SELECT COUNT(*) FROM project_applicants WHERE project_id=$projId AND freelancer_id=$flId")->fetchColumn();
check('lamaran tersimpan ke database', $countAfter === 1);

// ── Bersih-bersih ─────────────────────────────────────────────────────
echo "=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM project_applicants WHERE project_id = $projId");
$pdo->exec("DELETE FROM projects WHERE id = $projId");
$pdo->prepare("DELETE FROM user_skills WHERE user_id=?")->execute([$flId]);
$pdo->prepare("DELETE FROM user_interests WHERE user_id=?")->execute([$flId]);
$pdo->prepare("DELETE FROM users WHERE id=?")->execute([$flId]);
@unlink($jarFreelancer);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
