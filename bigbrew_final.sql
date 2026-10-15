-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 12, 2026 at 01:32 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bigbrew_final`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(200) NOT NULL,
  `role` varchar(50) DEFAULT 'admin',
  `employee_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `password`, `name`, `role`, `employee_id`, `created_at`) VALUES
(1, 'admin', '$2y$10$HpGVVydVG5TXSPjbXCR1COJE0pK5pv14PQEfV8EeR.FrlF.wTTqr2', 'System Administrator', 'admin', NULL, '2026-09-20 14:40:40'),
(3, 'nash', '$2y$10$9a1AGmcmmYHxiVlN/LyjgOoa35NDUWry6xz6VOC8DRRJVjLUMabhe', 'Nash Rodrigo', 'Admin', 7, '2026-09-21 06:10:15'),
(4, 'shao', '$2y$10$L8BuHSap9kGsnVeb3kk/z.75D8YOGgGN2ADIBID7on53u.hJoAZfu', 'shao valdez', 'Staff', 8, '2026-09-21 06:10:15'),
(6, 'cavi', '$2y$10$Bk4CjmNLl4MyuPG1M/C7qOoam/r/tlrLNQmHmWAKLgy6EPTwBP5R.', 'Cavi Pizares', 'Staff', 10, '2026-09-21 06:10:16'),
(7, 'kaiser', '$2y$10$jCDWAiG7PJ9UJxZOVV8BR.ZsY7byywC1VSl5M3JJTK9zmVxqI4Sjq', 'Kaiser Benter', 'Staff', 11, '2026-09-21 06:10:16'),
(8, 'vincentsadsa', '$2y$10$cFbj.pVKTVFRAG1Y8n4xN.MFO1ezWfYgF4LTKtA1AVHyREQ2eeDwu', '', 'Staff', 12, '2026-10-11 15:24:42'),
(9, 'kelthrodrigo', '$2y$10$coOHSa2icidzrsHSHwerMOtZjEBtUhSsbFmZsn2q3.0tFsFi94kbq', '', 'Staff', 13, '2026-10-11 15:27:24'),
(10, 'ravencaldez', '$2y$10$h6jbYW9x3E9Sm37KgqBZJOvJXUKCt1S.Jfr5i9NMfOjXcPEdpZ0ny', '', 'Staff', 14, '2026-10-11 15:29:14');

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `hours_worked` decimal(6,2) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'present',
  `remarks` text DEFAULT NULL,
  `source` varchar(50) DEFAULT 'biometric',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `employee_id`, `attendance_date`, `time_in`, `time_out`, `hours_worked`, `shift_id`, `status`, `remarks`, `source`, `created_at`) VALUES
