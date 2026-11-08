-- Project Exodia — Database Schema
-- Character Set: utf8mb4 (Full Unicode / Emoji support)
-- Collation: utf8mb4_unicode_ci

CREATE DATABASE IF NOT EXISTS `project_exodia`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `project_exodia`;

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(30) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `uq_users_username` UNIQUE (`username`),
  CONSTRAINT `uq_users_email` UNIQUE (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
