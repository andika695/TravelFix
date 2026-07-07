<?php
// api/freelancer_stats.php — Statistik Impact Portfolio milik seorang freelancer
// GET ?freelancer_id=N
//
// Response:
// {
//   "success": true,
//   "stats": {
//     "total_saldo":        <SUM budget proyek Completed>,
//     "completed_projects": <COUNT proyek Completed>,
//     "avg_engagement":     <rata-rata % engagement>,
//     "impact_score":       <akumulasi poin dampak>,
//     "avg_rating":         <rata-rata rating 4.0–5.0>
//   },
//   "projects": [ { id, title, description, budget, deadline, completed_at,
//                   client_name, location, icon, categories[],
//                   engagement, rating, review_text, submission_link,
//                   impact_points, impact_note } ]
// }
//
// Catatan metrik: rating dan ulasan (review_text) diambil LANGSUNG dari
// tabel projects (diisi UMKM saat menerima hasil kerja). Untuk proyek lama
// yang belum punya rating, dipakai fallback generator DETERMINISTIK
// (seed = crc32(project_id + freelancer_id)) agar nilai stabil antar-reload.
// Engagement & impact points tetap deterministik (belum ada tabel metrik).

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$freelancerId = intval($_GET['freelancer_id'] ?? 0);
if ($freelancerId <= 0) {
    jsonResponse(['success' => false, 'message' => 'freelancer_id wajib diisi dan berupa angka.'], 422);
}

try {
    $pdo = getDB();

    // Wajib login -- data ini memuat nama klien (UMKM) & nilai budget proyek
    // per freelancer, sebelumnya bisa diambil siapa saja tanpa login sama
    // sekali hanya dengan menebak freelancer_id.
    requireAuth($pdo);

    // Pastikan user ada dan berperan freelancer
    $stmt = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$freelancerId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'User tidak ditemukan.'], 404);
    }
    if ($user['role'] !== 'freelancer') {
        jsonResponse(['success' => false, 'message' => 'Statistik ini hanya tersedia untuk akun freelancer.'], 403);
    }

    // Semua proyek Completed yang dikerjakan freelancer ini
    $stmt = $pdo->prepare(
        'SELECT p.id, p.title, p.description, p.budget, p.deadline, p.icon,
                p.location, p.rating, p.review_text, p.submission_link, p.updated_at AS completed_at,
                u.name AS creator_name, u.business_name AS creator_business_name
         FROM projects p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE p.freelancer_id = ? AND p.project_status = ?
         ORDER BY p.updated_at DESC'
    );
    $stmt->execute([$freelancerId, 'Completed']);
    $rows = $stmt->fetchAll();

    $catStmt = $pdo->prepare('SELECT category FROM project_categories WHERE project_id = ?');

    $totalSaldo   = 0.0;
    $sumEngage    = 0;
    $sumRating    = 0.0;
    $impactScore  = 0;
    $projects     = [];

    foreach ($rows as $p) {
        // Seed deterministik per (proyek, freelancer)
        $seed       = crc32($p['id'] . '-' . $freelancerId);
        $engagement = 20 + ($seed % 51);                       // 20% – 70%
        $points     = 15 + (($seed >> 6) % 36);                // 15 – 50 poin

        // Rating asli dari ulasan UMKM; fallback deterministik untuk proyek lama
        $hasRealRating = $p['rating'] !== null && (int) $p['rating'] > 0;
        $rating = $hasRealRating
            ? (float) (int) $p['rating']
            : round(4.0 + ((($seed >> 3) % 11) / 10), 1); // 4.0 – 5.0

        $client = $p['creator_business_name'] ?: ($p['creator_name'] ?: 'UMKM Bantul');

        $catStmt->execute([$p['id']]);
        $categories = array_column($catStmt->fetchAll(), 'category');

        $totalSaldo  += (float) $p['budget'];
        $sumEngage   += $engagement;
        $sumRating   += $rating;
        $impactScore += $points;

        $projects[] = [
            'id'            => (int) $p['id'],
            'title'         => $p['title'],
            'description'   => $p['description'],
            'budget'        => (float) $p['budget'],
            'deadline'      => $p['deadline'],
            'completed_at'  => $p['completed_at'],
            'client_name'   => $client,
            'location'      => $p['location'],
            'icon'          => $p['icon'] ?: 'puzzle',
            'categories'    => $categories,
            'engagement'    => $engagement,
            'rating'        => $rating,
            'review_text'   => $p['review_text'],
            'submission_link' => $p['submission_link'],
            'impact_points' => $points,
            'impact_note'   => "Meningkatkan engagement digital {$client} hingga +{$engagement}% setelah proyek selesai.",
        ];
    }

    $count = count($projects);

    jsonResponse([
        'success' => true,
        'stats'   => [
            'total_saldo'        => $totalSaldo,
            'completed_projects' => $count,
            'avg_engagement'     => $count ? (int) round($sumEngage / $count) : 0,
            'impact_score'       => $impactScore,
            'avg_rating'         => $count ? round($sumRating / $count, 1) : 0,
        ],
        'projects' => $projects,
        'total'    => $count,
    ]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