(1, 7, '2026-09-02', '11:27:00', '17:21:00', 5.90, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(2, 7, '2026-09-03', '08:34:00', '18:26:00', 9.87, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(3, 7, '2026-09-04', '09:41:00', '22:59:00', 8.98, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(4, 7, '2026-09-05', '14:00:00', '23:17:00', 9.28, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(5, 7, '2026-09-06', '11:55:00', '18:41:00', 6.77, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(6, 7, '2026-09-07', '08:02:00', '19:46:00', 11.73, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(7, 7, '2026-09-08', '09:09:00', '17:51:00', 8.70, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(8, 7, '2026-09-09', '10:16:00', '23:13:00', 9.52, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(9, 7, '2026-09-10', '11:23:00', '19:01:00', 7.63, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(10, 7, '2026-09-11', '08:30:00', '17:06:00', 8.60, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(11, 7, '2026-09-12', '09:37:00', '18:11:00', 8.57, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(12, 7, '2026-09-13', '19:12:00', '23:04:00', 3.87, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(13, 7, '2026-09-14', '11:51:00', NULL, NULL, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(14, 8, '2026-09-02', '18:59:00', '23:03:00', 4.07, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(15, 8, '2026-09-03', '18:58:00', '23:27:00', 4.48, 1, 'present', NULL, 'biometric', '2026-09-20 15:21:42'),
(31, 9, '2026-09-02', '12:33:00', '21:02:00', 8.48, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(32, 9, '2026-09-03', '12:22:00', '21:00:00', 8.63, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(33, 9, '2026-09-04', '10:14:00', '20:16:00', 10.03, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(34, 9, '2026-09-05', '10:00:00', '19:59:00', 9.98, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(35, 9, '2026-09-06', '10:28:00', '19:01:00', 8.55, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(36, 9, '2026-09-07', '10:40:00', '19:01:00', 8.35, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(37, 9, '2026-09-08', '11:35:00', '19:13:00', 7.63, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(38, 9, '2026-09-09', '08:42:00', '17:18:00', 8.60, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(39, 9, '2026-09-10', '09:49:00', '18:23:00', 8.57, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(40, 10, '2026-09-01', '13:56:00', '23:35:00', 9.65, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(41, 10, '2026-09-02', '13:55:00', '23:22:00', 9.45, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(42, 10, '2026-09-03', '13:59:00', '23:27:00', 9.47, 1, 'present', NULL, 'biometric', '2026-09-20 15:47:46'),
(43, 7, '2026-09-01', '10:20:00', '19:16:00', 8.93, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(55, 7, '2026-09-15', '08:58:00', '18:26:00', 9.47, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(56, 8, '2026-09-01', '11:33:00', '17:27:00', 5.90, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(59, 8, '2026-09-04', '14:00:00', '23:12:00', 9.20, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(60, 8, '2026-09-06', '13:55:00', '23:17:00', 9.37, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(61, 8, '2026-09-07', '23:07:00', '17:57:00', 8.70, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(62, 8, '2026-09-08', '19:16:00', '23:33:00', 4.28, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(63, 8, '2026-09-09', '11:29:00', '19:07:00', 7.63, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(64, 8, '2026-09-10', '13:57:00', '23:01:00', 9.07, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(65, 8, '2026-09-11', '14:04:00', '18:17:00', 8.57, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(66, 8, '2026-09-12', '18:56:00', '23:16:00', 4.33, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(67, 8, '2026-09-14', '13:56:00', '23:04:00', 9.13, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(68, 8, '2026-09-15', '09:11:00', '19:37:00', 10.43, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(69, 9, '2026-09-01', '12:18:00', '22:17:00', 9.98, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(78, 9, '2026-09-11', '12:05:00', '23:06:00', 11.02, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(79, 9, '2026-09-12', '11:58:00', '21:01:00', 9.05, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(80, 9, '2026-09-14', '10:08:00', '19:06:00', 8.97, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(81, 9, '2026-09-15', '10:18:00', '19:07:00', 8.82, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(85, 10, '2026-09-04', '14:06:00', '23:20:00', 9.23, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(86, 10, '2026-09-06', '10:34:00', NULL, NULL, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(87, 10, '2026-09-07', '11:41:00', '19:19:00', 7.63, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(88, 10, '2026-09-08', '10:11:00', '19:03:00', 8.87, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(89, 10, '2026-09-09', '10:16:00', '19:00:00', 8.73, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(90, 10, '2026-09-10', '09:46:00', '19:00:00', 9.23, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(91, 10, '2026-09-11', '10:16:00', '19:00:00', 8.73, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(92, 10, '2026-09-12', '10:02:00', '19:02:00', 9.00, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(93, 10, '2026-09-14', '10:30:00', '17:54:00', 7.40, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(94, 10, '2026-09-15', '14:11:00', '18:59:00', 7.37, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(95, 11, '2026-09-01', '10:18:00', '19:18:00', 9.00, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(96, 11, '2026-09-02', '10:19:00', '19:08:00', 8.82, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(97, 11, '2026-09-03', '10:13:00', '18:59:00', 8.77, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(98, 11, '2026-09-04', '09:33:00', '17:15:00', 7.70, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(99, 11, '2026-09-06', '14:05:00', '23:17:00', 9.20, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(100, 11, '2026-09-07', '14:03:00', '23:10:00', 9.12, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(101, 11, '2026-09-08', '15:27:00', '23:33:00', 8.10, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(102, 11, '2026-09-09', '13:45:00', '23:10:00', 9.42, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(103, 11, '2026-09-10', '14:02:00', '23:07:00', 9.08, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(104, 11, '2026-09-11', '08:22:00', '18:50:00', 10.47, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(105, 11, '2026-09-12', '14:00:00', '19:55:00', 10.43, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(106, 11, '2026-09-14', '14:03:00', '23:26:00', 9.38, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(107, 11, '2026-09-15', '11:56:00', NULL, NULL, 1, 'present', NULL, 'biometric', '2026-09-20 16:05:57'),
(111, 8, '2026-09-05', '14:00:00', '22:59:00', 8.98, NULL, 'present', NULL, 'biometric', '2026-10-06 16:18:40'),
(118, 8, '2026-09-13', '19:12:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-06 16:18:40'),
(134, 10, '2026-09-05', '14:01:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-06 16:18:40'),
(140, 10, '2026-09-13', '10:09:00', '19:00:00', 8.85, NULL, 'present', NULL, 'biometric', '2026-10-06 16:18:40'),
(145, 11, '2026-09-05', '10:10:00', '21:02:00', 10.87, NULL, 'present', NULL, 'biometric', '2026-10-06 16:18:40'),
(152, 11, '2026-09-13', '14:03:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-06 16:18:40'),
(202, 8, '2026-10-05', '23:06:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(203, 8, '2026-10-06', '23:14:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(204, 8, '2026-10-10', '21:07:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(205, 10, '2026-10-01', '09:39:00', '19:17:00', 9.63, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(206, 10, '2026-10-02', '09:48:00', '19:01:00', 9.22, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(207, 10, '2026-10-03', '09:39:00', '19:00:00', 9.35, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(208, 10, '2026-10-04', '09:38:00', '19:27:00', 9.82, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(209, 10, '2026-10-05', '09:43:00', '19:03:00', 9.33, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(210, 10, '2026-10-06', '09:37:00', '18:59:00', 9.37, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(211, 10, '2026-10-11', '09:44:00', '19:46:00', 10.03, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(212, 11, '2026-10-01', '13:55:00', '23:13:00', 9.30, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(213, 11, '2026-10-02', '14:06:00', '23:26:00', 9.33, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(214, 11, '2026-10-04', '14:33:00', '23:40:00', 9.12, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(215, 11, '2026-10-07', '09:57:00', '23:39:00', 13.70, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(216, 11, '2026-10-08', '14:11:00', '23:43:00', 9.53, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(217, 11, '2026-10-09', '14:02:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(218, 11, '2026-10-10', '23:18:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(219, 11, '2026-10-11', '13:50:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:22:08'),
(256, 12, '2026-10-02', '23:27:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:25:35'),
(257, 12, '2026-10-04', '23:40:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:25:35'),
(258, 12, '2026-10-06', '23:14:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:25:35'),
(301, 13, '2026-10-04', '12:29:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:40:41'),
(302, 14, '2026-10-05', '10:52:00', '23:07:00', 12.25, NULL, 'present', NULL, 'biometric', '2026-10-11 15:40:41'),
(303, 14, '2026-10-07', '23:39:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:40:41'),
(304, 14, '2026-10-08', '10:00:00', '23:43:00', 13.72, NULL, 'present', NULL, 'biometric', '2026-10-11 15:40:41'),
(305, 14, '2026-10-09', '10:09:00', NULL, NULL, NULL, 'present', NULL, 'biometric', '2026-10-11 15:40:41'),
(306, 14, '2026-10-10', '09:58:00', '23:27:00', 13.48, NULL, 'present', NULL, 'biometric', '2026-10-11 15:40:41');

-- --------------------------------------------------------

--
-- Table structure for table `biometric_upload_logs`
--

CREATE TABLE `biometric_upload_logs` (
  `id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `total_rows` int(11) DEFAULT 0,
  `imported_rows` int(11) DEFAULT 0,
  `failed_rows` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `biometric_upload_logs`
--

INSERT INTO `biometric_upload_logs` (`id`, `uploaded_by`, `file_name`, `total_rows`, `imported_rows`, `failed_rows`, `status`, `notes`, `uploaded_at`) VALUES
(1, 1, 'All Report.xls', 1392, 0, 1392, 'done', NULL, '2026-09-20 15:06:55'),
(2, 1, 'All Report.xls', 1392, 0, 1392, 'done', NULL, '2026-09-20 15:07:05'),
(3, 1, 'All Report.xls', 1392, 0, 1392, 'done', NULL, '2026-09-20 15:07:15'),
(4, 1, '_endtoend_test.xlsx', 15, 15, 0, 'done', NULL, '2026-09-20 15:21:42'),
(5, 1, 'sample_e2e_upload.xls', 12, 12, 0, 'done', NULL, '2026-09-20 15:47:46'),
(6, 1, 'final_upload_all5.xls', 65, 65, 0, 'done', NULL, '2026-09-20 16:05:57'),
(7, 3, 'All Report (1).xls', 47, 0, 47, 'done', NULL, '2026-10-06 16:00:51'),
(8, 3, 'All Report (1).xls', 47, 0, 47, 'done', NULL, '2026-10-06 16:01:05'),
(9, 3, 'All Report (1).xls', 47, 0, 47, 'done', NULL, '2026-10-06 16:04:37'),
(10, 3, 'All Report (1).xls', 47, 0, 47, 'done', NULL, '2026-10-06 16:04:51'),
(11, 3, 'All Report (1).xls', 47, 47, 0, 'done', NULL, '2026-10-06 16:18:40'),
(12, 3, 'All Report (1).xls', 47, 47, 0, 'done', NULL, '2026-10-06 16:18:55'),
(13, 3, 'All Report (2).xls', 27, 18, 9, 'done', NULL, '2026-10-11 15:22:08'),
(14, 3, 'All Report (2).xls', 27, 18, 9, 'done', NULL, '2026-10-11 15:22:59'),
(15, 3, 'All Report (2).xls', 27, 21, 6, 'done', NULL, '2026-10-11 15:25:35'),
(16, 3, 'All Report (2).xls', 27, 21, 6, 'done', NULL, '2026-10-11 15:26:24'),
(17, 3, 'All Report (2).xls', 27, 27, 0, 'done', NULL, '2026-10-11 15:40:41');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `position` varchar(150) DEFAULT NULL,
  `branch` varchar(150) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `hourly_rate` decimal(8,2) NOT NULL DEFAULT 0.00,
  `shift_id` int(11) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `employee_id`, `first_name`, `last_name`, `position`, `branch`, `contact_number`, `email`, `hourly_rate`, `shift_id`, `status`, `created_at`, `updated_at`) VALUES
(7, '1', 'nash', 'rodrigo', 'owner', 'Branch 1', '', '', 0.00, 1, 'active', '2026-09-20 15:05:17', '2026-09-20 16:37:08'),
(8, '2', 'shao', 'valdez', 'valdez position', 'Branch 1', '', '', 43.75, 1, 'active', '2026-09-20 15:05:17', '2026-09-23 05:51:42'),
(9, '3', 'kyla', 'candava', 'candava position', 'Branch 1', '', '', 0.00, 1, 'active', '2026-09-20 15:05:17', '2026-09-29 07:42:16'),
(10, '4', 'cavi', 'pizares', 'pizares position', 'Branch 1', '', '', 45.00, 1, 'active', '2026-09-20 15:05:17', '2026-10-12 05:21:13'),
(11, '5', 'kaiser', 'benter', 'benter position', 'Branch 1', '', '', 45.00, 1, 'active', '2026-09-20 15:05:17', '2026-10-06 16:24:59'),
(12, '6', 'vincent', 'sadsa', 'barista', NULL, '', '', 0.00, NULL, 'active', '2026-10-11 15:24:42', '2026-10-11 15:24:42'),
(13, '7', 'kelth', 'rodrigo', '', NULL, '', '', 0.00, NULL, 'active', '2026-10-11 15:27:23', '2026-10-11 15:27:23'),
(14, '8', 'raven', 'caldez', '', NULL, '', '', 0.00, NULL, 'active', '2026-10-11 15:29:14', '2026-10-11 15:29:14');

-- --------------------------------------------------------

--
-- Table structure for table `employee_shifts`
--

CREATE TABLE `employee_shifts` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `week_start` date NOT NULL COMMENT 'Monday date of the week',
  `day_of_week` tinyint(4) NOT NULL COMMENT '1=Mon 7=Sun',
  `shift_row` varchar(50) NOT NULL DEFAULT 'Mid' COMMENT 'matches shifts.shift_name key',
  `branch` varchar(50) NOT NULL DEFAULT 'Branch 1' COMMENT 'Branch 1 or Branch 2',
  `shift_in` time DEFAULT NULL,
  `shift_out` time DEFAULT NULL,
  `shift_label` varchar(50) DEFAULT NULL COMMENT 'Morning/Mid/Night/HalfDay/Off/Custom',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employee_shifts`
--

INSERT INTO `employee_shifts` (`id`, `employee_id`, `week_start`, `day_of_week`, `shift_row`, `branch`, `shift_in`, `shift_out`, `shift_label`, `notes`, `created_at`, `updated_at`) VALUES
(15, 9, '2026-09-21', 1, 'Morning Shift', 'Branch 1', '06:00:00', '14:00:00', 'Morning Shift', NULL, '2026-09-21 08:48:45', '2026-09-21 08:48:45'),
(16, 11, '2026-09-21', 1, 'Morning Shift', 'Branch 1', '06:00:00', '14:00:00', 'Morning Shift', NULL, '2026-09-21 08:48:45', '2026-09-21 08:48:45'),
(17, 10, '2026-09-21', 2, 'Morning Shift', 'Branch 1', '06:00:00', '14:00:00', 'Morning Shift', NULL, '2026-09-21 08:48:45', '2026-09-21 08:48:45'),
(18, 11, '2026-09-21', 2, 'Morning Shift', 'Branch 1', '06:00:00', '14:00:00', 'Morning Shift', NULL, '2026-09-21 08:48:45', '2026-09-21 08:48:45'),
(19, 8, '2026-09-21', 1, 'Afternoon Shift', 'Branch 1', '14:00:00', '22:00:00', 'Afternoon Shift', NULL, '2026-09-21 08:48:45', '2026-09-21 08:48:45'),
(22, 8, '2026-09-21', 2, 'Afternoon Shift', 'Branch 1', '14:00:00', '22:00:00', 'Afternoon Shift', NULL, '2026-09-21 08:48:45', '2026-09-21 08:48:45'),
(70, 11, '2026-09-28', 1, 'Morning Shift', 'Branch 1', '06:00:00', '14:00:00', 'Morning Shift', NULL, '2026-09-29 08:32:17', '2026-09-29 08:32:17'),
(71, 11, '2026-09-28', 1, 'Afternoon Shift', 'Branch 1', '14:00:00', '22:00:00', 'Afternoon Shift', NULL, '2026-09-29 08:32:17', '2026-09-29 08:32:17'),
(72, 9, '2026-09-28', 2, 'Afternoon Shift', 'Branch 1', '14:00:00', '22:00:00', 'Afternoon Shift', NULL, '2026-09-29 08:32:17', '2026-09-29 08:32:17'),
(73, 11, '2026-09-28', 2, 'Morning Shift', 'Branch 1', '06:00:00', '14:00:00', 'Morning Shift', NULL, '2026-09-29 08:32:17', '2026-09-29 08:32:17'),
(89, 8, '2026-10-05', 4, 'Morning Shift', 'Branch 1', '06:00:00', '14:00:00', 'Morning Shift', NULL, '2026-10-06 17:34:05', '2026-10-06 17:34:05'),
(90, 10, '2026-10-05', 4, 'Morning Shift', 'Branch 2', '06:00:00', '14:00:00', 'Morning Shift', NULL, '2026-10-06 17:34:05', '2026-10-06 17:34:05'),
(91, 8, '2026-10-05', 5, 'Afternoon Shift', 'Branch 1', '14:00:00', '22:00:00', 'Afternoon Shift', NULL, '2026-10-06 17:34:05', '2026-10-06 17:34:05'),
(92, 10, '2026-10-05', 5, 'Afternoon Shift', 'Branch 2', '14:00:00', '22:00:00', 'Afternoon Shift', NULL, '2026-10-06 17:34:05', '2026-10-06 17:34:05'),
(93, 11, '2026-10-05', 3, 'Afternoon Shift', 'Branch 2', '14:00:00', '22:00:00', 'Afternoon Shift', NULL, '2026-10-06 17:34:05', '2026-10-06 17:34:05'),
(97, 11, '2026-10-05', 4, 'Afternoon Shift', 'Branch 2', '14:00:00', '22:00:00', 'Afternoon Shift', NULL, '2026-10-06 17:34:05', '2026-10-06 17:34:05');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `on_shift_employee_id` int(11) DEFAULT NULL COMMENT 'Employee on-shift when feedback was submitted (FK employees.id)',
  `on_shift_row` varchar(50) DEFAULT NULL COMMENT 'Shift row label (Morning/Afternoon/Night/Opening/Mid/Closing)',
  `on_shift_week_start` date DEFAULT NULL COMMENT 'Week start of the assigned shift (FK shift_weeks.week_start or employee_shifts.week_start)',
  `rating` int(11) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `branch` varchar(150) DEFAULT NULL,
  `qr_code_scanned` varchar(255) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `submitted_at_local` datetime DEFAULT NULL COMMENT 'Asia/Manila local time capture (matches uploaded biometric display format)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`id`, `employee_id`, `on_shift_employee_id`, `on_shift_row`, `on_shift_week_start`, `rating`, `comment`, `branch`, `qr_code_scanned`, `submitted_at`, `submitted_at_local`) VALUES
(1, NULL, NULL, NULL, NULL, 5, '', 'Branch 1', 'Branch 1', '2026-09-28 08:22:58', NULL),
(2, NULL, 9, 'Afternoon Shift', '2026-09-28', NULL, '', 'Branch 1', 'Branch 1', '2026-09-29 09:04:17', '2026-09-29 17:04:17');

-- --------------------------------------------------------

--
-- Table structure for table `feedback_answers`
--

CREATE TABLE `feedback_answers` (
  `id` int(11) NOT NULL,
  `feedback_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer_text` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `feedback_answers`
--

INSERT INTO `feedback_answers` (`id`, `feedback_id`, `question_id`, `answer_text`) VALUES
(1, 1, 1, '4'),
(2, 2, 1, '4'),
(3, 2, 2, '3'),
(4, 2, 6, '3'),
(5, 2, 3, '3'),
(6, 2, 7, '3');

-- --------------------------------------------------------

--
-- Table structure for table `feedback_questions`
--

CREATE TABLE `feedback_questions` (
  `id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` varchar(50) DEFAULT 'rating',
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `feedback_questions`
--

INSERT INTO `feedback_questions` (`id`, `question_text`, `question_type`, `is_active`, `sort_order`, `created_at`) VALUES
(1, 'How clean is the working area?', 'rating', 1, 1, '2026-09-20 14:40:40'),
(2, 'How would you rate your manager support?', 'rating', 1, 2, '2026-09-20 14:40:40'),
(3, 'How would you rate the training provided?', 'rating', 1, 3, '2026-09-20 14:40:40'),
(4, 'Any additional comments or suggestions?', 'text', 1, 4, '2026-09-20 14:40:40'),
(5, 'How clean is the working area?', 'rating', 1, 1, '2026-09-20 14:41:50'),
(6, 'How would you rate your manager support?', 'rating', 1, 2, '2026-09-20 14:41:50'),
(7, 'How would you rate the training provided?', 'rating', 1, 3, '2026-09-20 14:41:50'),
(8, 'Any additional comments or suggestions?', 'text', 1, 4, '2026-09-20 14:41:50');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_notifications`
--

CREATE TABLE `payroll_notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `processed_by` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `cutoff_start` date NOT NULL,
  `cutoff_end` date NOT NULL,
  `is_read` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_notifications`
--

INSERT INTO `payroll_notifications` (`id`, `processed_by`, `employee_id`, `cutoff_start`, `cutoff_end`, `is_read`, `created_at`) VALUES
(1, 3, 7, '2026-10-01', '2026-10-15', 0, '2026-10-07 00:20:18'),
(3, 3, 11, '2026-09-01', '2026-09-15', 1, '2026-10-11 22:51:28'),
(4, 3, 8, '2026-09-01', '2026-09-15', 1, '2026-10-11 23:11:14');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_records`
--

CREATE TABLE `payroll_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `cutoff_start` date NOT NULL,
  `cutoff_end` date NOT NULL,
  `total_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_days` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gross_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sss` decimal(10,2) NOT NULL DEFAULT 0.00,
  `philhealth` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pagibig` decimal(10,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','processed') NOT NULL DEFAULT 'draft',
  `processed_by` int(10) UNSIGNED DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_records`
--

INSERT INTO `payroll_records` (`id`, `employee_id`, `cutoff_start`, `cutoff_end`, `total_hours`, `total_days`, `hourly_rate`, `gross_pay`, `sss`, `philhealth`, `pagibig`, `other_deductions`, `net_pay`, `status`, `processed_by`, `processed_at`, `created_at`, `updated_at`) VALUES
(1, 7, '2026-10-01', '2026-10-15', 0.00, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'processed', 3, '2026-10-07 00:20:18', '2026-10-07 00:20:18', '2026-10-07 00:20:18'),
(2, 8, '2026-09-01', '2026-09-15', 104.14, 14, 43.75, 4.00, 0.00, 0.00, 0.00, 0.00, 4.00, 'processed', 3, '2026-10-11 23:11:14', '2026-10-07 00:21:13', '2026-10-11 23:11:14'),
(3, 11, '2026-09-01', '2026-09-15', 120.36, 13, 45.00, 5.00, 0.00, 0.00, 0.00, 0.00, 5.00, 'processed', 3, '2026-10-11 22:51:28', '2026-10-11 22:51:28', '2026-10-11 22:57:03');

-- --------------------------------------------------------

--
-- Table structure for table `schedule_requests`
--

CREATE TABLE `schedule_requests` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `week_start` date NOT NULL,
  `request_type` varchar(100) NOT NULL,
  `target_date` date DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('pending','approved','declined','resolved') DEFAULT 'pending',
  `admin_reply` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `is_staff_read` tinyint(4) DEFAULT 0,
  `is_admin_read` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedule_requests`
--

INSERT INTO `schedule_requests` (`id`, `employee_id`, `user_id`, `week_start`, `request_type`, `target_date`, `message`, `status`, `admin_reply`, `reviewed_by`, `reviewed_at`, `is_staff_read`, `is_admin_read`, `created_at`) VALUES
(1, 11, 7, '2026-10-05', 'Shift Swap', '2026-10-05', 'may celebration lang sa araw na ito', 'approved', 'okieee', 3, '2026-10-07 01:07:19', 1, 0, '2026-10-06 17:03:15');

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `shift_name` varchar(100) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shifts`
--

INSERT INTO `shifts` (`id`, `shift_name`, `start_time`, `end_time`, `description`, `created_at`) VALUES
(1, 'Morning Shift', '06:00:00', '14:00:00', 'Standard morning shift', '2026-09-20 14:40:40'),
(2, 'Afternoon Shift', '14:00:00', '22:00:00', 'Standard afternoon shift', '2026-09-20 14:40:40'),
(3, 'Night Shift', '22:00:00', '06:00:00', 'Overnight shift', '2026-09-20 14:40:40'),
(4, 'Morning Shift', '06:00:00', '14:00:00', 'Standard morning shift', '2026-09-20 14:41:50'),
(5, 'Afternoon Shift', '14:00:00', '22:00:00', 'Standard afternoon shift', '2026-09-20 14:41:50'),
(6, 'Night Shift', '22:00:00', '06:00:00', 'Overnight shift', '2026-09-20 14:41:50');

-- --------------------------------------------------------

--
-- Table structure for table `shift_notifications`
--

CREATE TABLE `shift_notifications` (
  `id` int(11) NOT NULL,
  `admin_user_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `week_start` date NOT NULL,
  `is_read` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shift_notifications`
--

INSERT INTO `shift_notifications` (`id`, `admin_user_id`, `employee_id`, `week_start`, `is_read`, `created_at`) VALUES
(1, 1, 9, '2026-09-21', 0, '2026-09-21 08:48:45'),
(2, 1, 11, '2026-09-21', 1, '2026-09-21 08:48:45'),
(3, 1, 10, '2026-09-21', 0, '2026-09-21 08:48:45'),
(4, 1, 8, '2026-09-21', 1, '2026-09-21 08:48:45'),
(5, 1, 7, '2026-09-21', 0, '2026-09-21 08:48:45'),
(6, 3, 9, '2026-09-28', 0, '2026-09-29 08:09:42'),
(7, 3, 11, '2026-09-28', 1, '2026-09-29 08:09:42'),
(8, 3, 10, '2026-09-28', 0, '2026-09-29 08:09:42'),
(9, 3, 7, '2026-09-28', 0, '2026-09-29 08:09:42'),
(10, 3, 9, '2026-09-28', 0, '2026-09-29 08:10:51'),
(11, 3, 10, '2026-09-28', 0, '2026-09-29 08:10:51'),
(12, 3, 11, '2026-09-28', 1, '2026-09-29 08:10:51'),
(13, 3, 7, '2026-09-28', 0, '2026-09-29 08:10:51'),
(14, 3, 8, '2026-09-28', 1, '2026-09-29 08:10:51'),
(15, 3, 8, '2026-09-28', 1, '2026-09-29 08:15:56'),
(16, 3, 11, '2026-09-28', 1, '2026-09-29 08:28:09'),
(17, 3, 11, '2026-09-28', 1, '2026-09-29 08:31:21'),
(18, 3, 9, '2026-09-28', 0, '2026-09-29 08:31:21'),
(19, 3, 11, '2026-09-28', 1, '2026-09-29 08:32:17'),
(20, 3, 9, '2026-09-28', 0, '2026-09-29 08:32:17'),
(21, 3, 8, '2026-10-05', 1, '2026-10-06 16:29:52'),
(22, 3, 11, '2026-10-05', 1, '2026-10-06 16:39:55'),
(23, 3, 8, '2026-10-05', 0, '2026-10-06 17:09:41'),
(24, 3, 8, '2026-10-05', 0, '2026-10-06 17:34:05'),
(25, 3, 10, '2026-10-05', 0, '2026-10-06 17:34:05'),
(26, 3, 11, '2026-10-05', 1, '2026-10-06 17:34:05'),
(27, 3, 7, '2026-10-05', 0, '2026-10-06 17:34:05');

-- --------------------------------------------------------

--
-- Table structure for table `shift_weeks`
--

CREATE TABLE `shift_weeks` (
  `id` int(11) NOT NULL,
  `week_start` date NOT NULL,
  `published_at` datetime DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shift_weeks`
--

INSERT INTO `shift_weeks` (`id`, `week_start`, `published_at`, `published_by`, `notes`, `created_at`, `updated_at`) VALUES
(1, '2026-09-21', '2026-09-21 10:48:45', 1, NULL, '2026-09-21 08:48:45', '2026-09-21 08:48:45'),
(2, '2026-09-28', '2026-09-29 10:32:17', 3, NULL, '2026-09-29 08:09:42', '2026-09-29 08:32:17'),
(8, '2026-10-05', '2026-10-07 01:34:05', 3, NULL, '2026-10-06 16:29:52', '2026-10-06 17:34:05');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_emp_id` (`employee_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_emp_date` (`employee_id`,`attendance_date`),
  ADD KEY `fk_attendance_shifts_shift_id` (`shift_id`);

--
-- Indexes for table `biometric_upload_logs`
--
ALTER TABLE `biometric_upload_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_biouploadlogs_admin_users_uploaded_by` (`uploaded_by`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD KEY `fk_employees_shifts_shift_id` (`shift_id`);

--
-- Indexes for table `employee_shifts`
--
ALTER TABLE `employee_shifts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_emp_week_day_row_branch` (`employee_id`,`week_start`,`day_of_week`,`shift_row`,`branch`),
  ADD KEY `idx_week` (`week_start`),
  ADD KEY `idx_emp` (`employee_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_feedback_employees_employee_id` (`employee_id`),
  ADD KEY `fk_feedback_employees_on_shift_employee_id` (`on_shift_employee_id`);

--
-- Indexes for table `feedback_answers`
--
ALTER TABLE `feedback_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_feedback_answers_feedback_feedback_id` (`feedback_id`),
  ADD KEY `fk_feedback_answers_questions_question_id` (`question_id`);

--
-- Indexes for table `feedback_questions`
--
ALTER TABLE `feedback_questions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payroll_notifications`
--
ALTER TABLE `payroll_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_emp_cutoff_notif` (`employee_id`,`cutoff_start`,`cutoff_end`),
  ADD KEY `idx_emp_read` (`employee_id`,`is_read`);

--
-- Indexes for table `payroll_records`
--
ALTER TABLE `payroll_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_emp_cutoff` (`employee_id`,`cutoff_start`,`cutoff_end`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_cutoff` (`cutoff_start`,`cutoff_end`);

--
-- Indexes for table `schedule_requests`
--
ALTER TABLE `schedule_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp` (`employee_id`),
  ADD KEY `idx_week` (`week_start`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shift_notifications`
--
ALTER TABLE `shift_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp_read` (`employee_id`,`is_read`),
  ADD KEY `fk_shift_notifications_admin_users_admin_user_id` (`admin_user_id`);

--
-- Indexes for table `shift_weeks`
--
ALTER TABLE `shift_weeks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `week_start` (`week_start`),
  ADD KEY `fk_shift_weeks_admin_users_published_by` (`published_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=307;

--
-- AUTO_INCREMENT for table `biometric_upload_logs`
--
ALTER TABLE `biometric_upload_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `employee_shifts`
--
ALTER TABLE `employee_shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `feedback_answers`
--
ALTER TABLE `feedback_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `feedback_questions`
--
ALTER TABLE `feedback_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payroll_notifications`
--
ALTER TABLE `payroll_notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payroll_records`
--
ALTER TABLE `payroll_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `schedule_requests`
--
ALTER TABLE `schedule_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `shift_notifications`
--
ALTER TABLE `shift_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `shift_weeks`
--
ALTER TABLE `shift_weeks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD CONSTRAINT `fk_admin_users_employees_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_employees_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_attendance_shifts_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `biometric_upload_logs`
--
ALTER TABLE `biometric_upload_logs`
  ADD CONSTRAINT `fk_biouploadlogs_admin_users_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `admin_users` (`id`);

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_employees_shifts_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `employee_shifts`
--
ALTER TABLE `employee_shifts`
  ADD CONSTRAINT `fk_employee_shifts_employees_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `fk_feedback_employees_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_feedback_employees_on_shift_employee_id` FOREIGN KEY (`on_shift_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `feedback_answers`
--
ALTER TABLE `feedback_answers`
  ADD CONSTRAINT `fk_feedback_answers_feedback_feedback_id` FOREIGN KEY (`feedback_id`) REFERENCES `feedback` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_feedback_answers_questions_question_id` FOREIGN KEY (`question_id`) REFERENCES `feedback_questions` (`id`);

--
-- Constraints for table `shift_notifications`
--
ALTER TABLE `shift_notifications`
  ADD CONSTRAINT `fk_shift_notifications_admin_users_admin_user_id` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`),
  ADD CONSTRAINT `fk_shift_notifications_employees_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `shift_weeks`
--
ALTER TABLE `shift_weeks`
  ADD CONSTRAINT `fk_shift_weeks_admin_users_published_by` FOREIGN KEY (`published_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
