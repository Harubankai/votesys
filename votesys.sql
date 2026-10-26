-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 26, 2026 at 11:06 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `votesys`
--

-- --------------------------------------------------------

--
-- Table structure for table `ballots`
--

CREATE TABLE `ballots` (
  `id` char(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ballots`
--

INSERT INTO `ballots` (`id`) VALUES
('d10a6092b855b4b04985392962c9729d');

-- --------------------------------------------------------

--
-- Table structure for table `ballot_choices`
--

CREATE TABLE `ballot_choices` (
  `ballot_id` char(32) NOT NULL,
  `position_id` int(10) UNSIGNED NOT NULL,
  `candidate_id` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ballot_choices`
--

INSERT INTO `ballot_choices` (`ballot_id`, `position_id`, `candidate_id`) VALUES
('d10a6092b855b4b04985392962c9729d', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `candidates`
--

CREATE TABLE `candidates` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `position_id` int(10) UNSIGNED NOT NULL,
  `partylist_id` int(10) UNSIGNED DEFAULT NULL,
  `department_code` varchar(10) NOT NULL,
  `course` varchar(150) NOT NULL,
  `year_level` varchar(10) NOT NULL,
  `section` varchar(30) NOT NULL DEFAULT '',
  `photo` varchar(255) NOT NULL DEFAULT '',
  `platform` text DEFAULT NULL,
  `achievements` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `candidates`
--

INSERT INTO `candidates` (`id`, `student_id`, `name`, `position_id`, `partylist_id`, `department_code`, `course`, `year_level`, `section`, `photo`, `platform`, `achievements`, `created_at`) VALUES
(1, '23-7200', 'Yona Ayawon', 1, 1, 'DCS', 'BS IT', '4th Year', 'HEXA', 'images/candidates/1.jpg', '', 'haha basta', '2026-10-26 02:01:32');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`code`, `name`, `sort_order`) VALUES
('CRIM', 'Criminology Department', 4),
('DBA', 'Department of Business and Accountancy', 2),
('DCS', 'Department of Computer Studies', 1),
('TED', 'Teachers Education Department', 3);

-- --------------------------------------------------------

--
-- Table structure for table `partylists`
--

CREATE TABLE `partylists` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `partylists`
--

INSERT INTO `partylists` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'NAYONIZZ', 'g, g, palag palag kahit ano', '2026-10-26 02:00:47');

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(60) NOT NULL,
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`id`, `name`, `sort_order`) VALUES
(1, 'President', 1),
(2, 'Vice President', 2),
(3, 'Secretary', 3),
(4, 'Treasurer', 4),
(5, 'Auditor', 5),
(6, 'Public Relations Officer', 6);

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(10) UNSIGNED NOT NULL,
  `year_level_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `year_level_id`, `name`) VALUES
(1, 3, 'DOMAIN'),
(2, 3, 'SERVER'),
(3, 7, 'KILO'),
(4, 7, 'MEGA'),
(5, 11, 'DECA'),
(6, 11, 'PENTA'),
(7, 15, 'HEXA'),
(8, 15, 'OCTA');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `election_title` varchar(150) NOT NULL,
  `voting_start` datetime DEFAULT NULL,
  `voting_end` datetime DEFAULT NULL,
  `end_code_hash` varchar(255) DEFAULT NULL,
  `end_code_failures` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `end_code_locked_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `election_title`, `voting_start`, `voting_end`, `end_code_hash`, `end_code_failures`, `end_code_locked_until`) VALUES
