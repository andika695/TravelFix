<?php
/**
 * tests/e2e_public_email_test.php — Verifikasi kolom users.public_email
 * ("Email Publik (Kontak)" di form Profil, WAJIB diisi sejak revisi
 * optimasi UI/UX profil). Sebelum revisi ini, field tsb ada di form tapi
 * TIDAK PERNAH tersimpan (mapUserUpdatesToApi() sengaja membuangnya karena
 * kolomnya belum ada) -- perubahan yang diketik user selalu diam-diam
 * hilang. Test ini membuktikan: (1) kolom terpisah dari users.email/login,
 * (2) PUT users.php benar-benar menyimpannya, (3) round-trip lewat
 * get_user_profile.php & me.php, (4) partial update tidak menghapus nilai
 * lama, (5) backfill migrasi V6 (public_email = email untuk akun lama).
 *
 * Cara pakai: php.exe tests/e2e_public_email_test.php http://127.0.0.1:PORT/api
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
    $jar = tempnam(sys_get_temp_dir(), 'e2e_pubemail_');
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

$pdo->exec("DELETE FROM users WHERE email = 'test_pubemail_fl@test.local'");

$loginPassword = 'testpubemail123';
$loginEmail    = 'test_pubemail_fl@test.local';
$pdo->exec("INSERT INTO users (name, email, password, role, account_status, whatsapp, created_at)
            VALUES ('Test PubEmail Freelancer', '$loginEmail', '" . password_hash($loginPassword, PASSWORD_DEFAULT) . "', 'freelancer', 'Active', '081200000000', NOW())");
$flId = (int) $pdo->lastInsertId();

$jar = loginAs($BASE, $loginEmail, $loginPassword);

// ── 1) Baris baru: public_email belum diisi (bukan auto-copy dari email login,
//      backfill migrasi V6 hanya berlaku sekali saat kolom pertama dibuat) ──
echo "=== 1. Baris baru: public_email masih kosong sampai diisi eksplisit ===\n";
$initial = (string) $pdo->query("SELECT public_email FROM users WHERE id = $flId")->fetchColumn();
check('public_email default NULL/kosong untuk akun baru', $initial === '');

// ── 2) PUT users.php dengan public_email BERBEDA dari email login ────────
echo "=== 2. PUT users.php menyimpan public_email (berbeda dari email login) ===\n";
$publicEmail = 'kontak-publik-berbeda@test.local';
[$code, $j] = http('PUT', "$BASE/users.php?id=$flId", ['public_email' => $publicEmail], $jar);
check('PUT sukses', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));

$stored = (string) $pdo->query("SELECT public_email FROM users WHERE id = $flId")->fetchColumn();
check('tersimpan persis di DB', $stored === $publicEmail, "got=$stored");

$loginEmailStillIntact = (string) $pdo->query("SELECT email FROM users WHERE id = $flId")->fetchColumn();
check('users.email (login) TIDAK ikut berubah', $loginEmailStillIntact === $loginEmail, "got=$loginEmailStillIntact");

// ── 3) Round-trip lewat get_user_profile.php ──────────────────────────────
echo "=== 3. get_user_profile.php mengembalikan public_email yang benar ===\n";
[$code, $j] = http('GET', "$BASE/get_user_profile.php?id=$flId", null, $jar);
check('status success', $code === 200 && ($j['status'] ?? '') === 'success', json_encode($j));
check('public_email sesuai yang disimpan', ($j['data']['public_email'] ?? null) === $publicEmail, json_encode($j['data']['public_email'] ?? null));

// ── 4) Round-trip lewat me.php (dipakai precheck & Progress Bar Kelengkapan Profil) ──
echo "=== 4. me.php (dipakai Progress Bar Kelengkapan Profil) mengembalikan public_email ===\n";
[$code, $j] = http('GET', "$BASE/me.php", null, $jar);
check('status success', $code === 200 && ($j['status'] ?? '') === 'success', json_encode($j));
check('public_email sesuai yang disimpan', ($j['data']['public_email'] ?? null) === $publicEmail, json_encode($j['data']['public_email'] ?? null));

// ── 5) Partial update (field lain, TANPA public_email) tidak menghapusnya ──
echo "=== 5. PUT tanpa public_email di body TIDAK menghapus nilai yang sudah ada ===\n";
[$code, $j] = http('PUT', "$BASE/users.php?id=$flId", ['bio' => 'Bio uji partial update'], $jar);
check('PUT sukses', $code === 200 && ($j['success'] ?? false) === true, json_encode($j));
$stillThere = (string) $pdo->query("SELECT public_email FROM users WHERE id = $flId")->fetchColumn();
check('public_email TIDAK berubah/hilang', $stillThere === $publicEmail, "got=$stillThere");

// ── 6) Backfill migrasi V6: akun LAMA (dibuat sebelum kolom ada secara
//      konsep) harus punya public_email terisi, bukan NULL kosong selamanya --
//      diverifikasi via akun admin seed yang sudah ada sejak awal proyek. ──
echo "=== 6. Backfill migrasi V6 untuk akun lama (bukan baris baru) ===\n";
$seedRow = $pdo->query("SELECT email, public_email FROM users WHERE email = 'admin123@gmail.com' LIMIT 1")->fetch();
if ($seedRow) {
    check('akun seed lama punya public_email terisi (backfill dari email)', !empty($seedRow['public_email']), json_encode($seedRow));
} else {
    echo "  [INFO] Akun seed admin123@gmail.com tidak ditemukan -- lewati pengecekan backfill (bukan kegagalan).\n";
}

// ── Bersih-bersih ─────────────────────────────────────────────────────
echo "=== Bersih-bersih ===\n";
$pdo->prepare("DELETE FROM users WHERE id=?")->execute([$flId]);
@unlink($jar);
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
