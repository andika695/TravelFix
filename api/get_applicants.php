<?php
// api/get_applicants.php — Daftar pelamar untuk satu proyek
// GET ?project_id=N → { "status": "success", "data": [ { applicant_id, freelancer_id,
//                       name, email, bio, skills[], status, applied_at } ], "total": N }
// Error            → { "status": "error", "message": "..." } (selalu JSON valid)

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
}

// Sanitasi: paksa menjadi integer agar aman dari SQL Injection
$projectId = intval($_GET['project_id'] ?? 0);
if ($projectId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'project_id wajib diisi dan berupa angka.'], 422);
}

try {
    $pdo = getDB();

    // Wajib login, DAN hanya pemilik proyek (UMKM terkait) atau admin yang
    // boleh melihat daftar pelamar -- sebelumnya endpoint ini TIDAK PUNYA
    // pengecekan sama sekali, jadi siapa pun (bahkan tanpa login) yang tahu
    // project_id bisa melihat nama, email, bio, dan skill semua pelamarnya
    // (kebocoran data pribadi / IDOR). Pola sama persis dengan
    // update_applicant_status.php & review_submission.php.
    $viewerId = (int) requireAuth($pdo)['id'];

    $projectStmt = $pdo->prepare('SELECT created_by FROM projects WHERE id = ? LIMIT 1');
    $projectStmt->execute([$projectId]);
    $project = $projectStmt->fetch();
    if (!$project) {
        jsonResponse(['status' => 'error', 'message' => 'Proyek tidak ditemukan.'], 404);
    }
    if ((int) $project['created_by'] !== $viewerId) {
        requireRole($pdo, $viewerId, ['admin']);
    }

    // Self-healing: jika database dibuat sebelum kolom `status` ditambahkan
    // (migration_add_applicant_status.sql belum dijalankan), tambahkan otomatis
    // supaya query di bawah tidak melempar "Unknown column 'pa.status'".
    $hasStatusColumn = $pdo->query(
        "SHOW COLUMNS FROM `project_applicants` LIKE 'status'"
    )->fetch();

    if (!$hasStatusColumn) {
        $pdo->exec(
            "ALTER TABLE `project_applicants`
             ADD COLUMN `status` ENUM('Pending','Diterima','Ditolak') NOT NULL DEFAULT 'Pending'
             AFTER `freelancer_id`"
        );
    }

    // JOIN ke tabel users via freelancer_id untuk mendapatkan nama, email, bio
    $stmt = $pdo->prepare(
        'SELECT pa.id AS applicant_id, pa.project_id, pa.freelancer_id,
                pa.status, pa.applied_at,
                u.name, u.email, u.bio
         FROM project_applicants pa
         JOIN users u ON u.id = pa.freelancer_id
         WHERE pa.project_id = ?
         ORDER BY pa.applied_at ASC'
    );
    $stmt->execute([$projectId]);
    $applicants = $stmt->fetchAll();

    // Lampirkan daftar skill tiap pelamar
    $skillStmt = $pdo->prepare('SELECT skill FROM user_skills WHERE user_id = ?');
    foreach ($applicants as &$a) {
        $skillStmt->execute([$a['freelancer_id']]);
        $a['skills'] = array_column($skillStmt->fetchAll(), 'skill');
    }
    unset($a);

    jsonResponse([
        'status' => 'success',
        'data'   => $applicants,
        'total'  => count($applicants),
    ]);

} catch (PDOException $e) {
    // Selalu balas JSON valid; detail error dicatat di server, bukan dibalikkan ke klien.
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse([
        'status'  => 'error',
        'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.',
    ], 500);
}
