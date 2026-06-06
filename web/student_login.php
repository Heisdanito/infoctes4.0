<?php
// ============================================================
//  api/auth/student_login.php
//  POST /api/auth/student_login.php
//  Body: { "index_number": "UEW/ICT/0001/22" }
//  Returns: { success, message, data: { user } }
// ============================================================

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', [], 405);
}

$body        = getBody();
$indexNumber = clean($body['index_number'] ?? '');

// ── Validate ──────────────────────────────────────────────────
if (empty($indexNumber)) {
    respond(false, 'Index number is required.', [], 422);
}

if (strlen($indexNumber) < 5) {
    respond(false, 'Enter a valid index number.', [], 422);
}

$db = getDB();

// ── Fetch student with programme, group and active period ─────
$stmt = $db->prepare("
    SELECT
        s.student_id,
        s.index_number,
        s.first_name,
        s.last_name,
        s.email,
        s.phone,
        s.level,
        p.programme_id,
        p.name          AS programme_name,
        p.code          AS programme_code,
        sg.group_id,
        sg.group_number,
        ap.period_id,
        ap.label        AS current_period,
        ap.academic_year,
        ap.semester_number
    FROM student s
    JOIN programme       p  ON p.programme_id = s.programme_id
    JOIN student_group   sg ON sg.group_id    = s.group_id
    JOIN academic_period ap ON ap.period_id   = sg.period_id
                           AND ap.is_active   = 1
    WHERE s.index_number = ?
    LIMIT 1
");

if (!$stmt) {
    respond(false, 'Server error. Please try again.', [], 500);
}

$stmt->bind_param('s', $indexNumber);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    respond(false, 'Index number not found. Please check and try again.', [], 404);
}

// ── Build user object ─────────────────────────────────────────
$user = [
    'student_id'      => (int) $student['student_id'],
    'index_number'    => $student['index_number'],
    'full_name'       => $student['first_name'] . ' ' . $student['last_name'],
    'first_name'      => $student['first_name'],
    'last_name'       => $student['last_name'],
    'initials'        => strtoupper(
                            substr($student['first_name'], 0, 1) .
                            substr($student['last_name'],  0, 1)
                         ),
    'email'           => $student['email'],
    'phone'           => $student['phone'],
    'level'           => (int) $student['level'],
    'programme_id'    => (int) $student['programme_id'],
    'programme_name'  => $student['programme_name'],
    'programme_code'  => $student['programme_code'],
    'group_id'        => (int) $student['group_id'],
    'group_number'    => (int) $student['group_number'],
    'period_id'       => (int) $student['period_id'],
    'current_period'  => $student['current_period'],
    'academic_year'   => $student['academic_year'],
    'semester_number' => (int) $student['semester_number'],
    'role'            => 'student',
    'login_time'      => date('Y-m-d H:i:s'),
];

// ── Store in session ──────────────────────────────────────────
$_SESSION['user'] = $user;

respond(true, 'Login successful. Welcome, ' . $student['first_name'] . '.', [
    'user' => $user,
]);
