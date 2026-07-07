<?php
// api/chats.php — Pesan proyek antara UMKM dan Freelancer
//
// GET  ?project_id=N&user_id=M
//      → seluruh pesan proyek N (M harus partisipan: pemilik UMKM,
//        freelancer yang mengerjakan, atau admin)
// GET  ?inbox=M
//      → 10 pesan terakhir yang DITERIMA user M (untuk dropdown "Pesan Klien")
// POST { "project_id": N, "sender_id": M, "message": "..." }
//      → kirim pesan; receiver ditentukan otomatis dari lawan bicara
//        (UMKM ↔ Freelancer). receiver_id boleh dikirim eksplisit,
//        tapi tetap divalidasi partisipan.
//
// Sukses → { "status": "success", "data": [...] }
// Gagal  → { "status": "error", "message": "..." } (selalu JSON valid)

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Identitas pemanggil SELALU dari session yang sedang login -- BUKAN dari
// inbox=/user_id=/sender_id= yang dikirim client. Sebelumnya "GET ?inbox=M"
// bisa dipakai siapa saja untuk membaca kotak masuk pesan pribadi user M
// mana pun tanpa verifikasi sama sekali (IDOR) -- ini yang menutupnya.
$sessionUserId = (int) requireAuth($pdo)['id'];

/** Ambil proyek + info partisipan chat. */
function getProjectForChat(PDO $pdo, int $projectId): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, title, created_by, freelancer_id, project_status
         FROM projects WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    return $project ?: null;
}

/** Cek apakah user boleh mengakses chat proyek (pemilik/freelancer/admin). */
function isParticipant(PDO $pdo, array $project, int $userId): bool {
    if ((int) $project['created_by'] === $userId) return true;
    if ((int) $project['freelancer_id'] === $userId) return true;

    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user && $user['role'] === 'admin';
}

try {
    // ── GET: inbox ringkas (dropdown "Pesan Klien" di navbar) ─────────
    if ($method === 'GET' && isset($_GET['inbox'])) {
        $userId = $sessionUserId;

        $stmt = $pdo->prepare(
            'SELECT c.id, c.project_id, c.sender_id, c.message, c.created_at,
                    u.name AS sender_name, u.business_name AS sender_business_name,
                    p.title AS project_title
             FROM chats c
             JOIN users u    ON u.id = c.sender_id
             JOIN projects p ON p.id = c.project_id
             WHERE c.receiver_id = ?
             ORDER BY c.created_at DESC
             LIMIT 10'
        );
        $stmt->execute([$userId]);

        jsonResponse(['status' => 'success', 'data' => $stmt->fetchAll()]);
    }

    // ── GET: seluruh pesan sebuah proyek ──────────────────────────────
    if ($method === 'GET') {
        $projectId = intval($_GET['project_id'] ?? 0);
        $userId    = $sessionUserId;

        if ($projectId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'project_id wajib diisi (angka).'], 422);
        }

        $project = getProjectForChat($pdo, $projectId);
        if (!$project) {
            jsonResponse(['status' => 'error', 'message' => 'Proyek tidak ditemukan.'], 404);
        }
        if (!isParticipant($pdo, $project, $userId)) {
            jsonResponse(['status' => 'error', 'message' => 'Anda tidak memiliki akses ke chat proyek ini.'], 403);
        }

        $stmt = $pdo->prepare(
            'SELECT c.id, c.project_id, c.sender_id, c.receiver_id, c.message, c.created_at,
                    u.name AS sender_name, u.business_name AS sender_business_name
             FROM chats c
             JOIN users u ON u.id = c.sender_id
             WHERE c.project_id = ?
             ORDER BY c.created_at ASC, c.id ASC'
        );
        $stmt->execute([$projectId]);

        jsonResponse([
            'status' => 'success',
            'data'   => $stmt->fetchAll(),
            'project' => [
                'id'             => (int) $project['id'],
                'title'          => $project['title'],
                'created_by'     => (int) $project['created_by'],
                'freelancer_id'  => $project['freelancer_id'] !== null ? (int) $project['freelancer_id'] : null,
                'project_status' => $project['project_status'],
            ],
        ]);
    }

    // ── POST: kirim pesan ─────────────────────────────────────────────
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        $projectId = intval($body['project_id'] ?? 0);
        $senderId  = $sessionUserId;
        $message   = trim((string) ($body['message'] ?? ''));

        if ($projectId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'project_id wajib diisi (angka).'], 422);
        }
        if ($message === '') {
            jsonResponse(['status' => 'error', 'message' => 'Pesan tidak boleh kosong.'], 422);
        }
        if (mb_strlen($message) > 2000) {
            jsonResponse(['status' => 'error', 'message' => 'Pesan maksimal 2000 karakter.'], 422);
        }

        $project = getProjectForChat($pdo, $projectId);
        if (!$project) {
            jsonResponse(['status' => 'error', 'message' => 'Proyek tidak ditemukan.'], 404);
        }
        if (empty($project['freelancer_id'])) {
            jsonResponse(['status' => 'error', 'message' => 'Belum ada freelancer yang mengerjakan proyek ini — chat belum tersedia.'], 409);
        }

        // Tentukan lawan bicara secara otomatis
        $ownerId      = (int) $project['created_by'];
        $freelancerId = (int) $project['freelancer_id'];

        if ($senderId === $ownerId) {
            $receiverId = $freelancerId;
        } elseif ($senderId === $freelancerId) {
            $receiverId = $ownerId;
        } else {
            jsonResponse(['status' => 'error', 'message' => 'Anda bukan partisipan chat proyek ini.'], 403);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO chats (project_id, sender_id, receiver_id, message, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$projectId, $senderId, $receiverId, $message]);
        $newId = (int) $pdo->lastInsertId();

        // Kembalikan baris pesan yang baru dibuat (dengan nama pengirim)
        $stmt = $pdo->prepare(
            'SELECT c.id, c.project_id, c.sender_id, c.receiver_id, c.message, c.created_at,
                    u.name AS sender_name, u.business_name AS sender_business_name
             FROM chats c JOIN users u ON u.id = c.sender_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$newId]);

        jsonResponse([
            'status'  => 'success',
            'message' => 'Pesan terkirim.',
            'data'    => $stmt->fetch(),
        ], 201);
    }

    jsonResponse(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
