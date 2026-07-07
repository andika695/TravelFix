<?php
// api/me.php — Identitas pengguna yang sedang login menurut session PHP asli
// (bukan sessionStorage client, yang scope-nya per-tab dan bisa hilang meski
// session PHP masih valid -- lihat js/db.js rehydrateSessionFromServer()).
// GET → { "status": "success", "data": {...profil lengkap} } atau 401 kalau
// belum login sama sekali.

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
}

$userId = currentSessionUserId();
if (!$userId) {
    jsonResponse(['status' => 'error', 'message' => 'Belum login.'], 401);
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'SELECT id, name, email, public_email, role, account_status, bio, location, portfolio_url,
                business_name, business_category, `field`, skills AS skills_text,
                interests AS interests_text, whatsapp, instagram, linkedin, website,
                address, latitude, longitude, created_at, updated_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        // Sesi menunjuk ke user yang sudah tidak ada (mis. dihapus admin)
        session_unset();
        session_destroy();
        jsonResponse(['status' => 'error', 'message' => 'Sesi tidak valid. Silakan login kembali.'], 401);
    }
    if (($user['account_status'] ?? 'Active') === 'Suspended') {
        jsonResponse(['status' => 'error', 'message' => 'Akun Anda Dibekukan.'], 403);
    }

    $stmt = $pdo->prepare('SELECT skill FROM user_skills WHERE user_id = ?');
    $stmt->execute([$userId]);
    $skills = array_column($stmt->fetchAll(), 'skill');

    $stmt = $pdo->prepare('SELECT interest FROM user_interests WHERE user_id = ?');
    $stmt->execute([$userId]);
    $interests = array_column($stmt->fetchAll(), 'interest');

    $splitText = static function (?string $text): array {
        if ($text === null || trim($text) === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $text))));
    };
    if (empty($skills))    { $skills    = $splitText($user['skills_text']); }
    if (empty($interests)) { $interests = $splitText($user['interests_text']); }

    $user['skills']    = $skills;
    $user['interests'] = $interests;
    unset($user['skills_text'], $user['interests_text']);

    jsonResponse(['status' => 'success', 'data' => $user]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
