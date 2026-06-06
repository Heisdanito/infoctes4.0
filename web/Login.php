<?php
// ============================================================
//  web/Login.php — Web Login (Lecturer + Course Rep)
//  Path fix: cors.php and db.php are at root of mobile-server
//
//  POST /web/Login.php
//  Body: { "role": "lecturer", "email": "...", "password": "..." }
//        { "role": "rep",      "index_number": "...", "pin": "..." }
// ============================================================

require_once __DIR__ . '/../cors.php';   // mobile-server/cors.php
require_once __DIR__ . '/../db.php';     // mobile-server/db.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', [], 405);
}

$body = getBody();
$role = clean($body['role'] ?? '');

if (!in_array($role, ['lecturer', 'rep'], true)) {
    respond(false, 'Invalid role. Must be lecturer or rep.', [], 422);
}

$db = getDB();

// ── LECTURER — email + password ───────────────────────────────
if ($role === 'lecturer') {
    $email    = clean($body['email']    ?? '');
    $password = (string) ($body['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(false, 'A valid email address is required.', [], 422);
    }
    if (empty($password)) {
        respond(false, 'Password is required.', [], 422);
    }

    $stmt = $db->prepare("
        SELECT lecturer_id, staff_id, first_name, last_name, email, password_hash
        FROM lecturer
        WHERE email = ?
        LIMIT 1
    ");
    if (!$stmt) respond(false, 'Server error. Please try again.', [], 500);

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $lecturer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$lecturer) {
        respond(false, 'No account found with that email.', [], 404);
    }
    if (!password_verify($password, $lecturer['password_hash'])) {
        respond(false, 'Incorrect password.', [], 401);
    }

    // Fetch courses this lecturer teaches this semester
    $cStmt = $db->prepare("
        SELECT DISTINCT c.course_id, c.code, c.title, c.level
        FROM timetable t
        JOIN course          c  ON c.course_id  = t.course_id
        JOIN academic_period ap ON ap.period_id = t.period_id AND ap.is_active = 1
        WHERE t.lecturer_id = ?
        ORDER BY c.level ASC, c.code ASC
    ");
    $cStmt->bind_param('i', $lecturer['lecturer_id']);
    $cStmt->execute();
    $cResult = $cStmt->get_result();
    $courses = [];
    while ($row = $cResult->fetch_assoc()) {
        $courses[] = [
            'course_id' => (int) $row['course_id'],
            'code'      => $row['code'],
            'title'     => $row['title'],
            'level'     => (int) $row['level'],
        ];
    }
    $cStmt->close();

    $user = [
        'lecturer_id' => (int) $lecturer['lecturer_id'],
        'staff_id'    => $lecturer['staff_id'],
        'full_name'   => $lecturer['first_name'] . ' ' . $lecturer['last_name'],
        'first_name'  => $lecturer['first_name'],
        'last_name'   => $lecturer['last_name'],
        'initials'    => strtoupper(substr($lecturer['first_name'], 0, 1) . substr($lecturer['last_name'], 0, 1)),
        'email'       => $lecturer['email'],
        'role'        => 'lecturer',
        'courses'     => $courses,
        'login_time'  => date('Y-m-d H:i:s'),
    ];

    $_SESSION['web_user'] = $user;
    respond(true, 'Login successful. Welcome, ' . $lecturer['first_name'] . '.', ['user' => $user]);
}

// ── COURSE REP — index number + 10-digit PIN ─────────────────
if ($role === 'rep') {
    $indexNumber = clean($body['index_number'] ?? '');
    $pin         = (string) ($body['pin'] ?? '');

    if (empty($indexNumber)) {
        respond(false, 'Index number is required.', [], 422);
    }
    if (strlen($pin) !== 10 || !ctype_digit($pin)) {
        respond(false, 'A valid 10-digit PIN is required.', [], 422);
    }

    $stmt = $db->prepare("
        SELECT
            s.student_id, s.index_number, s.first_name, s.last_name,
            s.email, s.phone, s.level, s.pin_hash,
            p.programme_id, p.name AS programme_name, p.code AS programme_code,
            sg.group_id, sg.group_number,
            ap.period_id, ap.label AS current_period,
            ap.academic_year, ap.semester_number
        FROM student s
        JOIN programme       p  ON p.programme_id = s.programme_id
        JOIN student_group   sg ON sg.group_id    = s.group_id
        JOIN academic_period ap ON ap.period_id   = sg.period_id AND ap.is_active = 1
        WHERE s.index_number = ?
        LIMIT 1
    ");
    if (!$stmt) respond(false, 'Server error. Please try again.', [], 500);

    $stmt->bind_param('s', $indexNumber);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        respond(false, 'Index number not found.', [], 404);
    }
    if (!$student['pin_hash'] || !password_verify($pin, $student['pin_hash'])) {
        respond(false, 'Incorrect PIN.', [], 401);
    }

    // Confirm they are actually a course rep this semester
    $repStmt = $db->prepare("
        SELECT id FROM course_rep
        WHERE student_id = ? AND period_id = ?
        LIMIT 1
    ");
    $repStmt->bind_param('ii', $student['student_id'], $student['period_id']);
    $repStmt->execute();
    if ($repStmt->get_result()->num_rows === 0) {
        respond(false, 'You are not assigned as a Course Rep this semester.', [], 403);
    }
    $repStmt->close();

    $user = [
        'student_id'      => (int) $student['student_id'],
        'index_number'    => $student['index_number'],
        'full_name'       => $student['first_name'] . ' ' . $student['last_name'],
        'first_name'      => $student['first_name'],
        'last_name'       => $student['last_name'],
        'initials'        => strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)),
        'email'           => $student['email'],
        'level'           => (int) $student['level'],
        'programme_name'  => $student['programme_name'],
        'programme_code'  => $student['programme_code'],
        'group_id'        => (int) $student['group_id'],
        'group_number'    => (int) $student['group_number'],
        'period_id'       => (int) $student['period_id'],
        'current_period'  => $student['current_period'],
        'academic_year'   => $student['academic_year'],
        'semester_number' => (int) $student['semester_number'],
        'role'            => 'rep',
        'is_course_rep'   => true,
        'login_time'      => date('Y-m-d H:i:s'),
    ];

    $_SESSION['web_user'] = $user;
    respond(true, 'Login successful. Welcome, ' . $student['first_name'] . '.', ['user' => $user]);
}