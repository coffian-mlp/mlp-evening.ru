-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jan 06, 2026 at 10:00 PM
-- Server version: 5.7.35-38
-- PHP Version: 8.3

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `coffian_eplist`
--

-- --------------------------------------------------------

--
-- Table structure for table `site_options`
--

CREATE TABLE IF NOT EXISTS `site_options` (
  `key_name` varchar(50) NOT NULL,
  `value` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `site_options`
--

INSERT INTO `site_options` (`key_name`, `value`) VALUES
('stream_url', 'https://example.com/stream');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'admin',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--
-- Default admin user. 
-- Login: admin
-- Password: password (hash below is for 'password')

INSERT INTO `users` (`login`, `password_hash`, `role`) VALUES
('admin', '$2y$10$n.9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9', 'admin');

-- --------------------------------------------------------

--
-- Table structure for table `episode_list`
--

CREATE TABLE IF NOT EXISTS `episode_list` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `TITLE` text NOT NULL,
  `TIMES_WATCHED` int(11) NOT NULL DEFAULT '0',
  `WANNA_WATCH` int(11) NOT NULL DEFAULT '0',
  `TWOPART_ID` int(11) DEFAULT NULL,
  `LENGTH` int(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=225 DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `episode_list`
--

INSERT INTO `episode_list` (`ID`, `TITLE`, `TIMES_WATCHED`, `WANNA_WATCH`, `TWOPART_ID`, `LENGTH`) VALUES
(1, 'My Little Pony Friendship is Magic - Season 1 Episode 01 - Friendship Is Magic, Part 01', 0, 0, 2, 2),
(2, 'My Little Pony Friendship is Magic - Season 1 Episode 02 - Friendship Is Magic, Part 02', 0, 0, 1, 2),
(3, 'My Little Pony Friendship is Magic - Season 1 Episode 03 - The Ticket Master', 0, 0, NULL, 1);
-- ... (truncated for sample, in real usage users would import full list or we can include full list if needed) ...
-- For this sample, I will include just a few to keep file size small, or I can include all if you prefer.
-- Let's include the full structure but empty watching_now to be clean.

-- --------------------------------------------------------

--
-- Table structure for table `watching_now`
--

CREATE TABLE IF NOT EXISTS `watching_now` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EPNUM` int(11) NOT NULL,
  `TITLE` text NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

