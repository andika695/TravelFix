<?php
// api/get_freelancer_applications.php — Daftar proyek yang dilamar freelancer
// GET ?freelancer_id=N → { "status": "success", "data": [ { applicant_id,
//   application_status, applied_at, project_id, title, description, budget,
//   deadline, icon, location, categories[], project_status, creator_name,
//   creator_business_name } ], "total": N }

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
}

$freelancerId = intval($_GET['freelancer_id'] ?? 0);
if ($freelancerId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'freelancer_id wajib diisi dan berupa angka.'], 422);
}

try {
    $pdo = getDB();

    // Wajib login DAN hanya boleh melihat lamaran milik sendiri (atau admin)
    // -- daftar ini dipakai halaman "Proyek Saya" milik freelancer sendiri,
    // tidak pernah lintas-akun di frontend manapun. Sebelumnya endpoint ini
    // bisa diakses tanpa login sama sekali hanya dengan menebak freelancer_id
    // (bocorkan daftar proyek yang dilamar + statusnya).
    $viewerId = (int) requireAuth($pdo)['id'];
    if ($viewerId !== $freelancerId) {
        requireRole($pdo, $viewerId, ['admin']);
    }

    // JOIN project_applicants + projects (status lamaran & status proyek bisa
    // berbeda: proyek bisa 'In Progress' sementara lamaran freelancer lain 'Ditolak')
    $stmt = $pdo->prepare(
        'SELECT pa.id AS applicant_id, pa.status AS application_status, pa.applied_at,
                p.id AS project_id, p.title, p.description, p.budget, p.deadline,
                p.icon, p.location, p.requirements, p.created_by,
                p.freelancer_id, p.project_status,
                u.name AS creator_name, u.business_name AS creator_business_name
         FROM project_applicants pa
         JOIN projects p ON p.id = pa.project_id
         LEFT JOIN users u ON u.id = p.created_by
         WHERE pa.freelancer_id = ?
         ORDER BY pa.applied_at DESC'
    );
    $stmt->execute([$freelancerId]);
    $applications = $stmt->fetchAll();

    $catStmt = $pdo->prepare('SELECT category FROM project_categories WHERE project_id = ?');
    foreach ($applications as &$a) {
        $catStmt->execute([$a['project_id']]);
        $a['categories'] = array_column($catStmt->fetchAll(), 'category');
    }
    unset($a);

    jsonResponse(['status' => 'success', 'data' => $applications, 'total' => count($applications)]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
