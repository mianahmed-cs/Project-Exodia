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

-- Contacts Table (Phase 2)
-- Stores directional contact relationships between registered users.
CREATE TABLE IF NOT EXISTS `contacts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'The user who owns this contact',
  `contact_id` INT UNSIGNED NOT NULL COMMENT 'The user added as a contact',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `uq_contacts_pair` UNIQUE (`user_id`, `contact_id`),
  CONSTRAINT `fk_contacts_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_contacts_contact` FOREIGN KEY (`contact_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_contacts_user_id` (`user_id`),
  INDEX `idx_contacts_contact_id` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversations Table (Phase 3)
CREATE TABLE IF NOT EXISTS `conversations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_conversations_updated` (`updated_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversation Participants (Phase 3)
CREATE TABLE IF NOT EXISTS `conversation_participants` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `uq_conv_participant` UNIQUE (`conversation_id`, `user_id`),
  CONSTRAINT `fk_cp_conversation` FOREIGN KEY (`conversation_id`)
    REFERENCES `conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cp_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_cp_user` (`user_id`),
  INDEX `idx_cp_conversation` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages Table (Phase 3 & Advanced Messaging)
CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT UNSIGNED NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `is_deleted_for_everyone` TINYINT(1) NOT NULL DEFAULT 0,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `reply_to_message_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_msg_conversation` FOREIGN KEY (`conversation_id`)
    REFERENCES `conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_msg_reply_to` FOREIGN KEY (`reply_to_message_id`)
    REFERENCES `messages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_msg_conversation_created` (`conversation_id`, `created_at` ASC),
  INDEX `idx_msg_sender` (`sender_id`),
  INDEX `idx_msg_reply_to` (`reply_to_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Hidden Messages Table (Delete for Me)
CREATE TABLE IF NOT EXISTS `user_hidden_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `message_id` INT UNSIGNED NOT NULL,
  `hidden_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `uq_user_hidden_message` UNIQUE (`user_id`, `message_id`),
  CONSTRAINT `fk_uhm_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_uhm_message` FOREIGN KEY (`message_id`)
    REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_uhm_user` (`user_id`),
  INDEX `idx_uhm_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Reactions Table (Emoji Reactions)
CREATE TABLE IF NOT EXISTS `message_reactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `message_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `emoji` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `uq_message_reaction_user` UNIQUE (`message_id`, `user_id`),
  CONSTRAINT `fk_mr_message` FOREIGN KEY (`message_id`)
    REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mr_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_mr_message` (`message_id`),
  INDEX `idx_mr_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversation Activity Events Feed Table (Real-Time Synchronization)
CREATE TABLE IF NOT EXISTS `conversation_events` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(32) NOT NULL,
  `message_id` INT UNSIGNED NOT NULL,
  `actor_id` INT UNSIGNED NOT NULL,
  `payload` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ce_conversation` FOREIGN KEY (`conversation_id`)
    REFERENCES `conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ce_message` FOREIGN KEY (`message_id`)
    REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ce_actor` FOREIGN KEY (`actor_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_ce_conv_event` (`conversation_id`, `id`),
  INDEX `idx_ce_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachments Table (Photo & Document Sharing)
CREATE TABLE IF NOT EXISTS `attachments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `message_id` INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `storage_filename` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `file_type` ENUM('image', 'document') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_att_message` FOREIGN KEY (`message_id`)
    REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_att_conversation` FOREIGN KEY (`conversation_id`)
    REFERENCES `conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_att_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_att_message` (`message_id`),
  INDEX `idx_att_conversation` (`conversation_id`),
  INDEX `idx_att_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
