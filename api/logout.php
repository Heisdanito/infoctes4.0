<?php
// ============================================================
//  api/auth/logout.php — Logout endpoint
//
//  POST /api/auth/logout
//  Body: { student_id: 123 }
// ============================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', [], 405);
}

$body = getBody();
$studentId = isset($body['student_id']) ? (int)$body['student_id'] : 0;

if ($studentId === 0) {
    respond(false, 'Student ID required.', [], 400);
}

respond(true, 'Logged out successfully.', [
    'student_id' => $studentId,
]);