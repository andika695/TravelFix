<?php
/**
 * tests/e2e_admin_dashboard_widgets_test.php — Verifikasi 3 widget baru di
 * Admin Dashboard yang menggantikan komponen dummy peninggalan template
 * travel ("Proyek Terpopuler" hardcoded & "Desa & UMKM Paling Aktif"
 * hardcoded): Aktivitas Terbaru, 5 Proyek Terbaru, Kategori Proyek Populer.
 * Ketiganya dipasok dari api/dashboard_stats.php (endpoint yang sama dengan
 * grafik pertumbuhan) lewat 3 key baru: recentActivity, recentProjects,
 * topCategories.
 *
 * Cara pakai: php.exe tests/e2e_admin_dashboard_widgets_test.php http://127.0.0.1:PORT/api
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
    $jar = tempnam(sys_get_temp_dir(), 'e2e_admin_widgets_');
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

$pdo->exec("DELETE FROM users WHERE email IN ('test_dashwidget_admin@test.local', 'test_dashwidget_umkm@test.local')");
$pdo->exec("DELETE FROM projects WHERE title = '[TEST-DASHWIDGET] Proyek Uji Widget'");

// ── Fixtures ───────────────────────────────────────────────────────────
// Catatan: users.created_at/projects.created_at bertipe DATETIME (presisi
// detik, bukan mikrodetik) -- insert admin/umkm/proyek yg dieksekusi cepat
// berurutan BISA jatuh di detik yang SAMA PERSIS, membuat urutan "terbaru"
// jadi ambigu di level DB (MySQL sendiri tidak menyimpan info urutan
// sub-detik). Supaya test ini menguji LOGIKA gabung+urut (bukan kebetulan
// presisi jam), user fixture sengaja diberi created_at 1 MENIT LEBIH AWAL
// -- proyek uji jadi TAK AMBIGU sebagai aktivitas paling baru.
$adminPassword = 'testdashwidget123';
$pdo->exec("INSERT INTO users (name, email, password, role, account_status, created_at)
            VALUES ('Test DashWidget Admin', 'test_dashwidget_admin@test.local', '" . password_hash($adminPassword, PASSWORD_DEFAULT) . "', 'admin', 'Active', NOW() - INTERVAL 1 MINUTE)");
$adminId = (int) $pdo->lastInsertId();

$umkmPassword = 'testdashwidget123';
$pdo->exec("INSERT INTO users (name, email, password, role, account_status, business_name, created_at)
            VALUES ('Test DashWidget UMKM Owner', 'test_dashwidget_umkm@test.local', '" . password_hash($umkmPassword, PASSWORD_DEFAULT) . "', 'umkm', 'Active', 'Toko Uji DashWidget', NOW() - INTERVAL 1 MINUTE)");
$umkmId = (int) $pdo->lastInsertId();

// Proyek uji dgn budget & 2 kategori spesifik -- created_at 30 detik lebih
// awal dari proyek ke-2 (langkah 6 di bawah), supaya urutan "terbaru" antar
// keduanya tak ambigu (lihat catatan presisi DATETIME di atas), tapi tetap
// lebih baru dari admin/umkm (1 menit lebih awal) supaya jadi aktivitas #1.
$stmt = $pdo->prepare("INSERT INTO projects (created_by, title, description, budget, project_status, created_at, updated_at)
                        VALUES (?, '[TEST-DASHWIDGET] Proyek Uji Widget', 'uji widget dashboard admin', 1750000, 'Open', NOW() - INTERVAL 30 SECOND, NOW())");
$stmt->execute([$umkmId]);
$projId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO project_categories (project_id, category) VALUES (?, '[TEST-DASHWIDGET] KategoriUjiA')")->execute([$projId]);
$pdo->prepare("INSERT INTO project_categories (project_id, category) VALUES (?, '[TEST-DASHWIDGET] KategoriUjiB')")->execute([$projId]);

$jarAdmin = loginAs($BASE, 'test_dashwidget_admin@test.local', $adminPassword);

// ── 1) Non-admin ditolak (gate lama, sekadar sanity check masih utuh) ────
echo "=== 1. dashboard_stats.php tetap wajib admin ===\n";
$jarUmkm = loginAs($BASE, 'test_dashwidget_umkm@test.local', $umkmPassword);
[$code, $j] = http('GET', "$BASE/dashboard_stats.php", null, $jarUmkm);
check('UMKM ditolak (403)', $code === 403, json_encode($j));

// ── 2) Admin bisa akses & response memuat 3 key baru ─────────────────────
echo "=== 2. Admin mengakses dashboard_stats.php: 3 key baru ada ===\n";
[$code, $j] = http('GET', "$BASE/dashboard_stats.php", null, $jarAdmin);
check('status 200 & success', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));
check('key recentActivity ada (array)', isset($j['recentActivity']) && is_array($j['recentActivity']));
check('key recentProjects ada (array)', isset($j['recentProjects']) && is_array($j['recentProjects']));
check('key topCategories ada (array)', isset($j['topCategories']) && is_array($j['topCategories']));
check('recentActivity maksimal 5 item', count($j['recentActivity']) <= 5);
check('recentProjects maksimal 5 item', count($j['recentProjects']) <= 5);
check('topCategories maksimal 5 item', count($j['topCategories']) <= 5);

// ── 3) "5 Proyek Terbaru": proyek uji (baru dibuat) harus di posisi #1 ───
echo "=== 3. 5 Proyek Terbaru: proyek uji ada di posisi teratas dgn data benar ===\n";
$top = $j['recentProjects'][0] ?? null;
check('proyek uji ada di posisi #1 (created_at terbaru)', $top && $top['title'] === '[TEST-DASHWIDGET] Proyek Uji Widget', json_encode($top));
check('budget sesuai', $top && (float)$top['budget'] === 1750000.00, json_encode($top['budget'] ?? null));
check('creator_name = nama bisnis UMKM (bukan nama pribadi)', $top && $top['creator_name'] === 'Toko Uji DashWidget', json_encode($top['creator_name'] ?? null));
check('status = Open', $top && $top['status'] === 'Open', json_encode($top['status'] ?? null));

// ── 4) "Aktivitas Terbaru": entri proyek uji harus ada & jenisnya benar ──
echo "=== 4. Aktivitas Terbaru: entri proyek uji ada dgn tipe project_created ===\n";
$activityTop = $j['recentActivity'][0] ?? null;
check('entri teratas adalah proyek uji (created_at paling baru)', $activityTop && str_contains($activityTop['title'] ?? '', '[TEST-DASHWIDGET] Proyek Uji Widget'), json_encode($activityTop));
check('type = project_created', $activityTop && $activityTop['type'] === 'project_created', json_encode($activityTop['type'] ?? null));

// ── 5) "Kategori Proyek Populer": 2 kategori uji (count=1 masing²) muncul ─
echo "=== 5. Kategori Proyek Populer: kategori uji muncul dgn count benar ===\n";
$catNames = array_column($j['topCategories'], 'category');
check('KategoriUjiA muncul di daftar top', in_array('[TEST-DASHWIDGET] KategoriUjiA', $catNames, true), json_encode($catNames));
check('KategoriUjiB muncul di daftar top', in_array('[TEST-DASHWIDGET] KategoriUjiB', $catNames, true), json_encode($catNames));
foreach ($j['topCategories'] as $cat) {
    if (str_starts_with($cat['category'], '[TEST-DASHWIDGET]')) {
        check("count kategori '{$cat['category']}' = 1", (int)$cat['total'] === 1, json_encode($cat));
    }
}

// ── 6) Tambah kategori umum kedua supaya urutan GROUP BY teruji (bukan cuma 1 baris) ──
echo "=== 6. Tambah proyek ke-2 pakai kategori sama -> total kategori itu harus jadi 2 & naik urutan ===\n";
$stmt2 = $pdo->prepare("INSERT INTO projects (created_by, title, description, budget, project_status, created_at, updated_at)
                         VALUES (?, '[TEST-DASHWIDGET] Proyek Uji Widget 2', 'uji widget dashboard admin ke-2', 500000, 'Open', NOW(), NOW())");
$stmt2->execute([$umkmId]);
$projId2 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO project_categories (project_id, category) VALUES (?, '[TEST-DASHWIDGET] KategoriUjiA')")->execute([$projId2]);

[$code, $j2] = http('GET', "$BASE/dashboard_stats.php", null, $jarAdmin);
$catA = null;
foreach ($j2['topCategories'] as $cat) {
    if ($cat['category'] === '[TEST-DASHWIDGET] KategoriUjiA') $catA = $cat;
}
check('KategoriUjiA sekarang total=2 (GROUP BY re-hitung benar)', $catA && (int)$catA['total'] === 2, json_encode($catA));

// "5 Proyek Terbaru" sekarang harus menampilkan proyek ke-2 di posisi #1 (paling baru)
$top2 = $j2['recentProjects'][0] ?? null;
check('proyek ke-2 (paling baru) sekarang di posisi #1', $top2 && $top2['title'] === '[TEST-DASHWIDGET] Proyek Uji Widget 2', json_encode($top2));

// ── Bersih-bersih ─────────────────────────────────────────────────────
echo "=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM project_categories WHERE project_id IN ($projId, $projId2)");
$pdo->exec("DELETE FROM projects WHERE id IN ($projId, $projId2)");
$pdo->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$adminId, $umkmId]);
@unlink($jarAdmin);
@unlink($jarUmkm);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
