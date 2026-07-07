<?php
// api/dashboard_stats.php — Statistik agregat untuk Admin Dashboard
// GET [?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD]
//   → { success, labels[], users[], projects[], totals: {...}, range: {...} }
//
//   labels/users/projects = pertumbuhan per-bulan (untuk grafik) dalam rentang
//   totals                = angka ringkasan (untuk kartu statistik)
//
// Tanpa parameter: rentang default = 6 bulan terakhir (perilaku lama),
// dan totals dihitung dari SELURUH data (all-time).
// Dengan start_date/end_date: grafik & totals dihitung hanya dari data
// yang created_at-nya berada dalam rentang tersebut.

require_once __DIR__ . '/config.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$pdo = getDB();

// Statistik agregat ini hanya untuk Admin Dashboard -- sebelumnya bisa
// diakses siapa pun tanpa login sama sekali (bocorkan jumlah user/proyek
// dan tren pertumbuhan platform ke publik).
requireRole($pdo, requireAuth($pdo)['id'], ['admin']);

// ── Baca & validasi rentang tanggal ─────────────────────────────────
function parseDateParam(string $key): ?string {
    if (empty($_GET[$key])) return null;
    $d = DateTime::createFromFormat('Y-m-d', $_GET[$key]);
    return ($d && $d->format('Y-m-d') === $_GET[$key]) ? $_GET[$key] : null;
}

$startDate = parseDateParam('start_date');
$endDate   = parseDateParam('end_date');
$hasRange  = ($startDate !== null && $endDate !== null);

if ($hasRange && $startDate > $endDate) {
    jsonResponse(['success' => false, 'message' => 'start_date tidak boleh melebihi end_date.'], 422);
}

// Rentang default grafik: 6 bulan terakhir (termasuk bulan berjalan)
if (!$hasRange) {
    $endDate   = date('Y-m-d');
    $startDate = date('Y-m-01', strtotime(date('Y-m-01') . ' -5 months'));
}

$rangeStart = $startDate . ' 00:00:00';
$rangeEnd   = $endDate   . ' 23:59:59';

// ── Susun bucket bulan dari start → end (maksimal 24 bulan) ─────────
$monthNames = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$months  = [];
$cursor  = new DateTime(date('Y-m-01', strtotime($startDate)));
$endMark = new DateTime(date('Y-m-01', strtotime($endDate)));
$guard   = 0;
while ($cursor <= $endMark && $guard < 24) {
    $months[] = [
        'key'   => $cursor->format('Y-m'),
        'label' => $monthNames[(int)$cursor->format('n') - 1] . ' ' . $cursor->format('y'),
    ];
    $cursor->modify('+1 month');
    $guard++;
}

function monthlyCounts(PDO $pdo, string $table, string $start, string $end): array {
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
         FROM `$table`
         WHERE created_at BETWEEN :start AND :end
         GROUP BY ym"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['ym']] = (int)$row['cnt'];
    }
    return $out;
}

$userCounts    = monthlyCounts($pdo, 'users', $rangeStart, $rangeEnd);
$projectCounts = monthlyCounts($pdo, 'projects', $rangeStart, $rangeEnd);

$labels   = [];
$users    = [];
$projects = [];
foreach ($months as $m) {
    $labels[]   = $m['label'];
    $users[]    = $userCounts[$m['key']]    ?? 0;
    $projects[] = $projectCounts[$m['key']] ?? 0;
}

// ── Ringkasan total ──────────────────────────────────────────────────
// Jika filter tanggal aktif, semua total dihitung dalam rentang tersebut.
$userWhere    = $hasRange ? ' WHERE created_at BETWEEN :start AND :end' : '';
$projectWhere = $hasRange ? ' AND created_at BETWEEN :start AND :end'   : '';
$rangeParams  = $hasRange ? [':start' => $rangeStart, ':end' => $rangeEnd] : [];

