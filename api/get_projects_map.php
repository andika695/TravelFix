<?php
// api/get_projects_map.php — Titik-titik proyek untuk Creative Trail Map
// GET (tanpa parameter)
//
// Mengembalikan semua proyek yang SEDANG AKTIF/BERJALAN (Open, In Progress,
// Submitted — belum Completed/Closed) DAN sudah punya koordinat (dipilih
// lewat Map Picker di form "Buat Proyek Baru" UMKM). Dipakai oleh
// initTrailLeafletMap() di js/app.js untuk merender marker otomatis di
// halaman trail-map.html.
//
// Sukses → { "status": "success", "message": "...", "data": [ {
//            id_proyek, judul_proyek, nama_umkm, kategori, latitude,
//            longitude, lokasi_teks } ] }
// Gagal  → { "status": "error", "message": "..." } (selalu JSON valid)

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
}

try {
    $pdo = getDB();

    $stmt = $pdo->query(
        "SELECT p.id, p.title, p.location, p.latitude, p.longitude,
                COALESCE(NULLIF(u.business_name, ''), u.name, 'UMKM Bantul') AS nama_umkm,
                GROUP_CONCAT(DISTINCT pc.category SEPARATOR ', ') AS kategori
         FROM projects p
         LEFT JOIN users u ON u.id = p.created_by
         LEFT JOIN project_categories pc ON pc.project_id = p.id
         WHERE p.project_status IN ('Open', 'In Progress', 'Submitted')
           AND p.latitude IS NOT NULL
           AND p.longitude IS NOT NULL
         GROUP BY p.id
         ORDER BY p.created_at DESC"
    );
    $rows = $stmt->fetchAll();

    $data = array_map(static function (array $r): array {
        return [
            'id_proyek'    => (int) $r['id'],
            'judul_proyek' => $r['title'],
            'nama_umkm'    => $r['nama_umkm'],
            'kategori'     => $r['kategori'] ?: 'Umum',
            'latitude'     => (float) $r['latitude'],
            'longitude'    => (float) $r['longitude'],
            'lokasi_teks'  => $r['location'] ?: '-',
        ];
    }, $rows);

    jsonResponse([
        'status'  => 'success',
        'message' => 'Titik proyek berhasil dimuat.',
        'data'    => $data,
    ]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
