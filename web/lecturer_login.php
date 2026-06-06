<?php
// ============================================================
//  api/auth/lecturer_login.php
//  POST /api/auth/lecturer_login.php
//  Body: { "email": "john@uew.edu.gh", "password": "secret" }
//  Returns: { success, message, data: { user } }
// ============================================================

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', [], 405);
}

$body     = getBody();
$email    = clean($body['email']    ?? '');
$password = (string) ($body['password'] ?? '');

// ── Validate ──────────────────────────────────────────────────
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'A valid email address is required.', [], 422);
}

if (empty($password)) {
    respond(false, 'Password is required.', [], 422);
}

$db = getDB();

// ── Fetch lecturer ────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        lecturer_id,
        staff_id,
        first_name,
        last_name,
        email,
        password_hash
    FROM lecturer
    WHERE email = ?
    LIMIT 1
");

if (!$stmt) {
    respond(false, 'Server error. Please try again.', [], 500);
}

$stmt->bind_param('s', $email);
$stmt->execute();
$lecturer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lecturer) {
    respond(false, 'No account found with that email.', [], 404);
}

if (!password_verify($password, $lecturer['password_hash'])) {
    respond(false, 'Incorrect password. Please try again.', [], 401);
}

// ── Fetch courses taught this active semester ─────────────────
$cStmt = $db->prepare("
    SELECT DISTINCT
        c.course_id,
        c.code,
        c.title,
        c.level,
        c.credit_hours
    FROM timetable t
    JOIN course          c  ON c.course_id  = t.course_id
    JOIN academic_period ap ON ap.period_id = t.period_id
                           AND ap.is_active = 1
    WHERE t.lecturer_id = ?
    ORDER BY c.level ASC, c.code ASC
");

$courses = [];
if ($cStmt) {
    $cStmt->bind_param('i', $lecturer['lecturer_id']);
    $cStmt->execute();
    $cResult = $cStmt->get_result();
    while ($row = $cResult->fetch_assoc()) {
        $courses[] = [
            'course_id'    => (int) $row['course_id'],
            'code'         => $row['code'],
            'title'        => $row['title'],
            'level'        => (int) $row['level'],
            'credit_hours' => (int) $row['credit_hours'],
        ];
    }
    $cStmt->close();
}

// ── Build user object ─────────────────────────────────────────
$user = [
    'lecturer_id' => (int) $lecturer['lecturer_id'],
    'staff_id'    => $lecturer['staff_id'],
    'full_name'   => $lecturer['first_name'] . ' ' . $lecturer['last_name'],
    'first_name'  => $lecturer['first_name'],
    'last_name'   => $lecturer['last_name'],
    'initials'    => strtoupper(
                        substr($lecturer['first_name'], 0, 1) .
                        substr($lecturer['last_name'],  0, 1)
                     ),
    'email'       => $lecturer['email'],
    'role'        => 'lecturer',
    'courses'     => $courses,
    'login_time'  => date('Y-m-d H:i:s'),
];

// ── Store in session ──────────────────────────────────────────
$_SESSION['user'] = $user;

respond(true, 'Login successful. Welcome, ' . $lecturer['first_name'] . '.', [
    'user' => $user,
]);
