-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3307
-- Generation Time: Jan 21, 2026 at 12:46 PM
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
-- Database: `frsm`
--

-- --------------------------------------------------------

--
-- Table structure for table `api_incidents`
--

CREATE TABLE `api_incidents` (
  `id` int(11) NOT NULL,
  `external_id` int(11) NOT NULL COMMENT 'ID from external API',
  `user_id` int(11) DEFAULT NULL,
  `alert_type` varchar(50) DEFAULT NULL,
  `emergency_type` varchar(50) NOT NULL,
  `assistance_needed` varchar(50) DEFAULT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `title` varchar(255) DEFAULT NULL,
  `caller_name` varchar(100) NOT NULL,
  `caller_phone` varchar(20) NOT NULL,
  `location` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` enum('pending','processing','responded','closed') DEFAULT 'pending',
  `affected_barangays` text DEFAULT NULL,
  `issued_by` varchar(100) DEFAULT NULL,
  `valid_until` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL COMMENT 'From API',
  `responded_at` datetime DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `sync_status` enum('synced','pending','failed') DEFAULT 'synced',
  `last_sync_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at_local` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_fire_rescue_related` tinyint(1) DEFAULT 0,
  `rescue_category` enum('building_collapse','vehicle_accident','height_rescue','water_rescue','other_rescue') DEFAULT NULL,
  `dispatch_status` enum('for_dispatch','processing','responded','closed') DEFAULT 'for_dispatch',
  `dispatch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `api_incidents`
--

INSERT INTO `api_incidents` (`id`, `external_id`, `user_id`, `alert_type`, `emergency_type`, `assistance_needed`, `severity`, `title`, `caller_name`, `caller_phone`, `location`, `description`, `status`, `affected_barangays`, `issued_by`, `valid_until`, `created_at`, `responded_at`, `responded_by`, `notes`, `sync_status`, `last_sync_at`, `created_at_local`, `updated_at`, `is_fire_rescue_related`, `rescue_category`, `dispatch_status`, `dispatch_id`) VALUES
(1, 3, NULL, 'Typhoon', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Taga jan lang po', 'May sunog po sami dito sa Commonwealth', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-11 15:05:41', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-13 12:27:12', 1, NULL, 'for_dispatch', NULL),
(2, 1, NULL, 'Typhoon', 'fire', 'fire', 'high', 'Fire Emergency Report', 'John Doe', '09171234567', '123 Main St, Holy Spirit, QC', 'Test emergency', 'pending', 'Holy Spirit', 'Test User', NULL, '2026-01-11 14:57:49', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-14 18:17:39', 1, NULL, 'processing', 15),
(3, 2, NULL, 'Typhoon', 'medical', 'medical', 'critical', 'Medical Emergency Report', 'Jane Smith', '09187654321', '456 Elm St, Batasan Hills, QC', 'Medical emergency', 'pending', 'Batasan Hills', 'Test User', NULL, '2026-01-11 14:57:49', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-13 12:28:49', 0, NULL, 'for_dispatch', NULL),
(4, 15, NULL, 'Typhoon', 'fire', 'fire', 'medium', 'Other Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Testing', 'Need Rescue collapsing building Barangay Holy Spirit', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-11 17:42:02', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-14 18:17:27', 1, NULL, 'for_dispatch', 7),
(5, 14, NULL, '', 'fire', 'medical', 'high', 'Severe Injury Assistance Needed', 'Maria Santos', '09171230011', 'Block 12, Brgy. Bagong Silangan, QC', 'Person sustained a severe cut on the leg after falling', 'pending', 'Bagong Silangan', 'Neighbor', NULL, '2026-01-11 15:30:00', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-14 18:17:25', 1, NULL, 'processing', 13),
(6, 13, NULL, 'Earthquake', '', 'monitoring', 'low', 'Aftershock Felt', 'Joshua Tan', '09171230010', 'Block 10, Brgy. Batasan, QC', 'Light aftershock felt, no visible damage', 'pending', '', 'Resident', NULL, '2026-01-11 15:05:00', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-11 17:48:46', 0, NULL, 'for_dispatch', NULL),
(7, 12, NULL, 'Typhoon', '', 'utility', '', 'Power Outage Report', 'Cynthia Ramos', '09171230009', 'Sitio Masagana, Brgy. Payatas, QC', 'No electricity since early morning', 'pending', 'Payatas', 'Resident', NULL, '2026-01-11 14:40:12', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-11 17:48:46', 0, NULL, 'for_dispatch', NULL),
(8, 9, NULL, 'Flood', '', 'monitoring', 'low', 'Minor Flooding Observed', 'Leo Navarro', '09171230006', 'Zone 1, Brgy. Commonwealth, QC', 'Ankle-deep water on side streets', 'pending', 'Commonwealth', 'Traffic Aide', NULL, '2026-01-11 13:00:00', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-11 17:48:46', 0, NULL, 'for_dispatch', NULL),
(9, 7, NULL, 'Earthquake', '', 'inspection', '', 'Post-Earthquake Inspection Request', 'Dennis Uy', '09171230004', 'Street 5, Brgy. Holy Spirit, QC', 'Visible cracks on residential wall', 'pending', 'Holy Spirit', 'Homeowner', NULL, '2026-01-11 11:40:00', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-11 17:48:46', 0, NULL, 'for_dispatch', NULL),
(10, 6, NULL, '', '', 'medical', 'high', 'Medical Assistance Needed', 'Ramon Dela Cruz', '09171230003', 'Block 8, Brgy. Bagong Silangan, QC', 'Patient experiencing severe chest pain', 'pending', 'Bagong Silangan', 'Family Member', NULL, '2026-01-11 11:05:22', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-11 17:48:46', 0, NULL, 'for_dispatch', NULL),
(11, 4, NULL, 'Flood', '', 'evacuation', 'high', 'Flood Alert in Commonwealth', 'Alvin Reyes', '09171230001', 'Zone 3, Brgy. Commonwealth, QC', 'Flood water rising rapidly after heavy rainfall', 'pending', 'Commonwealth', 'Barangay Officer', NULL, '2026-01-11 10:15:00', NULL, NULL, NULL, 'synced', '2026-01-11 17:48:46', '2026-01-11 17:48:46', '2026-01-11 17:48:46', 0, NULL, 'for_dispatch', NULL),
(14, 17, NULL, 'Typhoon', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'Katrina Decepida', '09383741627', '8-4C HACIENDA BALAI, BRGY. KALIGAYAHAN, QUEZON CITY', 'bzjakamxhwiwjsjsjsidnnxbxbxnsiwks', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-12 04:11:44', NULL, NULL, NULL, 'synced', '2026-01-12 15:32:37', '2026-01-12 15:32:37', '2026-01-13 12:27:12', 1, NULL, 'for_dispatch', NULL),
(17, 18, NULL, 'Typhoon', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Brgy. Holy Spirit', 'Nasusunog po yung bangketa dito banda sa talipapa sa Holy Drive', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-12 16:15:18', NULL, NULL, NULL, 'synced', '2026-01-12 17:30:25', '2026-01-12 17:30:25', '2026-01-13 12:27:12', 1, NULL, 'for_dispatch', NULL),
(18, 19, NULL, 'Typhoon', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Brgy. Holy Spirit', 'Nasusunog po yung bangketa dito banda sa talipapa sa Holy Drive', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-12 17:36:19', NULL, NULL, NULL, 'synced', '2026-01-12 17:36:21', '2026-01-12 17:36:21', '2026-01-13 12:27:12', 1, NULL, 'for_dispatch', NULL),
(19, 20, NULL, 'Typhoon', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Brgy. Commonwealth', 'Nasusunog po yung building malapit sa Puregold', 'pending', 'Commonwealth', 'Anonymous', NULL, '2026-01-12 17:37:01', NULL, NULL, NULL, 'synced', '2026-01-12 17:37:16', '2026-01-12 17:37:16', '2026-01-13 16:49:47', 1, NULL, 'for_dispatch', NULL),
(20, 21, NULL, 'Typhoon', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Barangay Bagong Silangan', 'Nasusunog po dito banda sa school malapit sa baranggay hall', 'pending', 'Bagong Silangan', 'Anonymous', NULL, '2026-01-12 18:24:44', NULL, NULL, NULL, 'synced', '2026-01-12 18:24:52', '2026-01-12 18:24:52', '2026-01-13 16:49:47', 1, NULL, 'for_dispatch', NULL),
(22, 23, NULL, 'Security', 'rescue', 'rescue', 'medium', 'Rescue Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Barangay Bagong Silangan', 'Nalaglag po ng 3rd floor yung kaibigan namin please send po ng help dito sa blk 22 lt 21', 'processing', 'Bagong Silangan', 'Anonymous', NULL, '2026-01-14 17:52:55', NULL, NULL, NULL, 'synced', '2026-01-14 17:53:10', '2026-01-14 17:53:10', '2026-01-14 17:53:10', 0, NULL, 'for_dispatch', NULL),
(23, 22, NULL, 'Typhoon', 'other', 'rescue', 'medium', 'Rescue Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Barangay Bagong Silangan', 'Nahulog po sa 3rd floor kasama namin nabalian po', 'pending', 'Bagong Silangan', 'Anonymous', NULL, '2026-01-14 17:28:22', NULL, NULL, NULL, 'synced', '2026-01-14 17:55:39', '2026-01-14 17:55:39', '2026-01-14 17:55:39', 0, NULL, 'for_dispatch', NULL),
(24, 24, NULL, 'Security', 'rescue', 'rescue', 'medium', 'Rescue Emergency Report', 'yukki', '09984319585', 'mary rose strore sanchez street', 'na ipit po yung ulo ng kaibigan namin sa bintana', 'processing', 'Holy Spirit', 'Anonymous', NULL, '2026-01-14 18:13:17', NULL, NULL, NULL, 'synced', '2026-01-14 18:13:29', '2026-01-14 18:13:29', '2026-01-14 18:13:29', 0, NULL, 'for_dispatch', NULL),
(25, 25, NULL, 'Security', 'rescue', 'rescue', 'medium', 'Rescue Emergency Report', 'yukki', '09984319585', 'mary rose strore sanchez street', 'testtasdasdadasdddddddddddddddddddddd', 'processing', 'Holy Spirit', 'Anonymous', NULL, '2026-01-14 18:31:51', NULL, NULL, NULL, 'synced', '2026-01-14 18:31:51', '2026-01-14 18:31:51', '2026-01-14 18:31:51', 0, NULL, 'for_dispatch', NULL),
(26, 26, NULL, 'Security', 'rescue', 'rescue', 'medium', 'Rescue Emergency Report', 'Marcus Pelaez', '09263969662', 'Barangay Holy Spirit, Quezon City', 'Yung tropa ko nasa bangin kelangan namen ng tulong dito banda sa payatas rd', 'processing', 'Holy Spirit', 'User 9', NULL, '2026-01-14 19:14:59', NULL, NULL, NULL, 'synced', '2026-01-14 19:15:07', '2026-01-14 19:15:07', '2026-01-14 19:17:41', 0, NULL, 'processing', 16),
(27, 16, NULL, 'Typhoon', 'other', 'fire', 'medium', 'Other Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Testing', 'Need Rescue collapsing building Barangay Holy Spirit', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-11 17:50:49', NULL, NULL, NULL, 'synced', '2026-01-14 21:00:53', '2026-01-14 21:00:53', '2026-01-14 21:00:53', 1, 'building_collapse', 'for_dispatch', NULL),
(28, 30, NULL, 'Fire', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'bing', '09358322191', 'Quezon city', 'sunoooggg 4t83tieguegeggregg3egiegegeg', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-15 08:41:51', NULL, NULL, NULL, 'synced', '2026-01-15 17:30:42', '2026-01-15 17:30:42', '2026-01-15 17:30:42', 1, NULL, 'for_dispatch', NULL),
(29, 29, NULL, 'Fire', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'Marcus Geremie D.R. Pelaez', '09263969662', 'Brgy. Commonwealth', 'Nasusunog po yung building malapit sa Puregold', 'pending', 'Commonwealth', 'Anonymous', NULL, '2026-01-15 02:26:47', NULL, NULL, NULL, 'synced', '2026-01-15 17:30:42', '2026-01-15 17:30:42', '2026-01-15 17:30:42', 1, NULL, 'for_dispatch', NULL),
(30, 27, NULL, 'Security', 'rescue', 'rescue', 'medium', 'Rescue Emergency Report', 'Marcus Pelaez', '09263969662', 'Barangay Holy Spirit, Quezon City', 'Yung tropa ko nasa bangin kelangan namen ng tulong dito banda sa payatas rd', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-14 21:36:37', NULL, NULL, NULL, 'synced', '2026-01-15 17:30:42', '2026-01-15 17:30:42', '2026-01-15 17:30:42', 0, NULL, 'for_dispatch', NULL),
(31, 28, NULL, 'Other', 'other', 'rescue', 'medium', 'Rain Emergency Report', 'Von Dulfo', '09458252517', 'Barangay Holy Spirit, Quezon City', 'Tulong guys 12345678nadjandk jnakdnakdakjsnaw', 'pending', 'Holy Spirit', 'User 10', NULL, '2026-01-14 22:36:15', NULL, NULL, NULL, 'synced', '2026-01-15 17:30:42', '2026-01-15 17:30:42', '2026-01-15 17:30:42', 0, NULL, 'for_dispatch', NULL),
(32, 34, NULL, 'fire', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'Maria Santos', '09569733114', 'Bagong Silangan', 'rfeegasdfghyjukloikjtgewddfghmjhngfdsfgnhn', 'pending', 'Bagong Silangan', 'Anonymous', NULL, '2026-01-16 13:19:43', NULL, NULL, NULL, 'synced', '2026-01-21 09:59:15', '2026-01-21 09:59:15', '2026-01-21 09:59:15', 1, NULL, 'for_dispatch', NULL),
(33, 32, NULL, 'fire', 'fire', 'fire', 'medium', 'Fire Emergency Report', 'Danielle Marsh', '09984319585', 'asddddddddddd', 'sadddddddddyiguiouasydgouasyhgduyasgduahosybdas', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-16 12:29:52', NULL, NULL, NULL, 'synced', '2026-01-21 09:59:15', '2026-01-21 09:59:15', '2026-01-21 09:59:15', 1, NULL, 'for_dispatch', NULL),
(34, 33, NULL, 'security', 'rescue', 'police', 'medium', 'Rescue Emergency Report', 'Danielle Marsh', '09984319585', 'asddddddddddd', '1111111111111111111111111111111111111', 'pending', 'Holy Spirit', 'Anonymous', NULL, '2026-01-16 12:31:54', NULL, NULL, NULL, 'synced', '2026-01-21 09:59:15', '2026-01-21 09:59:15', '2026-01-21 09:59:15', 0, NULL, 'for_dispatch', NULL),
(35, 31, NULL, 'security', 'rescue', 'rescue', 'medium', 'Rescue Emergency Report', 'Trisha May Tudillo', '09938137366', 'Barangay Batasan Hills, Quezon City', 'Nalaglag sa bangin tropa ko boss', 'pending', 'Batasan Hills', 'User 15', NULL, '2026-01-16 03:40:18', NULL, NULL, NULL, 'synced', '2026-01-21 09:59:15', '2026-01-21 09:59:15', '2026-01-21 09:59:15', 0, NULL, 'for_dispatch', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance_logs`
--

CREATE TABLE `attendance_logs` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `shift_date` date NOT NULL,
  `user_id` int(11) NOT NULL,
  `check_in` datetime DEFAULT NULL,
  `check_out` datetime DEFAULT NULL,
  `attendance_status` enum('present','late','absent','excused','on_leave') DEFAULT NULL,
  `total_hours` decimal(5,2) DEFAULT NULL,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_logs`
--

INSERT INTO `attendance_logs` (`id`, `shift_id`, `volunteer_id`, `shift_date`, `user_id`, `check_in`, `check_out`, `attendance_status`, `total_hours`, `overtime_hours`, `notes`, `verified_by`, `verified_at`, `created_at`, `updated_at`) VALUES
(2, 131, 13, '2026-01-19', 10, '2026-01-16 21:10:04', '2026-01-16 21:10:10', 'present', 0.00, 0.00, 'test Checked out: test', NULL, NULL, '2026-01-16 20:10:04', '2026-01-16 20:10:10');

-- --------------------------------------------------------

--
-- Table structure for table `change_requests`
--

CREATE TABLE `change_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `field` varchar(50) NOT NULL,
  `current_value` text DEFAULT NULL,
  `requested_value` text NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dispatch_incidents`
--

CREATE TABLE `dispatch_incidents` (
  `id` int(11) NOT NULL,
  `incident_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `vehicles_json` text DEFAULT NULL COMMENT 'JSON array of vehicles',
  `dispatched_by` int(11) DEFAULT NULL,
  `dispatched_at` datetime DEFAULT current_timestamp(),
  `status` enum('pending','dispatched','en_route','arrived','completed','cancelled') DEFAULT 'pending',
  `status_updated_at` datetime DEFAULT NULL,
  `er_notes` text DEFAULT NULL COMMENT 'Notes from Emergency Response',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dispatch_incidents`
--

INSERT INTO `dispatch_incidents` (`id`, `incident_id`, `unit_id`, `vehicles_json`, `dispatched_by`, `dispatched_at`, `status`, `status_updated_at`, `er_notes`, `created_at`) VALUES
(13, 5, 1, '[{\"id\":1,\"vehicle_name\":\"Fire Truck 1\",\"type\":\"Fire\"}]', 8, '2026-01-14 00:54:24', 'pending', NULL, NULL, '2026-01-13 16:54:24'),
(15, 2, 6, '[{\"id\":5,\"vehicle_name\":\"Fire Truck 5\",\"type\":\"Fire\",\"available\":1,\"status\":\"Available\"},{\"id\":4,\"vehicle_name\":\"Fire Truck 4\",\"type\":\"Fire\",\"available\":1,\"status\":\"Available\"}]', 8, '2026-01-15 00:58:19', 'pending', NULL, NULL, '2026-01-14 16:58:19'),
(16, 26, 2, '[{\"id\":8,\"vehicle_name\":\"Ambulance 3\",\"type\":\"Rescue\",\"available\":1,\"status\":\"Available\"}]', 8, '2026-01-15 03:17:41', 'pending', NULL, NULL, '2026-01-14 19:17:41');

-- --------------------------------------------------------

--
-- Table structure for table `duty_assignments`
--

CREATE TABLE `duty_assignments` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `duty_type` varchar(100) NOT NULL COMMENT 'Type of duty (e.g., Fire Suppression, Rescue, Medical, Logistics)',
  `duty_description` text NOT NULL COMMENT 'Specific duties and responsibilities',
  `priority` enum('primary','secondary','support') DEFAULT 'primary',
  `required_equipment` text DEFAULT NULL COMMENT 'Required equipment for this duty',
  `required_training` text DEFAULT NULL COMMENT 'Required training/certifications',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `duty_assignments`
--

INSERT INTO `duty_assignments` (`id`, `shift_id`, `duty_type`, `duty_description`, `priority`, `required_equipment`, `required_training`, `notes`, `created_at`, `updated_at`) VALUES
(1, 131, 'logistics_support', 'Manage and distribute equipment, supplies, and resources to support ongoing operations.', 'support', 'gears', 'Inventory Management, Supply Chain Operations', '', '2026-01-15 22:55:50', '2026-01-15 22:55:50'),
(5, 135, 'salvage_overhaul', 'Perform salvage operations to protect property and overhaul to ensure complete extinguishment.', 'primary', 'test', 'Salvage Operations, Overhaul Techniques, Property Conservation', 'tetst', '2026-01-16 17:14:57', '2026-01-16 17:14:57');

-- --------------------------------------------------------

--
-- Table structure for table `duty_templates`
--

CREATE TABLE `duty_templates` (
  `id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `duty_type` varchar(100) NOT NULL,
  `duty_description` text NOT NULL,
  `priority` enum('primary','secondary','support') DEFAULT 'primary',
  `required_equipment` text DEFAULT NULL,
  `required_training` text DEFAULT NULL,
  `applicable_units` text DEFAULT NULL COMMENT 'Comma-separated list of unit types this template applies to',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `duty_templates`
--

INSERT INTO `duty_templates` (`id`, `template_name`, `duty_type`, `duty_description`, `priority`, `required_equipment`, `required_training`, `applicable_units`, `created_at`, `updated_at`) VALUES
(1, 'Standard Fire Suppression', 'fire_suppression', 'Primary firefighting duties including hose line operations, water supply, ventilation, and search & rescue in fire conditions.', 'primary', 'Turnout gear, SCBA, helmet, gloves, boots, radio', 'Basic Firefighter Training, SCBA Certification, Hose & Ladder Operations', 'Fire', '2026-01-15 16:00:00', '2026-01-15 16:00:00'),
(2, 'Emergency Medical Response', 'emergency_medical', 'Provide emergency medical care including patient assessment, basic life support, and stabilization until EMS arrival.', 'primary', 'First aid kit, AED, oxygen, trauma bag, gloves', 'First Aid/CPR Certification, Emergency Medical Responder, Bloodborne Pathogens', 'EMS,Rescue', '2026-01-15 16:00:00', '2026-01-15 16:00:00'),
(3, 'Rescue Operations', 'rescue_operations', 'Search and rescue operations including victim location, extrication, and technical rescue scenarios.', 'primary', 'Rescue tools, ropes, harnesses, helmets, gloves', 'Technical Rescue Training, Rope Rescue Certification, Confined Space Awareness', 'Rescue', '2026-01-15 16:00:00', '2026-01-15 16:00:00'),
(4, 'Command Post Support', 'command_post', 'Assist with incident command system operations including communications, resource tracking, and documentation.', 'support', 'Radio, clipboard, forms, maps, computer', 'ICS Training, Resource Management, Communications Protocols', 'Command', '2026-01-15 16:00:00', '2026-01-15 16:00:00'),
(5, 'Logistics Support', 'logistics_support', 'Manage and distribute equipment, supplies, and resources to support ongoing operations.', 'support', 'Inventory lists, supplies, equipment tracking forms', 'Inventory Management, Supply Chain Operations', 'Logistics', '2026-01-15 16:00:00', '2026-01-15 16:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `recipient` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_logs`
--

INSERT INTO `email_logs` (`id`, `recipient`, `subject`, `body`, `status`, `sent_at`) VALUES
(1, 'stephenviray12123123@gmail.com', 'Volunteer Application Approved - Account Created', 'Dear party,\n\nYour volunteer application has been approved!\n\nYour login credentials:\nUsername: stephenviray12123123\nPassword: #PST0000\n\nPlease login at: [Your Login URL]\n\nNote: This is your default password. Please change it after your first login.\n\nBest regards,\nFire & Rescue Team', 'sent', '2026-01-14 23:15:06'),
(2, 'stephenviray121111@gmail.com', 'Volunteer Application Approved - Account Created', 'Dear zaldy,\n\nYour volunteer application has been approved!\n\nYour login credentials:\nUsername: stephenviray121111\nPassword: #Z0000\n\nPlease login at: [Your Login URL]\n\nNote: This is your default password. Please change it after your first login.\n\nBest regards,\nFire & Rescue Team', 'sent', '2026-01-14 23:41:08');

-- --------------------------------------------------------

--
-- Table structure for table `feedbacks`
--

CREATE TABLE `feedbacks` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `rating` int(11) NOT NULL DEFAULT 5,
  `message` text NOT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0,
  `is_approved` tinyint(1) DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedbacks`
--

INSERT INTO `feedbacks` (`id`, `name`, `email`, `rating`, `message`, `is_anonymous`, `is_approved`, `user_id`, `created_at`, `updated_at`) VALUES
(1, 'yuuki', 'maria@example.com', 5, 'The quick response from Barangay Commonwealth Fire & Rescue saved our home during the recent fire incident. Their professionalism and dedication are truly commendable.', 0, 1, NULL, '2025-12-03 12:29:53', '2025-12-03 12:30:14'),
(2, 'Carlos Reyes', 'carlos@example.com', 5, 'Volunteering with the fire and rescue team has been one of the most rewarding experiences of my life. The training is excellent and the team feels like family.', 0, 1, NULL, '2025-12-03 12:29:53', '2025-12-03 12:29:53'),
(3, 'Anna Santos', 'anna@example.com', 4, 'The fire safety seminar organized by the team was incredibly informative. I now feel much more prepared to handle emergency situations at home and work.', 0, 1, NULL, '2025-12-03 12:29:53', '2025-12-03 12:29:53'),
(4, NULL, NULL, 5, 'Excellent service! The team responded quickly to our emergency call and handled the situation professionally. Thank you for keeping our community safe.', 1, 1, NULL, '2025-12-03 12:29:53', '2025-12-03 12:29:53'),
(5, NULL, NULL, 5, 'The volunteer training program is outstanding. I learned valuable skills that I can use in everyday emergencies. Highly recommended!', 1, 1, NULL, '2025-12-03 12:29:53', '2025-12-03 12:29:53'),
(6, NULL, NULL, 4, 'This is trash. jk its tesing feedback tho', 1, 1, NULL, '2025-12-03 12:31:58', '2025-12-03 12:34:14'),
(7, 'Haerin Kang', 'stephenviray12@gmail.com', 5, 'WOWWWWW', 0, 1, NULL, '2025-12-03 12:33:43', '2025-12-03 12:34:16'),
(8, NULL, NULL, 2, 'panget ng gawa, pero pogi gumawa! <3', 1, 1, NULL, '2025-12-03 13:28:16', '2025-12-03 13:50:32');

-- --------------------------------------------------------

--
-- Table structure for table `incident_reports`
--

CREATE TABLE `incident_reports` (
  `id` int(11) NOT NULL,
  `external_id` int(11) DEFAULT NULL COMMENT 'ID from external API',
  `location` varchar(255) NOT NULL,
  `affected_barangays` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `incident_type` varchar(50) NOT NULL,
  `assistance_needed` varchar(50) DEFAULT NULL,
  `alert_type` varchar(50) DEFAULT NULL,
  `emergency_level` enum('low','medium','high','critical') DEFAULT 'medium',
  `status` enum('pending','processing','responded','closed','reported','dispatched','in_progress','resolved') DEFAULT 'pending',
  `date_reported` datetime DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reported_by` varchar(100) DEFAULT NULL,
  `issued_by` varchar(100) DEFAULT NULL,
  `caller_name` varchar(100) DEFAULT NULL,
  `caller_phone` varchar(20) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `valid_until` datetime DEFAULT NULL,
  `incident_proof` varchar(255) DEFAULT NULL COMMENT 'Optional photo/video proof'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `incident_status_logs`
--

CREATE TABLE `incident_status_logs` (
  `id` int(11) NOT NULL,
  `incident_id` int(11) NOT NULL,
  `old_status` varchar(50) NOT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `change_notes` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(106, '::1', 'stephenviray12@gmail.com', '2026-01-16 23:54:00', 1),
(108, '::1', 'stephenviray121111@gmail.com', '2026-01-16 23:54:30', 1),
(109, '::1', 'stephenviray121111@gmail.com', '2026-01-17 04:11:12', 1),
(110, '::1', 'stephenviray12@gmail.com', '2026-01-18 02:56:53', 1),
(111, '::1', 'stephenviray12@gmail.com', '2026-01-19 01:13:56', 1),
(112, '::1', 'stephenviray121111@gmail.com', '2026-01-19 01:15:03', 1),
(113, '::1', 'stephenviray12@gmail.com', '2026-01-19 01:19:11', 1),
(114, '::1', 'stephenviray121111@gmail.com', '2026-01-19 01:19:50', 1),
(116, '::1', 'stephenviray121111@gmail.com', '2026-01-19 14:42:14', 1),
(117, '::1', 'stephenviray12@gmail.com', '2026-01-19 18:15:56', 1),
(118, '::1', 'stephenviray121111@gmail.com', '2026-01-19 18:18:54', 1),
(119, '::1', 'stephenviray121111@gmail.com', '2026-01-20 00:49:14', 1),
(122, '::1', 'yenajigumina12@gmail.com', '2026-01-20 14:55:08', 1),
(123, '::1', 'stephenviray12@gmail.com', '2026-01-20 14:55:16', 1),
(125, '::1', 'stephenviray121111@gmail.com', '2026-01-20 14:55:30', 1),
(126, '::1', 'yenajigumina12@gmail.com', '2026-01-27 16:18:25', 1),
(127, '::1', 'stephenviray121111@gmail.com', '2026-01-20 18:27:51', 1),
(128, '::1', 'stephenviray121111@gmail.com', '2026-01-21 13:28:36', 1),
(129, '::1', 'stephenviray12@gmail.com', '2026-01-21 13:28:56', 1),
(130, '::1', 'yenajigumina12@gmail.com', '2026-01-21 13:29:04', 1);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `is_read`, `created_at`) VALUES
(4, 10, 'shift_declined', 'Shift Declined', 'You have declined your shift scheduled on 2026-01-18', 0, '2026-01-16 01:27:44'),
(5, 10, 'shift_confirmation', 'Shift Confirmed', 'You have confirmed your shift scheduled on 2026-01-17', 0, '2026-01-16 01:27:50'),
(6, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-01-24', 0, '2026-01-16 01:49:34'),
(7, 10, 'shift_declined', 'Shift Declined', 'You have declined your shift scheduled on 2026-01-31', 0, '2026-01-16 03:09:44'),
(8, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 03:30:49'),
(9, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Jan 19, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(10, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Jan 21, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(11, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Jan 23, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(12, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Jan 26, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(13, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Jan 28, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(14, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Jan 30, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(15, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(16, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(17, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(18, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 09, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(19, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 11, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(20, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 13, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:31:06'),
(21, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 03:35:54'),
(22, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-01-18', 0, '2026-01-16 03:36:29'),
(23, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 25, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 03:37:35'),
(24, 10, 'shift_confirmation', 'Shift Confirmed', 'You have confirmed your shift scheduled on 2026-01-25', 0, '2026-01-16 03:37:44'),
(25, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 03:52:39'),
(26, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-01-18', 0, '2026-01-16 03:53:00'),
(27, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 03:53:05'),
(28, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 26, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 03:56:55'),
(29, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(30, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(31, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(32, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 09, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(33, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 11, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(34, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 13, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(35, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 16, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(36, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 18, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(37, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 20, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(38, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 23, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(39, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 25, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(40, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 27, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(41, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Mar 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(42, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Mar 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(43, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Mar 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:17'),
(44, 10, 'shift_confirmation', 'Shift Confirmed', 'You have confirmed your shift scheduled on 2026-01-26', 0, '2026-01-16 03:57:32'),
(45, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(46, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(47, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(48, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 09, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(49, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 11, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(50, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 13, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(51, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 16, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(52, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 18, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(53, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 20, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(54, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 23, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(55, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 25, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(56, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Feb 27, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(57, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Mar 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(58, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Mar 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(59, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new recurring shift on Mar 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.', 0, '2026-01-16 03:57:38'),
(60, 10, 'shift_change_approved', 'Shift Change Approved', 'Your shift change request has been approved. New schedule: January 25, 2026 from 06:52 AM to 12:52 PM', 0, '2026-01-16 04:49:11'),
(61, 10, 'shift_change_status', 'Shift Change Request ', 'Your shift change request has been approved: oki dokie', 0, '2026-01-16 04:49:11'),
(62, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 15, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 04:49:57'),
(63, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 04:50:42'),
(64, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-01-18', 0, '2026-01-16 04:51:31'),
(65, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 04:51:35'),
(66, 10, 'shift_change_approved', 'Shift Change Approved', 'Your shift change request has been approved. New schedule: January 21, 2026 from 06:00 AM to 12:00 PM', 0, '2026-01-16 04:52:05'),
(67, 10, 'shift_change_status', 'Shift Change Request ', 'Your shift change request has been approved: okay make sure you gonna take that sched', 0, '2026-01-16 04:52:05'),
(68, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Feb 01, 2026 from 10:00 PM to 6:00 AM. Please confirm your availability.', 0, '2026-01-16 04:54:30'),
(69, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-02-01', 0, '2026-01-16 04:55:05'),
(70, 10, 'shift_change_status', 'Shift Change Request ', 'Your shift change request has been approved: test', 0, '2026-01-16 04:55:19'),
(71, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 31, 2026 from 8:00 AM to 5:00 PM. Please confirm your availability.', 0, '2026-01-16 04:56:18'),
(72, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-01-31', 0, '2026-01-16 04:56:48'),
(73, 10, 'shift_change_approved', 'Shift Change Approved', 'Your shift change request has been approved. New schedule: February 01, 2026 from 06:00 AM to 12:00 PM', 0, '2026-01-16 04:56:58'),
(74, 10, 'shift_change_status', 'Shift Change Request ', 'Your shift change request has been approved: okii dokie', 0, '2026-01-16 04:56:58'),
(75, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 31, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 05:03:55'),
(76, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Feb 02, 2026 from 8:00 AM to 5:00 PM. Please confirm your availability.', 0, '2026-01-16 05:04:11'),
(77, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-01-31', 0, '2026-01-16 05:04:50'),
(78, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-02-02', 0, '2026-01-16 05:04:57'),
(79, 10, 'shift_change_approved', 'Shift Change Approved', 'Your shift change request has been approved. New schedule: February 04, 2026 from 12:00 PM to 07:00 PM', 0, '2026-01-16 05:13:46'),
(80, 10, 'shift_change_status', 'Shift Change Request Updated', 'Your shift change request has been approved: okiii', 0, '2026-01-16 05:13:46'),
(81, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 25, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 05:16:54'),
(82, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Mar 01, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 05:17:04'),
(83, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-01-25', 0, '2026-01-16 05:17:46'),
(84, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-03-01', 0, '2026-01-16 05:17:56'),
(85, 10, 'shift_change_approved', 'Shift Change Approved', 'Your shift change request has been approved. New schedule: January 28, 2026 from 07:00 PM to 02:00 PM', 0, '2026-01-16 05:27:37'),
(86, 10, 'shift_change_status', 'Shift Change Request Updated', 'Your shift change request has been approved: 12312312', 0, '2026-01-16 05:27:37'),
(87, 10, 'shift_change_approved', 'Shift Change Approved', 'Your shift change request has been approved. New schedule: January 28, 2026 from 07:00 PM to 02:00 PM', 0, '2026-01-16 05:28:00'),
(88, 10, 'shift_change_status', 'Shift Change Request Updated', 'Your shift change request has been approved: 12312312', 0, '2026-01-16 05:28:00'),
(89, 10, 'shift_change_approved', 'Shift Change Request Approved', 'Your shift change request has been approved. Notes: asdasddddd', 0, '2026-01-16 06:30:31'),
(90, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 19, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-16 06:55:50'),
(91, 10, 'shift_confirmation', 'Shift Confirmed', 'You have confirmed your shift scheduled on 2026-01-19', 0, '2026-01-17 00:54:44'),
(92, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 16, 2026 from 2:00 PM to 10:00 PM. Please confirm your availability.', 0, '2026-01-17 00:55:16'),
(93, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 25, 2026 from 10:00 PM to 6:00 AM. Please confirm your availability.', 0, '2026-01-17 00:55:37'),
(94, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-01-25', 0, '2026-01-17 00:56:06'),
(95, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 25, 2026 from 10:00 PM to 6:00 AM. Please confirm your availability.', 0, '2026-01-17 00:56:08'),
(96, 10, 'new_shift', 'New Shift Assigned', 'You have been assigned a new shift on Jan 25, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.', 0, '2026-01-17 01:14:57'),
(97, 10, 'shift_change_request', 'Shift Change Requested', 'You have requested a change for your shift scheduled on 2026-01-25', 0, '2026-01-17 01:16:11'),
(98, 10, 'shift_change_approved', 'Shift Change Request Approved', 'Your shift change request has been approved. Notes: test', 0, '2026-01-17 01:17:00'),
(99, 10, 'shift_change_approved', 'Shift Change Request Approved', 'Your shift change request has been approved. Notes: test', 0, '2026-01-17 01:17:01'),
(100, 10, 'attendance_checkin', 'Checked In Successfully', 'You have been checked in for your shift starting at 6:00 AM.', 0, '2026-01-17 04:10:04'),
(101, 10, 'attendance_checkout', 'Checked Out Successfully', 'You have been checked out from your shift. Total hours: 0.', 0, '2026-01-17 04:10:10'),
(102, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 01:39:09'),
(103, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Incident Command System', 0, '2026-01-27 18:15:34'),
(104, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Incident Command System', 0, '2026-01-27 18:15:34'),
(106, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:29:46'),
(107, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:29:46'),
(109, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:36:43'),
(110, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:36:43'),
(112, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Wildland Firefighting', 0, '2026-01-27 18:42:37'),
(113, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Wildland Firefighting', 0, '2026-01-27 18:42:37'),
(115, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:48:51'),
(116, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:48:51'),
(118, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Wildland Firefighting', 0, '2026-01-27 18:52:28'),
(119, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Wildland Firefighting', 0, '2026-01-27 18:52:28'),
(121, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:54:02'),
(122, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:54:02'),
(124, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:56:29'),
(125, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 18:56:29'),
(127, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Incident Command System', 0, '2026-01-20 20:32:29'),
(128, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Incident Command System', 0, '2026-01-20 20:32:29'),
(130, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 20:32:41'),
(131, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 20:32:41'),
(133, 8, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 21:22:51'),
(134, 11, 'training_registration', 'New Training Registration', 'Volunteer zaldy solis has registered for training: Advanced Rescue Techniques', 0, '2026-01-20 21:22:51'),
(136, 10, 'training_assigned', 'Training Assigned', 'You have been assigned to training: Wildland Firefighting. Training starts on: January 28, 2026', 0, '2026-01-20 23:25:24');

-- --------------------------------------------------------

--
-- Table structure for table `password_change_logs`
--

CREATE TABLE `password_change_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `changed_by` int(11) DEFAULT NULL COMMENT 'Admin who initiated the change'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(9, '::1', 'stephenviray12@gmail.com', '2025-11-03 20:26:02', 1),
(10, '::1', 'yenajigumina12@gmail.com', '2026-01-20 14:54:34', 1);

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'Employee ID (NULL for volunteers)',
  `volunteer_id` int(11) DEFAULT NULL,
  `shift_for` enum('user','volunteer') DEFAULT 'user' COMMENT 'user = employee shift, volunteer = volunteer shift',
  `unit_id` int(11) DEFAULT NULL COMMENT 'Assigned unit',
  `duty_assignment_id` int(11) DEFAULT NULL,
  `shift_date` date NOT NULL COMMENT 'Date of shift',
  `shift_type` enum('morning','afternoon','evening','night','full_day') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('scheduled','confirmed','in_progress','completed','cancelled','absent') DEFAULT 'scheduled',
  `location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'Who scheduled this shift',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `confirmation_status` enum('pending','confirmed','declined','change_requested') DEFAULT 'pending',
  `confirmed_at` datetime DEFAULT NULL,
  `declined_reason` text DEFAULT NULL,
  `change_request_notes` text DEFAULT NULL,
  `late_threshold` int(11) DEFAULT 15 COMMENT 'Minutes allowed before marked as late',
  `attendance_marked_by` int(11) DEFAULT NULL,
  `attendance_marked_at` datetime DEFAULT NULL,
  `attendance_status` enum('pending','checked_in','checked_out','absent','excused') DEFAULT 'pending',
  `check_in_time` datetime DEFAULT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `attendance_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shifts`
--

INSERT INTO `shifts` (`id`, `user_id`, `volunteer_id`, `shift_for`, `unit_id`, `duty_assignment_id`, `shift_date`, `shift_type`, `start_time`, `end_time`, `status`, `location`, `notes`, `created_by`, `created_at`, `updated_at`, `confirmation_status`, `confirmed_at`, `declined_reason`, `change_request_notes`, `late_threshold`, `attendance_marked_by`, `attendance_marked_at`, `attendance_status`, `check_in_time`, `check_out_time`, `attendance_notes`) VALUES
(131, 10, 13, 'volunteer', 3, 1, '2026-01-19', 'morning', '06:00:00', '14:00:00', 'completed', 'Main Station', 'testtt', 8, '2026-01-15 22:55:50', '2026-01-16 20:10:10', 'confirmed', '2026-01-17 00:54:44', NULL, NULL, 15, NULL, NULL, 'checked_out', '2026-01-16 21:10:04', '2026-01-16 21:10:10', NULL),
(135, 10, 13, 'volunteer', 8, 5, '2026-02-10', 'morning', '07:00:00', '19:00:00', 'confirmed', 'Main Station', 'testt', 8, '2026-01-16 17:14:57', '2026-01-16 17:17:01', 'confirmed', '2026-01-17 01:17:01', NULL, '421', 15, NULL, NULL, 'pending', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `shift_change_requests`
--

CREATE TABLE `shift_change_requests` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `request_type` enum('time_change','date_change','swap','other') NOT NULL,
  `request_details` text NOT NULL,
  `proposed_date` date DEFAULT NULL,
  `proposed_start_time` time DEFAULT NULL,
  `proposed_end_time` time DEFAULT NULL,
  `swap_with_volunteer_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shift_change_requests`
--

INSERT INTO `shift_change_requests` (`id`, `shift_id`, `volunteer_id`, `request_type`, `request_details`, `proposed_date`, `proposed_start_time`, `proposed_end_time`, `swap_with_volunteer_id`, `status`, `admin_notes`, `requested_at`, `reviewed_at`, `reviewed_by`) VALUES
(12, 135, 13, 'time_change', '421', '2026-02-10', '07:00:00', '19:00:00', NULL, 'approved', 'test', '2026-01-16 17:16:11', '2026-01-17 01:17:01', 8);

-- --------------------------------------------------------

--
-- Table structure for table `shift_confirmations`
--

CREATE TABLE `shift_confirmations` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `status` enum('pending','confirmed','declined') DEFAULT 'pending',
  `response_notes` text DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shift_confirmations`
--

INSERT INTO `shift_confirmations` (`id`, `shift_id`, `volunteer_id`, `status`, `response_notes`, `responded_at`, `created_at`) VALUES
(9, 131, 13, 'confirmed', NULL, '2026-01-17 00:54:44', '2026-01-16 16:54:44'),
(10, 135, 13, 'confirmed', 'Time/Date change approved Time/Date change approved', '2026-01-17 01:17:01', '2026-01-16 17:17:00');

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL,
  `recipient` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` datetime DEFAULT current_timestamp(),
  `response` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sms_logs`
--

INSERT INTO `sms_logs` (`id`, `recipient`, `message`, `status`, `sent_at`, `response`) VALUES
(1, '09984319585', 'Reminder: You have a shift on 2026-01-18 at 06:00:00 - Main Station. Please confirm your availability.', 'sent', '2026-01-16 03:32:24', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `trainings`
--

CREATE TABLE `trainings` (
  `id` int(11) NOT NULL,
  `external_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `training_date` date NOT NULL,
  `training_end_date` date DEFAULT NULL,
  `duration_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `instructor` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `max_participants` int(11) DEFAULT 0,
  `current_participants` int(11) DEFAULT 0,
  `status` enum('scheduled','ongoing','completed','cancelled') DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_sync_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trainings`
--

INSERT INTO `trainings` (`id`, `external_id`, `title`, `description`, `training_date`, `training_end_date`, `duration_hours`, `instructor`, `location`, `max_participants`, `current_participants`, `status`, `created_at`, `updated_at`, `last_sync_at`) VALUES
(1, 8, 'Incident Command System', 'ICS structure and procedures for managing emergency incidents', '2026-02-27', '2026-02-28', 6.00, 'Battalion Chief James Miller', 'Command Center', 30, 0, 'scheduled', '2026-01-19 16:21:18', '2026-01-20 15:11:01', '2026-01-19 17:40:46'),
(2, 5, 'Vehicle Extrication', 'Techniques for extricating victims from vehicle accidents', '2026-02-14', '2026-02-16', 5.00, 'Sgt. Michael Brown', 'Extrication Training Grounds', 15, 0, 'scheduled', '2026-01-19 16:21:18', '2026-01-20 10:29:24', '2026-01-19 17:40:46'),
(3, 7, 'SCBA Maintenance & Use', 'Self-Contained Breathing Apparatus maintenance, inspection, and proper usage', '2026-02-13', '2026-02-14', 3.50, 'Engineer Lisa Thompson', 'Equipment Training Room', 20, 0, 'scheduled', '2026-01-19 16:21:18', '2026-01-20 10:29:26', '2026-01-19 17:40:46'),
(4, 1, 'Fire Safety Basics', 'Introduction to fire safety protocols and basic firefighting techniques', '2026-02-08', '2026-02-09', 4.50, 'Capt. John Smith', 'Main Fire Station', 30, 0, 'scheduled', '2026-01-19 16:21:18', '2026-01-20 10:29:29', '2026-01-19 17:40:46'),
(5, 4, 'Emergency Medical Response', 'First responder medical training and trauma care', '2026-02-01', '2026-02-03', 7.50, 'Dr. Sarah Johnson', 'Medical Training Center', 35, 0, 'scheduled', '2026-01-19 16:21:18', '2026-01-20 10:29:32', '2026-01-19 17:40:46'),
(6, 10, 'Building Collapse Search', 'Search and rescue operations in structural collapse scenarios', '2026-01-29', '2026-01-30', 9.00, 'Special Ops Captain Thomas Reed', 'Collapse Training Structure', 15, 0, 'scheduled', '2026-01-19 16:21:18', '2026-01-20 10:53:40', '2026-01-19 17:40:46'),
(7, 6, 'Wildland Firefighting', 'Techniques for combating wildfires in forest and grassland environments', '2026-01-28', '2026-01-29', 10.00, 'Captain David Wilson', 'Forest Training Area', 25, 1, 'scheduled', '2026-01-19 16:21:18', '2026-01-20 15:25:24', '2026-01-19 17:40:46'),
(8, 2, 'Advanced Rescue Techniques', 'Advanced rope rescue and confined space rescue training', '2026-01-25', '2026-01-26', 8.00, 'Lt. Maria Garcia', 'Training Center A', 20, 0, 'completed', '2026-01-19 16:21:18', '2026-01-20 15:10:22', '2026-01-19 17:40:46');

-- --------------------------------------------------------

--
-- Table structure for table `training_certificates`
--

CREATE TABLE `training_certificates` (
  `id` int(11) NOT NULL,
  `registration_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `training_id` int(11) NOT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `certificate_file` varchar(255) DEFAULT NULL,
  `certificate_data` text DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `training_certificates`
--

INSERT INTO `training_certificates` (`id`, `registration_id`, `volunteer_id`, `training_id`, `certificate_number`, `issue_date`, `expiry_date`, `certificate_file`, `certificate_data`, `issued_by`, `issued_at`, `verified`, `verified_by`, `verified_at`) VALUES
(10, 12, 13, 8, 'CERT-20260127-7698', '2026-01-27', '2027-01-27', 'uploads/certificates/certificate_12_1769520212.pdf', NULL, 11, '2026-01-27 13:23:32', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `training_registrations`
--

CREATE TABLE `training_registrations` (
  `id` int(11) NOT NULL,
  `training_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('registered','attending','completed','cancelled','no_show') DEFAULT 'registered',
  `completion_status` enum('not_started','in_progress','completed','failed') DEFAULT 'not_started',
  `completion_date` date DEFAULT NULL,
  `certificate_issued` tinyint(1) DEFAULT 0,
  `certificate_path` varchar(255) DEFAULT NULL,
  `certificate_issued_at` datetime DEFAULT NULL,
  `admin_approved` tinyint(1) DEFAULT 0,
  `admin_approved_by` int(11) DEFAULT NULL,
  `admin_approved_at` datetime DEFAULT NULL,
  `employee_submitted` tinyint(1) DEFAULT 0,
  `employee_submitted_by` int(11) DEFAULT NULL,
  `employee_submitted_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `completion_verified` tinyint(1) DEFAULT 0,
  `completion_verified_by` int(11) DEFAULT NULL,
  `completion_verified_at` datetime DEFAULT NULL,
  `completion_proof` varchar(255) DEFAULT NULL,
  `completion_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `training_registrations`
--

INSERT INTO `training_registrations` (`id`, `training_id`, `volunteer_id`, `user_id`, `registration_date`, `status`, `completion_status`, `completion_date`, `certificate_issued`, `certificate_path`, `certificate_issued_at`, `admin_approved`, `admin_approved_by`, `admin_approved_at`, `employee_submitted`, `employee_submitted_by`, `employee_submitted_at`, `notes`, `completion_verified`, `completion_verified_by`, `completion_verified_at`, `completion_proof`, `completion_notes`) VALUES
(12, 8, 13, 10, '2026-01-20 13:22:51', 'completed', 'completed', NULL, 1, NULL, '2026-01-27 21:23:32', 0, NULL, NULL, 0, NULL, NULL, NULL, 1, 8, '2026-01-27 21:40:13', 'proof_1769521213_6978c03ddc2a6.jpg', '\nEmployee Verification: test\nEmployee Verification: test\nEmployee Verification: test'),
(13, 7, 13, NULL, '2026-01-20 15:25:24', 'registered', 'not_started', NULL, 0, NULL, NULL, 1, 11, '2026-01-20 23:25:24', 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` int(11) NOT NULL,
  `unit_name` varchar(100) NOT NULL,
  `unit_code` varchar(20) NOT NULL,
  `unit_type` enum('Fire','Rescue','EMS','Logistics','Command') NOT NULL,
  `location` varchar(100) NOT NULL,
  `status` enum('Active','Inactive','Maintenance') DEFAULT 'Active',
  `capacity` int(11) DEFAULT 0,
  `current_count` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `current_status` enum('available','dispatched','unavailable','maintenance') DEFAULT 'available',
  `current_dispatch_id` int(11) DEFAULT NULL,
  `last_status_change` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `units`
--

INSERT INTO `units` (`id`, `unit_name`, `unit_code`, `unit_type`, `location`, `status`, `capacity`, `current_count`, `description`, `created_at`, `updated_at`, `current_status`, `current_dispatch_id`, `last_status_change`) VALUES
(1, 'Commonwealth Fire Unit 1', 'CFU-001', 'Fire', 'Brgy. Commonwealth, Near Market', 'Active', 15, 4, 'Primary fire response unit for Commonwealth area', '2025-11-19 08:28:29', '2026-01-14 17:52:33', 'available', 13, '2026-01-13 16:54:24'),
(2, 'Commonwealth Rescue Team A', 'CRT-A', 'Rescue', 'Brgy. Commonwealth, Main Road', 'Active', 10, 0, 'Search and rescue operations team', '2025-11-19 08:28:29', '2026-01-14 19:17:41', 'available', 16, '2026-01-14 19:17:41'),
(3, 'Commonwealth EMS Unit', 'CEMS-01', 'EMS', 'Brgy. Commonwealth Health Center', 'Active', 8, 1, 'Emergency medical services unit', '2025-11-19 08:28:29', '2026-01-13 16:52:39', 'available', NULL, '2026-01-13 16:52:39'),
(4, 'Commonwealth Logistics Support', 'CLS-01', 'Logistics', 'Brgy. Commonwealth HQ', 'Active', 12, 3, 'Equipment and logistics support team', '2025-11-19 08:28:29', '2026-01-13 16:52:39', 'available', NULL, '2026-01-13 16:52:39'),
(5, 'Commonwealth Command Center', 'CCC-01', 'Command', 'Brgy. Commonwealth Hall', 'Active', 5, 0, 'Incident command and coordination', '2025-11-19 08:28:29', '2026-01-13 16:52:39', 'available', NULL, '2026-01-13 16:52:39'),
(6, 'Commonwealth Fire Unit 2', 'CFU-002', 'Fire', 'Brgy. Commonwealth, Batasan Area', 'Active', 12, 0, 'Secondary fire response unit', '2025-11-19 08:28:29', '2026-01-14 16:58:19', 'available', 15, '2026-01-14 16:58:19'),
(7, 'Commonwealth Rescue Team B', 'CRT-B', 'Rescue', 'Brgy. Commonwealth, Payatas Area', 'Active', 8, 0, 'Secondary rescue operations team', '2025-11-19 08:28:29', '2026-01-14 15:52:32', 'available', 14, '2026-01-14 15:52:32'),
(8, 'Commonwealth Community Response', 'CCR-01', 'EMS', 'Brgy. Commonwealth, Various Locations', 'Active', 15, 0, 'Community emergency response team', '2025-11-19 08:28:29', '2026-01-13 16:52:39', 'available', NULL, '2026-01-13 16:52:39'),
(9, 'Commonwealth Equipment Unit', 'CEQ-01', 'Logistics', 'Brgy. Commonwealth Storage', 'Active', 6, 0, 'Equipment maintenance and management', '2025-11-19 08:28:29', '2026-01-13 16:52:39', 'available', NULL, '2026-01-13 16:52:39'),
(10, 'Commonwealth Communications', 'CCOM-01', 'Command', 'Brgy. Commonwealth HQ', 'Active', 4, 0, 'Communications and dispatch support', '2025-11-19 08:28:29', '2026-01-13 16:52:39', 'available', NULL, '2026-01-13 16:52:39');

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
  `avatar` varchar(255) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `middle_name`, `last_name`, `username`, `contact`, `address`, `date_of_birth`, `email`, `password`, `role`, `is_verified`, `verification_code`, `code_expiry`, `created_at`, `updated_at`, `reset_token`, `avatar`, `token_expiry`) VALUES
(8, 'Stephen', 'Kyle', 'Viray', 'Yukki', '09984319585', '054 gold extention\r\nbaranggay commonwelth qc', '2004-02-10', 'stephenviray12@gmail.com', '$2y$12$2a9p/WXFMFFzVjydxkjuYOumacEXvfZfSf2uhAf7d7lIe8YJcVuO6', 'EMPLOYEE', 1, NULL, NULL, '2025-11-03 12:26:02', '2025-11-26 15:17:43', NULL, 'avatar_8_1763866448.jpg', NULL),
(10, 'zaldy', 'g', 'solis', 'yukki1', '09984319585', '054 gold extention\r\nbaranggay commonwelth qc', '2003-02-10', 'stephenviray121111@gmail.com', '$2y$12$JmfpASpwVdSAa/d7uZ9og.FYVnA66Y2sX4cczKiU06m46ODfDwgzq', 'USER', 1, NULL, NULL, '2026-01-14 15:41:08', '2026-01-18 19:18:41', NULL, 'avatar_10_1768763921.jpg', NULL),
(11, 'Mariefee', 'S', 'Baturi', 'riri', '09984319585', '054 gold extention', '2004-02-29', 'yenajigumina12@gmail.com', '$2y$12$hs2Wez9y2UgIE68VxrDQNup9PcDEPOWY02GHKzl2L6VqIPYu.fd4m', 'ADMIN', 1, NULL, NULL, '2026-01-20 06:54:34', '2026-01-21 07:44:17', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_status`
--

CREATE TABLE `vehicle_status` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `vehicle_name` varchar(100) NOT NULL,
  `vehicle_type` varchar(50) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `dispatch_id` int(11) DEFAULT NULL,
  `suggestion_id` int(11) DEFAULT NULL,
  `status` enum('available','suggested','dispatched','maintenance','out_of_service') DEFAULT 'available',
  `current_location` varchar(255) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_status`
--

INSERT INTO `vehicle_status` (`id`, `vehicle_id`, `vehicle_name`, `vehicle_type`, `unit_id`, `dispatch_id`, `suggestion_id`, `status`, `current_location`, `last_updated`) VALUES
(11, 1, 'Fire Truck 1', 'Fire', 1, 13, 13, 'suggested', NULL, '2026-01-14 12:20:48'),
(12, 5, 'Fire Truck 5', 'Fire', 1, NULL, NULL, 'available', NULL, '2026-01-14 12:02:14'),
(13, 6, 'Ambulance 1', 'Rescue', 7, 14, 14, 'suggested', NULL, '2026-01-14 15:52:32'),
(14, 5, 'Fire Truck 5', 'Fire', 6, 15, 15, 'suggested', NULL, '2026-01-14 16:58:19'),
(15, 4, 'Fire Truck 4', 'Fire', 6, 15, 15, 'suggested', NULL, '2026-01-14 16:58:19'),
(16, 8, 'Ambulance 3', 'Rescue', 2, 16, 16, 'suggested', NULL, '2026-01-14 19:17:41');

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

-- --------------------------------------------------------

--
-- Table structure for table `volunteers`
--

CREATE TABLE `volunteers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `date_of_birth` date NOT NULL,
  `gender` enum('Male','Female','Other') NOT NULL,
  `civil_status` enum('Single','Married','Divorced','Widowed') NOT NULL,
  `address` text NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `social_media` varchar(100) DEFAULT NULL,
  `valid_id_type` varchar(50) NOT NULL,
  `valid_id_number` varchar(50) NOT NULL,
  `id_front_photo` varchar(255) DEFAULT NULL,
  `id_back_photo` varchar(255) DEFAULT NULL,
  `id_front_verified` tinyint(1) DEFAULT 0,
  `id_back_verified` tinyint(1) DEFAULT 0,
  `emergency_contact_name` varchar(100) NOT NULL,
  `emergency_contact_relationship` varchar(50) NOT NULL,
  `emergency_contact_number` varchar(20) NOT NULL,
  `emergency_contact_address` text NOT NULL,
  `volunteered_before` enum('Yes','No') NOT NULL,
  `previous_volunteer_experience` text DEFAULT NULL,
  `volunteer_motivation` text NOT NULL,
  `currently_employed` enum('Yes','No') NOT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `education` varchar(100) NOT NULL,
  `specialized_training` text DEFAULT NULL,
  `physical_fitness` enum('Excellent','Good','Fair') NOT NULL,
  `languages_spoken` varchar(200) NOT NULL,
  `skills_basic_firefighting` tinyint(1) DEFAULT 0,
  `skills_first_aid_cpr` tinyint(1) DEFAULT 0,
  `skills_search_rescue` tinyint(1) DEFAULT 0,
  `skills_driving` tinyint(1) DEFAULT 0,
  `driving_license_no` varchar(50) DEFAULT NULL,
  `skills_communication` tinyint(1) DEFAULT 0,
  `skills_mechanical` tinyint(1) DEFAULT 0,
  `skills_logistics` tinyint(1) DEFAULT 0,
  `available_days` varchar(100) NOT NULL,
  `available_hours` varchar(100) NOT NULL,
  `emergency_response` enum('Yes','No') NOT NULL,
  `area_interest_fire_suppression` tinyint(1) DEFAULT 0,
  `area_interest_rescue_operations` tinyint(1) DEFAULT 0,
  `area_interest_ems` tinyint(1) DEFAULT 0,
  `area_interest_disaster_response` tinyint(1) DEFAULT 0,
  `area_interest_admin_logistics` tinyint(1) DEFAULT 0,
  `declaration_agreed` tinyint(1) NOT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `application_date` date NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `volunteer_status` enum('New Volunteer','Active','Inactive','On Leave') DEFAULT 'New Volunteer',
  `training_completion_status` enum('none','in_progress','completed','certified') DEFAULT 'none',
  `first_training_completed_at` date DEFAULT NULL,
  `active_since` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `volunteers`
--

INSERT INTO `volunteers` (`id`, `user_id`, `first_name`, `middle_name`, `last_name`, `full_name`, `date_of_birth`, `gender`, `civil_status`, `address`, `contact_number`, `email`, `social_media`, `valid_id_type`, `valid_id_number`, `id_front_photo`, `id_back_photo`, `id_front_verified`, `id_back_verified`, `emergency_contact_name`, `emergency_contact_relationship`, `emergency_contact_number`, `emergency_contact_address`, `volunteered_before`, `previous_volunteer_experience`, `volunteer_motivation`, `currently_employed`, `occupation`, `company`, `education`, `specialized_training`, `physical_fitness`, `languages_spoken`, `skills_basic_firefighting`, `skills_first_aid_cpr`, `skills_search_rescue`, `skills_driving`, `driving_license_no`, `skills_communication`, `skills_mechanical`, `skills_logistics`, `available_days`, `available_hours`, `emergency_response`, `area_interest_fire_suppression`, `area_interest_rescue_operations`, `area_interest_ems`, `area_interest_disaster_response`, `area_interest_admin_logistics`, `declaration_agreed`, `signature`, `application_date`, `status`, `volunteer_status`, `training_completion_status`, `first_training_completed_at`, `active_since`, `created_at`, `updated_at`) VALUES
(1, NULL, 'stephen', 'kyle', 'viray', 'stephen kyle viray', '2004-02-10', 'Male', 'Married', '054 gold extention\r\nbaranggay commonwelth qc', '09984319585', 'stephenviray12@gmail.com', 'Spyke Kyle', 'Passport', 'asd123123123123', NULL, NULL, 0, 0, 'Mariefe S Baturi', 'Wife', '09984319585', '054 gold extention\r\nbaranggay commonwelth qc', 'No', '', 'asdasd', 'No', '', '', 'College Undergraduate', '123asd', 'Excellent', 'bisaya', 1, 1, 0, 0, '', 0, 0, 0, 'Monday,Tuesday,Wednesday', 'Morning,Afternoon', 'Yes', 1, 1, 1, 0, 1, 1, 'stephen kyle 12', '2025-11-13', 'approved', 'New Volunteer', 'none', NULL, NULL, '2025-11-12 23:17:10', '2026-01-14 14:22:19'),
(2, NULL, 'stephen', 'kyle', 'viray', 'stephen kyle viray', '2004-02-10', 'Male', 'Married', '054 gold extention\r\nbaranggay commonwelth qc', '09984319585', 'stephenvisssray12@gmail.com', 'asdas', 'Voter&#039;s ID', '123123', 'uploads/volunteer_id_photos/id_front_1763126462_c4fa8b15f901a947.jpg', 'uploads/volunteer_id_photos/id_back_1763126462_44b4886dc45049b0.jpg', 0, 0, 'stephen kyle viray', 'Husband', '09984319585', '054 gold extention\r\nbaranggay commonwelth qc', 'No', '', 'asdasdasd', 'No', '', '', 'Vocational', 'asdasd', 'Excellent', 'bisaya', 1, 0, 0, 0, '', 0, 0, 0, 'Wednesday', 'Afternoon', 'No', 1, 0, 0, 1, 0, 1, 'stephen kyle viray', '2025-11-14', 'approved', 'New Volunteer', 'none', NULL, NULL, '2025-11-14 13:21:02', '2026-01-14 14:22:19'),
(3, NULL, 'Juan', 'Dela', 'Cruz', 'Juan Dela Cruz', '1998-07-21', 'Male', 'Single', 'Brgy Commonwealth, QC', '09123456781', 'juan.cruz@example.com', 'JuanCruzFB', 'PhilHealth', 'PH123456', NULL, NULL, 1, 1, 'Maria Dela Cruz', 'Mother', '09181234567', 'Same address', 'No', '', 'To serve the community', 'Yes', 'Construction Worker', 'BuildWell Co.', 'High School Graduate', '', 'Good', 'Tagalog,English', 1, 1, 0, 0, '', 1, 0, 0, 'Monday,Wednesday,Friday', 'Morning', 'Yes', 1, 0, 1, 0, 1, 1, 'juan sig', '2025-11-14', 'rejected', 'New Volunteer', 'none', NULL, NULL, '2025-11-14 14:48:38', '2026-01-14 14:22:19'),
(4, NULL, 'Maria', '', 'Santos', 'Maria Santos', '2000-03-11', 'Female', 'Single', 'Brgy Holy Spirit, QC', '09955667788', 'maria.santos@example.com', 'MariaInsta', 'Driver License', 'DL987654', NULL, NULL, 0, 0, 'Ana Santos', 'Sister', '09229988776', 'Pasig City', 'Yes', 'School event volunteer', 'Wants to help during emergencies', 'No', '', '', 'College Undergraduate', 'Basic First Aid', 'Excellent', 'Tagalog', 0, 1, 1, 1, 'N1234567', 1, 0, 1, 'Tuesday,Thursday', 'Afternoon', 'Yes', 1, 1, 0, 1, 0, 1, 'maria sig', '2025-11-14', 'approved', 'Active', 'certified', NULL, '2026-01-20', '2025-11-14 14:48:38', '2026-01-19 16:57:56'),
(5, NULL, 'Mark', '', 'Villanueva', 'Mark Villanueva', '1995-01-05', 'Male', 'Married', 'Brgy Batasan, QC', '09187776655', 'mark.villa@example.com', 'MarkV', 'Passport', 'PS1223344', NULL, NULL, 0, 0, 'Jen Villanueva', 'Wife', '09175554433', 'Batasan Hills, QC', 'No', '', 'Wants to volunteer', 'Yes', 'Mechanic', 'AutoFix Shop', 'Vocational', 'Automotive Training', 'Good', 'Tagalog,English', 1, 0, 1, 1, 'D9988776', 1, 1, 1, 'Saturday,Sunday', 'Evening', 'No', 1, 0, 0, 0, 1, 1, 'mark sig', '2025-11-14', 'approved', 'Active', 'certified', NULL, '2026-01-20', '2025-11-14 14:48:38', '2026-01-19 16:57:56'),
(7, NULL, 'Carlos', '', 'Mendoza', 'Carlos Mendoza', '1990-12-02', 'Male', 'Married', 'Brgy Commonwealth, QC', '09219988776', 'carlos.mendoza@example.com', 'CarlM', 'SSS', 'SSS998877', NULL, NULL, 1, 1, 'Grace Mendoza', 'Wife', '09198877665', 'Same address', 'Yes', 'Barangay volunteer', 'Wants to support barangay programs', 'Yes', 'Driver', 'Transport Co.', 'High School', 'Defensive Driving', 'Fair', 'Tagalog', 1, 0, 0, 1, 'D5566778', 1, 1, 1, 'Wednesday,Friday,Sunday', 'Morning', 'Yes', 1, 0, 0, 1, 1, 1, 'carlos sig', '2025-11-14', 'approved', 'Active', 'certified', NULL, '2026-01-20', '2025-11-14 14:48:38', '2026-01-19 16:57:56'),
(8, NULL, 'Jasmine', '', 'Lopez', 'Jasmine Lopez', '2001-06-14', 'Female', 'Single', 'Brgy Holy Spirit, QC', '09334445566', 'jasmine.lopez@example.com', 'JasLopez', 'Postal ID', 'POST12345', NULL, NULL, 0, 0, 'Jose Lopez', 'Father', '09123334455', 'QC', 'No', '', 'To help disaster victims', 'No', '', '', 'High School', '', 'Good', 'Tagalog,English', 0, 1, 0, 0, '', 1, 0, 0, 'Thursday,Saturday', 'Afternoon,Evening', 'Yes', 0, 1, 0, 1, 0, 1, 'jasmine sig', '2025-11-14', 'approved', 'Active', 'certified', NULL, '2026-01-20', '2025-11-14 14:48:38', '2026-01-19 16:57:56'),
(9, NULL, 'hann', '', 'pham', 'hann pham', '0000-00-00', 'Female', 'Married', 'asdasd', '123123123123', 'asdasdd@asdasd.com', 'asdasdasd', 'Postal ID', '123123', 'uploads/volunteer_id_photos/id_front_1763227831_7ad79fc34b75c6e9.jpg', 'uploads/volunteer_id_photos/id_back_1763227831_b60b8c328fd4acf7.jpg', 1, 1, 'stephen kyle viray', 'Husband', '09984319585', '054 gold extention\r\nbaranggay commonwelth qc', 'No', '', 'asdasd', 'No', '', '', 'College Undergraduate', 'asdasdasdasd', 'Excellent', 'asdasdasd', 1, 0, 0, 0, '', 1, 0, 0, 'Sunday', 'Morning', 'Yes', 1, 0, 0, 0, 1, 1, 'Hanni Pham', '2025-11-15', 'rejected', 'Active', 'certified', NULL, '2026-01-20', '2025-11-15 17:30:31', '2026-01-19 16:57:56'),
(10, NULL, 'Danielle', '', 'Marsh', 'Danielle Marsh', '2004-02-10', 'Female', 'Married', '054 gold extention\r\nbaranggay commonwelth qc', '09984319585', 'stephensssviray12@gmail.com', 'asdasd', 'PhilHealth ID', 'asdas123123', 'uploads/volunteer_id_photos/id_front_1763229100_bdf900ffd3113e4e.jpg', 'uploads/volunteer_id_photos/id_back_1763229100_bea3dbffa3df8f1a.jpg', 0, 0, 'stephen kyle viray', 'Husband', '09984319585', '054 gold extention\r\nbaranggay commonwelth qc', 'No', '', 'asdasd', 'No', '', '', 'High School', 'asdasd123123', 'Excellent', 'asdasdasd', 1, 0, 0, 0, '', 0, 0, 0, 'Monday', 'Morning', 'Yes', 1, 1, 1, 1, 0, 1, 'Danielle Marsh', '2025-11-15', 'approved', 'Inactive', 'none', NULL, NULL, '2025-11-15 17:51:40', '2026-01-14 14:22:19'),
(13, 10, 'zaldy', 'g', 'solis', '', '2003-02-10', 'Male', 'Single', '054 gold extention\r\nbaranggay commonwelth qc', '09984319585', 'stephenviray121111@gmail.com', 'Spyke Kyle', 'Voter&#039;s ID', '123123', 'uploads/volunteer_id_photos/id_front_1768404277_79552e438be7c909.jpg', 'uploads/volunteer_id_photos/id_back_1768404277_ca2f8ff1735c4054.jpg', 0, 0, 'stephen kyle viray', 'brother', '09984319585', '054 gold extention\r\nbaranggay commonwelth qc', 'No', '', 'asddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd', 'No', '', '', 'High School', 'rescue', 'Good', 'tagalog', 0, 0, 1, 0, '', 0, 1, 0, 'Monday,Wednesday,Friday', 'Afternoon,Night', 'Yes', 0, 0, 1, 0, 0, 1, 'zaldy g solis', '2026-01-14', 'approved', 'Active', 'certified', '2026-01-27', '2026-01-27', '2026-01-14 15:24:37', '2026-01-27 10:10:40');

-- --------------------------------------------------------

--
-- Table structure for table `volunteer_assignments`
--

CREATE TABLE `volunteer_assignments` (
  `id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assignment_date` date NOT NULL,
  `status` enum('Active','Inactive','Transferred') DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `volunteer_assignments`
--

INSERT INTO `volunteer_assignments` (`id`, `volunteer_id`, `unit_id`, `assigned_by`, `assignment_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(6, 7, 4, 8, '2025-11-20', 'Active', NULL, '2025-11-19 21:27:22', '2025-11-19 21:27:22'),
(7, 2, 1, 8, '2025-11-20', 'Active', NULL, '2025-11-19 21:28:48', '2025-11-19 21:28:48'),
(8, 5, 4, 8, '2025-11-22', 'Active', NULL, '2025-11-21 20:32:32', '2025-11-21 20:32:32'),
(9, 10, 1, 8, '2026-01-13', 'Active', NULL, '2026-01-12 17:53:42', '2026-01-12 17:53:42'),
(10, 8, 1, 8, '2026-01-13', 'Active', NULL, '2026-01-12 17:53:48', '2026-01-12 17:53:48'),
(11, 4, 3, 8, '2026-01-13', 'Active', NULL, '2026-01-12 17:53:56', '2026-01-12 17:53:56'),
(12, 1, 4, 8, '2026-01-13', 'Active', NULL, '2026-01-12 17:54:04', '2026-01-12 17:54:04'),
(13, 13, 1, 8, '2026-01-15', 'Active', NULL, '2026-01-14 17:52:33', '2026-01-14 17:52:33');

-- --------------------------------------------------------

--
-- Table structure for table `volunteer_registration_status`
--

CREATE TABLE `volunteer_registration_status` (
  `id` int(11) NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'closed',
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `volunteer_registration_status`
--

INSERT INTO `volunteer_registration_status` (`id`, `status`, `updated_by`, `updated_at`) VALUES
(1, 'open', 8, '2026-01-08 11:15:18');

-- --------------------------------------------------------

--
-- Table structure for table `volunteer_shifts`
--

CREATE TABLE `volunteer_shifts` (
  `id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime DEFAULT current_timestamp(),
  `status` enum('assigned','confirmed','declined','completed','absent') DEFAULT 'assigned',
  `attendance_marked` tinyint(1) DEFAULT 0,
  `attendance_marked_at` datetime DEFAULT NULL,
  `attendance_marked_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `api_incidents`
--
ALTER TABLE `api_incidents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_external_id` (`external_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_emergency_type` (`emergency_type`),
  ADD KEY `idx_severity` (`severity`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_sync_status` (`sync_status`),
  ADD KEY `idx_fire_rescue` (`is_fire_rescue_related`),
  ADD KEY `idx_dispatch_status` (`dispatch_status`);

--
-- Indexes for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_id` (`shift_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_check_in_date` (`check_in`),
  ADD KEY `fk_attendance_verified_by` (`verified_by`),
  ADD KEY `idx_attendance_date` (`shift_date`,`user_id`);

--
-- Indexes for table `change_requests`
--
ALTER TABLE `change_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `dispatch_incidents`
--
ALTER TABLE `dispatch_incidents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_incident` (`incident_id`),
  ADD KEY `idx_unit` (`unit_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `duty_assignments`
--
ALTER TABLE `duty_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_id` (`shift_id`);

--
-- Indexes for table `duty_templates`
--
ALTER TABLE `duty_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `feedbacks`
--
ALTER TABLE `feedbacks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `is_approved` (`is_approved`);

--
-- Indexes for table `incident_reports`
--
ALTER TABLE `incident_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_external_id` (`external_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_incident_type` (`incident_type`);

--
-- Indexes for table `incident_status_logs`
--
ALTER TABLE `incident_status_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_incident_id` (`incident_id`),
  ADD KEY `idx_changed_at` (`changed_at`),
  ADD KEY `idx_changed_by` (`changed_by`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  ADD KEY `idx_time` (`attempt_time`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `is_read` (`is_read`);

--
-- Indexes for table `password_change_logs`
--
ALTER TABLE `password_change_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_changed_at` (`changed_at`),
  ADD KEY `fk_password_log_changed_by` (`changed_by`);

--
-- Indexes for table `registration_attempts`
--
ALTER TABLE `registration_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  ADD KEY `idx_time` (`attempt_time`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_shift_date` (`shift_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_shifts_unit` (`unit_id`),
  ADD KEY `fk_shifts_created_by` (`created_by`),
  ADD KEY `idx_volunteer_id` (`volunteer_id`),
  ADD KEY `idx_shift_for` (`shift_for`),
  ADD KEY `idx_duty_assignment` (`duty_assignment_id`),
  ADD KEY `idx_shifts_today` (`shift_date`,`status`,`confirmation_status`),
  ADD KEY `idx_shifts_volunteer` (`volunteer_id`,`shift_date`),
  ADD KEY `idx_shifts_attendance` (`shift_date`,`attendance_status`,`volunteer_id`);

--
-- Indexes for table `shift_change_requests`
--
ALTER TABLE `shift_change_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `volunteer_id` (`volunteer_id`),
  ADD KEY `swap_with_volunteer_id` (`swap_with_volunteer_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `shift_confirmations`
--
ALTER TABLE `shift_confirmations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_shift_volunteer` (`shift_id`,`volunteer_id`),
  ADD KEY `volunteer_id` (`volunteer_id`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `trainings`
--
ALTER TABLE `trainings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_training_date` (`training_date`);

--
-- Indexes for table `training_certificates`
--
ALTER TABLE `training_certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_certificate_number` (`certificate_number`),
  ADD KEY `idx_registration_id` (`registration_id`),
  ADD KEY `idx_volunteer_id` (`volunteer_id`),
  ADD KEY `idx_training_id` (`training_id`);

--
-- Indexes for table `training_registrations`
--
ALTER TABLE `training_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_training_volunteer` (`training_id`,`volunteer_id`),
  ADD KEY `idx_training_id` (`training_id`),
  ADD KEY `idx_volunteer_id` (`volunteer_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_completion` (`completion_status`),
  ADD KEY `completion_verified_by` (`completion_verified_by`),
  ADD KEY `employee_submitted_by` (`employee_submitted_by`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unit_code` (`unit_code`);

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
-- Indexes for table `vehicle_status`
--
ALTER TABLE `vehicle_status`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicle_status` (`status`),
  ADD KEY `idx_vehicle_unit` (`unit_id`),
  ADD KEY `idx_vehicle_dispatch` (`dispatch_id`),
  ADD KEY `idx_vehicle_suggestion` (`suggestion_id`);

--
-- Indexes for table `verification_codes`
--
ALTER TABLE `verification_codes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `volunteers`
--
ALTER TABLE `volunteers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_email` (`email`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_volunteers_status` (`status`),
  ADD KEY `idx_volunteers_created_at` (`created_at`),
  ADD KEY `idx_volunteers_active` (`status`,`volunteer_status`);

--
-- Indexes for table `volunteer_assignments`
--
ALTER TABLE `volunteer_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `volunteer_id` (`volunteer_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `assigned_by` (`assigned_by`);

--
-- Indexes for table `volunteer_registration_status`
--
ALTER TABLE `volunteer_registration_status`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `volunteer_shifts`
--
ALTER TABLE `volunteer_shifts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_volunteer_shift` (`volunteer_id`,`shift_id`),
  ADD KEY `idx_volunteer_id` (`volunteer_id`),
  ADD KEY `idx_shift_id` (`shift_id`),
  ADD KEY `idx_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `api_incidents`
--
ALTER TABLE `api_incidents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `change_requests`
--
ALTER TABLE `change_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dispatch_incidents`
--
ALTER TABLE `dispatch_incidents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `duty_assignments`
--
ALTER TABLE `duty_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `duty_templates`
--
ALTER TABLE `duty_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `feedbacks`
--
ALTER TABLE `feedbacks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `incident_reports`
--
ALTER TABLE `incident_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `incident_status_logs`
--
ALTER TABLE `incident_status_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=131;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137;

--
-- AUTO_INCREMENT for table `password_change_logs`
--
ALTER TABLE `password_change_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registration_attempts`
--
ALTER TABLE `registration_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=136;

--
-- AUTO_INCREMENT for table `shift_change_requests`
--
ALTER TABLE `shift_change_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `shift_confirmations`
--
ALTER TABLE `shift_confirmations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `trainings`
--
ALTER TABLE `trainings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `training_certificates`
--
ALTER TABLE `training_certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `training_registrations`
--
ALTER TABLE `training_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `vehicle_status`
--
ALTER TABLE `vehicle_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `verification_codes`
--
ALTER TABLE `verification_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `volunteers`
--
ALTER TABLE `volunteers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `volunteer_assignments`
--
ALTER TABLE `volunteer_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `volunteer_registration_status`
--
ALTER TABLE `volunteer_registration_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `volunteer_shifts`
--
ALTER TABLE `volunteer_shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD CONSTRAINT `fk_attendance_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attendance_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `change_requests`
--
ALTER TABLE `change_requests`
  ADD CONSTRAINT `change_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dispatch_incidents`
--
ALTER TABLE `dispatch_incidents`
  ADD CONSTRAINT `fk_dispatch_incident` FOREIGN KEY (`incident_id`) REFERENCES `api_incidents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dispatch_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `duty_assignments`
--
ALTER TABLE `duty_assignments`
  ADD CONSTRAINT `fk_duty_assignment_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feedbacks`
--
ALTER TABLE `feedbacks`
  ADD CONSTRAINT `feedbacks_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `incident_status_logs`
--
ALTER TABLE `incident_status_logs`
  ADD CONSTRAINT `incident_status_logs_ibfk_1` FOREIGN KEY (`incident_id`) REFERENCES `api_incidents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `incident_status_logs_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_change_logs`
--
ALTER TABLE `password_change_logs`
  ADD CONSTRAINT `fk_password_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_password_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shifts`
--
ALTER TABLE `shifts`
  ADD CONSTRAINT `fk_shifts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_shifts_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_shifts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_shifts_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `shift_change_requests`
--
ALTER TABLE `shift_change_requests`
  ADD CONSTRAINT `shift_change_requests_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shift_change_requests_ibfk_2` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shift_change_requests_ibfk_3` FOREIGN KEY (`swap_with_volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `shift_change_requests_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `shift_confirmations`
--
ALTER TABLE `shift_confirmations`
  ADD CONSTRAINT `shift_confirmations_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shift_confirmations_ibfk_2` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `training_certificates`
--
ALTER TABLE `training_certificates`
  ADD CONSTRAINT `fk_certificate_registration` FOREIGN KEY (`registration_id`) REFERENCES `training_registrations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_certificate_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_certificate_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `training_registrations`
--
ALTER TABLE `training_registrations`
  ADD CONSTRAINT `fk_training_reg_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_training_reg_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `training_registrations_ibfk_1` FOREIGN KEY (`completion_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `training_registrations_ibfk_2` FOREIGN KEY (`employee_submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `volunteers`
--
ALTER TABLE `volunteers`
  ADD CONSTRAINT `fk_volunteers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `volunteers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `volunteer_assignments`
--
ALTER TABLE `volunteer_assignments`
  ADD CONSTRAINT `volunteer_assignments_ibfk_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `volunteer_assignments_ibfk_2` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `volunteer_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `volunteer_shifts`
--
ALTER TABLE `volunteer_shifts`
  ADD CONSTRAINT `fk_volunteer_shifts_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_volunteer_shifts_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
