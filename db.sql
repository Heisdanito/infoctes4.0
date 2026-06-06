-- ============================================================
--  UEW ICT DEPARTMENT — INFOCTESS DATABASE SCHEMA
--  MySQL 8.0+
--  Generated for: BSc ICT | BEd ICT | BEd Computing
--  Updated: device_build_number added, indexes added
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. ACADEMIC_PERIOD
--    Admin creates one row per semester with date range.
--    Only ONE row may have is_active = 1 at any time.
-- ------------------------------------------------------------
CREATE TABLE academic_period (
    period_id        INT          NOT NULL AUTO_INCREMENT,
    label            VARCHAR(50)  NOT NULL COMMENT 'e.g. 2024/2025 Semester 1',
    academic_year    VARCHAR(9)   NOT NULL COMMENT 'e.g. 2024/2025',
    semester_number  TINYINT      NOT NULL COMMENT '1 or 2',
    start_date       DATE         NOT NULL,
    end_date         DATE         NOT NULL,
    is_active        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (period_id),
    CONSTRAINT chk_semester     CHECK (semester_number IN (1, 2)),
    CONSTRAINT chk_period_dates CHECK (end_date > start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='One row per semester. Only one is_active=1 allowed at a time.';

-- Fast lookup: "give me the current active period"
CREATE INDEX idx_ap_is_active ON academic_period (is_active);
-- Fast filtering by year for reporting
CREATE INDEX idx_ap_academic_year ON academic_period (academic_year);


-- ------------------------------------------------------------
-- 2. PROGRAMME
--    BSc ICT | BEd ICT | BEd Computing
-- ------------------------------------------------------------
CREATE TABLE programme (
    programme_id  INT         NOT NULL AUTO_INCREMENT,
    name          VARCHAR(60) NOT NULL,
    code          VARCHAR(10) NOT NULL COMMENT 'e.g. BSCICT',

    PRIMARY KEY (programme_id),
    UNIQUE KEY uq_programme_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO programme (name, code) VALUES
    ('BSc Information and Communication Technology', 'BSCICT'),
    ('BEd Information and Communication Technology', 'BEDICT'),
    ('BEd Computing',                                'BEDCOM');


-- ------------------------------------------------------------
-- 3. STUDENT_GROUP
--    Groups 1–5, recreated each academic period at L100.
-- ------------------------------------------------------------
CREATE TABLE student_group (
    group_id      INT     NOT NULL AUTO_INCREMENT,
    group_number  TINYINT NOT NULL COMMENT '1 to 5',
    period_id     INT     NOT NULL,

    PRIMARY KEY (group_id),
    UNIQUE KEY uq_group_per_period (group_number, period_id),
    CONSTRAINT chk_group_number CHECK (group_number BETWEEN 1 AND 5),
    CONSTRAINT fk_sg_period FOREIGN KEY (period_id)
        REFERENCES academic_period (period_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lookup: "which groups exist for this semester?"
CREATE INDEX idx_sg_period ON student_group (period_id);


-- ------------------------------------------------------------
-- 4. STUDENT
--    Every student has a unique index number, one programme,
--    one group (assigned at L100 based on total score).
-- ------------------------------------------------------------
CREATE TABLE student (
    student_id    INT          NOT NULL AUTO_INCREMENT,
    index_number  VARCHAR(20)  NOT NULL COMMENT 'Unique university index number',
    first_name    VARCHAR(50)  NOT NULL,
    last_name     VARCHAR(50)  NOT NULL,
    email         VARCHAR(100) NULL,
    phone         VARCHAR(15)  NULL,
    programme_id  INT          NOT NULL,
    group_id      INT          NOT NULL,
    level         SMALLINT     NOT NULL COMMENT '100 | 200 | 300 | 400',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (student_id),
    UNIQUE KEY uq_index_number (index_number),
    CONSTRAINT chk_level CHECK (level IN (100, 200, 300, 400)),
    CONSTRAINT fk_student_programme FOREIGN KEY (programme_id)
        REFERENCES programme (programme_id) ON UPDATE CASCADE,
    CONSTRAINT fk_student_group FOREIGN KEY (group_id)
        REFERENCES student_group (group_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lookup: "get all students in a programme" / "get all students in a group"
CREATE INDEX idx_student_programme ON student (programme_id);
CREATE INDEX idx_student_group     ON student (group_id);
-- Lookup: "get all L200 students"
CREATE INDEX idx_student_level     ON student (level);
-- Full-name search
CREATE INDEX idx_student_name      ON student (last_name, first_name);


-- ------------------------------------------------------------
-- 5. COURSE
-- ------------------------------------------------------------
CREATE TABLE course (
    course_id     INT          NOT NULL AUTO_INCREMENT,
    code          VARCHAR(15)  NOT NULL COMMENT 'e.g. ICT 101',
    title         VARCHAR(120) NOT NULL,
    credit_hours  TINYINT      NOT NULL DEFAULT 3,
    level         SMALLINT     NOT NULL COMMENT '100 | 200 | 300 | 400',
    semester      TINYINT      NOT NULL COMMENT '1 or 2',

    PRIMARY KEY (course_id),
    UNIQUE KEY uq_course_code (code),
    CONSTRAINT chk_course_level    CHECK (level IN (100, 200, 300, 400)),
    CONSTRAINT chk_course_semester CHECK (semester IN (1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lookup: "get all level 100 semester 1 courses"
CREATE INDEX idx_course_level_sem ON course (level, semester);


-- ------------------------------------------------------------
-- 6. PROGRAMME_COURSE
-- ------------------------------------------------------------
CREATE TABLE programme_course (
    id            INT        NOT NULL AUTO_INCREMENT,
    programme_id  INT        NOT NULL,
    course_id     INT        NOT NULL,
    is_required   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=core, 0=elective',

    PRIMARY KEY (id),
    UNIQUE KEY uq_prog_course (programme_id, course_id),
    CONSTRAINT fk_pc_programme FOREIGN KEY (programme_id)
        REFERENCES programme (programme_id) ON UPDATE CASCADE,
    CONSTRAINT fk_pc_course FOREIGN KEY (course_id)
        REFERENCES course (course_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lookup: "which programmes offer this course?"
CREATE INDEX idx_pc_course_id ON programme_course (course_id);


-- ------------------------------------------------------------
-- 7. LECTURER
-- ------------------------------------------------------------
CREATE TABLE lecturer (
    lecturer_id  INT          NOT NULL AUTO_INCREMENT,
    staff_id     VARCHAR(20)  NOT NULL,
    first_name   VARCHAR(50)  NOT NULL,
    last_name    VARCHAR(50)  NOT NULL,
    email        VARCHAR(100) NOT NULL,

    PRIMARY KEY (lecturer_id),
    UNIQUE KEY uq_staff_id   (staff_id),
    UNIQUE KEY uq_lect_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Full-name search
CREATE INDEX idx_lecturer_name ON lecturer (last_name, first_name);


-- ------------------------------------------------------------
-- 8. VENUE
-- ------------------------------------------------------------
CREATE TABLE venue (
    venue_id   INT           NOT NULL AUTO_INCREMENT,
    name       VARCHAR(80)   NOT NULL,
    type       ENUM('lab','lecture_hall','seminar_room','other')
                             NOT NULL DEFAULT 'lecture_hall',
    capacity   SMALLINT      NOT NULL,
    gps_lat    DECIMAL(10,7) NULL,
    gps_lng    DECIMAL(10,7) NULL,

    PRIMARY KEY (venue_id),
    UNIQUE KEY uq_venue_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lookup: "show me all labs"
CREATE INDEX idx_venue_type ON venue (type);


-- ------------------------------------------------------------
-- 9. TIMETABLE
-- ------------------------------------------------------------
CREATE TABLE timetable (
    timetable_id  INT  NOT NULL AUTO_INCREMENT,
    course_id     INT  NOT NULL,
    lecturer_id   INT  NOT NULL,
    venue_id      INT  NOT NULL,
    period_id     INT  NOT NULL,
    day_of_week   ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday')
                       NOT NULL,
    start_time    TIME NOT NULL,
    end_time      TIME NOT NULL,

    PRIMARY KEY (timetable_id),
    CONSTRAINT chk_tt_times CHECK (end_time > start_time),
    CONSTRAINT fk_tt_course   FOREIGN KEY (course_id)
        REFERENCES course (course_id) ON UPDATE CASCADE,
    CONSTRAINT fk_tt_lecturer FOREIGN KEY (lecturer_id)
        REFERENCES lecturer (lecturer_id) ON UPDATE CASCADE,
    CONSTRAINT fk_tt_venue    FOREIGN KEY (venue_id)
        REFERENCES venue (venue_id) ON UPDATE CASCADE,
    CONSTRAINT fk_tt_period   FOREIGN KEY (period_id)
        REFERENCES academic_period (period_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Most common timetable queries filter by period first, then course or lecturer
CREATE INDEX idx_tt_period          ON timetable (period_id);
CREATE INDEX idx_tt_period_course   ON timetable (period_id, course_id);
CREATE INDEX idx_tt_period_lecturer ON timetable (period_id, lecturer_id);
-- Schedule view: "what's on Monday?"
CREATE INDEX idx_tt_day             ON timetable (day_of_week);
-- Venue clash check: "is this venue free at this time?"
CREATE INDEX idx_tt_venue_day_time  ON timetable (venue_id, day_of_week, start_time);


-- ------------------------------------------------------------
-- 10. TIMETABLE_GROUP
-- ------------------------------------------------------------
CREATE TABLE timetable_group (
    id            INT NOT NULL AUTO_INCREMENT,
    timetable_id  INT NOT NULL,
    group_id      INT NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tt_group (timetable_id, group_id),
    CONSTRAINT fk_ttg_timetable FOREIGN KEY (timetable_id)
        REFERENCES timetable (timetable_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ttg_group FOREIGN KEY (group_id)
        REFERENCES student_group (group_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lookup: "get all timetable slots for group 3"
CREATE INDEX idx_ttg_group ON timetable_group (group_id);


-- ------------------------------------------------------------
-- 11. TIMETABLE_PROGRAMME
-- ------------------------------------------------------------
CREATE TABLE timetable_programme (
    id            INT NOT NULL AUTO_INCREMENT,
    timetable_id  INT NOT NULL,
    programme_id  INT NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tt_programme (timetable_id, programme_id),
    CONSTRAINT fk_ttp_timetable FOREIGN KEY (timetable_id)
        REFERENCES timetable (timetable_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ttp_programme FOREIGN KEY (programme_id)
        REFERENCES programme (programme_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lookup: "get all slots for BSc ICT"
CREATE INDEX idx_ttp_programme ON timetable_programme (programme_id);


-- ------------------------------------------------------------
-- 12. COURSE_REGISTRATION
-- ------------------------------------------------------------
CREATE TABLE course_registration (
    id          INT      NOT NULL AUTO_INCREMENT,
    student_id  INT      NOT NULL,
    course_id   INT      NOT NULL,
    period_id   INT      NOT NULL,
    status      ENUM('registered','dropped','completed')
                         NOT NULL DEFAULT 'registered',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_student_course_period (student_id, course_id, period_id),
    CONSTRAINT fk_cr_student FOREIGN KEY (student_id)
        REFERENCES student (student_id) ON UPDATE CASCADE,
    CONSTRAINT fk_cr_course  FOREIGN KEY (course_id)
        REFERENCES course (course_id) ON UPDATE CASCADE,
    CONSTRAINT fk_cr_period  FOREIGN KEY (period_id)
        REFERENCES academic_period (period_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lookup: "all registrations for this semester"
CREATE INDEX idx_cr_period         ON course_registration (period_id);
-- Lookup: "all students registered for course X this semester"
CREATE INDEX idx_cr_course_period  ON course_registration (course_id, period_id);
-- Lookup: "all courses a student is taking this semester"
CREATE INDEX idx_cr_student_period ON course_registration (student_id, period_id);


-- ------------------------------------------------------------
-- 13. COURSE_REP
-- ------------------------------------------------------------
CREATE TABLE course_rep (
    id          INT      NOT NULL AUTO_INCREMENT,
    student_id  INT      NOT NULL,
    course_id   INT      NOT NULL,
    group_id    INT      NOT NULL,
    period_id   INT      NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_rep_course_group_period (course_id, group_id, period_id),
    CONSTRAINT fk_crep_student FOREIGN KEY (student_id)
        REFERENCES student (student_id) ON UPDATE CASCADE,
    CONSTRAINT fk_crep_course  FOREIGN KEY (course_id)
        REFERENCES course (course_id) ON UPDATE CASCADE,
    CONSTRAINT fk_crep_group   FOREIGN KEY (group_id)
        REFERENCES student_group (group_id) ON UPDATE CASCADE,
    CONSTRAINT fk_crep_period  FOREIGN KEY (period_id)
        REFERENCES academic_period (period_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lookup: "is this student a rep for anything this semester?"
CREATE INDEX idx_crep_student_period ON course_rep (student_id, period_id);


-- ------------------------------------------------------------
-- 14. ATTENDANCE_SESSION
--     Created by course rep when they start a class.
--     QR + numeric code for student submission.
--     Rep GPS is the location anchor for validation.
-- ------------------------------------------------------------
CREATE TABLE attendance_session (
    session_id    INT           NOT NULL AUTO_INCREMENT,
    course_rep_id INT           NOT NULL COMMENT 'FK to course_rep.id',
    timetable_id  INT           NOT NULL,
    period_id     INT           NOT NULL,
    qr_code       VARCHAR(64)   NOT NULL COMMENT 'Unique token encoded in QR image',
    numeric_code  VARCHAR(8)    NOT NULL COMMENT 'Short numeric code e.g. 481923',
    rep_lat       DECIMAL(10,7) NOT NULL COMMENT 'Rep GPS latitude at session start',
    rep_lng       DECIMAL(10,7) NOT NULL COMMENT 'Rep GPS longitude at session start',
    radius_meters SMALLINT      NOT NULL DEFAULT 50 COMMENT 'Max allowed distance from rep in metres',
    started_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME      NOT NULL COMMENT 'started_at + fixed window e.g. +30 minutes',
    closed_at     DATETIME      NULL     COMMENT 'Set when rep manually closes early',
    status        ENUM('active','expired','closed')
                                NOT NULL DEFAULT 'active',

    PRIMARY KEY (session_id),
    UNIQUE KEY uq_qr_code      (qr_code),
    UNIQUE KEY uq_numeric_code (numeric_code),
    CONSTRAINT fk_as_course_rep FOREIGN KEY (course_rep_id)
        REFERENCES course_rep (id) ON UPDATE CASCADE,
    CONSTRAINT fk_as_timetable  FOREIGN KEY (timetable_id)
        REFERENCES timetable (timetable_id) ON UPDATE CASCADE,
    CONSTRAINT fk_as_period     FOREIGN KEY (period_id)
        REFERENCES academic_period (period_id) ON UPDATE CASCADE,
    CONSTRAINT chk_expires CHECK (expires_at > started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Critical hot path: validate a submitted code → must be instant
CREATE INDEX idx_as_numeric_code   ON attendance_session (numeric_code);
-- Lookup: "get all sessions started by this rep this semester"
CREATE INDEX idx_as_rep_period     ON attendance_session (course_rep_id, period_id);
-- Lookup: "get active sessions" (used by cron job to auto-expire)
CREATE INDEX idx_as_status_expires ON attendance_session (status, expires_at);
-- Lookup: "all sessions for a timetable slot this period"
CREATE INDEX idx_as_timetable      ON attendance_session (timetable_id, period_id);


-- ------------------------------------------------------------
-- 15. ATTENDANCE_RECORD
--     One row per student per session.
--     Stores: GPS coords, location check result,
--             submission method, device build number.
-- ------------------------------------------------------------
CREATE TABLE attendance_record (
    record_id           INT           NOT NULL AUTO_INCREMENT,
    session_id          INT           NOT NULL,
    student_id          INT           NOT NULL,
    student_lat         DECIMAL(10,7) NOT NULL  COMMENT 'Student GPS latitude at submission',
    student_lng         DECIMAL(10,7) NOT NULL  COMMENT 'Student GPS longitude at submission',
    location_valid      TINYINT(1)    NOT NULL  COMMENT '1=within radius, 0=outside radius',
    method              ENUM('qr','code')
                                      NOT NULL  COMMENT 'qr=scanned QR, code=typed numeric code',
    device_build_number VARCHAR(100)  NOT NULL  COMMENT 'Mobile OS build number e.g. Android: RQ3A.211001.001 | iOS: 21A5284e — identifies the physical device used at submission',
    submitted_at        DATETIME      NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    status              ENUM('present','rejected')
                                      NOT NULL  DEFAULT 'present',

    PRIMARY KEY (record_id),
    -- One submission per student per session — no duplicates
    UNIQUE KEY uq_student_session (session_id, student_id),
    CONSTRAINT fk_ar_session FOREIGN KEY (session_id)
        REFERENCES attendance_session (session_id) ON UPDATE CASCADE,
    CONSTRAINT fk_ar_student FOREIGN KEY (student_id)
        REFERENCES student (student_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Critical hot path: duplicate check on submission → must be instant
CREATE INDEX idx_ar_session_student  ON attendance_record (session_id, student_id);
-- Lookup: "all attendance records for a student" (student dashboard)
CREATE INDEX idx_ar_student          ON attendance_record (student_id);
-- Security audit: "find all submissions from this device build"
CREATE INDEX idx_ar_device_build     ON attendance_record (device_build_number);
-- Reporting: filter by status (present vs rejected)
CREATE INDEX idx_ar_status           ON attendance_record (status);
-- Reporting: filter by submission time range
CREATE INDEX idx_ar_submitted_at     ON attendance_record (submitted_at);


-- ============================================================
-- TRIGGER: enforce only one active academic period at a time
-- ============================================================
DELIMITER $$

CREATE TRIGGER trg_one_active_period_insert
BEFORE INSERT ON academic_period
FOR EACH ROW
BEGIN
    IF NEW.is_active = 1 THEN
        UPDATE academic_period SET is_active = 0 WHERE is_active = 1;
    END IF;
END$$

CREATE TRIGGER trg_one_active_period_update
BEFORE UPDATE ON academic_period
FOR EACH ROW
BEGIN
    IF NEW.is_active = 1 AND OLD.is_active = 0 THEN
        UPDATE academic_period SET is_active = 0 WHERE is_active = 1;
    END IF;
END$$

DELIMITER ;


-- ============================================================
-- VIEWS
-- ============================================================

-- Current active period
CREATE OR REPLACE VIEW v_current_period AS
SELECT * FROM academic_period WHERE is_active = 1;

-- Full student profile
CREATE OR REPLACE VIEW v_student_profile AS
SELECT
    s.student_id,
    s.index_number,
    CONCAT(s.first_name, ' ', s.last_name) AS full_name,
    s.level,
    p.code   AS programme_code,
    p.name   AS programme_name,
    sg.group_number,
    ap.label AS current_period
FROM student s
JOIN programme       p  ON p.programme_id = s.programme_id
JOIN student_group   sg ON sg.group_id    = s.group_id
JOIN academic_period ap ON ap.period_id   = sg.period_id;

-- Current semester timetable
CREATE OR REPLACE VIEW v_current_timetable AS
SELECT
    t.timetable_id,
    c.code   AS course_code,
    c.title  AS course_title,
    CONCAT(l.first_name, ' ', l.last_name) AS lecturer,
    v.name   AS venue,
    t.day_of_week,
    t.start_time,
    t.end_time
FROM timetable t
JOIN course          c  ON c.course_id   = t.course_id
JOIN lecturer        l  ON l.lecturer_id = t.lecturer_id
JOIN venue           v  ON v.venue_id    = t.venue_id
JOIN academic_period ap ON ap.period_id  = t.period_id
WHERE ap.is_active = 1;

-- Attendance summary per student per course per period
CREATE OR REPLACE VIEW v_attendance_summary AS
SELECT
    s.index_number,
    CONCAT(s.first_name, ' ', s.last_name)                           AS full_name,
    c.code                                                            AS course_code,
    c.title                                                           AS course_title,
    ap.label                                                          AS period,
    COUNT(ar.record_id)                                               AS total_sessions,
    SUM(ar.status = 'present')                                        AS attended,
    ROUND(SUM(ar.status = 'present') / COUNT(ar.record_id) * 100, 1) AS attendance_pct
FROM attendance_record  ar
JOIN attendance_session ases ON ases.session_id  = ar.session_id
JOIN course_rep         cr   ON cr.id            = ases.course_rep_id
JOIN course             c    ON c.course_id      = cr.course_id
JOIN academic_period    ap   ON ap.period_id     = ases.period_id
JOIN student            s    ON s.student_id     = ar.student_id
GROUP BY s.student_id, c.course_id, ap.period_id;

-- Security view: detect same device used by multiple students in one session
CREATE OR REPLACE VIEW v_suspicious_device_activity AS
SELECT
    ar.session_id,
    ar.device_build_number,
    COUNT(DISTINCT ar.student_id) AS student_count,
    GROUP_CONCAT(s.index_number ORDER BY s.index_number) AS index_numbers
FROM attendance_record ar
JOIN student s ON s.student_id = ar.student_id
GROUP BY ar.session_id, ar.device_build_number
HAVING COUNT(DISTINCT ar.student_id) > 1;


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- END OF SCHEMA
-- ============================================================