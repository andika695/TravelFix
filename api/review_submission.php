<?php
// api/review_submission.php — UMKM me-review hasil submit freelancer
// POST (JSON atau form-data):
//   { "project_id": N, "umkm_id": N, "decision": "accept" | "revise" | "reject",
//     "rating": 1-5, "review_text": "..." }
//   (alias diterima: "terima"→accept, "revisi"→revise, "tolak"→reject)
//
// Keputusan:
//   accept → project_status = 'Completed'; rating (wajib, 1-5) dan review_text
//            disimpan ke tabel projects, lalu otomatis tampil di halaman
//            Impact Portofolio milik freelancer terkait. Saldo freelancer
//            bertambah otomatis (Total Saldo = SUM budget proyek Completed).
//            submission_link TIDAK dihapus — tetap tersimpan agar UMKM/Admin
//            bisa melihat kembali hasil kerja freelancer di kemudian hari.
//   revise → project_status = 'In Progress' (dikembalikan untuk revisi);
//            submission_link DIKOSONGKAN (NULL) karena freelancer wajib
//            mengirim tautan hasil pekerjaan yang baru.
//   reject → project_status = 'Closed' (hasil ditolak, proyek dibatalkan);
//            submission_link tetap disimpan sebagai arsip.
//
// Sukses → { "status": "success", "message": "...", "data": { project_id, project_status } }
// Gagal  → { "status": "error", "message": "..." } (selalu JSON valid)

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

$projectId = intval($body['project_id'] ?? 0);
$decision  = strtolower(trim($body['decision'] ?? ($body['action'] ?? '')));

// Normalisasi alias Indonesia → kanonis
$aliases  = ['terima' => 'accept', 'revisi' => 'revise', 'tolak' => 'reject'];
$decision = $aliases[$decision] ?? $decision;

$statusMap = [
    'accept' => 'Completed',
    'revise' => 'In Progress',
    'reject' => 'Closed',
];

if ($projectId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'project_id wajib diisi (angka).'], 422);
}
if (!isset($statusMap[$decision])) {
    jsonResponse(['status' => 'error', 'message' => "decision tidak valid. Gunakan 'accept', 'revise', atau 'reject'."], 422);
}

// Rating & ulasan hanya relevan untuk keputusan "Terima Hasil"
$rating     = isset($body['rating']) ? intval($body['rating']) : 0;
$reviewText = trim((string) ($body['review_text'] ?? ''));

if ($decision === 'accept' && ($rating < 1 || $rating > 5)) {
    jsonResponse(['status' => 'error', 'message' => 'Rating wajib diisi (bintang 1 sampai 5) sebelum menerima hasil pekerjaan.'], 422);
}

try {
    $pdo = getDB();

    // umkm_id SELALU dari session yang sedang login -- BUKAN dari body client.
    $umkmId = (int) requireAuth($pdo)['id'];

    $stmt = $pdo->prepare(
        'SELECT id, title, created_by, project_status, freelancer_id FROM projects WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        jsonResponse(['status' => 'error', 'message' => 'Proyek tidak ditemukan.'], 404);
    }
    if ((int) $project['created_by'] !== $umkmId) {
        jsonResponse(['status' => 'error', 'message' => 'Anda bukan pemilik proyek ini.'], 403);
    }
    if ($project['project_status'] !== 'Submitted') {
        jsonResponse(['status' => 'error', 'message' => "Proyek belum disubmit oleh freelancer (status saat ini: '{$project['project_status']}')."], 409);
    }

    $newStatus = $statusMap[$decision];

    if ($decision === 'accept') {
        // Simpan rating + ulasan; keduanya tampil di Impact Portofolio freelancer.
        // submission_link SENGAJA tidak disentuh di sini agar hasil kerja tetap
        // bisa dilihat kembali oleh UMKM/Admin setelah proyek Completed.
        $pdo->prepare(
            'UPDATE projects SET project_status = ?, rating = ?, review_text = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$newStatus, $rating, $reviewText !== '' ? $reviewText : null, $projectId]);
    } elseif ($decision === 'revise') {
        // Revisi: kosongkan submission_link — freelancer wajib mengirim tautan baru
        $pdo->prepare(
            'UPDATE projects SET project_status = ?, submission_link = NULL, updated_at = NOW() WHERE id = ?'
        )->execute([$newStatus, $projectId]);
    } else {
        // Tolak (reject): submission_link tetap disimpan sebagai arsip riwayat
        $pdo->prepare(
            'UPDATE projects SET project_status = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$newStatus, $projectId]);
    }

    // Sinkronkan kolom status lama seadanya (enum lama tak punya 'Completed');
    // abaikan jika kolom sudah dihapus.
    $legacyMap = ['Completed' => 'Done', 'In Progress' => 'In Progress', 'Closed' => 'Closed'];
    try {
        $pdo->prepare('UPDATE projects SET status = ? WHERE id = ?')
            ->execute([$legacyMap[$newStatus], $projectId]);
    } catch (PDOException $e) { /* kolom lama sudah dihapus — abaikan */ }

    $messages = [
        'accept' => 'Hasil pekerjaan diterima. Rating dan ulasan tersimpan, proyek selesai (Completed) dan saldo freelancer bertambah.',
        'revise' => 'Proyek dikembalikan ke freelancer untuk direvisi (In Progress).',
        'reject' => 'Hasil pekerjaan ditolak. Proyek dibatalkan (Closed).',
    ];

    jsonResponse([
        'status'  => 'success',
        'message' => $messages[$decision],
        'data'    => [
            'project_id'     => $projectId,
            'decision'       => $decision,
            'project_status' => $newStatus,
            'rating'         => $decision === 'accept' ? $rating : null,
            'review_text'    => $decision === 'accept' && $reviewText !== '' ? $reviewText : null,
        ],
    ]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
