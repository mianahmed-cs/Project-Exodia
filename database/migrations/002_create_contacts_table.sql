-- Project Exodia — Migration 002: Contacts Table
-- Phase 2: User Discovery & Contact Management
-- 
-- This migration adds the contacts table for managing user relationships.
-- Run AFTER the initial schema (001 = schema.sql) has been applied.
-- Existing users and authentication data are fully preserved.

USE `project_exodia`;

-- Contacts Table
-- Stores directional contact relationships between registered users.
-- user_id = the person who added the contact
-- contact_id = the person being added as a contact
CREATE TABLE IF NOT EXISTS `contacts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'The user who owns this contact',
  `contact_id` INT UNSIGNED NOT NULL COMMENT 'The user added as a contact',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Prevent duplicate contacts: a user can only add another user once
  CONSTRAINT `uq_contacts_pair` UNIQUE (`user_id`, `contact_id`),

  -- Foreign keys: both sides must reference valid registered users
  CONSTRAINT `fk_contacts_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_contacts_contact` FOREIGN KEY (`contact_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

  -- Index for retrieving a user's contact list efficiently
  INDEX `idx_contacts_user_id` (`user_id`),
  INDEX `idx_contacts_contact_id` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
