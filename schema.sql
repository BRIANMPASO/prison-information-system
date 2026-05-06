-- ============================================================
--  Maula Prison Management System — Full Schema
--  Run this ONE TIME in phpMyAdmin > SQL tab
--
--  HOW TO USE:
--  1. Open http://localhost/phpmyadmin
--  2. Click the "SQL" tab
--  3. Paste this entire file and click "Go"
--  4. Done! Visit http://localhost/prison/index.php
--     Login: admin / admin123
-- ============================================================

CREATE DATABASE IF NOT EXISTS prison_db;
USE prison_db;

-- ── TABLE 1: users ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50)  NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role     ENUM('admin','staff','receptionist') NOT NULL DEFAULT 'staff'
);

-- ── TABLE 2: cells ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cells (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    cell_number VARCHAR(20) NOT NULL UNIQUE,
    block       VARCHAR(50) NOT NULL,
    capacity    INT         NOT NULL DEFAULT 4
);

-- ── TABLE 3: prisoners ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS prisoners (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    full_name        VARCHAR(100) NOT NULL,
    crime            VARCHAR(150) NOT NULL,
    sentence_months  INT          NOT NULL,
    cell_id          INT          DEFAULT NULL,
    date_entered     DATE         NOT NULL,
    release_date     DATE         DEFAULT NULL,
    status           ENUM('active','released') NOT NULL DEFAULT 'active',
    FOREIGN KEY (cell_id) REFERENCES cells(id) ON DELETE SET NULL
);

-- ── TABLE 4: staff ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS staff (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(100) NOT NULL,
    position    VARCHAR(50)  NOT NULL,
    shift       ENUM('Morning','Afternoon','Night') NOT NULL,
    phone       VARCHAR(20)  NOT NULL,
    date_joined DATE         NOT NULL
);

-- ── TABLE 5: visitors ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS visitors (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    visitor_name VARCHAR(100) NOT NULL,
    prisoner_id  INT          NOT NULL,
    visit_date   DATE         NOT NULL,
    visit_time   TIME         NOT NULL,
    purpose      VARCHAR(200) NOT NULL,
    status       ENUM('Pending','Approved','Denied') NOT NULL DEFAULT 'Pending',
    reviewed_by  VARCHAR(50)  DEFAULT NULL,
    reviewed_at  DATETIME     DEFAULT NULL,
    FOREIGN KEY (prisoner_id) REFERENCES prisoners(id) ON DELETE CASCADE
);

-- ── TABLE 6: logs ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    done_by    VARCHAR(50)  NOT NULL,
    action     VARCHAR(255) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── TABLE 7: pending_prisoners ───────────────────────────────
CREATE TABLE IF NOT EXISTS pending_prisoners (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    full_name        VARCHAR(100) NOT NULL,
    crime            VARCHAR(150) NOT NULL,
    sentence_months  INT          NOT NULL,
    cell_id          INT          DEFAULT NULL,
    date_entered     DATE         NOT NULL,
    submitted_by     VARCHAR(50)  NOT NULL,
    submitted_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by      VARCHAR(50)  DEFAULT NULL,
    reviewed_at      DATETIME     DEFAULT NULL,
    FOREIGN KEY (cell_id) REFERENCES cells(id) ON DELETE SET NULL
);

-- ── DEFAULT USERS ─────────────────────────────────────────────
-- admin     / admin123
-- staff1    / staff123
-- recept1   / recept123
INSERT INTO users (username, password, role) VALUES
('admin',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('staff1',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff'),
('recept1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'receptionist');

-- NOTE: All three accounts above use password "admin123".
-- After first login use User Management to create real accounts,
-- or run generate_hash.php to get a proper hash for your password.

-- ── SAMPLE CELLS ─────────────────────────────────────────────
INSERT INTO cells (cell_number, block, capacity) VALUES
('A-101', 'Block A', 4),
('A-102', 'Block A', 4),
('B-201', 'Block B', 2),
('B-202', 'Block B', 2),
('C-301', 'Block C', 6);

-- ── SAMPLE PRISONERS ─────────────────────────────────────────
INSERT INTO prisoners (full_name, crime, sentence_months, cell_id, date_entered, release_date, status) VALUES
('John Phiri',    'Armed Robbery',    96,  1, '2023-03-15', DATE_ADD('2023-03-15', INTERVAL 96  MONTH), 'active'),
('James Banda',   'Fraud',            36,  1, '2024-01-10', DATE_ADD('2024-01-10', INTERVAL 36  MONTH), 'active'),
('Peter Tembo',   'Assault',          60,  2, '2022-11-20', DATE_ADD('2022-11-20', INTERVAL 60  MONTH), 'active'),
('Samuel Mwale',  'Theft',            24,  3, '2025-06-05', DATE_ADD('2025-06-05', INTERVAL 24  MONTH), 'active'),
('David Chirwa',  'Drug Trafficking', 120, 4, '2021-08-30', DATE_ADD('2021-08-30', INTERVAL 120 MONTH), 'active');

-- ── SAMPLE STAFF ─────────────────────────────────────────────
INSERT INTO staff (full_name, position, shift, phone, date_joined) VALUES
('Mary Gondwe',      'Warden',    'Morning',   '0881234567', '2019-04-01'),
('Chisomo Nyirenda', 'Guard',     'Afternoon', '0882345678', '2021-07-15'),
('Tadala Phiri',     'Nurse',     'Morning',   '0883456789', '2020-02-10'),
('Frank Lungu',      'Guard',     'Night',     '0884567890', '2022-09-01'),
('Grace Mvula',      'Counselor', 'Afternoon', '0885678901', '2023-01-20');

-- ── SAMPLE VISITORS ───────────────────────────────────────────
INSERT INTO visitors (visitor_name, prisoner_id, visit_date, visit_time, purpose, status) VALUES
('Agnes Phiri',     1, '2026-04-20', '10:00:00', 'Family Visit',  'Approved'),
('Blessings Banda', 2, '2026-04-22', '14:00:00', 'Legal Counsel', 'Approved'),
('Rose Tembo',      3, '2026-04-28', '09:30:00', 'Family Visit',  'Pending');
