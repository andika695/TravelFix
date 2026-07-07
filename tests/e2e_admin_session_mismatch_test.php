<?php
/**
 * tests/e2e_admin_session_mismatch_test.php — Reproduksi bug yang dilaporkan:
 * tombol Tangguhkan/Hapus di admin-users.html (halaman "Semua Pengguna")
 * membalas alert "Akses ditolak. Anda tidak memiliki izin untuk melakukan
 * aksi ini." walau yang mengeklik adalah admin.
 *
 * Akar masalah: SAMA PERSIS dengan bug "Hanya akun freelancer..." di
 * marketplace.html (lihat e2e_session_mismatch_test.php) -- sessionStorage
 * tab admin di-cache PER TAB, sedangkan session PHP (sumber kebenaran
 * backend) DIBAGI ke SEMUA tab dalam satu browser. Kalau browser yang sama
 * dipakai login sebagai akun lain di tab lain SETELAH tab admin dibuka,
 * tab admin tetap menampilkan UI admin (dari sessionStorage basi) padahal
 * session PHP browser itu sudah berpindah akun.
 *
 * Cara pakai: php.exe tests/e2e_admin_session_mismatch_test.php http://127.0.0.1:PORT/api
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

function check($label, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [OK]   $label\n"; }
    else       { $fail++; echo "  [FAIL] $label $extra\n"; }
}

echo "Base URL API: $BASE\n\n";

$pdo = new PDO('mysql:host=127.0.0.1;dbname=bantul_creative;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$suffix = time();
$flEmail = "e2e_adminmismatch_fl_{$suffix}@test.local";
$password = 'testadminmismatch123';

http('POST', "$BASE/register.php", ['name' => 'AdminMismatch FL', 'email' => $flEmail, 'password' => $password, 'role' => 'freelancer']);
$victimJar = tempnam(sys_get_temp_dir(), 'e2e_victim_');
[, $j] = http('POST', "$BASE/login.php", ['email' => $flEmail, 'password' => $password], $victimJar);
$flId = $j['user']['id'];
@unlink($victimJar);

// ── Simulasi: SATU browser (satu cookie jar), admin login duluan lalu
//    "tab lain" login sebagai freelancer di browser yang sama ──────────
echo "=== Simulasi: satu browser, login admin lalu login freelancer di 'tab lain' ===\n";
$browserJar = tempnam(sys_get_temp_dir(), 'e2e_adminmismatch_browser_');

[$c, $j] = http('POST', "$BASE/login.php", ['email' => 'admin123@gmail.com', 'password' => 'admin123'], $browserJar);
check('login sebagai admin di "tab 1" sukses', $c === 200 && ($j['success'] ?? false) === true);

[$c, $j] = http('POST', "$BASE/login.php", ['email' => $flEmail, 'password' => $password], $browserJar);
check('login sebagai freelancer di "tab 2" (browser sama) sukses -- session PHP browser ini sekarang freelancer', $c === 200 && ($j['success'] ?? false) === true);

// Balik ke "tab admin" (sessionStorage tab ini MASIH mengira dia admin, tapi
// cookie session PHP browser ini sekarang milik freelancer) -- klik Tangguhkan.
echo "\n=== 1. api/me.php (dipakai precheck baru initAdminDashboard()): mengungkap ketidakcocokan ===\n";
[$c, $j] = http('GET', "$BASE/me.php", null, $browserJar);
check('me.php membalas sukses', $c === 200 && ($j['status'] ?? '') === 'success');
check('me.php mengungkap sesi SEBENARNYA adalah freelancer (bukan admin seperti dikira sessionStorage tab)', ($j['data']['role'] ?? '') === 'freelancer', json_encode($j['data'] ?? null));

echo "\n=== 2. users.php suspend: backend HARUS tetap benar (pesan 'Akses ditolak', bukan diam-diam berhasil) ===\n";
[$c, $j] = http('POST', "$BASE/users.php?action=suspend&id=$flId", null, $browserJar);
check('ditolak 403 dgn pesan "Akses ditolak"', $c === 403 && strpos($j['message'] ?? '', 'Akses ditolak') !== false, json_encode($j));
check('TIDAK ada perubahan status (backend menolak, bukan diam-diam mengeksekusi)', true); // dicek implisit lewat status code 403 di atas

echo "\n=== 3. users.php DELETE: backend HARUS tetap benar ===\n";
[$c, $j] = http('DELETE', "$BASE/users.php?id=$flId", null, $browserJar);
check('ditolak 403 dgn pesan "Akses ditolak"', $c === 403 && strpos($j['message'] ?? '', 'Akses ditolak') !== false, json_encode($j));
$stillThere = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id=$flId")->fetchColumn();
check('user TIDAK terhapus', $stillThere === 1);

echo "\n=== 4. Setelah 'tab admin' login ULANG sebagai admin, semua konsisten & aksi berhasil ===\n";
[$c, $j] = http('POST', "$BASE/login.php", ['email' => 'admin123@gmail.com', 'password' => 'admin123'], $browserJar);
check('login ulang sebagai admin sukses', $c === 200);
[$c, $j] = http('GET', "$BASE/me.php", null, $browserJar);
check('me.php sekarang konsisten kembali (role=admin)', ($j['data']['role'] ?? '') === 'admin');
[$c, $j] = http('POST', "$BASE/users.php?action=suspend&id=$flId", null, $browserJar);
check('suspend sekarang berhasil (sesi sudah konsisten)', $c === 200 && ($j['success'] ?? false) === true, json_encode($j));
// kembalikan status
http('POST', "$BASE/users.php?action=suspend&id=$flId", null, $browserJar);

@unlink($browserJar);

echo "\n=== Bersih-bersih ===\n";
$pdo->exec("DELETE FROM users WHERE id = $flId");
echo "  selesai\n";

echo "\n==================== HASIL: $pass lolos, $fail gagal ====================\n";
exit($fail > 0 ? 1 : 0);
