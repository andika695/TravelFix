<?php
// api/generate_hash.php
// ── HANYA UNTUK DEVELOPMENT ──────────────────────────────────
// Gunakan file ini untuk men-generate bcrypt hash dari password plain text.
// HAPUS atau BLOKIR file ini sebelum deploy ke production!
//
// Cara pakai: buka http://localhost/travelfix-main/api/generate_hash.php
// ──────────────────────────────────────────────────────────────

// Guard: file ini membocorkan password seed + hash bcrypt-nya (info
// disclosure) kalau tetap bisa diakses lewat internet setelah deploy.
// Sebelumnya TIDAK ada guard sama sekali — siapa pun yang tahu URL-nya bisa
// membukanya. Dibatasi hanya untuk akses dari localhost (dev environment).
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}

$passwords = [
    'admin123',
    'user123',
    'umkm123',
];

echo "<pre style='font-family:monospace;font-size:14px;padding:20px'>";
echo "<strong>Generated bcrypt hashes (untuk Seed SQL)</strong>\n\n";

foreach ($passwords as $plain) {
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    echo "Plain : {$plain}\n";
    echo "Hash  : {$hash}\n";
    echo "Verify: " . (password_verify($plain, $hash) ? "✓ OK" : "✗ GAGAL") . "\n\n";
}

echo "</pre>";
