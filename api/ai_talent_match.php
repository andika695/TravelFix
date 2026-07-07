<?php
// api/ai_talent_match.php — AI Proximity & Skill Matcher
// GET ?freelancer_id=N
//
// Merekomendasikan maksimal 5 proyek 'Open' yang paling cocok untuk seorang
// freelancer, berdasarkan gabungan dua skor:
//   1. Skor Skill  — kecocokan token kata antara skills/interests freelancer
//                     dengan judul + deskripsi proyek (0-100).
//   2. Skor Jarak  — kedekatan lokasi freelancer <-> proyek, dihitung dengan
//                     rumus Haversine (0-100, 100 = sangat dekat).
// Skor Akhir = (Skor Skill * 0.6) + (Skor Jarak * 0.4), diurutkan tertinggi
// ke terendah.
//
// Proyek yang tidak punya koordinat (belum dipilih lewat Map Picker UMKM)
// TIDAK diikutsertakan — tanpa koordinat, jaraknya tidak bisa dihitung sama
// sekali, jadi proyek itu tidak bisa memenuhi janji "proyek terdekat".
//
// Sukses → { "status": "success", "message": "...", "data": [ {
//            project_id, title, description, budget, deadline, icon,
//            location, umkm_name, categories[], jarak_km, skor_skill,
//            skor_jarak, skor_akhir, persentase_cocok } ] }
// Gagal  → { "status": "error", "message": "...", "code": "..." } (selalu JSON valid)

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

// Radius referensi (km) untuk menormalkan jarak menjadi skor 0-100 — kira-kira
// diagonal wilayah Kabupaten Bantul + sekitarnya (sama dgn maxBounds peta).
const AI_MATCH_MAX_RADIUS_KM = 40.0;
const AI_MATCH_SKILL_WEIGHT  = 0.6;
const AI_MATCH_DISTANCE_WEIGHT = 0.4;
const AI_MATCH_LIMIT = 5;

/** Jarak antar dua titik koordinat bumi dalam KM (rumus Haversine). */
function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadiusKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusKm * $c;
}

/** Pecah teks jadi token kata huruf/angka, huruf kecil, minimal 3 karakter. */
function tokenizeWords(string $text): array {
    $text = mb_strtolower($text);
    preg_match_all('/[a-z0-9]+/u', $text, $matches);
    $tokens = array_filter($matches[0], static fn(string $t): bool => mb_strlen($t) >= 3);
    return array_values(array_unique($tokens));
}

/**
 * Skor kecocokan skill (0-100): persentase token kata milik freelancer
 * (dari skills + interests) yang muncul di judul/deskripsi proyek.
 */
function calcSkillScore(array $freelancerTokens, string $projectText): float {
    if (empty($freelancerTokens)) return 0.0;
    $projectTokenSet = array_flip(tokenizeWords($projectText));
    $matched = 0;
    foreach ($freelancerTokens as $t) {
        if (isset($projectTokenSet[$t])) $matched++;
    }
    return min(100.0, ($matched / count($freelancerTokens)) * 100);
}

/** Skor kedekatan jarak (0-100): 100 = sangat dekat, turun linear ke 0 di radius maksimum. */
function calcDistanceScore(float $jarakKm): float {
    $skor = 100.0 - ($jarakKm / AI_MATCH_MAX_RADIUS_KM) * 100.0;
    return max(0.0, min(100.0, $skor));
}

