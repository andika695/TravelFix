<?php
// api/projects.php — Endpoint CRUD Proyek
// GET    ?id=N            → satu proyek (+ freelancer_name yang mengerjakan)
// GET    ?created_by=N    → hanya proyek milik user (UMKM) tersebut
// GET    (no param)       → semua proyek + applicants
// POST   action=create → buat proyek baru
//        (body opsional: latitude, longitude — dari Map Picker UMKM;
//         dipakai untuk merender marker otomatis di api/get_projects_map.php)
// POST   action=update_status&id=N&status=X&actor_id=M → ubah project_status
//        (X: Open | In Progress | Submitted | Completed | Closed; M wajib admin)
// DELETE ?id=N&actor_id=M → hapus proyek permanen (M wajib admin)
//
// Catatan: sistem status baru memakai kolom `project_status`; kolom `status`
// lama tetap di-select sebagai alias agar frontend lama tidak rusak.

require_once __DIR__ . '/config.php';

setCorsHeaders();

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Kolom yang di-select untuk semua query proyek (sekali definisi, dipakai bersama)
const PROJECT_COLS = 'p.id, p.created_by, p.title, p.description, p.budget, p.deadline,
        p.project_status, p.project_status AS status, p.icon, p.location, p.requirements,
        p.freelancer_id, p.freelancer_id AS assigned_to, p.created_at, p.updated_at,
        p.submission_link, p.rating, p.review_text, p.latitude, p.longitude,
        u.name AS creator_name, u.business_name AS creator_business_name,
        f.name AS freelancer_name';

const PROJECT_JOINS = ' FROM projects p
        LEFT JOIN users u ON u.id = p.created_by
        LEFT JOIN users f ON f.id = p.freelancer_id';

// ── GET ──────────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id > 0) {
        // Satu proyek + categories + applicants
        $stmt = $pdo->prepare('SELECT ' . PROJECT_COLS . PROJECT_JOINS . ' WHERE p.id = ?');
        $stmt->execute([$id]);
        $project = $stmt->fetch();

        if (!$project) {
            jsonResponse(['success' => false, 'message' => 'Proyek tidak ditemukan.'], 404);
        }

        $project['categories'] = fetchCategories($pdo, $id);
        $project['applicants'] = fetchApplicants($pdo, $id);

        jsonResponse(['success' => true, 'project' => $project]);
    } else {
        // Semua proyek, atau hanya milik satu UMKM jika ?created_by= diberikan
        $createdBy = isset($_GET['created_by']) ? (int)$_GET['created_by'] : 0;

        $sql = 'SELECT ' . PROJECT_COLS . PROJECT_JOINS;

        if ($createdBy > 0) {
            $sql .= ' WHERE p.created_by = :created_by';
        }
        $sql .= ' ORDER BY p.created_at DESC';

        $stmt = $pdo->prepare($sql);
        if ($createdBy > 0) {
            $stmt->execute([':created_by' => $createdBy]);
        } else {
            $stmt->execute();
        }
        $projects = $stmt->fetchAll();

        foreach ($projects as &$p) {
            $p['categories'] = fetchCategories($pdo, $p['id']);
            $p['applicants'] = fetchApplicants($pdo, $p['id']);
        }
        unset($p);

        jsonResponse(['success' => true, 'projects' => $projects, 'total' => count($projects)]);
    }
}

