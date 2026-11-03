-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 13, 2026 at 03:10 PM
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
-- Database: `php_rest_api`
--

-- --------------------------------------------------------

--
-- Table structure for table `advice_templates`
--

CREATE TABLE `advice_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `category` enum('diet','activity','sleep','hygiene','wound_care','other') NOT NULL,
  `text` varchar(500) NOT NULL,
  `text_hi` varchar(500) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `doctor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `advice_templates`
--

INSERT INTO `advice_templates` (`id`, `category`, `text`, `text_hi`, `is_system`, `doctor_id`, `is_active`) VALUES
(1, 'diet', 'Avoid cold and frozen food items', 'Thanda aur frozen khana band karein', 1, NULL, 1),
(2, 'diet', 'Drink at least 8–10 glasses of water daily', 'Roz 8-10 gilaas paani piyein', 1, NULL, 1),
(3, 'diet', 'Avoid oily and spicy food', 'Tel-masaledaar khana avoid karein', 1, NULL, 1),
(4, 'diet', 'Take light, easily digestible food', 'Halka aur supaachya bhojan karein', 1, NULL, 1),
(5, 'diet', 'Avoid alcohol and tobacco completely', 'Sharaab aur tambaku bilkul band karein', 1, NULL, 1),
(6, 'diet', 'Eat small frequent meals instead of large ones', 'Thodi thodi der pe thoda khana khayein', 1, NULL, 1),
(7, 'sleep', 'Take proper 7–8 hours of sleep', '7-8 ghante ki neend zaroor lein', 1, NULL, 1),
(8, 'sleep', 'Avoid screen time 30 minutes before sleeping', 'Sone se 30 min pehle mobile band karein', 1, NULL, 1),
(9, 'activity', 'Avoid heavy physical activity for 3 days', '3 din tak bhaari kaam na karein', 1, NULL, 1),
(10, 'activity', 'Take a short 15-minute walk after meals', 'Khane ke baad 15 min walk karein', 1, NULL, 1),
(11, 'hygiene', 'Keep the wound clean and dry', 'Ghav ko saaf aur sukha rakhein', 1, NULL, 1),
(12, 'hygiene', 'Wash hands before meals and after using washroom', 'Haath achhe se dhoyein', 1, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `clinic_id` bigint(20) UNSIGNED DEFAULT NULL,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `prescription_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Linked rx that generated this appt',
  `scheduled_at` datetime NOT NULL,
  `duration_min` tinyint(3) UNSIGNED NOT NULL DEFAULT 15,
  `reason` varchar(500) DEFAULT NULL,
  `status` enum('scheduled','confirmed','arrived','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
  `reminder_24h_sent` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_1h_sent` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_24h_at` datetime DEFAULT NULL,
  `reminder_1h_at` datetime DEFAULT NULL,
  `cancelled_by` enum('doctor','patient','system') DEFAULT NULL,
  `cancel_reason` varchar(300) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `actor_type` enum('doctor','system','admin') NOT NULL DEFAULT 'doctor',
  `actor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL COMMENT 'prescription.created, medicine.deleted …',
  `entity_type` varchar(50) NOT NULL,
  `entity_id` bigint(20) UNSIGNED NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `auth_tokens`
--

CREATE TABLE `auth_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `refresh_token` varchar(512) NOT NULL,
  `device_info` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `auth_tokens`
--

INSERT INTO `auth_tokens` (`id`, `doctor_id`, `refresh_token`, `device_info`, `ip_address`, `expires_at`, `revoked_at`, `created_at`) VALUES
(78, 19, '087d2918cccc3d7497e9c9784ca1119598ee7ee342b9a9ccafd8ceac3f4167811a534f0e6333bfc0', '{\"user_agent\":\"Thunder Client (https:\\/\\/www.thunderclient.com)\",\"platform\":\"Windows NT DESKTOP-MHR63RD 10.0 build 19045 (Windows 10) AMD64\"}', '127.0.0.1', '2026-07-08 06:53:51', NULL, '2026-07-01 06:53:51'),
(89, 23, '1ac20c816e2bb3881f20b443a692a1757bb69944c83278c32c8c20326a1c469793ba911ab8bf376e', '{\"user_agent\":\"Dart\\/3.6 (dart:io)\",\"platform\":\"Windows NT DESKTOP-MHR63RD 10.0 build 19045 (Windows 10) AMD64\"}', '192.168.1.15', '2026-07-15 06:09:48', NULL, '2026-07-08 06:09:48');

-- --------------------------------------------------------

--
-- Table structure for table `chronic_conditions`
--

CREATE TABLE `chronic_conditions` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `name_hi` varchar(200) DEFAULT NULL COMMENT 'Hindi',
  `icd10_code` varchar(10) DEFAULT NULL COMMENT 'International disease code e.g. E11, I10',
  `category` enum('cardiovascular','endocrine','respiratory','neurological','gastrointestinal','renal','musculoskeletal','oncology','psychiatric','dermatological','ophthalmological','ent','haematological','immunological','infectious','congenital','other') NOT NULL DEFAULT 'other',
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `doctor_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL=system; set=doctor private',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chronic_conditions`
--

INSERT INTO `chronic_conditions` (`id`, `name`, `name_hi`, `icd10_code`, `category`, `is_system`, `doctor_id`, `is_active`, `created_at`) VALUES
(1, 'Type 2 Diabetes Mellitus', 'Type 2 Madhumeh', 'E11', 'endocrine', 1, NULL, 1, '2026-06-15 18:08:13'),
(2, 'Type 1 Diabetes Mellitus', 'Type 1 Madhumeh', 'E10', 'endocrine', 1, NULL, 1, '2026-06-15 18:08:13'),
(3, 'Hypothyroidism', 'Hypothyroidism', 'E03', 'endocrine', 1, NULL, 1, '2026-06-15 18:08:13'),
(4, 'Hyperthyroidism', 'Hyperthyroidism', 'E05', 'endocrine', 1, NULL, 1, '2026-06-15 18:08:13'),
(5, 'Obesity', 'Motapa', 'E66', 'endocrine', 1, NULL, 1, '2026-06-15 18:08:13'),
(6, 'PCOD / PCOS', 'PCOD', 'E28', 'endocrine', 1, NULL, 1, '2026-06-15 18:08:13'),
(7, 'Hypertension', 'Uchh Raktachaap', 'I10', 'cardiovascular', 1, NULL, 1, '2026-06-15 18:08:13'),
(8, 'Coronary Artery Disease', 'Heart ki naliyon ki bimari', 'I25', 'cardiovascular', 1, NULL, 1, '2026-06-15 18:08:13'),
(9, 'Heart Failure', 'Heart Failure', 'I50', 'cardiovascular', 1, NULL, 1, '2026-06-15 18:08:13'),
(10, 'Atrial Fibrillation', 'Atrial Fibrillation', 'I48', 'cardiovascular', 1, NULL, 1, '2026-06-15 18:08:13'),
(11, 'Rheumatic Heart Disease', 'Rheumatic Heart Disease', 'I09', 'cardiovascular', 1, NULL, 1, '2026-06-15 18:08:13'),
(12, 'Bronchial Asthma', 'Dama / Saans ki bimari', 'J45', 'respiratory', 1, NULL, 1, '2026-06-15 18:08:13'),
(13, 'COPD', 'COPD', 'J44', 'respiratory', 1, NULL, 1, '2026-06-15 18:08:13'),
(14, 'Allergic Rhinitis', 'Naak ki allergy', 'J30', 'respiratory', 1, NULL, 1, '2026-06-15 18:08:13'),
(15, 'Tuberculosis (TB)', 'TB / Kshay rog', 'A15', 'respiratory', 1, NULL, 1, '2026-06-15 18:08:13'),
(16, 'Epilepsy / Seizure Disorder', 'Mirgi', 'G40', 'neurological', 1, NULL, 1, '2026-06-15 18:08:13'),
(17, 'Migraine', 'Adhasisi', 'G43', 'neurological', 1, NULL, 1, '2026-06-15 18:08:13'),
(18, 'Parkinsons Disease', 'Parkinson', 'G20', 'neurological', 1, NULL, 1, '2026-06-15 18:08:13'),
(19, 'Stroke / CVA', 'Laqwa / Brain Attack', 'I63', 'neurological', 1, NULL, 1, '2026-06-15 18:08:13'),
(20, 'Gastroesophageal Reflux (GERD)', 'Acidity ki bimari', 'K21', 'gastrointestinal', 1, NULL, 1, '2026-06-15 18:08:13'),
(21, 'Irritable Bowel Syndrome (IBS)', 'IBS', 'K58', 'gastrointestinal', 1, NULL, 1, '2026-06-15 18:08:13'),
(22, 'Peptic Ulcer Disease', 'Aanth ka ghav', 'K27', 'gastrointestinal', 1, NULL, 1, '2026-06-15 18:08:13'),
(23, 'Chronic Liver Disease', 'Jigar ki bimari', 'K74', 'gastrointestinal', 1, NULL, 1, '2026-06-15 18:08:13'),
(24, 'Crohn\'s Disease', 'Crohn\'s Disease', 'K50', 'gastrointestinal', 1, NULL, 1, '2026-06-15 18:08:13'),
(25, 'Chronic Kidney Disease (CKD)', 'Gurde ki bimari', 'N18', 'renal', 1, NULL, 1, '2026-06-15 18:08:13'),
(26, 'Kidney Stones', 'Pathri', 'N20', 'renal', 1, NULL, 1, '2026-06-15 18:08:13'),
(27, 'Rheumatoid Arthritis', 'Gathiya', 'M05', 'musculoskeletal', 1, NULL, 1, '2026-06-15 18:08:13'),
(28, 'Osteoarthritis', 'Haddi ghisna', 'M15', 'musculoskeletal', 1, NULL, 1, '2026-06-15 18:08:13'),
(29, 'Osteoporosis', 'Haddi kamzori', 'M81', 'musculoskeletal', 1, NULL, 1, '2026-06-15 18:08:13'),
(30, 'Gout', 'Vaat rog', 'M10', 'musculoskeletal', 1, NULL, 1, '2026-06-15 18:08:13'),
(31, 'Ankylosing Spondylitis', 'Ankylosing Spondylitis', 'M45', 'musculoskeletal', 1, NULL, 1, '2026-06-15 18:08:13'),
(32, 'Anaemia', 'Khoon ki kami', 'D64', 'haematological', 1, NULL, 1, '2026-06-15 18:08:13'),
(33, 'Sickle Cell Disease', 'Sickle Cell Bimari', 'D57', 'haematological', 1, NULL, 1, '2026-06-15 18:08:13'),
(34, 'Thalassemia', 'Thalassemia', 'D56', 'haematological', 1, NULL, 1, '2026-06-15 18:08:13'),
(35, 'Depression', 'Udaasi / Depression', 'F32', 'psychiatric', 1, NULL, 1, '2026-06-15 18:08:13'),
(36, 'Anxiety Disorder', 'Chinta rog', 'F41', 'psychiatric', 1, NULL, 1, '2026-06-15 18:08:13'),
(37, 'Bipolar Disorder', 'Bipolar', 'F31', 'psychiatric', 1, NULL, 1, '2026-06-15 18:08:13'),
(38, 'Schizophrenia', 'Schizophrenia', 'F20', 'psychiatric', 1, NULL, 1, '2026-06-15 18:08:13'),
(39, 'Glaucoma', 'Kaala Motia', 'H40', 'ophthalmological', 1, NULL, 1, '2026-06-15 18:08:13'),
(40, 'Diabetic Retinopathy', 'Sugar se aankh ki bimari', 'H36', 'ophthalmological', 1, NULL, 1, '2026-06-15 18:08:13'),
(41, 'Psoriasis', 'Chaal rog', 'L40', 'dermatological', 1, NULL, 1, '2026-06-15 18:08:13'),
(42, 'Eczema / Atopic Dermatitis', 'Khujli / Chaam ki bimari', 'L20', 'dermatological', 1, NULL, 1, '2026-06-15 18:08:13'),
(43, 'Cancer (unspecified)', 'Cancer', 'C80', 'oncology', 1, NULL, 1, '2026-06-15 18:08:13'),
(44, 'HIV / AIDS', 'HIV', 'B20', 'infectious', 1, NULL, 1, '2026-06-15 18:08:13'),
(45, 'Hepatitis B', 'Hepatitis B', 'B18', 'infectious', 1, NULL, 1, '2026-06-15 18:08:13'),
(46, 'Hepatitis C', 'Hepatitis C', 'B17', 'infectious', 1, NULL, 1, '2026-06-15 18:08:13');

-- --------------------------------------------------------

--
-- Table structure for table `clinics`
--

CREATE TABLE `clinics` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `timings` varchar(500) DEFAULT NULL COMMENT 'Mon-Sat 9am-1pm, 5pm-9pm',
  `logo_url` varchar(512) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `clinics`
--

INSERT INTO `clinics` (`id`, `doctor_id`, `name`, `address_line1`, `address_line2`, `city`, `state`, `pincode`, `phone`, `timings`, `logo_url`, `is_primary`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 19, 'city dental hospital', 'abc road , main sector', 'old city , rajkot', 'rajkot', 'gujarat', '360004', '9876543210', 'Mon-Sat\r\n10am-1pm\r\n5pm-9pm', NULL, 0, '2026-06-16 10:45:26', '2026-06-16 10:45:26', NULL),
(2, 23, 'civil hospital', 'new civil hospital , near hospital chowk', 'old road , rajkot', 'rajkot', 'gujarat', '360005', '1234567890', 'Mon-Sat\r\n9am-1pm\r\n4pm-8pm', NULL, 0, '2026-06-16 10:47:39', '2026-06-16 10:47:39', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `clinic_contacts`
--

CREATE TABLE `clinic_contacts` (
  `id` int(10) UNSIGNED NOT NULL,
  `clinic_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('mobile','landline','fax','whatsapp') NOT NULL DEFAULT 'mobile',
  `number` varchar(20) NOT NULL,
  `label` varchar(50) DEFAULT NULL COMMENT 'Front Desk, Emergency, Dr. Personal …',
  `show_on_rx` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Print on prescription header?',
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `clinic_contacts`
--

INSERT INTO `clinic_contacts` (`id`, `clinic_id`, `type`, `number`, `label`, `show_on_rx`, `sort_order`, `is_active`) VALUES
(10, 1, 'landline', '0261-2345678', 'Reception', 1, 1, 1),
(11, 1, 'mobile', '9876543210', 'Dr. Mobile', 1, 2, 1),
(12, 1, 'whatsapp', '9876543210', 'WhatsApp', 0, 3, 1),
(13, 1, 'mobile', '9988776655', 'Emergency', 1, 4, 1);

-- --------------------------------------------------------

--
-- Table structure for table `clinic_timings`
--

CREATE TABLE `clinic_timings` (
  `id` int(10) UNSIGNED NOT NULL,
  `clinic_id` bigint(20) UNSIGNED NOT NULL,
  `day_of_week` tinyint(3) UNSIGNED NOT NULL COMMENT '0=Sunday,1=Monday…6=Saturday',
  `shift` enum('morning','afternoon','evening','night') NOT NULL DEFAULT 'morning',
  `open_time` time NOT NULL COMMENT '09:00:00',
  `close_time` time NOT NULL COMMENT '13:00:00',
  `is_open` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = clinic closed this day/shift',
  `notes` varchar(200) DEFAULT NULL COMMENT 'Public holiday, by appointment only …'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `clinic_timings`
--

INSERT INTO `clinic_timings` (`id`, `clinic_id`, `day_of_week`, `shift`, `open_time`, `close_time`, `is_open`, `notes`) VALUES
(44, 1, 1, 'morning', '09:00:00', '13:00:00', 1, NULL),
(45, 1, 1, 'evening', '17:00:00', '21:00:00', 1, NULL),
(46, 1, 2, 'morning', '09:00:00', '13:00:00', 1, NULL),
(47, 1, 2, 'evening', '17:00:00', '21:00:00', 1, NULL),
(48, 1, 3, 'morning', '09:00:00', '13:00:00', 1, NULL),
(49, 1, 3, 'evening', '17:00:00', '21:00:00', 1, NULL),
(50, 1, 4, 'morning', '09:00:00', '13:00:00', 1, NULL),
(51, 1, 4, 'evening', '17:00:00', '21:00:00', 1, NULL),
(52, 1, 5, 'morning', '09:00:00', '13:00:00', 1, NULL),
(53, 1, 5, 'evening', '17:00:00', '21:00:00', 1, NULL),
(54, 1, 6, 'morning', '09:00:00', '13:00:00', 1, NULL),
(55, 1, 6, 'evening', '17:00:00', '21:00:00', 0, NULL),
(56, 1, 0, 'morning', '09:00:00', '13:00:00', 0, NULL),
(57, 1, 0, 'evening', '17:00:00', '21:00:00', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `doctors`
--

CREATE TABLE `doctors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `mobile` varchar(15) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `degree` varchar(255) DEFAULT NULL COMMENT 'MBBS, MD, BDS …',
  `specialization` varchar(255) DEFAULT NULL,
  `registration_no` varchar(100) DEFAULT NULL COMMENT 'MCI / state council reg no',
  `experience_yrs` tinyint(3) UNSIGNED DEFAULT NULL,
  `signature_url` varchar(512) DEFAULT NULL COMMENT 'Base-64 or CDN URL for rx letterhead',
  `plan` enum('trial','basic','pro','enterprise') NOT NULL DEFAULT 'trial',
  `plan_expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `doctors`
--

INSERT INTO `doctors` (`id`, `name`, `email`, `mobile`, `password_hash`, `degree`, `specialization`, `registration_no`, `experience_yrs`, `signature_url`, `plan`, `plan_expires_at`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES
(19, 'karan', 'simet13909@dyleris.com', '+911234567890', '$2y$10$OaeMWoLh3spq9x/YcFw1lOQ.grgJIq2xtG3mVZf/lIUuEKWUlpXXe', NULL, NULL, NULL, NULL, NULL, 'trial', '2026-07-15 06:18:19', 1, '2026-06-15 06:18:19', '2026-06-15 12:03:16', NULL),
(23, 'test', 'jaypatel7586@gmail.com', '+918574542150', '$2y$10$eYVcRACfuZmEu9L7D8dc2.Ipf9XM5pLh9HAnOxzS0Dhxob4XeuyVS', 'Mbbs', 'ortho', 'gf43gd53f', 12, NULL, 'trial', '2026-07-15 12:25:41', 1, '2026-06-15 12:25:41', '2026-06-15 17:56:27', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `lab_orders`
--

CREATE TABLE `lab_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `prescription_id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `lab_partner_id` int(10) UNSIGNED DEFAULT NULL,
  `lab_name` varchar(200) DEFAULT NULL,
  `lab_address` varchar(500) DEFAULT NULL,
  `lab_phone` varchar(20) DEFAULT NULL,
  `order_date` datetime NOT NULL DEFAULT current_timestamp(),
  `urgency` enum('routine','urgent','stat') NOT NULL DEFAULT 'routine',
  `clinical_notes` text DEFAULT NULL,
  `status` enum('ordered','sample_collected','processing','report_ready','delivered','cancelled') NOT NULL DEFAULT 'ordered',
  `result_date` datetime DEFAULT NULL,
  `result_summary` text DEFAULT NULL,
  `result_file_url` varchar(512) DEFAULT NULL,
  `estimated_cost` decimal(8,2) DEFAULT NULL,
  `actual_cost` decimal(8,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_order_tests`
--

CREATE TABLE `lab_order_tests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lab_order_id` bigint(20) UNSIGNED NOT NULL,
  `test_id` int(10) UNSIGNED DEFAULT NULL,
  `custom_test` varchar(200) DEFAULT NULL COMMENT 'If not in catalog',
  `result_value` varchar(200) DEFAULT NULL,
  `result_unit` varchar(50) DEFAULT NULL,
  `result_flag` enum('normal','low','high','critical') DEFAULT 'normal',
  `notes` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_partners`
--

CREATE TABLE `lab_partners` (
  `id` int(10) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('lab','radiology','both') NOT NULL DEFAULT 'lab',
  `name` varchar(200) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `nabl_accredited` tinyint(1) NOT NULL DEFAULT 0,
  `notes` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `lab_partners`
--

INSERT INTO `lab_partners` (`id`, `doctor_id`, `type`, `name`, `contact_person`, `phone`, `email`, `address`, `city`, `nabl_accredited`, `notes`, `is_active`, `created_at`, `deleted_at`) VALUES
(1, 23, 'lab', 'checkup lab', 'surajbhai', '9876543210', 'suraj@gmail.com', 'yagnik road junction', 'rajkot', 1, 'we are open from monday to sunday noon , take emergency patient ', 1, '2026-06-16 10:52:14', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `lab_partner_contacts`
--

CREATE TABLE `lab_partner_contacts` (
  `id` int(10) UNSIGNED NOT NULL,
  `lab_partner_id` int(10) UNSIGNED NOT NULL,
  `type` enum('mobile','landline','fax','whatsapp') NOT NULL DEFAULT 'mobile',
  `number` varchar(20) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL COMMENT 'Person answering this number',
  `label` varchar(50) DEFAULT NULL COMMENT 'Sample pickup, Reports, Admin …',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `lab_partner_contacts`
--

INSERT INTO `lab_partner_contacts` (`id`, `lab_partner_id`, `type`, `number`, `contact_person`, `label`, `is_primary`, `is_active`) VALUES
(14, 1, 'landline', '0261-2987654', 'Reception', 'Main Line', 1, 1),
(15, 1, 'mobile', '9876500001', 'Ravi Patel', 'Sample Pickup', 0, 1),
(16, 1, 'whatsapp', '9876500001', 'Ravi Patel', 'Report WhatsApp', 0, 1),
(17, 1, 'mobile', '9988001122', 'Dr. Mehta', 'Pathologist', 0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `lab_tests_catalog`
--

CREATE TABLE `lab_tests_catalog` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `short_code` varchar(30) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL COMMENT 'Haematology, Biochemistry, Microbiology …',
  `normal_range` varchar(200) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `lab_tests_catalog`
--

INSERT INTO `lab_tests_catalog` (`id`, `name`, `short_code`, `category`, `normal_range`, `unit`, `is_active`) VALUES
(1, 'Complete Blood Count', 'CBC', 'Haematology', NULL, NULL, 1),
(2, 'Haemoglobin', 'HB', 'Haematology', '12–17 g/dL', 'g/dL', 1),
(3, 'Fasting Blood Sugar', 'FBS', 'Biochemistry', '70–100 mg/dL', 'mg/dL', 1),
(4, 'Post-Prandial Blood Sugar', 'PPBS', 'Biochemistry', '<140 mg/dL', 'mg/dL', 1),
(5, 'HbA1c', 'HBA1C', 'Biochemistry', '<5.7%', '%', 1),
(6, 'Serum Creatinine', 'CR', 'Biochemistry', '0.6–1.2 mg/dL', 'mg/dL', 1),
(7, 'Blood Urea Nitrogen', 'BUN', 'Biochemistry', '7–20 mg/dL', 'mg/dL', 1),
(8, 'Lipid Profile', 'LIPID', 'Biochemistry', NULL, NULL, 1),
(9, 'Thyroid Stimulating Hormone', 'TSH', 'Endocrine', '0.4–4.0 mIU/L', 'mIU/L', 1),
(10, 'Urine Routine', 'URINE_RE', 'Urology', NULL, NULL, 1),
(11, 'Serum Electrolytes', 'ELECTRO', 'Biochemistry', NULL, NULL, 1),
(12, 'Liver Function Test', 'LFT', 'Biochemistry', NULL, NULL, 1),
(13, 'Dengue NS1 Antigen', 'DENGUE_NS1', 'Serology', NULL, NULL, 1),
(14, 'Malaria Card Test', 'MALARIA', 'Serology', NULL, NULL, 1),
(15, 'COVID-19 RTPCR', 'COVID_PCR', 'Microbiology', NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `medicines`
--

CREATE TABLE `medicines` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` smallint(5) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `generic_name` varchar(200) DEFAULT NULL,
  `manufacturer` varchar(200) DEFAULT NULL,
  `strength` varchar(100) DEFAULT NULL COMMENT '500mg, 10ml/5ml …',
  `default_unit` enum('piece','ml','mg','gm','kg','IU','mcg','drop','puff','sachet','patch','suppository','unit','other') NOT NULL DEFAULT 'piece',
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `doctor_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = system-wide; set = doctor-private',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `medicines`
--

INSERT INTO `medicines` (`id`, `category_id`, `name`, `generic_name`, `manufacturer`, `strength`, `default_unit`, `is_system`, `doctor_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Dolo 650', 'Paracetamol', 'Micro Labs', '650mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(2, 1, 'Crocin Advance', 'Paracetamol', 'GSK', '500mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(3, 1, 'Pan 40', 'Pantoprazole', 'Alkem', '40mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(4, 1, 'Metformin 500', 'Metformin HCl', 'Sun Pharma', '500mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(5, 1, 'Telma 40', 'Telmisartan', 'Glenmark', '40mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(6, 1, 'Ecosprin 75', 'Aspirin', 'USV', '75mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(7, 1, 'Calpol 500', 'Paracetamol', 'GSK', '500mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(8, 1, 'Azithral 500', 'Azithromycin', 'Alembic', '500mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(9, 1, 'Amoxyclav 625', 'Amoxicillin+Clavulanate', 'Cipla', '625mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(10, 1, 'Montek LC', 'Montelukast+Levocetirizine', 'Sun Pharma', '10mg+5mg', 'piece', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(11, 3, 'Benadryl Cough', 'Diphenhydramine', 'J&J', '14.08mg/5ml', 'ml', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(12, 3, 'Phenergan', 'Promethazine', 'Sanofi', '5mg/5ml', 'ml', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(13, 3, 'Ambroxol Syrup', 'Ambroxol HCl', 'Boehringer Ingelheim', '15mg/5ml', 'ml', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(14, 6, 'Emset Injection', 'Ondansetron', 'Emcure', '4mg/2ml', 'ml', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(15, 6, 'Dexona Injection', 'Dexamethasone', 'Samarth Life Sci', '4mg/ml', 'ml', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(16, 6, 'Voveran Inj', 'Diclofenac', 'Novartis', '75mg/3ml', 'ml', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(17, 8, 'Betnovate-N', 'Betamethasone+Neomycin', 'GSK', '0.1%+0.5%', 'gm', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(18, 8, 'Soframycin Cream', 'Framycetin', 'Sanofi', '1%', 'gm', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(19, 7, 'Normal Saline 500ml', 'Sodium Chloride 0.9%', 'Baxter', '500ml', 'ml', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57'),
(20, 7, 'Ringer Lactate', 'Compound Sodium Lactate', 'Fresenius Kabi', '500ml', 'ml', 1, NULL, 1, '2026-06-15 18:06:57', '2026-06-15 18:06:57');

-- --------------------------------------------------------

--
-- Table structure for table `medicine_categories`
--

CREATE TABLE `medicine_categories` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `default_unit` enum('piece','ml','mg','gm','kg','IU','mcg','drop','puff','sachet','patch','suppository','unit','other') NOT NULL DEFAULT 'piece',
  `icon_name` varchar(50) DEFAULT NULL COMMENT 'icon key in app',
  `route` enum('oral','topical','injectable','inhalation','ophthalmic','otic','nasal','rectal','sublingual','transdermal','other') NOT NULL DEFAULT 'oral',
  `notes` varchar(255) DEFAULT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `medicine_categories`
--

INSERT INTO `medicine_categories` (`id`, `name`, `slug`, `default_unit`, `icon_name`, `route`, `notes`, `sort_order`, `is_active`) VALUES
(1, 'Tablet', 'tablet', 'piece', 'tablet', 'oral', NULL, 1, 1),
(2, 'Capsule', 'capsule', 'piece', 'capsule', 'oral', NULL, 2, 1),
(3, 'Syrup / Suspension', 'syrup', 'ml', 'syrup', 'oral', NULL, 3, 1),
(4, 'Drops (Oral)', 'drops-oral', 'drop', 'drops', 'oral', NULL, 4, 1),
(5, 'Powder / Sachet', 'powder-sachet', 'sachet', 'sachet', 'oral', NULL, 5, 1),
(6, 'Injection', 'injection', 'ml', 'syringe', 'injectable', NULL, 6, 1),
(7, 'IV Infusion / Drip', 'iv-infusion', 'ml', 'iv-bag', 'injectable', NULL, 7, 1),
(8, 'Cream / Ointment', 'cream', 'gm', 'cream-tube', 'topical', NULL, 8, 1),
(9, 'Gel', 'gel', 'gm', 'gel', 'topical', NULL, 9, 1),
(10, 'Lotion', 'lotion', 'ml', 'lotion', 'topical', NULL, 10, 1),
(11, 'Spray (Topical)', 'spray-topical', 'ml', 'spray', 'topical', NULL, 11, 1),
(12, 'Inhaler / MDI', 'inhaler', 'puff', 'inhaler', 'inhalation', NULL, 12, 1),
(13, 'Nebulizer Solution', 'nebulizer', 'ml', 'nebulizer', 'inhalation', NULL, 13, 1),
(14, 'Eye Drops', 'eye-drops', 'drop', 'eye-drop', 'ophthalmic', NULL, 14, 1),
(15, 'Eye Ointment', 'eye-ointment', 'gm', 'eye-oint', 'ophthalmic', NULL, 15, 1),
(16, 'Ear Drops', 'ear-drops', 'drop', 'ear-drop', 'otic', NULL, 16, 1),
(17, 'Nasal Drops / Spray', 'nasal', 'drop', 'nasal', 'nasal', NULL, 17, 1),
(18, 'Suppository', 'suppository', 'piece', 'suppository', 'rectal', NULL, 18, 1),
(19, 'Enema', 'enema', 'ml', 'enema', 'rectal', NULL, 19, 1),
(20, 'Patch (Transdermal)', 'patch', 'patch', 'patch', 'transdermal', NULL, 20, 1),
(21, 'Sublingual Tablet', 'sublingual', 'piece', 'sublingual', 'sublingual', NULL, 21, 1),
(22, 'Vaccine', 'vaccine', 'ml', 'vaccine', 'injectable', NULL, 22, 1),
(23, 'Oil', 'oil', 'ml', 'oil', 'topical', NULL, 23, 1),
(24, 'Dressing / Bandage', 'dressing', 'piece', 'bandage', 'topical', NULL, 24, 1),
(25, 'Device / Equipment', 'device', 'unit', 'device', 'other', NULL, 25, 1),
(26, 'Suture / Surgical', 'surgical', 'unit', 'surgical', 'other', NULL, 26, 1),
(27, 'ORS / Electrolyte', 'ors', 'sachet', 'ors', 'oral', NULL, 27, 1),
(28, 'Lozenge / Pastille', 'lozenge', 'piece', 'lozenge', 'oral', NULL, 28, 1),
(29, 'Mouthwash / Gargle', 'mouthwash', 'ml', 'mouthwash', 'oral', NULL, 29, 1),
(30, 'Contraceptive', 'contraceptive', 'piece', 'pill', 'other', NULL, 30, 1);

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `uhid` varchar(20) NOT NULL COMMENT 'Unique Health ID per doctor',
  `name` varchar(150) NOT NULL,
  `mobile` varchar(15) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `age_years` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Fallback when DOB unknown',
  `sex` enum('male','female','other','prefer_not_to_say') NOT NULL DEFAULT 'male',
  `address` varchar(500) DEFAULT NULL,
  `occupation` varchar(150) DEFAULT NULL,
  `blood_group` enum('A+','A-','B+','B-','AB+','AB-','O+','O-','unknown') DEFAULT 'unknown',
  `known_allergies` text DEFAULT NULL COMMENT 'Free text or JSON array',
  `chronic_conditions` text DEFAULT NULL COMMENT 'Diabetes, HTN, Asthma …',
  `whatsapp_number` varchar(15) DEFAULT NULL COMMENT 'If different from mobile',
  `whatsapp_opted` tinyint(1) NOT NULL DEFAULT 1,
  `language_pref` varchar(10) NOT NULL DEFAULT 'hi' COMMENT 'ISO 639-1',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`id`, `doctor_id`, `uhid`, `name`, `mobile`, `email`, `date_of_birth`, `age_years`, `sex`, `address`, `occupation`, `blood_group`, `known_allergies`, `chronic_conditions`, `whatsapp_number`, `whatsapp_opted`, `language_pref`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 23, 'abc101ac3', 'alpeshbhai', '9876543101', 'alpeshbhai@gmail.com', '0000-00-00', 20, 'male', 'abc road ', 'doctor', 'A+', NULL, NULL, NULL, 1, 'en', 1, '2026-06-16 18:01:26', '2026-06-17 10:47:20', NULL),
(2, 19, 'UHID-19-A9A71D', 'Amit Sharma', '9876543210', 'amit.sharma@gmail.com', '1995-08-15', 30, 'male', 'Rajkot, Gujarat', 'Engineer', 'B+', 'Penicillin', 'heart patient', NULL, 1, 'hi', 1, '2026-06-17 10:56:50', '2026-06-17 10:56:50', NULL),
(3, 19, 'UHID-19-6F0326', 'Om shree', '9876543200', 'amit.sharma@gmail.com', '1995-08-15', 30, 'male', 'Ahmedabad, Gujarat', 'Engineer', 'A+', 'Penicillin', 'heart patient', '9876543210', 1, 'hi', 1, '2026-06-17 11:29:50', '2026-06-17 11:29:50', NULL),
(4, 19, 'UHID-19-87C1AF', 'Rahul Patel', '9876501234', 'rahul.patel@gmail.com', '1992-05-10', 34, 'male', 'Rajkot, Gujarat', 'Software Engineer', 'O+', 'Dust', 'Asthma', '9876501234', 1, 'hi', 1, '2026-06-17 17:22:08', '2026-06-17 17:22:08', NULL),
(5, 19, 'UHID-19-DC29E2', 'Preetam singh', '9876501114', 'preetam.singhtest@gmail.com', '1996-09-07', 29, 'male', 'amritsar, Punjab', 'Farmer', 'AB+', '', '', '9876501114', 1, 'hi', 1, '2026-06-18 10:45:09', '2026-06-18 10:45:09', NULL),
(6, 19, 'UHID-19-358A2C', 'Priya patel', '9876501111', 'priya.pateltest@gmail.com', '2003-09-11', 22, 'female', 'Rajkot, Gujarat', 'Model', 'B+', '', '', '9876501111', 1, 'hi', 1, '2026-06-19 11:27:55', '2026-06-19 11:27:55', NULL),
(7, 23, 'UHID-23-537F9D', 'preeti patel ', '1235648790', 'preeti@gmail.com', '2005-07-02', 21, 'female', '', '', 'B+', NULL, NULL, NULL, 1, 'hi', 1, '2026-07-02 11:19:25', '2026-07-02 11:19:25', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `patient_chronic_conditions`
--

CREATE TABLE `patient_chronic_conditions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `condition_id` int(10) UNSIGNED NOT NULL,
  `diagnosed_year` year(4) DEFAULT NULL,
  `severity` enum('mild','moderate','severe','controlled','remission') DEFAULT 'moderate',
  `on_medication` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(300) DEFAULT NULL,
  `added_by_doctor` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Which doctor added this',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `clinic_id` bigint(20) UNSIGNED DEFAULT NULL,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `visit_date` datetime NOT NULL DEFAULT current_timestamp(),
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `bmi` decimal(4,2) DEFAULT NULL COMMENT 'Computed and stored for history',
  `bp_systolic` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'mmHg',
  `bp_diastolic` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'mmHg',
  `pulse_bpm` smallint(5) UNSIGNED DEFAULT NULL,
  `temperature_f` decimal(4,1) DEFAULT NULL,
  `spo2_pct` tinyint(3) UNSIGNED DEFAULT NULL COMMENT '% oxygen saturation',
  `rbs_mg_dl` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Random Blood Sugar mg/dL',
  `respiratory_rate` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'breaths/min',
  `chief_complaint` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `clinical_notes` text DEFAULT NULL COMMENT 'Doctor private notes',
  `status` enum('draft','final','cancelled') NOT NULL DEFAULT 'draft',
  `followup_date` date DEFAULT NULL,
  `followup_note` varchar(500) DEFAULT NULL,
  `wa_notif_sent` tinyint(1) NOT NULL DEFAULT 0,
  `wa_notif_at` datetime DEFAULT NULL,
  `followup_job_id` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `prescriptions`
--

INSERT INTO `prescriptions` (`id`, `doctor_id`, `clinic_id`, `patient_id`, `visit_date`, `weight_kg`, `height_cm`, `bmi`, `bp_systolic`, `bp_diastolic`, `pulse_bpm`, `temperature_f`, `spo2_pct`, `rbs_mg_dl`, `respiratory_rate`, `chief_complaint`, `diagnosis`, `clinical_notes`, `status`, `followup_date`, `followup_note`, `wa_notif_sent`, `wa_notif_at`, `followup_job_id`, `created_at`, `updated_at`, `deleted_at`) VALUES
(7, 23, NULL, 1, '2026-06-16 18:02:32', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Fever', 'Viral infection', NULL, 'final', NULL, NULL, 0, NULL, NULL, '2026-06-16 18:02:32', '2026-06-17 17:02:19', '2026-06-17 11:32:19'),
(8, 19, 1, 2, '2026-06-17 10:56:50', 72.00, 175.00, 23.51, 122, 82, 76, 99.1, 98, 108, 18, 'Fever, headache, cough', 'Viral infection', 'Mild viral symptoms, no complications', 'final', '2026-06-25', 'Come back if fever persists >3 days', 0, NULL, NULL, '2026-06-17 10:56:50', '2026-06-17 17:03:00', '2026-06-17 11:33:00'),
(9, 19, 1, 3, '2026-06-17 11:29:51', 72.00, 175.00, 23.51, 122, 82, 76, 99.1, 98, 108, 18, 'Fever, headache, cough', 'Viral infection', 'Mild viral symptoms, no complications', 'final', '2026-06-25', 'Come back if fever persists >3 days', 0, NULL, NULL, '2026-06-17 11:29:51', '2026-06-17 17:15:18', '2026-06-17 11:45:18'),
(10, 19, 1, 2, '2026-06-17 11:44:01', 70.00, 175.00, NULL, 120, 80, 72, 98.6, 99, 105, 18, 'Cold and cough', 'Seasonal infection', 'Patient stable, mild symptoms', 'final', '2026-06-25', 'Visit if symptoms worsen', 0, NULL, NULL, '2026-06-17 11:44:01', '2026-06-17 11:44:01', NULL),
(11, 19, NULL, 3, '2026-06-17 12:49:29', 70.00, 175.00, 22.86, 120, 80, 72, 98.6, 99, 105, 18, 'Cold and cough', 'Seasonal infection', 'Patient stable, mild symptoms', 'final', '2026-06-25', 'Visit if symptoms worsen', 0, NULL, NULL, '2026-06-17 12:49:29', '2026-06-19 11:31:24', '2026-06-19 06:01:24'),
(12, 19, 1, 4, '2026-06-17 17:22:08', 68.00, 172.00, 22.99, 120, 80, 75, 100.2, 97, 110, 18, 'Fever, cough, throat pain', 'Upper respiratory infection', 'Patient shows mild infection symptoms', 'final', '2026-06-25', 'Visit again if symptoms persist', 0, NULL, NULL, '2026-06-17 17:22:08', '2026-06-17 17:22:08', NULL),
(13, 19, 1, 5, '2026-06-18 10:45:09', 82.00, 170.00, 28.37, 118, 76, 75, 90.2, 87, 100, 22, 'Fever, cough, throat pain', 'Upper respiratory infection', 'Patient shows mild infection symptoms', 'final', NULL, 'Visit again if symptoms persist', 0, NULL, NULL, '2026-06-18 10:45:09', '2026-06-18 10:45:09', NULL),
(14, 19, 2, 6, '2026-06-19 11:27:55', 57.00, 177.00, 18.19, 108, 88, 85, 40.2, 87, 100, 22, 'Fever, headache, body pain', 'due to over work load', 'Patient has not rest as needed', 'final', NULL, '', 0, NULL, NULL, '2026-06-19 11:27:55', '2026-06-19 11:27:55', NULL),
(15, 23, 2, 7, '2026-07-02 11:19:25', 60.00, 176.00, 19.37, 120, 90, 60, 30.0, 50, 60, 50, NULL, 'due to cold weather ', '', 'final', NULL, NULL, 0, NULL, NULL, '2026-07-02 11:19:25', '2026-07-02 11:19:25', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `prescription_advice`
--

CREATE TABLE `prescription_advice` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `prescription_id` bigint(20) UNSIGNED NOT NULL,
  `template_id` int(10) UNSIGNED DEFAULT NULL,
  `custom_text` varchar(500) DEFAULT NULL COMMENT 'Free-type by doctor',
  `category` enum('diet','activity','sleep','hygiene','wound_care','other') NOT NULL DEFAULT 'other',
  `sort_order` tinyint(4) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `prescription_advice`
--

INSERT INTO `prescription_advice` (`id`, `prescription_id`, `template_id`, `custom_text`, `category`, `sort_order`, `deleted_at`) VALUES
(1, 8, 1, 'Drink warm water', 'diet', 0, NULL),
(2, 8, NULL, 'Take proper rest', 'activity', 0, NULL),
(3, 9, 1, 'Drink warm water', 'diet', 0, '2026-06-17 11:45:19'),
(4, 9, NULL, 'Take proper rest', 'activity', 0, '2026-06-17 11:45:19'),
(5, 10, NULL, 'Drink warm water', 'diet', 0, NULL),
(6, 11, NULL, 'Drink warm water', 'diet', 0, '2026-06-19 06:01:24'),
(7, 12, 1, 'Drink warm water', 'diet', 0, NULL),
(8, 12, NULL, 'Take proper rest', 'activity', 0, NULL),
(9, 12, NULL, 'Avoid cold drinks', 'diet', 0, NULL),
(10, 13, 1, 'Drink warm water', 'diet', 0, NULL),
(11, 13, NULL, 'Take proper rest', 'activity', 0, NULL),
(12, 13, NULL, 'Avoid cold drinks', 'diet', 0, NULL),
(13, 13, NULL, 'Avoid oily and spicy food', 'diet', 0, NULL),
(14, 14, 7, 'Take proper rest', 'activity', 0, NULL),
(15, 14, NULL, 'Avoid cold drinks', 'diet', 0, NULL),
(16, 14, NULL, 'Avoid oily and spicy food', 'diet', 0, NULL),
(17, 14, NULL, 'Avoid physical activity', 'activity', 0, NULL),
(18, 15, 9, 'Avoid heavy physical activity for 3 days', 'activity', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `prescription_medicines`
--

CREATE TABLE `prescription_medicines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `prescription_id` bigint(20) UNSIGNED NOT NULL,
  `medicine_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL if free-text',
  `medicine_name` varchar(200) NOT NULL COMMENT 'Denormalized for rx print',
  `category_id` smallint(5) UNSIGNED DEFAULT NULL,
  `total_qty` decimal(8,2) NOT NULL COMMENT 'Total dispensed (10 tablets / 100ml)',
  `qty_unit` varchar(20) NOT NULL COMMENT 'piece, ml, gm …',
  `duration_days` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'NULL = indefinite / SOS',
  `duration_note` varchar(100) DEFAULT NULL COMMENT 'or use as directed, till fever subsides',
  `dose_schedule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Array of slot objects' CHECK (json_valid(`dose_schedule`)),
  `times_per_day` tinyint(3) UNSIGNED GENERATED ALWAYS AS (json_length(`dose_schedule`)) STORED,
  `freq_display` varchar(50) DEFAULT NULL,
  `instructions` varchar(500) DEFAULT NULL COMMENT 'Apply on affected area, swallow whole …',
  `is_substitutable` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `prescription_medicines`
--

INSERT INTO `prescription_medicines` (`id`, `prescription_id`, `medicine_id`, `medicine_name`, `category_id`, `total_qty`, `qty_unit`, `duration_days`, `duration_note`, `dose_schedule`, `freq_display`, `instructions`, `is_substitutable`, `sort_order`, `deleted_at`) VALUES
(1, 7, NULL, 'Paracetamol', NULL, 10.00, 'tablet', NULL, NULL, '[{\"slot\":\"morning\",\"qty\":1},{\"slot\":\"night\",\"qty\":1}]', NULL, NULL, 0, 0, NULL),
(2, 8, 1, 'Dolo 650', 1, 10.00, 'piece', 5, NULL, '[{\"slot\":\"morning\",\"food_relation\":\"after\",\"qty\":1,\"unit\":\"piece\",\"time\":\"08:00\"},{\"slot\":\"night\",\"food_relation\":\"after\",\"qty\":1,\"unit\":\"piece\",\"time\":\"20:00\"}]', '1-0-1', 'After food', 0, 0, NULL),
(3, 8, 11, 'Benadryl Cough', 3, 100.00, 'ml', 5, NULL, '[{\"slot\":\"morning\",\"qty\":5,\"unit\":\"ml\"},{\"slot\":\"afternoon\",\"qty\":5,\"unit\":\"ml\"},{\"slot\":\"night\",\"qty\":5,\"unit\":\"ml\"}]', '1-1-1', 'Shake well before use', 0, 0, NULL),
(4, 9, 1, 'Dolo 650', 1, 10.00, 'piece', 5, NULL, '[{\"slot\":\"morning\",\"food_relation\":\"after\",\"qty\":1,\"unit\":\"piece\",\"time\":\"08:00\"},{\"slot\":\"night\",\"food_relation\":\"after\",\"qty\":1,\"unit\":\"piece\",\"time\":\"20:00\"}]', '1-0-1', 'After food', 0, 0, '2026-06-17 11:45:18'),
(5, 9, 11, 'Benadryl Cough', 3, 100.00, 'ml', 5, NULL, '[{\"slot\":\"morning\",\"qty\":5,\"unit\":\"ml\"},{\"slot\":\"afternoon\",\"qty\":5,\"unit\":\"ml\"},{\"slot\":\"night\",\"qty\":5,\"unit\":\"ml\"}]', '1-1-1', 'Shake well before use', 0, 0, '2026-06-17 11:45:18'),
(6, 10, NULL, 'Dolo 650', NULL, 6.00, 'tablet', 3, NULL, '[{\"slot\":\"morning\",\"qty\":1},{\"slot\":\"night\",\"qty\":1}]', '1-0-1', 'After food', 0, 0, NULL),
(7, 11, NULL, 'Dolo 650', NULL, 6.00, 'tablet', 3, NULL, '[{\"slot\":\"morning\",\"qty\":1},{\"slot\":\"night\",\"qty\":1}]', '1-0-1', 'After food', 0, 0, '2026-06-19 06:01:24'),
(8, 12, 1, 'Paracetamol 650', 1, 10.00, 'tablet', 5, NULL, '[{\"slot\":\"morning\",\"food_relation\":\"after\",\"qty\":1,\"unit\":\"tablet\",\"time\":\"08:00\"},{\"slot\":\"night\",\"food_relation\":\"after\",\"qty\":1,\"unit\":\"tablet\",\"time\":\"20:00\"}]', '1-0-1', 'After food', 0, 0, NULL),
(9, 12, 2, 'Cough Syrup', 3, 100.00, 'ml', 5, NULL, '[{\"slot\":\"morning\",\"qty\":5,\"unit\":\"ml\"},{\"slot\":\"afternoon\",\"qty\":5,\"unit\":\"ml\"},{\"slot\":\"night\",\"qty\":5,\"unit\":\"ml\"}]', '1-1-1', 'Shake well before use', 0, 0, NULL),
(10, 13, 8, 'Azithral 500', 1, 5.00, 'tablet', 2, NULL, '[{\"slot\":\"morning\",\"food_relation\":\"after\",\"qty\":1,\"unit\":\"tablet\",\"time\":\"08:00\"},{\"slot\":\"night\",\"food_relation\":\"after\",\"qty\":0.5,\"unit\":\"tablet\",\"time\":\"20:00\"}]', '1-0-1', 'Use with water', 0, 0, NULL),
(11, 13, 2, 'Cough Syrup', 3, 100.00, 'ml', 5, NULL, '[{\"slot\":\"morning\",\"qty\":5,\"unit\":\"ml\"},{\"slot\":\"afternoon\",\"qty\":5,\"unit\":\"ml\"},{\"slot\":\"night\",\"qty\":5,\"unit\":\"ml\"}]', '1-1-1', 'Shake well before use', 0, 0, NULL),
(12, 14, 8, 'Azithromycin', 1, 5.00, 'tablet', 2, NULL, '[{\"slot\":\"night\",\"food_relation\":\"after\",\"qty\":1,\"unit\":\"tablet\",\"time\":\"20:00\"}]', '0-0-1', 'Use with water', 0, 0, NULL),
(13, 14, 18, 'Framyctein', 8, 5.00, 'piece', 3, NULL, '[{\"slot\":\"afternoon\",\"food_relation\":\"after\",\"qty\":1,\"unit\":\"gm\"},{\"slot\":\"night\",\"food_relation\":\"after\",\"qty\":0.5,\"unit\":\"gm\"}]', '0-1-1', 'chew with water', 0, 0, NULL),
(14, 15, 1, 'Dolo 650', NULL, 5.00, 'piece', 3, NULL, '[{\"slot\":\"night\",\"qty\":0.5,\"food_relation\":\"after\",\"unit\":\"piece\"}]', '0-0-1', 'After Meal', 0, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `prescription_symptoms`
--

CREATE TABLE `prescription_symptoms` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `prescription_id` bigint(20) UNSIGNED NOT NULL,
  `symptom_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL if free-text custom',
  `custom_symptom` varchar(200) DEFAULT NULL,
  `severity` enum('mild','moderate','severe') DEFAULT 'mild',
  `duration` varchar(100) DEFAULT NULL COMMENT '3 days, 1 week …',
  `notes` varchar(300) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `prescription_symptoms`
--

INSERT INTO `prescription_symptoms` (`id`, `prescription_id`, `symptom_id`, `custom_symptom`, `severity`, `duration`, `notes`, `deleted_at`) VALUES
(1, 8, 1, NULL, 'moderate', '2 days', NULL, NULL),
(2, 8, 2, NULL, 'mild', NULL, NULL, NULL),
(3, 8, NULL, 'Eye pain', 'mild', NULL, NULL, NULL),
(4, 9, 1, NULL, 'moderate', '2 days', NULL, '2026-06-17 11:45:18'),
(5, 9, 2, NULL, 'mild', NULL, NULL, '2026-06-17 11:45:18'),
(6, 9, NULL, 'Eye pain', 'mild', NULL, NULL, '2026-06-17 11:45:18'),
(7, 10, 1, NULL, 'mild', '2 days', NULL, NULL),
(8, 11, 1, NULL, 'mild', '2 days', NULL, '2026-06-19 06:01:24'),
(9, 12, 1, NULL, 'moderate', '2 days', 'High fever', NULL),
(10, 12, 2, NULL, 'mild', '1 day', NULL, NULL),
(11, 12, NULL, 'Throat irritation', 'mild', NULL, NULL, NULL),
(12, 13, 1, NULL, 'moderate', '2 days', 'High fever', NULL),
(13, 13, 2, NULL, 'mild', '1 day', NULL, NULL),
(14, 13, NULL, 'Throat irritation', 'mild', NULL, NULL, NULL),
(15, 14, 1, NULL, 'moderate', '1 days', 'fever', NULL),
(16, 14, 2, NULL, 'mild', '1 day', NULL, NULL),
(17, 14, NULL, 'Throat irritation', 'mild', NULL, NULL, NULL),
(18, 15, 3, NULL, 'mild', '', '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `radiology_orders`
--

CREATE TABLE `radiology_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `prescription_id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `lab_partner_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Affiliated radiology center',
  `center_name` varchar(200) DEFAULT NULL,
  `center_address` varchar(500) DEFAULT NULL,
  `center_phone` varchar(20) DEFAULT NULL,
  `modality` enum('x-ray','ultrasound','ct_scan','mri','ecg','echo','endoscopy','pet_scan','mammography','dexa','other') NOT NULL DEFAULT 'x-ray',
  `body_part` varchar(200) DEFAULT NULL COMMENT 'Chest, Abdomen, Right Knee …',
  `view_position` varchar(100) DEFAULT NULL COMMENT 'AP, Lateral, Both … (X-Ray specific)',
  `contrast` tinyint(1) NOT NULL DEFAULT 0,
  `clinical_indication` text DEFAULT NULL,
  `urgency` enum('routine','urgent','stat') NOT NULL DEFAULT 'routine',
  `status` enum('ordered','report_ready','delivered','cancelled') NOT NULL DEFAULT 'ordered',
  `result_date` datetime DEFAULT NULL,
  `radiologist_report` text DEFAULT NULL,
  `impression` text DEFAULT NULL,
  `image_url` varchar(512) DEFAULT NULL COMMENT 'CDN / DICOM viewer link',
  `report_file_url` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(10) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `key` char(32) NOT NULL,
  `endpoints` varchar(255) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `hits` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `window_start` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`id`, `doctor_id`, `key`, `endpoints`, `ip`, `hits`, `window_start`, `updated_at`) VALUES
(32, NULL, '0453c88e43455039cb95498db1b0802f', 'NewAuth/register', '192.168.1.7', 6, '2026-06-15 06:18:19', '2026-06-16 12:20:41'),
(33, NULL, '3f8ec0f6ae69e6245cdda54560aaeb2c', 'NewAuth/login', '192.168.1.7', 27, '2026-06-15 06:19:43', '2026-06-16 13:24:39'),
(34, NULL, 'fefa3ff20d90b820e864199ed112a37d', 'NewAuth/login', '127.0.0.1', 13, '2026-06-17 05:18:29', '2026-07-01 06:53:50'),
(35, NULL, 'f5643c7b71156cdec604e9d5a5644508', 'NewAuth/login', '192.168.1.15', 24, '2026-06-18 10:38:04', '2026-07-08 06:09:47'),
(36, NULL, '60523c4856d67162b1aa491bbf81fcac', 'NewAuth/login', '192.168.43.1', 1, '2026-06-29 07:23:34', '2026-06-29 07:23:34'),
(37, NULL, '1c73d97634ca06138d43db48e8fa5326', 'NewAuth/login', '172.23.19.80', 1, '2026-07-01 06:17:15', '2026-07-01 06:17:15'),
(38, NULL, 'fc4a92934507d3ab6836f909f27b65a6', 'NewAuth/login', '192.168.1.13', 5, '2026-07-02 05:21:25', '2026-07-02 11:55:37'),
(39, NULL, '3320199faa41d139050dbc2ce04bb83f', 'NewAuth/login', '192.168.1.10', 4, '2026-07-06 05:05:50', '2026-07-07 05:01:35');

-- --------------------------------------------------------

--
-- Table structure for table `scheduled_jobs`
--

CREATE TABLE `scheduled_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `job_type` enum('followup_reminder','appointment_reminder_24h','appointment_reminder_1h','prescription_wa','report_wa','custom') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `run_at` datetime NOT NULL,
  `status` enum('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `symptoms`
--

CREATE TABLE `symptoms` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `name_hi` varchar(200) DEFAULT NULL COMMENT 'Hindi transliteration',
  `category` varchar(100) DEFAULT NULL COMMENT 'Respiratory, GI, Neurological …',
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `doctor_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = system; set = doctor-private',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `symptoms`
--

INSERT INTO `symptoms` (`id`, `name`, `name_hi`, `category`, `is_system`, `doctor_id`, `is_active`, `created_at`) VALUES
(1, 'Fever', 'Bukhar', 'General', 1, NULL, 1, '2026-06-15 18:07:13'),
(2, 'Headache', 'Sar dard', 'Neurological', 1, NULL, 1, '2026-06-15 18:07:13'),
(3, 'Cough', 'Khansi', 'Respiratory', 1, NULL, 1, '2026-06-15 18:07:13'),
(4, 'Cold / Runny Nose', 'Nazla / Zukam', 'Respiratory', 1, NULL, 1, '2026-06-15 18:07:13'),
(5, 'Sore Throat', 'Gale mein dard', 'ENT', 1, NULL, 1, '2026-06-15 18:07:13'),
(6, 'Breathlessness', 'Sans ki takleef', 'Respiratory', 1, NULL, 1, '2026-06-15 18:07:13'),
(7, 'Chest Pain', 'Seene mein dard', 'Cardiac', 1, NULL, 1, '2026-06-15 18:07:13'),
(8, 'Palpitations', 'Dil ki dhadkan', 'Cardiac', 1, NULL, 1, '2026-06-15 18:07:13'),
(9, 'Nausea', 'Ji michlana', 'Gastro', 1, NULL, 1, '2026-06-15 18:07:13'),
(10, 'Vomiting', 'Ulti', 'Gastro', 1, NULL, 1, '2026-06-15 18:07:13'),
(11, 'Diarrhoea', 'Dast', 'Gastro', 1, NULL, 1, '2026-06-15 18:07:13'),
(12, 'Constipation', 'Kabz', 'Gastro', 1, NULL, 1, '2026-06-15 18:07:13'),
(13, 'Abdominal Pain', 'Pet mein dard', 'Gastro', 1, NULL, 1, '2026-06-15 18:07:13'),
(14, 'Acidity / Heartburn', 'Amlapitta', 'Gastro', 1, NULL, 1, '2026-06-15 18:07:13'),
(15, 'Bloating', 'Afara', 'Gastro', 1, NULL, 1, '2026-06-15 18:07:13'),
(16, 'Loss of Appetite', 'Bhook na lagna', 'General', 1, NULL, 1, '2026-06-15 18:07:13'),
(17, 'Fatigue / Weakness', 'Thakaan', 'General', 1, NULL, 1, '2026-06-15 18:07:13'),
(18, 'Weight Loss', 'Vajan kam hona', 'General', 1, NULL, 1, '2026-06-15 18:07:13'),
(19, 'Dizziness', 'Chakkar aana', 'Neurological', 1, NULL, 1, '2026-06-15 18:07:13'),
(20, 'Body Ache', 'Badan dard', 'Musculoskeletal', 1, NULL, 1, '2026-06-15 18:07:13');

-- --------------------------------------------------------

--
-- Table structure for table `verification`
--

CREATE TABLE `verification` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `otp` varchar(6) DEFAULT NULL,
  `otp_expires` datetime DEFAULT NULL,
  `type` enum('mobile','email') NOT NULL,
  `send_to` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wa_notifications`
--

CREATE TABLE `wa_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` bigint(20) UNSIGNED NOT NULL,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `reference_type` enum('prescription','appointment','lab_order','radiology_order','custom') NOT NULL,
  `reference_id` bigint(20) UNSIGNED NOT NULL,
  `template_name` varchar(100) DEFAULT NULL COMMENT 'WhatsApp Business template ID',
  `mobile` varchar(15) NOT NULL,
  `message_body` text DEFAULT NULL,
  `status` enum('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
  `provider_msg_id` varchar(200) DEFAULT NULL COMMENT 'Meta / Twilio message ID',
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `failed_reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `advice_templates`
--
ALTER TABLE `advice_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_adv_cat` (`category`,`is_active`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_appt_rx` (`prescription_id`),
  ADD KEY `idx_appt_doctor_date` (`doctor_id`,`scheduled_at`,`status`),
  ADD KEY `idx_appt_patient` (`patient_id`,`scheduled_at`),
  ADD KEY `idx_appt_reminder_scan` (`scheduled_at`,`reminder_24h_sent`,`reminder_1h_sent`,`status`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`,`created_at`),
  ADD KEY `idx_audit_actor` (`actor_type`,`actor_id`,`created_at`);

--
-- Indexes for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_doctor_token` (`doctor_id`),
  ADD KEY `idx_token_lookup` (`refresh_token`(64));

--
-- Indexes for table `chronic_conditions`
--
ALTER TABLE `chronic_conditions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cond_cat` (`category`,`is_active`),
  ADD KEY `idx_cond_doctor` (`doctor_id`,`is_active`);
ALTER TABLE `chronic_conditions` ADD FULLTEXT KEY `ft_cond` (`name`,`name_hi`);

--
-- Indexes for table `clinics`
--
ALTER TABLE `clinics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_clinic_doctor` (`doctor_id`,`deleted_at`);

--
-- Indexes for table `clinic_contacts`
--
ALTER TABLE `clinic_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cc_clinic` (`clinic_id`,`show_on_rx`,`sort_order`);

--
-- Indexes for table `clinic_timings`
--
ALTER TABLE `clinic_timings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_clinic_day_shift` (`clinic_id`,`day_of_week`,`shift`),
  ADD KEY `idx_ct_clinic` (`clinic_id`,`day_of_week`);

--
-- Indexes for table `doctors`
--
ALTER TABLE `doctors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `mobile` (`mobile`),
  ADD UNIQUE KEY `registration_no` (`registration_no`),
  ADD KEY `idx_mobile` (`mobile`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_active` (`is_active`,`deleted_at`);

--
-- Indexes for table `lab_orders`
--
ALTER TABLE `lab_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lo_doctor` (`doctor_id`),
  ADD KEY `fk_lo_lab` (`lab_partner_id`),
  ADD KEY `idx_lo_rx` (`prescription_id`),
  ADD KEY `idx_lo_patient` (`patient_id`,`order_date`),
  ADD KEY `idx_lo_status` (`status`,`doctor_id`);

--
-- Indexes for table `lab_order_tests`
--
ALTER TABLE `lab_order_tests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lot_test` (`test_id`),
  ADD KEY `idx_lot_order` (`lab_order_id`);

--
-- Indexes for table `lab_partners`
--
ALTER TABLE `lab_partners`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lab_doctor` (`doctor_id`,`type`,`is_active`);

--
-- Indexes for table `lab_partner_contacts`
--
ALTER TABLE `lab_partner_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lpc_lab` (`lab_partner_id`,`is_primary`),
  ADD KEY `lab_partner_id` (`lab_partner_id`,`is_primary`);

--
-- Indexes for table `lab_tests_catalog`
--
ALTER TABLE `lab_tests_catalog`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ltc_name` (`name`,`is_active`);
ALTER TABLE `lab_tests_catalog` ADD FULLTEXT KEY `ft_ltc` (`name`,`short_code`);

--
-- Indexes for table `medicines`
--
ALTER TABLE `medicines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_med_cat_name` (`category_id`,`name`,`is_active`),
  ADD KEY `idx_med_doctor` (`doctor_id`,`is_active`);
ALTER TABLE `medicines` ADD FULLTEXT KEY `ft_med_name` (`name`,`generic_name`);

--
-- Indexes for table `medicine_categories`
--
ALTER TABLE `medicine_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_cat_active` (`is_active`,`sort_order`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_uhid` (`doctor_id`,`uhid`),
  ADD KEY `idx_pat_mobile` (`doctor_id`,`mobile`),
  ADD KEY `idx_pat_name` (`doctor_id`,`name`),
  ADD KEY `idx_pat_active` (`doctor_id`,`is_active`,`deleted_at`);

--
-- Indexes for table `patient_chronic_conditions`
--
ALTER TABLE `patient_chronic_conditions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pat_cond` (`patient_id`,`condition_id`),
  ADD KEY `fk_pcc_condition` (`condition_id`),
  ADD KEY `idx_pcc_patient` (`patient_id`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rx_clinic` (`clinic_id`),
  ADD KEY `idx_rx_patient_date` (`patient_id`,`visit_date`,`deleted_at`),
  ADD KEY `idx_rx_doctor_date` (`doctor_id`,`visit_date`,`deleted_at`),
  ADD KEY `idx_rx_followup` (`followup_date`,`wa_notif_sent`) COMMENT 'Cron scan';

--
-- Indexes for table `prescription_advice`
--
ALTER TABLE `prescription_advice`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_adv_rx` (`prescription_id`);

--
-- Indexes for table `prescription_medicines`
--
ALTER TABLE `prescription_medicines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rxm_med` (`medicine_id`),
  ADD KEY `idx_rxm_rx` (`prescription_id`,`sort_order`);

--
-- Indexes for table `prescription_symptoms`
--
ALTER TABLE `prescription_symptoms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_psx_sym` (`symptom_id`),
  ADD KEY `idx_psx_rx` (`prescription_id`);

--
-- Indexes for table `radiology_orders`
--
ALTER TABLE `radiology_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rad_doctor` (`doctor_id`),
  ADD KEY `idx_rad_rx` (`prescription_id`),
  ADD KEY `idx_rad_patient` (`patient_id`,`created_at`),
  ADD KEY `idx_rad_status` (`status`,`doctor_id`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`),
  ADD KEY `idx_rl_key` (`key`),
  ADD KEY `idx_rl_updated_at` (`updated_at`);

--
-- Indexes for table `scheduled_jobs`
--
ALTER TABLE `scheduled_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sj_scan` (`status`,`run_at`),
  ADD KEY `idx_sj_ref` (`reference_type`,`reference_id`);

--
-- Indexes for table `symptoms`
--
ALTER TABLE `symptoms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sym_doctor` (`doctor_id`),
  ADD KEY `idx_sym_cat` (`category`,`is_active`);
ALTER TABLE `symptoms` ADD FULLTEXT KEY `ft_sym` (`name`,`name_hi`);

--
-- Indexes for table `verification`
--
ALTER TABLE `verification`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `wa_notifications`
--
ALTER TABLE `wa_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wan_ref` (`reference_type`,`reference_id`),
  ADD KEY `idx_wan_status` (`status`,`created_at`),
  ADD KEY `idx_wan_patient` (`patient_id`,`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `advice_templates`
--
ALTER TABLE `advice_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `chronic_conditions`
--
ALTER TABLE `chronic_conditions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `clinics`
--
ALTER TABLE `clinics`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `clinic_contacts`
--
ALTER TABLE `clinic_contacts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `clinic_timings`
--
ALTER TABLE `clinic_timings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `doctors`
--
ALTER TABLE `doctors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `lab_orders`
--
ALTER TABLE `lab_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_order_tests`
--
ALTER TABLE `lab_order_tests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_partners`
--
ALTER TABLE `lab_partners`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `lab_partner_contacts`
--
ALTER TABLE `lab_partner_contacts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `lab_tests_catalog`
--
ALTER TABLE `lab_tests_catalog`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `medicines`
--
ALTER TABLE `medicines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `medicine_categories`
--
ALTER TABLE `medicine_categories`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `patient_chronic_conditions`
--
ALTER TABLE `patient_chronic_conditions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prescriptions`
--
ALTER TABLE `prescriptions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `prescription_advice`
--
ALTER TABLE `prescription_advice`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `prescription_medicines`
--
ALTER TABLE `prescription_medicines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `prescription_symptoms`
--
ALTER TABLE `prescription_symptoms`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `radiology_orders`
--
ALTER TABLE `radiology_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `scheduled_jobs`
--
ALTER TABLE `scheduled_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `symptoms`
--
ALTER TABLE `symptoms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `verification`
--
ALTER TABLE `verification`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `wa_notifications`
--
ALTER TABLE `wa_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appt_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`),
  ADD CONSTRAINT `fk_appt_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `fk_appt_rx` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`);

--
-- Constraints for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD CONSTRAINT `fk_token_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`);

--
-- Constraints for table `chronic_conditions`
--
ALTER TABLE `chronic_conditions`
  ADD CONSTRAINT `fk_cc_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`);

--
-- Constraints for table `clinics`
--
ALTER TABLE `clinics`
  ADD CONSTRAINT `fk_clinic_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`);

--
-- Constraints for table `clinic_contacts`
--
ALTER TABLE `clinic_contacts`
  ADD CONSTRAINT `fk_cc_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`);

--
-- Constraints for table `clinic_timings`
--
ALTER TABLE `clinic_timings`
  ADD CONSTRAINT `fk_ct_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`);

--
-- Constraints for table `lab_orders`
--
ALTER TABLE `lab_orders`
  ADD CONSTRAINT `fk_lo_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`),
  ADD CONSTRAINT `fk_lo_lab` FOREIGN KEY (`lab_partner_id`) REFERENCES `lab_partners` (`id`),
  ADD CONSTRAINT `fk_lo_pat` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `fk_lo_rx` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`);

--
-- Constraints for table `lab_order_tests`
--
ALTER TABLE `lab_order_tests`
  ADD CONSTRAINT `fk_lot_order` FOREIGN KEY (`lab_order_id`) REFERENCES `lab_orders` (`id`),
  ADD CONSTRAINT `fk_lot_test` FOREIGN KEY (`test_id`) REFERENCES `lab_tests_catalog` (`id`);

--
-- Constraints for table `lab_partners`
--
ALTER TABLE `lab_partners`
  ADD CONSTRAINT `fk_lab_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`);

--
-- Constraints for table `lab_partner_contacts`
--
ALTER TABLE `lab_partner_contacts`
  ADD CONSTRAINT `fk_lpc_lab` FOREIGN KEY (`lab_partner_id`) REFERENCES `lab_partners` (`id`);

--
-- Constraints for table `medicines`
--
ALTER TABLE `medicines`
  ADD CONSTRAINT `fk_med_cat` FOREIGN KEY (`category_id`) REFERENCES `medicine_categories` (`id`),
  ADD CONSTRAINT `fk_med_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`);

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `fk_pat_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`);

--
-- Constraints for table `patient_chronic_conditions`
--
ALTER TABLE `patient_chronic_conditions`
  ADD CONSTRAINT `fk_pcc_condition` FOREIGN KEY (`condition_id`) REFERENCES `chronic_conditions` (`id`),
  ADD CONSTRAINT `fk_pcc_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`);

--
-- Constraints for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD CONSTRAINT `fk_rx_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`),
  ADD CONSTRAINT `fk_rx_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`),
  ADD CONSTRAINT `fk_rx_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`);

--
-- Constraints for table `prescription_advice`
--
ALTER TABLE `prescription_advice`
  ADD CONSTRAINT `fk_adv_rx` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`);

--
-- Constraints for table `prescription_medicines`
--
ALTER TABLE `prescription_medicines`
  ADD CONSTRAINT `fk_rxm_med` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`),
  ADD CONSTRAINT `fk_rxm_rx` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`);

--
-- Constraints for table `prescription_symptoms`
--
ALTER TABLE `prescription_symptoms`
  ADD CONSTRAINT `fk_psx_rx` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`),
  ADD CONSTRAINT `fk_psx_sym` FOREIGN KEY (`symptom_id`) REFERENCES `symptoms` (`id`);

--
-- Constraints for table `radiology_orders`
--
ALTER TABLE `radiology_orders`
  ADD CONSTRAINT `fk_rad_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`),
  ADD CONSTRAINT `fk_rad_pat` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `fk_rad_rx` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`);

--
-- Constraints for table `symptoms`
--
ALTER TABLE `symptoms`
  ADD CONSTRAINT `fk_sym_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
