<?php
// api/logout.php — Hancurkan session PHP di server
// POST (tanpa body) → { "success": true }
//
// Sebelumnya "logout" cuma menghapus sessionStorage di browser (js/db.js
// logout()) tanpa pernah memberi tahu server — session PHP di server tetap
// hidup. Endpoint ini melengkapi itu supaya logout benar-benar mengakhiri
// sesi di sisi server juga.

require_once __DIR__ . '/config.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$_SESSION = [];
session_unset();
session_destroy();

jsonResponse(['success' => true, 'message' => 'Logout berhasil.']);
