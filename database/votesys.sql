-- ---------------------------------------------------------------
-- Online Voting System for Supreme Student Council
-- St. Rose College Educational Foundation Inc.
--
-- Database: votesys
-- Import in phpMyAdmin (Import tab) or run:
--   C:\xampp\mysql\bin\mysql.exe -u root < database\votesys.sql
--
-- Safe to run again: it only creates what is missing and never
-- deletes or overwrites existing voters, candidates or votes.
-- ---------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS votesys
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE votesys;

-- ---------------------------------------------------------------
-- Departments
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
    code        VARCHAR(10)      NOT NULL,
    name        VARCHAR(100)     NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (code),
    UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Year levels offered by each department (e.g. 1st Year to 4th Year)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS year_levels (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    department_code  VARCHAR(10)      NOT NULL,
    name             VARCHAR(20)      NOT NULL,
    sort_order       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_year_levels_department_name (department_code, name),
    CONSTRAINT fk_year_levels_department FOREIGN KEY (department_code)
        REFERENCES departments (code) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Sections inside each year level (e.g. A, B, C)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sections (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    year_level_id  INT UNSIGNED NOT NULL,
    name           VARCHAR(30)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sections_year_level_name (year_level_id, name),
    CONSTRAINT fk_sections_year_level FOREIGN KEY (year_level_id)
        REFERENCES year_levels (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Positions on the ballot, in ballot order
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS positions (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name        VARCHAR(60)      NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_positions_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Election settings (a single row, id = 1)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id              TINYINT UNSIGNED NOT NULL,
    election_title  VARCHAR(150)     NOT NULL,
    voting_start    DATETIME         NULL,
    voting_end      DATETIME         NULL,
    -- Code for "End Voting Now", stored as a password hash (never as plain text)
    end_code_hash           VARCHAR(255)     NULL,
    end_code_failures       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    end_code_locked_until   DATETIME         NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Admin accounts (the first one is created on adminDash/login.php,
-- from the server computer itself)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    username         VARCHAR(50)      NOT NULL,
    -- password_hash() output, never the password itself
    password_hash    VARCHAR(255)     NOT NULL,
    failed_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until     DATETIME         NULL,
    last_login_at    DATETIME         NULL,
    created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Partylists
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partylists (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(100) NOT NULL,
    description  VARCHAR(255) NOT NULL DEFAULT '',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_partylists_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Candidates (partylist_id NULL = Independent)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidates (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id       VARCHAR(20)  NOT NULL,
    name             VARCHAR(150) NOT NULL,
    position_id      INT UNSIGNED NOT NULL,
    partylist_id     INT UNSIGNED NULL,
    department_code  VARCHAR(10)  NOT NULL,
    course           VARCHAR(150) NOT NULL,
    year_level       VARCHAR(10)  NOT NULL,
    section          VARCHAR(30)  NOT NULL DEFAULT '',
    photo            VARCHAR(255) NOT NULL DEFAULT '',
    platform         TEXT         NULL,
    achievements     TEXT         NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_candidates_student_id (student_id),
    KEY idx_candidates_position (position_id),
    KEY idx_candidates_partylist (partylist_id),
    KEY idx_candidates_department (department_code),
    CONSTRAINT fk_candidates_position   FOREIGN KEY (position_id)     REFERENCES positions (id),
    CONSTRAINT fk_candidates_partylist  FOREIGN KEY (partylist_id)    REFERENCES partylists (id),
    CONSTRAINT fk_candidates_department FOREIGN KEY (department_code) REFERENCES departments (code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Voters (voter_id is generated by the system and used to log in)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voters (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    voter_id         VARCHAR(20)  NOT NULL,
    student_id       VARCHAR(12)  NOT NULL,
    name             VARCHAR(150) NOT NULL,
    department_code  VARCHAR(10)  NOT NULL,
    year_level       VARCHAR(10)  NOT NULL,
    section          VARCHAR(30)  NOT NULL DEFAULT '',
    has_voted        TINYINT(1)   NOT NULL DEFAULT 0,
    voted_at         DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_voters_voter_id (voter_id),
    UNIQUE KEY uq_voters_student_id (student_id),
    KEY idx_voters_department (department_code),
    CONSTRAINT fk_voters_department FOREIGN KEY (department_code) REFERENCES departments (code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Ballots — secret by design:
--   * no link to the voter and no timestamp
--   * random id (not AUTO_INCREMENT), so ballot order can't be
--     matched against voters.voted_at
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ballots (
    id  CHAR(32) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

-- One row per position on a ballot (candidate_id NULL = abstained)
CREATE TABLE IF NOT EXISTS ballot_choices (
    ballot_id     CHAR(32)     NOT NULL,
    position_id   INT UNSIGNED NOT NULL,
    candidate_id  INT UNSIGNED NULL,
    PRIMARY KEY (ballot_id, position_id),
    KEY idx_choices_candidate (candidate_id),
    KEY idx_choices_position (position_id),
    CONSTRAINT fk_choices_ballot    FOREIGN KEY (ballot_id)    REFERENCES ballots (id) ON DELETE CASCADE,
    CONSTRAINT fk_choices_position  FOREIGN KEY (position_id)  REFERENCES positions (id),
    CONSTRAINT fk_choices_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Updates for databases created before these columns existed
-- (ADD COLUMN IF NOT EXISTS is supported by XAMPP's MariaDB)
-- ---------------------------------------------------------------
ALTER TABLE voters     ADD COLUMN IF NOT EXISTS section VARCHAR(30) NOT NULL DEFAULT '' AFTER year_level;
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS section VARCHAR(30) NOT NULL DEFAULT '' AFTER year_level;
ALTER TABLE settings   ADD COLUMN IF NOT EXISTS end_code_hash         VARCHAR(255)     NULL;
ALTER TABLE settings   ADD COLUMN IF NOT EXISTS end_code_failures     TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE settings   ADD COLUMN IF NOT EXISTS end_code_locked_until DATETIME         NULL;

-- ---------------------------------------------------------------
-- Required setup rows (not sample data)
-- ---------------------------------------------------------------
INSERT IGNORE INTO departments (code, name, sort_order) VALUES
    ('DCS',  'Department of Computer Studies',         1),
    ('DBA',  'Department of Business and Accountancy', 2),
    ('TED',  'Teachers Education Department',          3),
    ('CRIM', 'Criminology Department',                 4);

-- 1st to 4th Year for the starting departments (sections are added in Manage Departments)
INSERT IGNORE INTO year_levels (department_code, name, sort_order)
SELECT d.code, y.name, y.sort_order
FROM departments d
CROSS JOIN (
    SELECT '1st Year' AS name, 1 AS sort_order UNION ALL
    SELECT '2nd Year', 2 UNION ALL
    SELECT '3rd Year', 3 UNION ALL
    SELECT '4th Year', 4
) y
WHERE d.code IN ('DCS', 'DBA', 'TED', 'CRIM');

INSERT IGNORE INTO positions (id, name, sort_order) VALUES
    (1, 'President',                1),
    (2, 'Vice President',           2),
    (3, 'Secretary',                3),
    (4, 'Treasurer',                4),
    (5, 'Auditor',                  5),
    (6, 'Public Relations Officer', 6);

INSERT IGNORE INTO settings (id, election_title, voting_start, voting_end) VALUES
    (1, 'SSC Election S.Y. 2026-2027', NULL, NULL);

-- Starting "End Voting Now" code: 0131 (hashed). Change it in Settings.
-- Only set when no code exists yet, so re-running this file keeps a changed code.
UPDATE settings
SET end_code_hash = '$2y$10$Dy9oKu/UvWnJHgbKvX5KiepuPmJe.VQbETRGcI1/FN8twN8dQNT3q'
WHERE id = 1 AND end_code_hash IS NULL;
