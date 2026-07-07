<?php
// api/register.php — Endpoint registrasi akun baru
// Method: POST
// Body (JSON): { "name": "", "email": "", "password": "", "role": "freelancer|umkm" }
// Response: { "success": true/false, "message": "...", "user": {...} }

require_once __DIR__ . '/config.php';

setCorsHeaders();

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

// Baca body JSON
$body = json_decode(file_get_contents('php://input'), true);

// Ambil & sanitasi input
$name             = isset($body['name'])             ? trim($body['name'])             : '';
$email            = isset($body['email'])            ? trim($body['email'])            : '';
$password         = isset($body['password'])         ? $body['password']               : '';
$role             = isset($body['role'])             ? trim($body['role'])             : 'freelancer';
$businessName     = isset($body['businessName'])     ? trim($body['businessName'])     : '';
$businessCategory = isset($body['businessCategory']) ? trim($body['businessCategory']) : '';

// ── VALIDASI ─────────────────────────────────────────────────
$errors = [];

if (empty($name)) {
    $errors[] = 'Nama Lengkap wajib diisi.';
} elseif (mb_strlen($name) > 150) {
    $errors[] = 'Nama Lengkap maksimal 150 karakter.';
}

if (empty($email)) {
    $errors[] = 'Alamat Email wajib diisi.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Format email tidak valid.';
} elseif (mb_strlen($email) > 200) {
    $errors[] = 'Email terlalu panjang (maksimal 200 karakter).';
}

if (empty($password)) {
    $errors[] = 'Password wajib diisi.';
} elseif (mb_strlen($password) < 6) {
    $errors[] = 'Password minimal 6 karakter.';
}

$allowedRoles = ['freelancer', 'umkm'];
if (!in_array($role, $allowedRoles, true)) {
    $errors[] = 'Role tidak valid. Pilih Freelancer atau UMKM.';
}

// Validasi khusus UMKM
if ($role === 'umkm') {
    if (empty($businessName)) {
        $errors[] = 'Nama Bisnis wajib diisi untuk akun UMKM.';
    } elseif (mb_strlen($businessName) > 200) {
        $errors[] = 'Nama Bisnis maksimal 200 karakter.';
    }
    if (empty($businessCategory)) {
        $errors[] = 'Kategori Bisnis wajib dipilih untuk akun UMKM.';
    }
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

// ── CEK EMAIL DUPLIKAT ────────────────────────────────────────
$pdo  = getDB();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);

if ($stmt->fetch()) {
    jsonResponse(['success' => false, 'message' => 'Email sudah terdaftar!'], 409);
}

// ── SIMPAN USER BARU ──────────────────────────────────────────
// Hash password menggunakan bcrypt (PASSWORD_DEFAULT)
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$insert = $pdo->prepare(
    'INSERT INTO users (name, email, password, role, business_name, business_category, status, created_at)
     VALUES (:name, :email, :password, :role, :business_name, :business_category, "active", NOW())'
);

$insert->execute([
    ':name'              => $name,
    ':email'             => $email,
    ':password'          => $hashedPassword,
    ':role'              => $role,
    ':business_name'     => $role === 'umkm' ? $businessName     : null,
    ':business_category' => $role === 'umkm' ? $businessCategory : null,
]);

$newUserId = (int) $pdo->lastInsertId();

// ── BALIKAN RESPONSE ──────────────────────────────────────────
jsonResponse([
    'success' => true,
    'message' => 'Registrasi berhasil! Silakan login.',
    'user'    => [
        'id'         => $newUserId,
        'name'       => $name,
        'email'      => $email,
        'role'       => $role,
        'created_at' => date('c'),
    ]
], 201);