try {
    $pdo = getDB();

    // Wajib login DAN hanya boleh melihat rekomendasi milik sendiri (atau
    // admin) -- rekomendasi ini dihitung dari lokasi & skill pribadi
    // freelancer, tidak pernah dipakai lintas-akun di frontend manapun.
    // Sebelumnya endpoint ini bisa diakses tanpa login sama sekali hanya
    // dengan menebak freelancer_id.
    $viewerId = (int) requireAuth($pdo)['id'];
    if ($viewerId !== $freelancerId) {
        requireRole($pdo, $viewerId, ['admin']);
    }

    // 1) Ambil profil freelancer: skills, interests, latitude, longitude
    $stmt = $pdo->prepare('SELECT id, name, role, latitude, longitude, skills AS skills_text, interests AS interests_text FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$freelancerId]);
    $freelancer = $stmt->fetch();

    if (!$freelancer) {
        jsonResponse(['status' => 'error', 'message' => 'Freelancer tidak ditemukan.'], 404);
    }
    if ($freelancer['role'] !== 'freelancer') {
        jsonResponse(['status' => 'error', 'message' => 'Rekomendasi AI hanya tersedia untuk akun freelancer.'], 403);
    }
    if ($freelancer['latitude'] === null || $freelancer['longitude'] === null) {
        jsonResponse([
            'status'  => 'error',
            'code'    => 'location_missing',
            'message' => 'Mohon tentukan lokasi Anda melalui peta di halaman Profil Saya agar sistem AI dapat merekomendasikan proyek terdekat!',
        ], 422);
    }

    $flLat = (float) $freelancer['latitude'];
    $flLng = (float) $freelancer['longitude'];

    // Skills & interests: sumber utama tabel relasional, fallback kolom teks
    // (pola yang sama dengan api/get_user_profile.php)
    $stmt = $pdo->prepare('SELECT skill FROM user_skills WHERE user_id = ?');
    $stmt->execute([$freelancerId]);
    $skills = array_column($stmt->fetchAll(), 'skill');

    $stmt = $pdo->prepare('SELECT interest FROM user_interests WHERE user_id = ?');
    $stmt->execute([$freelancerId]);
    $interests = array_column($stmt->fetchAll(), 'interest');

    $splitText = static function (?string $text): array {
        if ($text === null || trim($text) === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $text))));
    };
    if (empty($skills))    { $skills    = $splitText($freelancer['skills_text']); }
    if (empty($interests)) { $interests = $splitText($freelancer['interests_text']); }

    $freelancerTokens = tokenizeWords(implode(' ', array_merge($skills, $interests)));

    // 2) Ambil proyek 'Open' yang sudah punya koordinat (dipilih via Map Picker UMKM)
    $stmt = $pdo->query(
        "SELECT p.id, p.title, p.description, p.budget, p.deadline, p.icon, p.location,
                p.latitude, p.longitude,
                COALESCE(NULLIF(u.business_name, ''), u.name, 'UMKM Bantul') AS umkm_name
         FROM projects p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE p.project_status = 'Open'
           AND p.latitude IS NOT NULL
           AND p.longitude IS NOT NULL"
    );
    $rows = $stmt->fetchAll();

    $catStmt = $pdo->prepare('SELECT category FROM project_categories WHERE project_id = ?');

    $results = [];
    foreach ($rows as $p) {
        $jarakKm = haversineKm($flLat, $flLng, (float) $p['latitude'], (float) $p['longitude']);

        $projectText = $p['title'] . ' ' . $p['description'];
        $skorSkill   = calcSkillScore($freelancerTokens, $projectText);
        $skorJarak   = calcDistanceScore($jarakKm);
        $skorAkhir   = ($skorSkill * AI_MATCH_SKILL_WEIGHT) + ($skorJarak * AI_MATCH_DISTANCE_WEIGHT);

        $catStmt->execute([$p['id']]);
        $categories = array_column($catStmt->fetchAll(), 'category');

        $results[] = [
            'project_id'       => (int) $p['id'],
            'title'            => $p['title'],
            'description'      => $p['description'],
            'budget'           => (float) $p['budget'],
            'deadline'         => $p['deadline'],
            'icon'             => $p['icon'] ?: 'puzzle',
            'location'         => $p['location'] ?: '-',
            'umkm_name'        => $p['umkm_name'],
            'categories'       => $categories,
            'jarak_km'         => round($jarakKm, 1),
            'skor_skill'       => round($skorSkill, 1),
            'skor_jarak'       => round($skorJarak, 1),
            'skor_akhir'       => round($skorAkhir, 1),
            'persentase_cocok' => (int) round($skorAkhir),
        ];
    }

    // 3) Urutkan skor akhir tertinggi -> terendah, ambil 5 teratas
    usort($results, static fn(array $a, array $b): int => $b['skor_akhir'] <=> $a['skor_akhir']);
    $top = array_slice($results, 0, AI_MATCH_LIMIT);

    jsonResponse([
        'status'  => 'success',
        'message' => count($top) > 0
            ? 'Rekomendasi proyek berhasil dihitung.'
            : 'Belum ada proyek terbuka dengan lokasi terdaftar di sekitar Anda saat ini.',
        'data'    => $top,
    ]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
