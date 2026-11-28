SET @care_column = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'related_plant_id'
);
SET @care_sql = IF(@care_column = 0, 'ALTER TABLE notifications ADD COLUMN related_plant_id CHAR(36) NULL AFTER related_record_id', 'SELECT 1');
PREPARE care_statement FROM @care_sql;
EXECUTE care_statement;
DEALLOCATE PREPARE care_statement;

SET @care_column = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'scheduled_at'
);
SET @care_sql = IF(@care_column = 0, 'ALTER TABLE notifications ADD COLUMN scheduled_at DATETIME NULL AFTER related_plant_id', 'SELECT 1');
PREPARE care_statement FROM @care_sql;
EXECUTE care_statement;
DEALLOCATE PREPARE care_statement;

SET @care_column = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'is_completed'
);
SET @care_sql = IF(@care_column = 0, 'ALTER TABLE notifications ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read', 'SELECT 1');
PREPARE care_statement FROM @care_sql;
EXECUTE care_statement;
DEALLOCATE PREPARE care_statement;

SET @care_column = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'dismissed_at'
);
SET @care_sql = IF(@care_column = 0, 'ALTER TABLE notifications ADD COLUMN dismissed_at DATETIME NULL AFTER is_completed', 'SELECT 1');
PREPARE care_statement FROM @care_sql;
EXECUTE care_statement;
DEALLOCATE PREPARE care_statement;

SET @care_index = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'notifications' AND index_name = 'idx_notifications_care_due'
);
SET @care_sql = IF(@care_index = 0, 'CREATE INDEX idx_notifications_care_due ON notifications (user_id, scheduled_at, dismissed_at)', 'SELECT 1');
PREPARE care_statement FROM @care_sql;
EXECUTE care_statement;
DEALLOCATE PREPARE care_statement;

SET @care_index = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND index_name = 'uq_plant_analysis_user_scan'
);
SET @care_sql = IF(@care_index = 0, 'CREATE UNIQUE INDEX uq_plant_analysis_user_scan ON plant_analysis_history (user_id, id)', 'SELECT 1');
PREPARE care_statement FROM @care_sql;
EXECUTE care_statement;
DEALLOCATE PREPARE care_statement;

CREATE TABLE IF NOT EXISTS `fertilizer_recommendations` (
  `id` CHAR(36) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plant_id` CHAR(36) NOT NULL,
  `source_scan_id` CHAR(36) NOT NULL,
  `context_hash` CHAR(64) NOT NULL,
  `decision` ENUM('apply', 'not_needed', 'review') NOT NULL,
  `fertilizer_category` ENUM('organic', 'compost', 'balanced_npk', 'nitrogen_rich', 'crop_specific', 'none') NOT NULL,
  `nutrient_stress` ENUM('not_indicated', 'possible') NOT NULL,
  `confidence` ENUM('low', 'moderate', 'high') NOT NULL,
  `recommended_on` DATE NULL,
  `guidance` VARCHAR(1200) NOT NULL,
  `reason` VARCHAR(1200) NOT NULL,
  `warnings` JSON NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fertilizer_recommendation_context` (`user_id`, `plant_id`, `context_hash`),
  UNIQUE KEY `uq_fertilizer_recommendation_owner` (`user_id`, `plant_id`, `id`),
  KEY `idx_fertilizer_recommendations_latest` (`user_id`, `plant_id`, `created_at`),
  CONSTRAINT `fk_fertilizer_recommendations_plant` FOREIGN KEY (`user_id`, `plant_id`) REFERENCES `plants` (`user_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_fertilizer_recommendations_scan` FOREIGN KEY (`user_id`, `source_scan_id`) REFERENCES `plant_analysis_history` (`user_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fertilizer_tasks` (
  `id` CHAR(36) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plant_id` CHAR(36) NOT NULL,
  `recommendation_id` CHAR(36) NULL,
  `scheduled_on` DATE NOT NULL,
  `scheduled_time` TIME NOT NULL DEFAULT '08:00:00',
  `fertilizer_category` ENUM('organic', 'compost', 'balanced_npk', 'nitrogen_rich', 'crop_specific', 'other') NOT NULL,
  `guidance` VARCHAR(1200) NOT NULL,
  `status` ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  `product_name` VARCHAR(160) NULL,
  `applied_amount` VARCHAR(100) NULL,
  `completed_at` DATETIME NULL,
  `reminder_sent_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fertilizer_task_recommendation` (`user_id`, `plant_id`, `recommendation_id`),
  KEY `idx_fertilizer_tasks_user_date_status` (`user_id`, `scheduled_on`, `status`),
  KEY `idx_fertilizer_tasks_plant_status` (`user_id`, `plant_id`, `status`, `scheduled_on`),
  KEY `idx_fertilizer_tasks_reminders` (`user_id`, `status`, `scheduled_on`, `reminder_sent_at`),
  CONSTRAINT `fk_fertilizer_tasks_plant` FOREIGN KEY (`user_id`, `plant_id`) REFERENCES `plants` (`user_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_fertilizer_tasks_recommendation` FOREIGN KEY (`user_id`, `plant_id`, `recommendation_id`) REFERENCES `fertilizer_recommendations` (`user_id`, `plant_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @care_column = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'farm_activities' AND column_name = 'care_task_id'
);
SET @care_sql = IF(@care_column = 0, 'ALTER TABLE farm_activities ADD COLUMN care_task_id CHAR(36) NULL AFTER plant_id', 'SELECT 1');
PREPARE care_statement FROM @care_sql;
EXECUTE care_statement;
DEALLOCATE PREPARE care_statement;

SET @care_index = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'farm_activities' AND index_name = 'uq_farm_activities_care_task'
);
SET @care_sql = IF(@care_index = 0, 'CREATE UNIQUE INDEX uq_farm_activities_care_task ON farm_activities (care_task_id)', 'SELECT 1');
PREPARE care_statement FROM @care_sql;
EXECUTE care_statement;
DEALLOCATE PREPARE care_statement;
