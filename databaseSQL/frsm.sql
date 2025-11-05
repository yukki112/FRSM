-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3307
-- Generation Time: Nov 05, 2025 at 05:12 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `frsm`
--

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `attempt_time` datetime NOT NULL,
  `successful` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `ip_address`, `email`, `attempt_time`, `successful`) VALUES
(25, '::1', 'stephenviray12@gmail.com', '2025-11-03 22:03:30', 1),
(26, '::1', 'stephenviray12@gmail.com', '2025-11-03 22:06:59', 1),
(27, '::1', 'stephenviray12@gmail.com', '2025-11-03 22:07:28', 1),
(33, '::1', 'stephenviray12@gmail.com', '2025-11-03 23:12:40', 1),
(37, '::1', 'stephenviray12@gmail.com', '2025-11-03 23:44:02', 1),
(39, '::1', 'stephenviray12@gmail.com', '2025-11-04 21:56:34', 1),
(40, '::1', 'stephenviray12@gmail.com', '2025-11-05 12:11:29', 1),
(41, '::1', 'stephenviray12@gmail.com', '2025-11-05 12:11:53', 1),
(42, '::1', 'stephenviray12@gmail.com', '2025-11-05 12:12:10', 1);

-- --------------------------------------------------------

--
-- Table structure for table `registration_attempts`
--

CREATE TABLE `registration_attempts` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `attempt_time` datetime NOT NULL,
  `successful` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `registration_attempts`
--

INSERT INTO `registration_attempts` (`id`, `ip_address`, `email`, `attempt_time`, `successful`) VALUES
(9, '::1', 'stephenviray12@gmail.com', '2025-11-03 20:26:02', 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `contact` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `date_of_birth` date NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('ADMIN','EMPLOYEE','USER') DEFAULT 'USER',
  `is_verified` tinyint(1) DEFAULT 0,
  `verification_code` varchar(10) DEFAULT NULL,
  `code_expiry` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `middle_name`, `last_name`, `username`, `contact`, `address`, `date_of_birth`, `email`, `password`, `role`, `is_verified`, `verification_code`, `code_expiry`, `created_at`, `updated_at`, `reset_token`, `token_expiry`) VALUES
(8, 'Stephen', 'Kyle', 'Viray', 'Yukki', '09984319585', '054 gold extention\r\nbaranggay commonwelth qc', '2004-02-10', 'stephenviray12@gmail.com', '$2y$12$uqYr3cQY/J.qh/mplrTby.ZgL.VoVczZtwtTCvSnfbDCyicDil4pS', 'USER', 1, NULL, NULL, '2025-11-03 12:26:02', '2025-11-05 04:12:20', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `verification_codes`
--

CREATE TABLE `verification_codes` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  `expiry` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `verification_codes`
--

INSERT INTO `verification_codes` (`id`, `email`, `code`, `expiry`, `created_at`) VALUES
(8, 'stephenviray12@gmail.com', '713642', '2025-11-03 13:41:01', '2025-11-03 12:26:02'),
(9, 'stephenviray12@gmail.com', '491175', '2025-11-03 13:56:17', '2025-11-03 12:26:17'),
(10, 'stephenviray12@gmail.com', '589667', '2025-11-03 14:13:52', '2025-11-03 12:43:52'),
(11, 'stephenviray12@gmail.com', '787000', '2025-11-03 14:14:35', '2025-11-03 12:44:35'),
(13, 'stephenviray12@gmail.com', '073181', '2025-11-03 14:24:16', '2025-11-03 12:54:16'),
(14, 'stephenviray12@gmail.com', '481594', '2025-11-03 14:24:42', '2025-11-03 12:54:42'),
(15, 'stephenviray12@gmail.com', '311995', '2025-11-03 14:25:50', '2025-11-03 12:55:50'),
(16, 'stephenviray12@gmail.com', '536095', '2025-11-03 14:26:24', '2025-11-03 12:56:24'),
(18, 'stephenviray12@gmail.com', '194171', '2025-11-03 15:49:25', '2025-11-03 14:19:25'),
(19, 'stephenviray12@gmail.com', '335715', '2025-11-03 16:41:34', '2025-11-03 15:11:34'),
(20, 'stephenviray12@gmail.com', '801337', '2025-11-03 16:41:53', '2025-11-03 15:11:53');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  ADD KEY `idx_time` (`attempt_time`);

--
-- Indexes for table `registration_attempts`
--
ALTER TABLE `registration_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  ADD KEY `idx_time` (`attempt_time`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_reset_token` (`reset_token`),
  ADD KEY `idx_token_expiry` (`token_expiry`);

--
-- Indexes for table `verification_codes`
--
ALTER TABLE `verification_codes`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `registration_attempts`
--
ALTER TABLE `registration_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `verification_codes`
--
ALTER TABLE `verification_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
