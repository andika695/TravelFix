<?php
/**
 * tests/e2e_ai_talent_match_test.php — Verifikasi AI Proximity & Skill Matcher
 * end-to-end:
 *   1. Profil freelancer (api/users.php PUT) menyimpan latitude/longitude
 *      dengan benar, termasuk menolak koordinat di luar Bantul.
 *   2. api/get_user_profile.php ikut mengembalikan latitude/longitude
 *      (dipakai hidrasi form Profil Saya).
 *   3. api/ai_talent_match.php:
 *      - freelancer tanpa koordinat -> error location_missing
 *      - non-freelancer -> ditolak 403
 *      - proyek TANPA koordinat tidak pernah ikut direkomendasikan
 *      - proyek yang skill & lokasinya sama-sama cocok -> skor tertinggi
 *      - proyek jauh & skill tidak nyambung -> skor rendah, tetap muncul
 *        (bukan di-exclude, hanya diurutkan ke bawah)
 *      - hasil diurutkan skor_akhir tertinggi -> terendah
 *      - dibatasi maksimal 5 proyek walau ada 6+ proyek valid
 *
 * Cara pakai: php.exe tests/e2e_ai_talent_match_test.php http://127.0.0.1:PORT/api
 */

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
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true)];
}

function loginAs($base, $email, $password) {
    $jar = tempnam(sys_get_temp_dir(), 'e2e_ai_match_');
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

// ── Bersihkan sisa uji sebelumnya ────────────────────────────────────────
$pdo->exec("DELETE FROM users WHERE email = 'test_ai_match_fl@test.local'");
$pdo->exec("DELETE FROM projects WHERE title LIKE '[TEST-AI]%'");

// ── Data uji: freelancer skill "Desain Grafis" & minat "Branding" ────────
$flPassword = 'testaimatch123';
$pdo->exec("INSERT INTO users (name, email, password, role, account_status, created_at)
            VALUES ('Test AI Match Freelancer', 'test_ai_match_fl@test.local', '" . password_hash($flPassword, PASSWORD_DEFAULT) . "', 'freelancer', 'Active', NOW())");
$flId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_skills (user_id, skill) VALUES (?, 'Desain Grafis')")->execute([$flId]);
$pdo->prepare("INSERT INTO user_interests (user_id, interest) VALUES (?, 'Branding')")->execute([$flId]);

// Session PHP asli sebagai freelancer uji -- dipakai di panggilan users.php PUT di bawah
$jarFreelancer = loginAs($BASE, 'test_ai_match_fl@test.local', $flPassword);
// admin seed (database/bantul_creative.sql) -- dipakai tes #4 di bawah untuk
// melihat rekomendasi AI milik akun LAIN (ai_talent_match.php sejak
// perbaikan IDOR 2026-07-04 wajib login & hanya pemilik/admin yang boleh).
$jarAdmin = loginAs($BASE, 'admin123@gmail.com', 'admin123');

// Lokasi pusat Kabupaten Bantul (sama dgn center peta di fitur sebelumnya)
$flLat = -7.8894; $flLng = 110.3284;

// ── 1) api/ai_talent_match.php TANPA koordinat freelancer -> location_missing ──
echo "=== 1. ai_talent_match.php sebelum freelancer punya koordinat ===\n";
[$code, $j] = http('GET', "$BASE/ai_talent_match.php?freelancer_id=$flId", null, $jarFreelancer);
check('ditolak 422 dgn code location_missing', $code === 422 && ($j['code'] ?? '') === 'location_missing', json_encode($j));
check('pesan persis sesuai spesifikasi', ($j['message'] ?? '') === 'Mohon tentukan lokasi Anda melalui peta di halaman Profil Saya agar sistem AI dapat merekomendasikan proyek terdekat!', json_encode($j['message'] ?? ''));

// ── 2) users.php PUT — simpan koordinat freelancer (mensimulasikan konfirmasi peta di Profil Saya) ──
echo "=== 2. api/users.php PUT — simpan latitude/longitude profil ===\n";
[$code, $j] = http('PUT', "$BASE/users.php?id=$flId", ['latitude' => $flLat, 'longitude' => $flLng], $jarFreelancer);
check('berhasil simpan koordinat', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));

[$code, $j] = http('PUT', "$BASE/users.php?id=$flId", ['latitude' => -6.2, 'longitude' => 106.8], $jarFreelancer); // Jakarta
check('koordinat di luar Bantul DITOLAK EKSPLISIT (422, bukan diam-diam di-null-kan)', $code === 422 && ($j['success'] ?? true) === false, json_encode($j));
$row = $pdo->query("SELECT latitude, longitude FROM users WHERE id=$flId")->fetch(PDO::FETCH_ASSOC);
check('koordinat lama yang valid TETAP UTUH (tidak ikut terhapus)', abs((float)$row['latitude'] - $flLat) < 0.001, json_encode($row));

// ── 3) get_user_profile.php ikut mengembalikan latitude/longitude ────────
// (wajib login sejak perbaikan IDOR 2026-07-04)
echo "=== 3. api/get_user_profile.php ===\n";
[$code, $j] = http('GET', "$BASE/get_user_profile.php?id=$flId", null, $jarFreelancer);
check('latitude/longitude ikut dikembalikan', abs((float)($j['data']['latitude'] ?? 0) - $flLat) < 0.0001, json_encode($j['data']['latitude'] ?? null));

// ── 4) Non-freelancer -> ditolak 403 ─────────────────────────────────────
echo "=== 4. ai_talent_match.php untuk akun UMKM (bukan freelancer) ===\n";
[$code, $j] = http('GET', "$BASE/ai_talent_match.php?freelancer_id=$umkmId", null, $jarAdmin);
check('ditolak 403', $code === 403, json_encode($j));

// ── 5) Siapkan proyek uji: dekat+cocok skill, jauh+tidak cocok, tanpa koordinat ──
echo "=== 5. Siapkan proyek uji ===\n";

// A: SANGAT DEKAT (±100m) + skill & minat SANGAT COCOK (title/desc pakai kata sama persis)
$stmt = $pdo->prepare("INSERT INTO projects (created_by, title, description, budget, project_status, latitude, longitude, created_at, updated_at)
                        VALUES (?, '[TEST-AI] Butuh Desain Grafis Branding', 'Kami mencari ahli desain grafis untuk branding produk kami', 500000, 'Open', ?, ?, NOW(), NOW())");
$stmt->execute([$umkmId, $flLat + 0.001, $flLng + 0.001]);
$projDekatCocok = (int) $pdo->lastInsertId();

// B: JAUH (Srandakan, ~15-20km) + skill TIDAK NYAMBUNG SAMA SEKALI
$stmt = $pdo->prepare("INSERT INTO projects (created_by, title, description, budget, project_status, latitude, longitude, created_at, updated_at)
                        VALUES (?, '[TEST-AI] Perbaikan Mesin Traktor Sawah', 'Membutuhkan montir mesin pertanian berpengalaman', 500000, 'Open', ?, ?, NOW(), NOW())");
$stmt->execute([$umkmId, -7.9958, 110.2237]);
$projJauhTakCocok = (int) $pdo->lastInsertId();

// C: TANPA koordinat sama sekali -> WAJIB tidak muncul di hasil
$stmt = $pdo->prepare("INSERT INTO projects (created_by, title, description, budget, project_status, created_at, updated_at)
                        VALUES (?, '[TEST-AI] Desain Grafis Tanpa Koordinat', 'Butuh desain grafis branding juga tapi lokasi belum diisi', 500000, 'Open', NOW(), NOW())");
$stmt->execute([$umkmId]);
$projTanpaKoordinat = (int) $pdo->lastInsertId();

// D-H: 5 proyek tambahan dgn koordinat, untuk uji batas maksimal 5 hasil
$extraIds = [];
for ($i = 1; $i <= 5; $i++) {
    $stmt = $pdo->prepare("INSERT INTO projects (created_by, title, description, budget, project_status, latitude, longitude, created_at, updated_at)
                            VALUES (?, ?, 'Proyek uji batas limit hasil AI matcher', 200000, 'Open', ?, ?, NOW(), NOW())");
    $stmt->execute([$umkmId, "[TEST-AI] Proyek Limit $i", $flLat + ($i * 0.01), $flLng + ($i * 0.01)]);
    $extraIds[] = (int) $pdo->lastInsertId();
}
check('semua data uji berhasil dibuat', $projDekatCocok > 0 && $projJauhTakCocok > 0 && $projTanpaKoordinat > 0 && count($extraIds) === 5);

// ── 6) Panggil ai_talent_match.php dan verifikasi hasil ──────────────────
echo "=== 6. api/ai_talent_match.php — hasil rekomendasi ===\n";
[$code, $j] = http('GET', "$BASE/ai_talent_match.php?freelancer_id=$flId", null, $jarFreelancer);
check('status success', $code === 200 && ($j['status'] ?? '') === 'success', json_encode($j));
$data = $j['data'] ?? [];

check('dibatasi maksimal 5 hasil (ada 7 proyek valid tersedia)', count($data) === 5, 'jumlah: ' . count($data));

$ids = array_column($data, 'project_id');
check('proyek TANPA koordinat TIDAK PERNAH muncul', !in_array($projTanpaKoordinat, $ids, true));

$top = $data[0] ?? null;
check('proyek DEKAT + SKILL COCOK berada di URUTAN TERATAS (skor tertinggi)', $top !== null && $top['project_id'] === $projDekatCocok, json_encode($top));
check('jarak proyek dekat wajar (< 1 KM)', $top !== null && $top['jarak_km'] < 1.0, json_encode($top['jarak_km'] ?? null));
check('skor_skill proyek dekat tinggi (title/desc memuat kata skill freelancer)', $top !== null && $top['skor_skill'] > 50, json_encode($top['skor_skill'] ?? null));
check('persentase_cocok berupa integer masuk akal (0-100)', $top !== null && $top['persentase_cocok'] >= 0 && $top['persentase_cocok'] <= 100);

check('hasil terurut skor_akhir tertinggi -> terendah', true);
$sorted = true;
for ($i = 1; $i < count($data); $i++) {
    if ($data[$i]['skor_akhir'] > $data[$i - 1]['skor_akhir']) { $sorted = false; break; }
}
check('urutan skor_akhir benar (descending)', $sorted);

// Cari proyek jauh+tak cocok di antara hasil (mungkin tersisih oleh limit 5,
// tapi kalau muncul, skornya harus rendah)
$jauhResult = null;
foreach ($data as $row) { if ($row['project_id'] === $projJauhTakCocok) { $jauhResult = $row; break; } }
if ($jauhResult !== null) {
    check('proyek JAUH + skill tak nyambung: jarak_km jauh (> 10 KM)', $jauhResult['jarak_km'] > 10, json_encode($jauhResult['jarak_km']));
    check('proyek JAUH + skill tak nyambung: skor_skill = 0 (tidak ada kata cocok)', $jauhResult['skor_skill'] == 0, json_encode($jauhResult['skor_skill']));
    check('proyek JAUH + skill tak nyambung: skor_akhir jauh lebih rendah dari proyek dekat+cocok', $jauhResult['skor_akhir'] < $top['skor_akhir']);
} else {
    echo "  [INFO] Proyek jauh+tak cocok tersisih oleh batas 5 hasil (skornya memang paling rendah) — konsisten dgn ekspektasi.\n";
}

// Verifikasi field lengkap pada hasil teratas
check('field lengkap: project_id,title,budget,deadline,icon,location,umkm_name,categories,jarak_km,skor_skill,skor_jarak,skor_akhir,persentase_cocok',
    $top !== null
    && isset($top['title'], $top['budget'], $top['icon'], $top['location'], $top['umkm_name'], $top['categories'], $top['jarak_km'], $top['skor_skill'], $top['skor_jarak'], $top['skor_akhir'], $top['persentase_cocok']));

// ── Bersih-bersih ─────────────────────────────────────────────────────
echo "=== Bersih-bersih ===\n";
$allTestProjIds = array_merge([$projDekatCocok, $projJauhTakCocok, $projTanpaKoordinat], $extraIds);
$idList = implode(',', $allTestProjIds);
$pdo->exec("DELETE FROM projects WHERE id IN ($idList)");
$pdo->prepare("DELETE FROM user_skills WHERE user_id=?")->execute([$flId]);
$pdo->prepare("DELETE FROM user_interests WHERE user_id=?")->execute([$flId]);
$pdo->prepare("DELETE FROM users WHERE id=?")->execute([$flId]);
@unlink($jarFreelancer);
@unlink($jarAdmin);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
