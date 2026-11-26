CREATE TABLE IF NOT EXISTS `plants` (
  `id` CHAR(36) NOT NULL,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `species` VARCHAR(120) NULL DEFAULT NULL,
  `location` VARCHAR(200) NULL DEFAULT NULL,
  `planted_on` DATE NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plants_user_plant` (`user_id`, `id`),
  KEY `idx_plants_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_plants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plant_analysis_history` (
  `id` CHAR(36) NOT NULL,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `plant_id` CHAR(36) NULL DEFAULT NULL,
  `plant_name` VARCHAR(120) NULL DEFAULT NULL,
  `health_status` VARCHAR(32) NOT NULL,
  `confidence` VARCHAR(16) NOT NULL DEFAULT 'Unknown',
  `severity` VARCHAR(16) NULL DEFAULT NULL,
  `detected_issue` VARCHAR(200) NULL DEFAULT NULL,
  `visible_symptoms` JSON NULL DEFAULT NULL,
  `possible_causes` JSON NULL DEFAULT NULL,
  `recommendations` JSON NULL DEFAULT NULL,
  `watering_advice` TEXT NULL DEFAULT NULL,
  `prevention_tips` JSON NULL DEFAULT NULL,
  `image_path` VARCHAR(255) NULL DEFAULT NULL,
  `image_mime_type` ENUM('image/jpeg', 'image/png', 'image/webp') NULL DEFAULT NULL,
  `image_quality` VARCHAR(40) NOT NULL DEFAULT 'Good',
  `analysis_result` JSON NULL DEFAULT NULL,
  `leaf_count` INT UNSIGNED NULL DEFAULT NULL,
  `plant_size_cm` DECIMAL(8,2) NULL DEFAULT NULL,
  `plant_height_cm` DECIMAL(8,2) NULL DEFAULT NULL,
  `additional_notes` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plant_analysis_history_user_created` (`user_id`, `created_at`),
  KEY `idx_plant_analysis_history_plant_created` (`plant_id`, `created_at`),
  CONSTRAINT `fk_plant_analysis_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_plant_analysis_history_plant` FOREIGN KEY (`user_id`, `plant_id`) REFERENCES `plants` (`user_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @plant_monitoring_column = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND column_name = 'plant_id');
SET @plant_monitoring_sql = IF(@plant_monitoring_column = 0, 'ALTER TABLE plant_analysis_history ADD COLUMN plant_id CHAR(36) NULL DEFAULT NULL AFTER user_id', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;

SET @plant_monitoring_column = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND column_name = 'severity');
SET @plant_monitoring_sql = IF(@plant_monitoring_column = 0, 'ALTER TABLE plant_analysis_history ADD COLUMN severity VARCHAR(16) NULL DEFAULT NULL AFTER confidence', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;

SET @plant_monitoring_column = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND column_name = 'detected_issue');
SET @plant_monitoring_sql = IF(@plant_monitoring_column = 0, 'ALTER TABLE plant_analysis_history ADD COLUMN detected_issue VARCHAR(200) NULL DEFAULT NULL AFTER severity', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;

SET @plant_monitoring_column = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND column_name = 'image_mime_type');
SET @plant_monitoring_sql = IF(@plant_monitoring_column = 0, 'ALTER TABLE plant_analysis_history ADD COLUMN image_mime_type ENUM(''image/jpeg'', ''image/png'', ''image/webp'') NULL DEFAULT NULL AFTER image_path', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;

SET @plant_monitoring_column = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND column_name = 'analysis_result');
SET @plant_monitoring_sql = IF(@plant_monitoring_column = 0, 'ALTER TABLE plant_analysis_history ADD COLUMN analysis_result JSON NULL DEFAULT NULL AFTER image_quality', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;

SET @plant_monitoring_column = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND column_name = 'leaf_count');
SET @plant_monitoring_sql = IF(@plant_monitoring_column = 0, 'ALTER TABLE plant_analysis_history ADD COLUMN leaf_count INT UNSIGNED NULL DEFAULT NULL AFTER analysis_result', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;

SET @plant_monitoring_column = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND column_name = 'plant_size_cm');
SET @plant_monitoring_sql = IF(@plant_monitoring_column = 0, 'ALTER TABLE plant_analysis_history ADD COLUMN plant_size_cm DECIMAL(8,2) NULL DEFAULT NULL AFTER leaf_count', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;

SET @plant_monitoring_column = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND column_name = 'plant_height_cm');
SET @plant_monitoring_sql = IF(@plant_monitoring_column = 0, 'ALTER TABLE plant_analysis_history ADD COLUMN plant_height_cm DECIMAL(8,2) NULL DEFAULT NULL AFTER plant_size_cm', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;

ALTER TABLE `plant_analysis_history`
  MODIFY COLUMN `plant_name` VARCHAR(120) NULL DEFAULT NULL,
  MODIFY COLUMN `health_status` VARCHAR(32) NOT NULL,
  MODIFY COLUMN `confidence` VARCHAR(16) NOT NULL DEFAULT 'Unknown';

SET @plant_monitoring_constraint = (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_plant_analysis_history_plant');
SET @plant_monitoring_sql = IF(@plant_monitoring_constraint = 0, 'ALTER TABLE plant_analysis_history ADD CONSTRAINT fk_plant_analysis_history_plant FOREIGN KEY (user_id, plant_id) REFERENCES plants (user_id, id) ON UPDATE CASCADE ON DELETE RESTRICT', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;

SET @plant_monitoring_index = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'plant_analysis_history' AND index_name = 'idx_plant_analysis_history_plant_created');
SET @plant_monitoring_sql = IF(@plant_monitoring_index = 0, 'CREATE INDEX idx_plant_analysis_history_plant_created ON plant_analysis_history (plant_id, created_at)', 'SELECT 1');
PREPARE plant_monitoring_statement FROM @plant_monitoring_sql;
EXECUTE plant_monitoring_statement;
DEALLOCATE PREPARE plant_monitoring_statement;
