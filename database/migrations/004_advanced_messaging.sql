-- Project Exodia — Migration 004: Advanced Messaging Suite
-- Features: Message Deletion (For Me & For Everyone), Quoted Replies, Emoji Reactions, Activity Events
--
-- Non-destructive migration preserving all existing conversations and messages.
-- Compatible with MySQL 8.0+

USE `project_exodia`;

-- 1. Alter messages table to support Delete for Everyone and Quoted Replies
SET @dbname = DATABASE();
SET @tablename = 'messages';

-- Add is_deleted_for_everyone if not present
SET @colname = 'is_deleted_for_everyone';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @colname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `messages` ADD COLUMN `is_deleted_for_everyone` TINYINT(1) NOT NULL DEFAULT 0 AFTER `body`'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add deleted_at if not present
SET @colname = 'deleted_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @colname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `messages` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `is_deleted_for_everyone`'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add reply_to_message_id if not present
SET @colname = 'reply_to_message_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @colname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `messages` ADD COLUMN `reply_to_message_id` INT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`, ADD INDEX `idx_msg_reply_to` (`reply_to_message_id`)'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add foreign key constraint for reply_to_message_id if not present
SET @fkname = 'fk_msg_reply_to';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND CONSTRAINT_NAME = @fkname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `messages` ADD CONSTRAINT `fk_msg_reply_to` FOREIGN KEY (`reply_to_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Per-user hidden messages table (Delete for Me)
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

-- 3. Message emoji reactions table
CREATE TABLE IF NOT EXISTS `message_reactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `message_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `emoji` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Enforce one reaction per user per message
  CONSTRAINT `uq_message_reaction_user` UNIQUE (`message_id`, `user_id`),
  CONSTRAINT `fk_mr_message` FOREIGN KEY (`message_id`)
    REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mr_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

  INDEX `idx_mr_message` (`message_id`),
  INDEX `idx_mr_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Activity events feed table for real-time synchronization
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