// ── POST ──────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $_GET['action'] ?? ($body['action'] ?? 'create');

    // -- Ubah status (dipakai admin "Tutup Proyek" dan alur lainnya) --
    if ($action === 'update_status') {
        $id      = isset($_GET['id'])    ? (int)$_GET['id']     : (isset($body['id'])     ? (int)$body['id']     : 0);
        $status  = isset($_GET['status']) ? $_GET['status']      : ($body['status'] ?? '');
        requireRole($pdo, currentSessionUserId(), ['admin']);

        $allowed = ['Open', 'In Progress', 'Submitted', 'Completed', 'Closed'];
        if (!in_array($status, $allowed, true)) {
            jsonResponse(['success' => false, 'message' => 'Status tidak valid.'], 422);
        }
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'ID proyek diperlukan.'], 422);
        }

        $pdo->prepare('UPDATE projects SET project_status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, $id]);
        syncLegacyStatus($pdo, $id, $status);

        jsonResponse(['success' => true, 'message' => 'Status diperbarui.', 'project_status' => $status]);
    }

    // -- Buat proyek --
    // created_by SELALU diambil dari session yang sedang login, BUKAN dari
    // body['created_by'] yang dikirim client -- kalau tidak, UMKM A bisa
    // membuat proyek atas nama UMKM B hanya dengan mengganti angka di body.
    $actor       = requireRole($pdo, currentSessionUserId(), ['umkm', 'admin']);
    $createdBy   = currentSessionUserId();

    // Wajib lengkapi Lokasi Bisnis di profil sebelum membuat proyek -- dicek
    // juga di frontend (initCreateProjectModal di js/app.js) sebelum modal
    // dibuka; ini lapisan otoritatif agar tidak bisa dilewati lewat curl.
    // HANYA berlaku utk role umkm -- admin bukan "bisnis" dan tidak punya
    // form Profil Saya dengan field Lokasi Bisnis, jadi tidak masuk akal
    // mewajibkan admin mengisinya sebelum bisa membuat/menguji proyek.
    if ($actor['role'] === 'umkm') {
        $actorLocStmt = $pdo->prepare('SELECT location FROM users WHERE id = ? LIMIT 1');
        $actorLocStmt->execute([$createdBy]);
        $actorLocation = trim((string) ($actorLocStmt->fetchColumn() ?: ''));
        if ($actorLocation === '') {
            jsonResponse(['success' => false, 'message' => 'Silakan lengkapi Lokasi Bisnis di Profil Anda terlebih dahulu sebelum membuat proyek.'], 422);
        }
    }

    $title       = trim($body['title']       ?? '');
    $description = trim($body['description'] ?? '');
    $budget      = isset($body['prize'])  ? $body['prize']  : ($body['budget'] ?? null);
    $deadline    = trim($body['deadline'] ?? '');
    $location    = trim($body['location'] ?? '');
    $requirements= trim($body['requirements'] ?? '');
    $icon        = trim($body['icon']       ?? 'puzzle');
    $categories  = is_array($body['categories'] ?? null) ? $body['categories'] : [];
    $latitude    = isset($body['latitude'])  && $body['latitude']  !== '' ? (float)$body['latitude']  : null;
    $longitude   = isset($body['longitude']) && $body['longitude'] !== '' ? (float)$body['longitude'] : null;

    if (!$title) {
        jsonResponse(['success' => false, 'message' => 'Judul proyek wajib diisi.'], 422);
    }

    // Validasi koordinat: opsional, tapi kalau dikirim harus masuk akal dan
    // berada di sekitar wilayah Kabupaten Bantul (sama dengan maxBounds Map
    // Picker + sedikit toleransi). Nilai di luar itu diabaikan (NULL) — bukan
    // menggagalkan seluruh pembuatan proyek, karena lokasi teks tetap wajib
    // & sudah cukup untuk fungsi utama proyek.
    if ($latitude !== null && ($latitude < -8.20 || $latitude > -7.55)) {
        $latitude = null;
    }
    if ($longitude !== null && ($longitude < 110.00 || $longitude > 110.65)) {
        $longitude = null;
    }
    if ($latitude === null || $longitude === null) {
        $latitude  = null;
        $longitude = null;
    }

    // Bersihkan budget: hapus titik ribu, ganti koma desimal ke titik
    $budgetClean = $budget;
    if (!is_numeric($budgetClean) && $budgetClean !== null) {
        $budgetClean = str_replace(' ', '', $budgetClean);
        $budgetClean = str_replace('.', '', $budgetClean);
        $budgetClean = str_replace(',', '.', $budgetClean);
    }
    if (!is_numeric($budgetClean)) {
        $budgetClean = null;
    }

    // Kolom `status` lama tidak disebut — nilai default DB ('Open') yang mengisi,
    // sehingga INSERT tetap valid meskipun kolom lama nanti dihapus.
    $stmt = $pdo->prepare(
        'INSERT INTO projects (created_by, title, description, budget, deadline, project_status, icon, location, requirements, latitude, longitude, created_at, updated_at)
         VALUES (:created_by, :title, :description, :budget, :deadline, :project_status, :icon, :location, :requirements, :latitude, :longitude, NOW(), NOW())'
    );
    $stmt->execute([
        ':created_by'     => $createdBy,
        ':title'          => $title,
        ':description'    => $description ?: null,
        ':budget'         => $budgetClean ?: null,
        ':deadline'       => $deadline    ?: null,
        ':project_status' => 'Open',
        ':icon'           => $icon,
        ':location'       => $location    ?: null,
        ':requirements'   => $requirements ?: null,
        ':latitude'       => $latitude,
        ':longitude'      => $longitude,
    ]);

    $newId = (int)$pdo->lastInsertId();

    // Simpan categories
    if (!empty($categories)) {
        $catStmt = $pdo->prepare('INSERT INTO project_categories (project_id, category) VALUES (?, ?)');
        foreach ($categories as $cat) {
            if (trim($cat)) $catStmt->execute([$newId, trim($cat)]);
        }
    }

    jsonResponse(['success' => true, 'message' => 'Proyek berhasil dibuat.', 'id' => $newId], 201);
}

// ── DELETE ────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    requireRole($pdo, currentSessionUserId(), ['admin']);

    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'ID proyek diperlukan.'], 422);
    }
    $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
    jsonResponse(['success' => true, 'message' => 'Proyek berhasil dihapus.']);
}

jsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.'], 405);

// ── Helpers ───────────────────────────────────────────────────────
function fetchCategories(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare('SELECT category FROM project_categories WHERE project_id=?');
    $stmt->execute([$projectId]);
    return array_column($stmt->fetchAll(), 'category');
}

function fetchApplicants(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare('SELECT freelancer_id FROM project_applicants WHERE project_id=?');
    $stmt->execute([$projectId]);
    return array_column($stmt->fetchAll(), 'freelancer_id');
}

/**
 * Sinkronkan kolom `status` lama (enum lama tidak punya
 * Submitted/Completed, jadi dipetakan ke nilai terdekat).
 */
function syncLegacyStatus(PDO $pdo, int $projectId, string $newStatus): void {
    $map = [
        'Open'        => 'Open',
        'In Progress' => 'In Progress',
        'Submitted'   => 'In Progress',
        'Completed'   => 'Done',
        'Closed'      => 'Closed',
    ];
    try {
        $pdo->prepare('UPDATE projects SET status = ? WHERE id = ?')
            ->execute([$map[$newStatus] ?? 'Open', $projectId]);
    } catch (PDOException $e) { /* kolom lama sudah dihapus — abaikan */ }
}