(1, 'SSC Election S.Y. 2026-2027', '2026-10-26 09:46:00', '2026-10-26 09:50:00', '$2y$10$Dy9oKu/UvWnJHgbKvX5KiepuPmJe.VQbETRGcI1/FN8twN8dQNT3q', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `voters`
--

CREATE TABLE `voters` (
  `id` int(10) UNSIGNED NOT NULL,
  `voter_id` varchar(20) NOT NULL,
  `student_id` varchar(12) NOT NULL,
  `name` varchar(150) NOT NULL,
  `department_code` varchar(10) NOT NULL,
  `year_level` varchar(10) NOT NULL,
  `section` varchar(30) NOT NULL DEFAULT '',
  `has_voted` tinyint(1) NOT NULL DEFAULT 0,
  `voted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `voters`
--

INSERT INTO `voters` (`id`, `voter_id`, `student_id`, `name`, `department_code`, `year_level`, `section`, `has_voted`, `voted_at`, `created_at`) VALUES
(2, 'DCS-C2BUJJ', '23-7200', 'Yona Ayawon', 'DCS', '4th Year', 'HEXA', 1, '2026-10-26 09:47:02', '2026-10-26 01:57:36');

-- --------------------------------------------------------

--
-- Table structure for table `year_levels`
--

CREATE TABLE `year_levels` (
  `id` int(10) UNSIGNED NOT NULL,
  `department_code` varchar(10) NOT NULL,
  `name` varchar(20) NOT NULL,
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `year_levels`
--

INSERT INTO `year_levels` (`id`, `department_code`, `name`, `sort_order`) VALUES
(1, 'CRIM', '1st Year', 1),
(2, 'DBA', '1st Year', 1),
(3, 'DCS', '1st Year', 1),
(4, 'TED', '1st Year', 1),
(5, 'CRIM', '2nd Year', 2),
(6, 'DBA', '2nd Year', 2),
(7, 'DCS', '2nd Year', 2),
(8, 'TED', '2nd Year', 2),
(9, 'CRIM', '3rd Year', 3),
(10, 'DBA', '3rd Year', 3),
(11, 'DCS', '3rd Year', 3),
(12, 'TED', '3rd Year', 3),
(13, 'CRIM', '4th Year', 4),
(14, 'DBA', '4th Year', 4),
(15, 'DCS', '4th Year', 4),
(16, 'TED', '4th Year', 4);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ballots`
--
ALTER TABLE `ballots`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ballot_choices`
--
ALTER TABLE `ballot_choices`
  ADD PRIMARY KEY (`ballot_id`,`position_id`),
  ADD KEY `idx_choices_candidate` (`candidate_id`),
  ADD KEY `idx_choices_position` (`position_id`);

--
-- Indexes for table `candidates`
--
ALTER TABLE `candidates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_candidates_student_id` (`student_id`),
  ADD KEY `idx_candidates_position` (`position_id`),
  ADD KEY `idx_candidates_partylist` (`partylist_id`),
  ADD KEY `idx_candidates_department` (`department_code`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`code`),
  ADD UNIQUE KEY `uq_departments_name` (`name`);

--
-- Indexes for table `partylists`
--
ALTER TABLE `partylists`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_partylists_name` (`name`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_positions_name` (`name`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sections_year_level_name` (`year_level_id`,`name`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `voters`
--
ALTER TABLE `voters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_voters_voter_id` (`voter_id`),
  ADD UNIQUE KEY `uq_voters_student_id` (`student_id`),
  ADD KEY `idx_voters_department` (`department_code`);

--
-- Indexes for table `year_levels`
--
ALTER TABLE `year_levels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_year_levels_department_name` (`department_code`,`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `candidates`
--
ALTER TABLE `candidates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `partylists`
--
ALTER TABLE `partylists`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `voters`
--
ALTER TABLE `voters`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `year_levels`
--
ALTER TABLE `year_levels`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ballot_choices`
--
ALTER TABLE `ballot_choices`
  ADD CONSTRAINT `fk_choices_ballot` FOREIGN KEY (`ballot_id`) REFERENCES `ballots` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_choices_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`),
  ADD CONSTRAINT `fk_choices_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`);

--
-- Constraints for table `candidates`
--
ALTER TABLE `candidates`
  ADD CONSTRAINT `fk_candidates_department` FOREIGN KEY (`department_code`) REFERENCES `departments` (`code`),
  ADD CONSTRAINT `fk_candidates_partylist` FOREIGN KEY (`partylist_id`) REFERENCES `partylists` (`id`),
  ADD CONSTRAINT `fk_candidates_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`);

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `fk_sections_year_level` FOREIGN KEY (`year_level_id`) REFERENCES `year_levels` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `voters`
--
ALTER TABLE `voters`
  ADD CONSTRAINT `fk_voters_department` FOREIGN KEY (`department_code`) REFERENCES `departments` (`code`);

--
-- Constraints for table `year_levels`
--
ALTER TABLE `year_levels`
  ADD CONSTRAINT `fk_year_levels_department` FOREIGN KEY (`department_code`) REFERENCES `departments` (`code`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
