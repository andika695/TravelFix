<?php
// api/update_applicant_status.php — Terima/Tolak pelamar (dengan transaksi)
// POST (JSON atau form-data): { "applicant_id": N, "status": "Diterima" | "Ditolak" }
//   (key "status_baru" juga diterima sebagai alias "status")
//
// Jika status = 'Diterima':
//   1. UPDATE project_applicants SET status='Diterima' WHERE id = applicant_id
//   2. UPDATE projects SET status='In Progress' WHERE id = project_id
//   3. UPDATE project_applicants SET status='Ditolak'
//      WHERE project_id = project_id AND status = 'Pending' (tolak pelamar lain otomatis)
//   Seluruh langkah dibungkus DB transaction — jika salah satu gagal, semua dibatalkan.
//
// Jika status = 'Ditolak':
//   Hanya UPDATE baris pelamar tersebut, tidak menyentuh proyek/pelamar lain.
//
// Sukses → { "status": "success", "message": "...", "data": { applicant_id, project_id,
//             freelancer_id, status, project_status, rejected_others } }
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

// Sanitasi: paksa menjadi integer agar aman dari SQL Injection
$applicantId = intval($body['applicant_id'] ?? 0);
$newStatus   = trim($body['status'] ?? ($body['status_baru'] ?? ''));

$allowedStatus = ['Diterima', 'Ditolak'];
if ($applicantId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'applicant_id wajib diisi (angka).'], 422);
}
if (!in_array($newStatus, $allowedStatus, true)) {
    jsonResponse(['status' => 'error', 'message' => "Status tidak valid. Gunakan 'Diterima' atau 'Ditolak'."], 422);
}

try {
    $pdo = getDB();

    // umkm_id SELALU dari session yang sedang login -- BUKAN dari body client.
    $umkmId = (int) requireAuth($pdo)['id'];

    // Pastikan lamaran ada, sekaligus ambil project_id & freelancer_id-nya
    $stmt = $pdo->prepare('SELECT id, project_id, freelancer_id, status FROM project_applicants WHERE id = ? LIMIT 1');
    $stmt->execute([$applicantId]);
    $applicant = $stmt->fetch();

    if (!$applicant) {
        jsonResponse(['status' => 'error', 'message' => 'Data pelamar tidak ditemukan.'], 404);
    }

    $projectId = (int) $applicant['project_id'];

    // Pastikan yang menerima/menolak pelamar adalah pemilik proyek (UMKM
    // terkait) atau admin — sebelumnya endpoint ini bisa dipanggil siapa saja
    // asal tahu applicant_id, tanpa verifikasi kepemilikan proyek sama sekali.
    $projectStmt = $pdo->prepare('SELECT created_by FROM projects WHERE id = ? LIMIT 1');
    $projectStmt->execute([$projectId]);
    $projectOwner = $projectStmt->fetch();
    if (!$projectOwner) {
        jsonResponse(['status' => 'error', 'message' => 'Proyek tidak ditemukan.'], 404);
    }
    if ((int) $projectOwner['created_by'] !== $umkmId) {
        requireRole($pdo, $umkmId, ['admin']);
    }

    $pdo->beginTransaction();

    // 1. Update status pelamar yang bersangkutan
    $pdo->prepare('UPDATE project_applicants SET status = ? WHERE id = ?')
        ->execute([$newStatus, $applicantId]);

    $projectStatus  = null;
    $rejectedOthers = 0;

    if ($newStatus === 'Diterima') {
        // 2. Proyek otomatis masuk tahap pengerjaan + catat freelancer yang
        //    mengerjakan pada kolom freelancer_id
        $pdo->prepare(
            "UPDATE projects
             SET project_status = 'In Progress', freelancer_id = ?, updated_at = NOW()
             WHERE id = ?"
        )->execute([(int) $applicant['freelancer_id'], $projectId]);

        // Sinkronkan kolom lama (status/assigned_to) jika masih ada
        try {
            $pdo->prepare("UPDATE projects SET status = 'In Progress', assigned_to = ? WHERE id = ?")
                ->execute([(int) $applicant['freelancer_id'], $projectId]);
        } catch (PDOException $e) { /* kolom lama sudah dihapus — abaikan */ }

        $projectStatus = 'In Progress';

        // 3. Pelamar lain yang masih Pending pada proyek yang sama otomatis ditolak
        $rejectStmt = $pdo->prepare(
            "UPDATE project_applicants
             SET status = 'Ditolak'
             WHERE project_id = ? AND status = 'Pending' AND id != ?"
        );
        $rejectStmt->execute([$projectId, $applicantId]);
        $rejectedOthers = $rejectStmt->rowCount();
    }

    $pdo->commit();

    jsonResponse([
        'status'  => 'success',
        'message' => $newStatus === 'Diterima'
            ? 'Freelancer berhasil diterima. Proyek sekarang berstatus In Progress.'
            : 'Pelamar berhasil ditolak.',
        'data'    => [
            'applicant_id'    => $applicantId,
            'project_id'      => $projectId,
            'freelancer_id'   => (int) $applicant['freelancer_id'],
            'status'          => $newStatus,
            'project_status'  => $projectStatus, // null jika status = Ditolak (proyek tidak berubah)
            'rejected_others' => $rejectedOthers, // jumlah pelamar lain yang otomatis ditolak
        ],
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
