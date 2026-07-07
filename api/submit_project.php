<?php
// api/submit_project.php — Freelancer men-submit hasil pekerjaan
// POST (JSON atau form-data):
//   { "project_id": N, "freelancer_id": N, "submission_link": "https://..." }
//
// Syarat: proyek berstatus 'In Progress', freelancer_id proyek tersebut
// sama dengan freelancer yang men-submit, dan submission_link (tautan hasil
// pekerjaan) wajib diisi. Link disimpan ke projects.submission_link dan
// status berubah → 'Submitted' (menunggu review UMKM: Terima Hasil / Revisi).
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

// Sanitasi: paksa menjadi integer agar aman dari SQL Injection
$projectId      = intval($body['project_id'] ?? 0);
$submissionLink = trim((string) ($body['submission_link'] ?? ''));

if ($projectId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'project_id wajib diisi (angka).'], 422);
}
if ($submissionLink === '') {
    jsonResponse(['status' => 'error', 'message' => 'Tautan/link hasil pekerjaan wajib diisi.'], 422);
}
if (mb_strlen($submissionLink) > 2000) {
    jsonResponse(['status' => 'error', 'message' => 'Tautan hasil pekerjaan terlalu panjang (maksimal 2000 karakter).'], 422);
}

try {
    $pdo = getDB();

    // freelancer_id SELALU dari session yang sedang login -- BUKAN dari body
    // client -- supaya tidak ada yang bisa submit hasil "atas nama" freelancer lain.
    $freelancerId = (int) requireAuth($pdo)['id'];

    $stmt = $pdo->prepare(
        'SELECT id, title, project_status, freelancer_id FROM projects WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        jsonResponse(['status' => 'error', 'message' => 'Proyek tidak ditemukan.'], 404);
    }
    if ((int) $project['freelancer_id'] !== $freelancerId) {
        jsonResponse(['status' => 'error', 'message' => 'Anda bukan freelancer yang mengerjakan proyek ini.'], 403);
    }
    if ($project['project_status'] === 'Submitted') {
        jsonResponse(['status' => 'error', 'code' => 'already_submitted', 'message' => 'Proyek sudah disubmit dan sedang menunggu review UMKM.'], 409);
    }
    if ($project['project_status'] !== 'In Progress') {
        jsonResponse(['status' => 'error', 'message' => "Proyek tidak dapat disubmit karena berstatus '{$project['project_status']}'."], 409);
    }

    $pdo->prepare(
        "UPDATE projects SET project_status = 'Submitted', submission_link = ?, updated_at = NOW() WHERE id = ?"
    )->execute([$submissionLink, $projectId]);

    jsonResponse([
        'status'  => 'success',
        'message' => 'Hasil pekerjaan berhasil dikumpulkan! Menunggu review dari client (UMKM).',
        'data'    => [
            'project_id'      => $projectId,
            'project_status'  => 'Submitted',
            'submission_link' => $submissionLink,
        ],
    ]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
