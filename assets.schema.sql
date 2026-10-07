-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Oct 07, 2026 at 01:49 PM
-- Server version: 8.0.46-0ubuntu0.22.04.4
-- PHP Version: 8.1.2-1ubuntu2.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `assets`
--

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `asset_id` int UNSIGNED NOT NULL,
  `asset_number` varchar(32) NOT NULL,
  `serial_number` varchar(32) NOT NULL,
  `type_id` int UNSIGNED NOT NULL,
  `po_number` varchar(32) NOT NULL,
  `cost` decimal(10,2) UNSIGNED NOT NULL,
  `purchaser_id` int UNSIGNED NOT NULL,
  `purchase_date` datetime NOT NULL,
  `department_id` int UNSIGNED NOT NULL,
  `building_id` int UNSIGNED NOT NULL,
  `room` varchar(8) NOT NULL,
  `description` varchar(64) NOT NULL,
  `verified_date` datetime DEFAULT NULL,
  `notes` varchar(10240) NOT NULL,
  `user_id` varchar(16) NOT NULL,
  `created_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `asset_types`
--

CREATE TABLE `asset_types` (
  `type_id` int UNSIGNED NOT NULL,
  `name` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `depreciation_years` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `buildings`
--

CREATE TABLE `buildings` (
  `building_id` int UNSIGNED NOT NULL,
  `abbr` varchar(4) NOT NULL,
  `name` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int UNSIGNED NOT NULL,
  `abbr` varchar(6) NOT NULL,
  `name` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `user_id` varchar(16) NOT NULL,
  `department_id` int UNSIGNED NOT NULL,
  `permission` enum('r','rw') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `tassets`
--

CREATE TABLE `tassets` (
  `id` int UNSIGNED NOT NULL,
  `asset_number` varchar(32) NOT NULL,
  `serial_number` varchar(32) NOT NULL,
  `department` varchar(32) NOT NULL,
  `building` varchar(32) NOT NULL,
  `room` varchar(32) NOT NULL,
  `purchaser` varchar(32) NOT NULL,
  `description` varchar(64) NOT NULL,
  `acq_date` varchar(32) NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `user` varchar(32) NOT NULL,
  `ram` varchar(32) NOT NULL,
  `hd` varchar(32) NOT NULL,
  `pro` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `transfers`
--

CREATE TABLE `transfers` (
  `transfer_id` int UNSIGNED NOT NULL,
  `asset_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `department_from` varchar(6) NOT NULL,
  `department_to` varchar(6) NOT NULL,
  `location_from` varchar(16) NOT NULL,
  `location_to` varchar(16) NOT NULL,
  `reason` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `transfer_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` varchar(16) NOT NULL,
  `username` varchar(64) NOT NULL,
  `password` varchar(40) NOT NULL DEFAULT '713cc24cb856455d3a9f9138d8f322c4',
  `firstname` varchar(32) NOT NULL,
  `lastname` varchar(32) NOT NULL,
  `email` varchar(128) NOT NULL,
  `department_id` int NOT NULL,
  `admin` tinyint(1) NOT NULL DEFAULT '0',
  `timezone` varchar(32) NOT NULL,
  `lastlogin` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`asset_id`),
  ADD UNIQUE KEY `asset_number` (`asset_number`),
  ADD KEY `assets` (`asset_number`,`purchaser_id`,`department_id`,`building_id`);

--
-- Indexes for table `asset_types`
--
ALTER TABLE `asset_types`
  ADD PRIMARY KEY (`type_id`);

--
-- Indexes for table `buildings`
--
ALTER TABLE `buildings`
  ADD PRIMARY KEY (`building_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`user_id`,`department_id`);

--
-- Indexes for table `tassets`
--
ALTER TABLE `tassets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transfers`
--
ALTER TABLE `transfers`
  ADD PRIMARY KEY (`transfer_id`),
  ADD KEY `asset_id` (`asset_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `asset_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `asset_types`
--
ALTER TABLE `asset_types`
  MODIFY `type_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `buildings`
--
ALTER TABLE `buildings`
  MODIFY `building_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tassets`
--
ALTER TABLE `tassets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transfers`
--
ALTER TABLE `transfers`
  MODIFY `transfer_id` int UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
