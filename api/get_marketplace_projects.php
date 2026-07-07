<?php
// api/get_marketplace_projects.php — Proyek yang tampil di halaman Eksplorasi
// Proyek (marketplace.html) freelancer.
//
// GET ?status=open|review|close|all (default: all kalau parameter kosong/tak dikenal)
//   - open   -> project_status = 'Open' (bisa dilamar)
//   - review -> project_status IN ('In Progress','Submitted') (sedang dikerjakan/
//               menunggu review UMKM -- tidak bisa dilamar, tapi bukan referensi lama)
//   - close  -> project_status IN ('Completed','Closed') (arsip/referensi buat
//               freelancer lain, sudah tidak bisa dilamar)
//   - all / kosong -> semua status, tanpa filter WHERE
//
// Response: { "status": "success", "data": [...], "total": N }

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
}

// Peta filter sederhana (dipakai tombol Tab di frontend) -> ENUM asli
// projects.project_status. Whitelist eksplisit supaya parameter status dari
// query string TIDAK PERNAH langsung ditempel ke SQL (aman dari injection).
$statusFilterMap = [
    'open'   => ['Open'],
    'review' => ['In Progress', 'Submitted'],
    'close'  => ['Completed', 'Closed'],
];

$statusParam = strtolower(trim($_GET['status'] ?? ''));

if ($statusParam !== '' && $statusParam !== 'all' && !isset($statusFilterMap[$statusParam])) {
    jsonResponse(['status' => 'error', 'message' => 'Parameter status tidak dikenal. Gunakan: all, open, review, close.'], 422);
}

try {
    $pdo = getDB();

    $where  = '';
    $params = [];

    if ($statusParam !== '' && $statusParam !== 'all') {
        $statuses     = $statusFilterMap[$statusParam];
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $where        = "WHERE p.project_status IN ($placeholders)";
        $params       = $statuses;
    }
    // status kosong atau 'all' -> tanpa WHERE, tarik semua proyek apa pun statusnya

    $stmt = $pdo->prepare(
        "SELECT p.id, p.created_by, p.title, p.description, p.budget, p.deadline,
                p.project_status, p.project_status AS status, p.icon, p.location,
                p.requirements, p.freelancer_id, p.freelancer_id AS assigned_to,
                p.created_at, p.updated_at,
                u.name AS creator_name, u.business_name AS creator_business_name
         FROM projects p
         LEFT JOIN users u ON u.id = p.created_by
         $where
         ORDER BY p.created_at DESC"
    );
    $stmt->execute($params);
    $projects = $stmt->fetchAll();

    $catStmt = $pdo->prepare('SELECT category FROM project_categories WHERE project_id = ?');
    $appStmt = $pdo->prepare('SELECT freelancer_id FROM project_applicants WHERE project_id = ?');

    foreach ($projects as &$p) {
        $catStmt->execute([$p['id']]);
        $p['categories'] = array_column($catStmt->fetchAll(), 'category');

        $appStmt->execute([$p['id']]);
        $p['applicants'] = array_column($appStmt->fetchAll(), 'freelancer_id');
    }
    unset($p);

    jsonResponse(['status' => 'success', 'data' => $projects, 'total' => count($projects)]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
