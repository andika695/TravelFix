<?php
// api/apply_project.php — Freelancer melamar sebuah proyek
// POST (JSON atau form-data): { "project_id": N, "freelancer_id": N }
// Sukses → { "status": "success", "message": "...", "data": { applicant_id, project_id, freelancer_id, status } }
// Gagal  → { "status": "error", "message": "...", "code": "..." } (selalu JSON valid)

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
}

// Terima body JSON maupun form-encoded
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

// Sanitasi: paksa menjadi integer agar aman dari SQL Injection
$projectId = intval($body['project_id'] ?? 0);

if ($projectId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'project_id wajib diisi (angka).'], 422);
}

try {
    $pdo = getDB();

    // freelancer_id SELALU dari session yang sedang login -- BUKAN dari body
    // client -- supaya tidak ada yang bisa melamar "atas nama" freelancer lain.
    $sessionUser  = requireAuth($pdo);
    $freelancerId = (int) $sessionUser['id'];

    // Self-healing: tambahkan kolom `status` jika migrasi belum dijalankan
    $hasStatusColumn = $pdo->query("SHOW COLUMNS FROM `project_applicants` LIKE 'status'")->fetch();
    if (!$hasStatusColumn) {
        $pdo->exec(
            "ALTER TABLE `project_applicants`
             ADD COLUMN `status` ENUM('Pending','Diterima','Ditolak') NOT NULL DEFAULT 'Pending'
             AFTER `freelancer_id`"
        );
    }

    // Pastikan proyek ada dan masih menerima lamaran (sistem status baru)
    $stmt = $pdo->prepare('SELECT id, project_status AS status FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        jsonResponse(['status' => 'error', 'message' => 'Proyek tidak ditemukan.'], 404);
    }
    if ($project['status'] !== 'Open') {
        jsonResponse(['status' => 'error', 'code' => 'project_closed', 'message' => 'Proyek sudah tidak menerima lamaran.'], 409);
    }

    // Pastikan pelamar ada dan berperan freelancer
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$freelancerId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['status' => 'error', 'message' => 'User pelamar tidak ditemukan.'], 404);
    }
    if ($user['role'] !== 'freelancer') {
        jsonResponse(['status' => 'error', 'message' => 'Hanya akun freelancer yang dapat melamar proyek.'], 403);
    }

    // Validasi wajib: profil freelancer harus punya Skill dan Minat sebelum melamar.
    // Sumber utama tabel relasional; fallback kolom teks users.skills/interests.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_skills WHERE user_id = ?');
    $stmt->execute([$freelancerId]);
    $hasSkills = (int) $stmt->fetchColumn() > 0;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_interests WHERE user_id = ?');
    $stmt->execute([$freelancerId]);
    $hasInterests = (int) $stmt->fetchColumn() > 0;

    // Data profil freelancer dari tabel users — dipakai fallback skill/minat
    // teks di atas, DAN validasi lokasi di bawah.
    $stmt = $pdo->prepare('SELECT skills, interests, location, latitude, longitude FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$freelancerId]);
    $profil = $stmt->fetch() ?: [];

    if (!$hasSkills)    { $hasSkills    = trim((string) ($profil['skills'] ?? '')) !== ''; }
    if (!$hasInterests) { $hasInterests = trim((string) ($profil['interests'] ?? '')) !== ''; }

    if (!$hasSkills || !$hasInterests) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 'profile_incomplete',
            'message' => 'Anda diwajibkan mengisi data Minat dan Keahlian (Skill) di profil Anda sebelum melamar proyek ini!',
        ], 422);
    }

    // Validasi wajib: profil freelancer harus punya Lokasi (teks + koordinat,
    // diisi lewat Map Picker di halaman Profil Saya) sebelum melamar — juga
    // dibutuhkan fitur AI Proximity & Skill Matcher untuk rekomendasi berikutnya.
    $hasLocation = trim((string) ($profil['location'] ?? '')) !== ''
        && $profil['latitude'] !== null
        && $profil['longitude'] !== null;

    if (!$hasLocation) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 'location_missing',
            'message' => 'Anda diwajibkan mengisi Lokasi di profil Anda sebelum melamar proyek ini! Silakan lengkapi lewat halaman Profil Saya.',
        ], 422);
    }

    // Cek apakah sudah pernah melamar
    $stmt = $pdo->prepare(
        'SELECT id, status FROM project_applicants WHERE project_id = ? AND freelancer_id = ? LIMIT 1'
    );
    $stmt->execute([$projectId, $freelancerId]);
    $existing = $stmt->fetch();

    if ($existing) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 'already_applied',
            'message' => 'Kamu sudah melamar proyek ini.',
            'data'    => [
                'applicant_id'   => (int) $existing['id'],
                'current_status' => $existing['status'],
            ],
        ], 409);
    }

    // Simpan lamaran baru dengan status Pending
    $stmt = $pdo->prepare(
        "INSERT INTO project_applicants (project_id, freelancer_id, status, applied_at)
         VALUES (?, ?, 'Pending', NOW())"
    );
    $stmt->execute([$projectId, $freelancerId]);

    jsonResponse([
        'status'  => 'success',
        'message' => 'Lamaran berhasil dikirim.',
        'data'    => [
            'applicant_id'  => (int) $pdo->lastInsertId(),
            'project_id'    => $projectId,
            'freelancer_id' => $freelancerId,
            'status'        => 'Pending',
        ],
    ], 201);

} catch (PDOException $e) {
    // 23000 = pelanggaran UNIQUE (project_id, freelancer_id) — lamaran ganda (race condition)
    if ($e->getCode() === '23000') {
        jsonResponse(['status' => 'error', 'code' => 'already_applied', 'message' => 'Kamu sudah melamar proyek ini.'], 409);
    }
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
