CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `notification_type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `message` TEXT NOT NULL,
  `related_module` VARCHAR(50) NOT NULL,
  `related_record_id` CHAR(36) NULL DEFAULT NULL,
  `event_key` VARCHAR(191) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notifications_user_event` (`user_id`, `event_key`),
  KEY `idx_notifications_user_unread_created` (`user_id`, `is_read`, `created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `watering_plans` (
  `id` CHAR(36) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plant_id` CHAR(36) NOT NULL,
  `container_volume_l` DECIMAL(8,2) NULL,
  `growing_area_m2` DECIMAL(9,3) NULL,
  `soil_type` VARCHAR(80) NULL,
  `soil_moisture_percent` DECIMAL(5,2) NULL,
  `soil_moisture_recorded_at` DATETIME NULL,
  `custom_amount_ml` INT UNSIGNED NULL,
  `custom_frequency_days` SMALLINT UNSIGNED NULL,
  `reminder_time` TIME NULL,
  `needs_reassessment` TINYINT(1) NOT NULL DEFAULT 1,
  `current_recommendation_id` CHAR(36) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_watering_plans_user_plant` (`user_id`, `plant_id`),
  KEY `idx_watering_plans_reassessment` (`user_id`, `needs_reassessment`),
  CONSTRAINT `fk_watering_plans_plant` FOREIGN KEY (`user_id`, `plant_id`) REFERENCES `plants` (`user_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `chk_watering_soil_moisture` CHECK (`soil_moisture_percent` IS NULL OR `soil_moisture_percent` BETWEEN 0 AND 100),
  CONSTRAINT `chk_watering_container_volume` CHECK (`container_volume_l` IS NULL OR `container_volume_l` > 0),
  CONSTRAINT `chk_watering_growing_area` CHECK (`growing_area_m2` IS NULL OR `growing_area_m2` > 0),
  CONSTRAINT `chk_watering_custom_frequency` CHECK (`custom_frequency_days` IS NULL OR `custom_frequency_days` BETWEEN 1 AND 30),
  CONSTRAINT `chk_watering_custom_amount` CHECK (`custom_amount_ml` IS NULL OR `custom_amount_ml` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `watering_recommendations` (
  `id` CHAR(36) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plant_id` CHAR(36) NOT NULL,
  `source_scan_id` CHAR(36) NOT NULL,
  `source_weather_at` DATETIME NULL,
  `decision` ENUM('water_now', 'check_soil', 'not_needed') NOT NULL,
  `evidence_status` ENUM('recent_scan', 'previous_scan', 'awaiting_soil_confirmation') NOT NULL,
  `confidence` ENUM('low', 'moderate', 'high') NOT NULL,
  `frequency_days` SMALLINT UNSIGNED NOT NULL,
  `amount_min_ml` INT UNSIGNED NULL,
  `amount_max_ml` INT UNSIGNED NULL,
  `recommended_time` TIME NOT NULL,
  `first_scheduled_on` DATE NULL,
  `reason` VARCHAR(1200) NOT NULL,
  `soil_check_instruction` VARCHAR(500) NOT NULL,
  `warnings` JSON NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_watering_recommendations_owner` (`user_id`, `plant_id`, `id`),
  KEY `idx_watering_recommendations_plant_created` (`user_id`, `plant_id`, `created_at`),
  CONSTRAINT `fk_watering_recommendations_plant` FOREIGN KEY (`user_id`, `plant_id`) REFERENCES `plants` (`user_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `chk_watering_recommendation_frequency` CHECK (`frequency_days` BETWEEN 1 AND 30),
  CONSTRAINT `chk_watering_recommendation_amount` CHECK (
    (`amount_min_ml` IS NULL AND `amount_max_ml` IS NULL)
    OR (`amount_min_ml` IS NOT NULL AND `amount_max_ml` IS NOT NULL AND `amount_min_ml` > 0 AND `amount_max_ml` >= `amount_min_ml`)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `watering_tasks` (
  `id` CHAR(36) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plant_id` CHAR(36) NOT NULL,
  `recommendation_id` CHAR(36) NULL,
  `rescheduled_from_task_id` CHAR(36) NULL,
  `scheduled_on` DATE NOT NULL,
  `scheduled_time` TIME NOT NULL,
  `reminder_time` TIME NULL,
  `amount_ml` INT UNSIGNED NULL,
  `amount_min_ml` INT UNSIGNED NULL,
  `amount_max_ml` INT UNSIGNED NULL,
  `status` ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  `completed_at` DATETIME NULL,
  `reminder_sent_at` DATETIME NULL,
  `status_slot` TINYINT GENERATED ALWAYS AS (
    CASE `status` WHEN 'pending' THEN 1 WHEN 'completed' THEN 2 ELSE NULL END
  ) STORED,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_watering_tasks_owner` (`user_id`, `plant_id`, `id`),
  UNIQUE KEY `uq_watering_task_slot_status` (`user_id`, `plant_id`, `scheduled_on`, `scheduled_time`, `status_slot`),
  KEY `idx_watering_tasks_user_date_status` (`user_id`, `scheduled_on`, `status`),
  KEY `idx_watering_tasks_plant_status` (`user_id`, `plant_id`, `status`, `scheduled_on`),
  KEY `idx_watering_tasks_reminders` (`user_id`, `status`, `scheduled_on`, `reminder_time`),
  CONSTRAINT `fk_watering_tasks_plant` FOREIGN KEY (`user_id`, `plant_id`) REFERENCES `plants` (`user_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_watering_tasks_recommendation` FOREIGN KEY (`user_id`, `plant_id`, `recommendation_id`) REFERENCES `watering_recommendations` (`user_id`, `plant_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_watering_tasks_rescheduled_from` FOREIGN KEY (`user_id`, `plant_id`, `rescheduled_from_task_id`) REFERENCES `watering_tasks` (`user_id`, `plant_id`, `id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `chk_watering_task_amount_range` CHECK (
    (`amount_min_ml` IS NULL AND `amount_max_ml` IS NULL)
    OR (`amount_min_ml` IS NOT NULL AND `amount_max_ml` IS NOT NULL AND `amount_min_ml` > 0 AND `amount_max_ml` >= `amount_min_ml`)
  ),
  CONSTRAINT `chk_watering_task_amount` CHECK (`amount_ml` IS NULL OR `amount_ml` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
