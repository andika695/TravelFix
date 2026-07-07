<?php
// api/get_freelancer_history.php — Riwayat proyek selesai seorang freelancer
// GET ?freelancer_id=N
//
// Dipakai tombol "Lihat Riwayat" di daftar pelamar (halaman Kelola Proyek
// UMKM): menampilkan proyek Completed milik freelancer beserta rating dan
// ulasan dari klien sebelumnya.
//
// Sukses → { "status": "success", "message": "...", "data": {
//            freelancer: {...}, summary: { total_completed, avg_rating },
//            projects: [ { id, title, budget, completed_at, client_name,
//                          rating, review_text, submission_link } ] } }
// Gagal  → { "status": "error", "message": "..." } (selalu JSON valid)

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

    // Wajib login -- dipakai UMKM memverifikasi rekam jejak pelamar sebelum
    // menerima lamaran. Sebelumnya TANPA pengecekan sama sekali, jadi siapa
    // pun tanpa login bisa melihat riwayat proyek, rating, dan ulasan klien
    // freelancer mana pun hanya dengan menebak freelancer_id.
    requireAuth($pdo);

    $stmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$freelancerId]);
    $freelancer = $stmt->fetch();

    if (!$freelancer) {
        jsonResponse(['status' => 'error', 'message' => 'Freelancer tidak ditemukan.'], 404);
    }
    if ($freelancer['role'] !== 'freelancer') {
        jsonResponse(['status' => 'error', 'message' => 'Riwayat hanya tersedia untuk akun freelancer.'], 403);
    }

    $stmt = $pdo->prepare(
        "SELECT p.id, p.title, p.budget, p.updated_at AS completed_at,
                p.rating, p.review_text, p.submission_link,
                COALESCE(NULLIF(u.business_name, ''), u.name, 'UMKM Bantul') AS client_name
         FROM projects p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE p.freelancer_id = ? AND p.project_status = 'Completed'
         ORDER BY p.updated_at DESC"
    );
    $stmt->execute([$freelancerId]);
    $rows = $stmt->fetchAll();

    $projects  = [];
    $sumRating = 0;
    $numRated  = 0;

    foreach ($rows as $p) {
        $rating = $p['rating'] !== null ? (int) $p['rating'] : null;
        if ($rating !== null) {
            $sumRating += $rating;
            $numRated++;
        }
        $projects[] = [
            'id'              => (int) $p['id'],
            'title'           => $p['title'],
            'budget'          => (float) $p['budget'],
            'completed_at'    => $p['completed_at'],
            'client_name'     => $p['client_name'],
            'rating'          => $rating,
            'review_text'     => $p['review_text'],
            'submission_link' => $p['submission_link'],
        ];
    }

    jsonResponse([
        'status'  => 'success',
        'message' => 'Riwayat freelancer berhasil dimuat.',
        'data'    => [
            'freelancer' => [
                'id'   => (int) $freelancer['id'],
                'name' => $freelancer['name'],
            ],
            'summary' => [
                'total_completed' => count($projects),
                'avg_rating'      => $numRated ? round($sumRating / $numRated, 1) : null,
            ],
            'projects' => $projects,
        ],
    ]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
