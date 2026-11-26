CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` CHAR(36) NOT NULL,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `plant_id` CHAR(36) NULL DEFAULT NULL,
  `title` VARCHAR(120) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_conversations_user_id` (`user_id`, `id`),
  KEY `idx_ai_conversations_user_updated` (`user_id`, `updated_at`),
  CONSTRAINT `fk_ai_conversations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_ai_conversations_plant` FOREIGN KEY (`user_id`, `plant_id`) REFERENCES `plants` (`user_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `conversation_id` CHAR(36) NOT NULL,
  `role` ENUM('user', 'assistant') NOT NULL,
  `content` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_chat_messages_user_conversation` (`user_id`, `conversation_id`, `id`),
  CONSTRAINT `fk_ai_chat_messages_conversation` FOREIGN KEY (`user_id`, `conversation_id`)
    REFERENCES `ai_conversations` (`user_id`, `id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