function countQuery(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$totalUsers  = countQuery($pdo, 'SELECT COUNT(*) FROM users' . $userWhere, $rangeParams);
$freelancers = countQuery($pdo, "SELECT COUNT(*) FROM users WHERE role = 'freelancer'" . str_replace('WHERE', 'AND', $userWhere), $rangeParams);
$umkmCount   = countQuery($pdo, "SELECT COUNT(*) FROM users WHERE role = 'umkm'" . str_replace('WHERE', 'AND', $userWhere), $rangeParams);

$totalProjects = countQuery($pdo, 'SELECT COUNT(*) FROM projects' . ($hasRange ? ' WHERE created_at BETWEEN :start AND :end' : ''), $rangeParams);
$openProjects  = countQuery($pdo, "SELECT COUNT(*) FROM projects WHERE project_status = 'Open'" . $projectWhere, $rangeParams);
$inProgress    = countQuery($pdo, "SELECT COUNT(*) FROM projects WHERE project_status IN ('In Progress','Submitted')" . $projectWhere, $rangeParams);
$completed     = countQuery($pdo, "SELECT COUNT(*) FROM projects WHERE project_status = 'Completed'" . $projectWhere, $rangeParams);
$closed        = countQuery($pdo, "SELECT COUNT(*) FROM projects WHERE project_status = 'Closed'" . $projectWhere, $rangeParams);

// ── Widget "Aktivitas Terbaru", "5 Proyek Terbaru", "Kategori Proyek
// Populer" (menggantikan komponen dummy peninggalan template travel di
// admin-dashboard.html) — SENGAJA TIDAK ikut difilter start_date/end_date
// di atas: ketiganya adalah widget "terbaru/terpopuler apa pun", bukan
// widget rentang waktu -- kalau ikut difilter, widget bisa tampak kosong
// membingungkan saat rentang filter kebetulan tidak mencakup data terbaru.

// Aktivitas Terbaru: gabungan 5 user terbaru mendaftar + 5 proyek terbaru
// dibuat, disatukan lalu diurutkan ulang berdasarkan created_at, diambil 5
// teratas gabungan (bukan sekadar 5+5 ditumpuk).
$recentUsersStmt = $pdo->query(
    "SELECT name, business_name, role, created_at
     FROM users ORDER BY created_at DESC LIMIT 5"
);
$recentActivity = [];
foreach ($recentUsersStmt->fetchAll() as $u) {
    $displayName = ($u['role'] === 'umkm' && !empty($u['business_name'])) ? $u['business_name'] : $u['name'];
    $roleLabel   = $u['role'] === 'umkm' ? 'UMKM' : ($u['role'] === 'freelancer' ? 'Freelancer' : 'Admin');
    $recentActivity[] = [
        'type'       => 'user_registered',
        'title'      => "{$displayName} mendaftar sebagai {$roleLabel}",
        'created_at' => $u['created_at'],
    ];
}

$recentProjectsForFeedStmt = $pdo->query(
    "SELECT title, created_at FROM projects ORDER BY created_at DESC LIMIT 5"
);
foreach ($recentProjectsForFeedStmt->fetchAll() as $p) {
    $recentActivity[] = [
        'type'       => 'project_created',
        'title'      => "Proyek baru dibuat: {$p['title']}",
        'created_at' => $p['created_at'],
    ];
}

usort($recentActivity, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
$recentActivity = array_slice($recentActivity, 0, 5);

// 5 Proyek Terbaru (tabel): join ke users untuk nama pemberi kerja (UMKM).
$recentProjectsStmt = $pdo->query(
    "SELECT p.title, p.budget, p.project_status AS status,
            COALESCE(NULLIF(u.business_name, ''), u.name, 'Tidak diketahui') AS creator_name
     FROM projects p
     LEFT JOIN users u ON u.id = p.created_by
     ORDER BY p.created_at DESC
     LIMIT 5"
);
$recentProjects = $recentProjectsStmt->fetchAll();

// Kategori Proyek Populer: top 5 kategori (project_categories.category)
// berdasarkan jumlah proyek yang memakainya.
$topCategoriesStmt = $pdo->query(
    "SELECT category, COUNT(*) AS total
     FROM project_categories
     GROUP BY category
     ORDER BY total DESC, category ASC
     LIMIT 5"
);
$topCategories = $topCategoriesStmt->fetchAll();

jsonResponse([
    'success'  => true,
    'labels'   => $labels,
    'users'    => $users,
    'projects' => $projects,
    'range'    => ['start' => $startDate, 'end' => $endDate, 'filtered' => $hasRange],
    'totals'   => [
        'users'             => $totalUsers,
        'freelancers'       => $freelancers,
        'umkm'              => $umkmCount,
        'projects'          => $totalProjects,
        'open'              => $openProjects,
        'inProgress'        => $inProgress,
        'completed'         => $completed,
        'closed'            => $closed,
        // Alias lama agar konsumen sebelumnya tetap bekerja
        'activeProjects'    => $openProjects + $inProgress,
        'completedProjects' => $completed + $closed,
    ],
    'recentActivity'  => $recentActivity,
    'recentProjects'  => $recentProjects,
    'topCategories'   => $topCategories,
]);
