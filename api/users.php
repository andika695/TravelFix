<?php
// api/users.php — CRUD pengguna (untuk admin)
// GET    /api/users.php           → Daftar semua user
// GET    /api/users.php?id=N      → Detail user by ID (termasuk business_name UMKM)
// PUT    /api/users.php?id=N      → Update data profil user
//        Body wajib sertakan actor_id: pemilik profil sendiri (actor_id === id)
//        ATAU admin.
// DELETE /api/users.php?id=N&actor_id=M → Hapus user permanen (M wajib admin)
// POST   /api/users.php?action=suspend&id=N&actor_id=M → Toggle account_status (M wajib admin)

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id'])     ? (int) $_GET['id']     : null;
$action = isset($_GET['action']) ? $_GET['action']        : null;

// Semua query di bawah dibungkus try/catch: sebelumnya file ini TIDAK
// menangkap PDOException sama sekali (beda dari get_user_profile.php/
// ai_talent_match.php/dst yang sudah benar sejak awal) -- kalau UPDATE gagal
// karena alasan apa pun di server (constraint DB, koneksi putus, dst), PHP
// akan memuntahkan fatal error mentah (HTML/kosong, TANPA Content-Type
// application/json) alih-alih JSON, dan itu yang bikin `response.json()` di
// frontend gagal parse ("Unexpected token/end of JSON input") -- persis
// "JSON crash" yang selama ini muncul di console.
try {
    $pdo = getDB();

    // ── GET: Ambil daftar / detail user ──────────────────────────
    if ($method === 'GET') {
        if ($id) {
            $stmt = $pdo->prepare(
                'SELECT id, name, email, public_email, role, account_status, account_status AS status,
                        bio, location, portfolio_url, business_name, business_category,
                        `field`, whatsapp, instagram, linkedin, website, address,
                        latitude, longitude, created_at, updated_at
                 FROM   users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $user = $stmt->fetch();

            if (!$user) {
                jsonResponse(['success' => false, 'status' => 'error', 'message' => 'User tidak ditemukan.'], 404);
            }

            // Tambahkan skills dan interests
            $user['skills']    = getSkills($pdo, $id);
            $user['interests'] = getInterests($pdo, $id);

            jsonResponse(['success' => true, 'status' => 'success', 'user' => $user]);
        } else {
            $stmt = $pdo->query(
                'SELECT id, name, email, public_email, role, account_status, account_status AS status,
                        business_name, business_category, `field`, latitude, longitude, created_at
                 FROM users ORDER BY id DESC'
            );
            $users = $stmt->fetchAll();

            // Lampirkan skills & interests tiap user (dibutuhkan modal profil di
            // sisi UMKM/Admin — sebelumnya daftar ini tidak menyertakannya sama
            // sekali sehingga cache client-side (db.getUserById) selalu kosong).
            foreach ($users as &$u) {
                $u['skills']    = getSkills($pdo, (int) $u['id']);
                $u['interests'] = getInterests($pdo, (int) $u['id']);
            }
            unset($u);

            jsonResponse(['success' => true, 'status' => 'success', 'users' => $users, 'total' => count($users)]);
        }
    }

    // ── POST: Toggle suspend (account_status) ─────────────────────
    if ($method === 'POST' && $action === 'suspend' && $id) {
        requireRole($pdo, currentSessionUserId(), ['admin']);

        $stmt = $pdo->prepare('SELECT id, role, account_status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(['success' => false, 'status' => 'error', 'message' => 'User tidak ditemukan.'], 404);
        }
        if ($user['role'] === 'admin') {
            jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Akun admin tidak dapat di-suspend.'], 403);
        }

        $newStatus = ($user['account_status'] === 'Suspended') ? 'Active' : 'Suspended';
        $pdo->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$newStatus, $id]);

        // Sinkronkan kolom status lama (jika masih ada) agar data konsisten
        try {
            $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')
                ->execute([strtolower($newStatus), $id]);
        } catch (PDOException $e) { /* kolom lama sudah dihapus — abaikan */ }

        jsonResponse([
            'success' => true,
            'status'  => 'success',
            'message' => $newStatus === 'Suspended'
                ? 'Akun berhasil dibekukan (Suspended).'
                : 'Akun berhasil diaktifkan kembali.',
            'account_status' => $newStatus,
        ]);
    }

    // ── PUT: Update profil user ───────────────────────────────────
    if ($method === 'PUT' && $id) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Boleh mengedit profil sendiri (session user === id), atau admin mengedit siapa pun
        $actorId = currentSessionUserId();
        if ($actorId !== $id) {
            requireRole($pdo, $actorId, ['admin']);
        }

        $allowed = [
            'name', 'bio', 'location', 'portfolio_url', 'business_name', 'business_category',
            'field', 'public_email', 'whatsapp', 'instagram', 'linkedin', 'website', 'address'
        ];

        $set    = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $set[]    = "`$field` = ?";
                $params[] = $body[$field];
            }
        }

        // Koordinat (dipilih lewat Map Picker di Profil Saya) — hanya disentuh
        // kalau KEDUA field dikirim bersamaan (bukan lewat loop $allowed di atas
        // karena butuh validasi & cast angka, bukan disalin mentah-mentah seperti
        // field teks biasa).
        //
        // Beda dengan api/projects.php (proyek baru tanpa koordinat sebelumnya,
        // jadi aman di-null-kan): di sini SUDAH ada koordinat valid tersimpan
        // sebelumnya — koordinat di luar Bantul harus DITOLAK EKSPLISIT (bukan
        // diam-diam di-null-kan), supaya data lama yang valid tidak hilang gara-gara
        // request yang keliru/nakal.
        if (array_key_exists('latitude', $body) && array_key_exists('longitude', $body)) {
            $latRaw = $body['latitude'];
            $lngRaw = $body['longitude'];

            if (($latRaw === '' || $latRaw === null) || ($lngRaw === '' || $lngRaw === null)) {
                jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Latitude dan longitude harus diisi berdua, atau dikosongkan berdua.'], 422);
            }

            $lat = (float) $latRaw;
            $lng = (float) $lngRaw;

            if ($lat < -8.20 || $lat > -7.55 || $lng < 110.00 || $lng > 110.65) {
                jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Koordinat berada di luar wilayah Kabupaten Bantul. Silakan pilih ulang lokasi lewat peta.'], 422);
            }

            $set[]    = '`latitude` = ?';
            $set[]    = '`longitude` = ?';
            $params[] = $lat;
            $params[] = $lng;
        }

        $hasSkillsUpdate    = isset($body['skills'])    && is_array($body['skills']);
        $hasInterestsUpdate = isset($body['interests']) && is_array($body['interests']);

        if (empty($set) && !$hasSkillsUpdate && !$hasInterestsUpdate) {
            jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Tidak ada data yang diperbarui.'], 422);
        }

        if (!empty($set)) {
            $params[] = $id;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')
                ->execute($params);
        }

        // Update skills jika dikirim (tabel relasional + kolom teks users.skills)
        if ($hasSkillsUpdate) {
            $skills = array_values(array_filter(array_map('trim', $body['skills'])));
            $pdo->prepare('DELETE FROM user_skills WHERE user_id = ?')->execute([$id]);
            $insSkill = $pdo->prepare('INSERT INTO user_skills (user_id, skill) VALUES (?, ?)');
            foreach ($skills as $skill) {
                $insSkill->execute([$id, $skill]);
            }
            $pdo->prepare('UPDATE users SET skills = ? WHERE id = ?')
                ->execute([$skills ? implode(', ', $skills) : null, $id]);
        }

        // Update interests jika dikirim (tabel relasional + kolom teks users.interests)
        if ($hasInterestsUpdate) {
            $interests = array_values(array_filter(array_map('trim', $body['interests'])));
            $pdo->prepare('DELETE FROM user_interests WHERE user_id = ?')->execute([$id]);
            $insInt = $pdo->prepare('INSERT INTO user_interests (user_id, interest) VALUES (?, ?)');
            foreach ($interests as $interest) {
                $insInt->execute([$id, $interest]);
            }
            $pdo->prepare('UPDATE users SET interests = ? WHERE id = ?')
                ->execute([$interests ? implode(', ', $interests) : null, $id]);
        }

        jsonResponse(['success' => true, 'status' => 'success', 'message' => 'Profil berhasil diperbarui.']);
    }

    // ── DELETE: Hapus user permanen ───────────────────────────────
    if ($method === 'DELETE' && $id) {
        requireRole($pdo, currentSessionUserId(), ['admin']);

        $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(['success' => false, 'status' => 'error', 'message' => 'User tidak ditemukan.'], 404);
        }
        if ($user['role'] === 'admin') {
            jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Akun admin tidak dapat dihapus.'], 403);
        }

        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true, 'status' => 'success', 'message' => 'User berhasil dihapus.']);
    }

    jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Request tidak valid.'], 400);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}

// ── Helpers ───────────────────────────────────────────────────
function getSkills(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT skill FROM user_skills WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_column($stmt->fetchAll(), 'skill');
}

function getInterests(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT interest FROM user_interests WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_column($stmt->fetchAll(), 'interest');
}
