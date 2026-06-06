<?php
// ============================================================
//  config/cors.php — CORS + session start
//  Include at the top of every API file.
// ============================================================

// Include CORS first (starts session)
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/db.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', [], 405);
}

$body = getBody();
$role = clean($body['role'] ?? '');

if (!in_array($role, ['student', 'rep', 'lecturer'], true)) {
    respond(false, 'Invalid role. Must be student, rep, or lecturer.', [], 422);
}

$db = getDB();

// ────────────────────────────────────────────────────────────
// STUDENT — index number only
// ────────────────────────────────────────────────────────────
if ($role === 'student') {
    $indexNumber = clean($body['index_number'] ?? '');

    if (empty($indexNumber)) {
        respond(false, 'Index number is required.', [], 422);
    }

    $sql = "
        SELECT
            s.student_id,
            s.index_number,
            s.first_name,
            s.last_name,
            s.email,
            s.phone,
            s.level,
            p.programme_id,
            p.name AS programme_name,
            p.code AS programme_code,
            sg.group_id,
            sg.group_number,
            ap.label AS current_period,
            ap.period_id,
            ap.academic_year,
            ap.semester_number
        FROM student s
        JOIN programme p ON p.programme_id = s.programme_id
        JOIN student_group sg ON sg.group_id = s.group_id
        JOIN academic_period ap ON ap.period_id = sg.period_id AND ap.is_active = 1
        WHERE s.index_number = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        respond(false, 'Server error. Please try again.', [], 500);
    }

    $stmt->bind_param('s', $indexNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

    if (!$student) {
        respond(false, 'Index number not found.', [], 404);
    }

    // Check if course rep
    $repSql = "SELECT id FROM course_rep WHERE student_id = ? AND period_id = ? LIMIT 1";
    $repStmt = $db->prepare($repSql);
    $repStmt->bind_param('ii', $student['student_id'], $student['period_id']);
    $repStmt->execute();
    $isCourseRep = $repStmt->get_result()->num_rows > 0;
    $repStmt->close();

    $userPayload = [
        'student_id'      => (int) $student['student_id'],
        'index_number'    => $student['index_number'],
        'full_name'       => $student['first_name'] . ' ' . $student['last_name'],
        'first_name'      => $student['first_name'],
        'last_name'       => $student['last_name'],
        'initials'        => strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)),
        'email'           => $student['email'],
        'phone'           => $student['phone'],
        'level'           => (int) $student['level'],
        'programme_name'  => $student['programme_name'],
        'programme_code'  => $student['programme_code'],
        'group_number'    => (int) $student['group_number'],
        'current_period'  => $student['current_period'],
        'period_id'       => (int) $student['period_id'],
        'academic_year'   => $student['academic_year'],
        'semester_number' => (int) $student['semester_number'],
        'role'            => $isCourseRep ? 'rep' : 'student',
        'is_course_rep'   => $isCourseRep
    ];

    // Store in session
    $_SESSION['user'] = $userPayload;
    
    // Log session for debugging
    error_log("Session created for student: " . $userPayload['index_number']);

    respond(true, 'Login successful.', ['user' => $userPayload]);
}

// ────────────────────────────────────────────────────────────
// COURSE REP — index number + PIN
// ────────────────────────────────────────────────────────────
if ($role === 'rep') {
    $indexNumber = clean($body['index_number'] ?? '');
    $pin = (string) ($body['pin'] ?? '');

    if (empty($indexNumber)) {
        respond(false, 'Index number is required.', [], 422);
    }
    if (strlen($pin) !== 10) {
        respond(false, 'A 10-digit PIN is required.', [], 422);
    }

    $sql = "
        SELECT
            s.student_id,
            s.index_number,
            s.first_name,
            s.last_name,
            s.email,
            s.phone,
            s.level,
            s.pin_hash,
            p.programme_id,
            p.name AS programme_name,
            p.code AS programme_code,
            sg.group_id,
            sg.group_number,
            ap.label AS current_period,
            ap.period_id,
            ap.academic_year,
            ap.semester_number
        FROM student s
        JOIN programme p ON p.programme_id = s.programme_id
        JOIN student_group sg ON sg.group_id = s.group_id
        JOIN academic_period ap ON ap.period_id = sg.period_id AND ap.is_active = 1
        WHERE s.index_number = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
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

    // Verify course rep
    $repStmt = $db->prepare("SELECT id FROM course_rep WHERE student_id = ? AND period_id = ? LIMIT 1");
    $repStmt->bind_param('ii', $student['student_id'], $student['period_id']);
    $repStmt->execute();
    if ($repStmt->get_result()->num_rows === 0) {
        respond(false, 'You are not a Course Rep this semester.', [], 403);
    }
    $repStmt->close();

    $userPayload = [
        'student_id'      => (int) $student['student_id'],
        'index_number'    => $student['index_number'],
        'full_name'       => $student['first_name'] . ' ' . $student['last_name'],
        'first_name'      => $student['first_name'],
        'last_name'       => $student['last_name'],
        'initials'        => strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)),
        'email'           => $student['email'],
        'phone'           => $student['phone'],
        'level'           => (int) $student['level'],
        'programme_name'  => $student['programme_name'],
        'programme_code'  => $student['programme_code'],
        'group_number'    => (int) $student['group_number'],
        'current_period'  => $student['current_period'],
        'period_id'       => (int) $student['period_id'],
        'academic_year'   => $student['academic_year'],
        'semester_number' => (int) $student['semester_number'],
        'role'            => 'rep',
        'is_course_rep'   => true
    ];

    $_SESSION['user'] = $userPayload;
    error_log("Session created for rep: " . $userPayload['index_number']);

    respond(true, 'Login successful.', ['user' => $userPayload]);
}

// ────────────────────────────────────────────────────────────
// LECTURER — email + password
// ────────────────────────────────────────────────────────────
if ($role === 'lecturer') {
    $email = clean($body['email'] ?? '');
    $password = (string) ($body['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(false, 'Valid email required.', [], 422);
    }
    if (empty($password)) {
        respond(false, 'Password required.', [], 422);
    }

    $stmt = $db->prepare("
        SELECT lecturer_id, staff_id, first_name, last_name, email, password_hash
        FROM lecturer WHERE email = ? LIMIT 1
    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $lecturer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$lecturer) {
        respond(false, 'Account not found.', [], 404);
    }

    if (!password_verify($password, $lecturer['password_hash'])) {
        respond(false, 'Incorrect password.', [], 401);
    }

    $userPayload = [
        'lecturer_id' => (int) $lecturer['lecturer_id'],
        'staff_id'    => $lecturer['staff_id'],
        'full_name'   => $lecturer['first_name'] . ' ' . $lecturer['last_name'],
        'first_name'  => $lecturer['first_name'],
        'last_name'   => $lecturer['last_name'],
        'initials'    => strtoupper(substr($lecturer['first_name'], 0, 1) . substr($lecturer['last_name'], 0, 1)),
        'email'       => $lecturer['email'],
        'role'        => 'lecturer'
    ];

    $_SESSION['user'] = $userPayload;
    error_log("Session created for lecturer: " . $lecturer['email']);

    respond(true, 'Login successful.', ['user' => $userPayload]);
}
?>