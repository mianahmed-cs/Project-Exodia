-- Project Exodia — Migration 005: File & Photo Attachments Table
-- Features: Photo Sharing (JPEG, PNG, WebP, GIF) and Document Sharing (PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT)
--
-- Non-destructive migration preserving all existing conversations, messages, and relationships.
-- Compatible with MySQL 8.0+

USE `project_exodia`;

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

