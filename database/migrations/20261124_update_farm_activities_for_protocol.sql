ALTER TABLE `farm_activities`
  MODIFY COLUMN `activity_type` VARCHAR(32) NOT NULL,
  CHANGE COLUMN `related_crop_animal` `related_plant` VARCHAR(200) NULL DEFAULT NULL,
  ADD COLUMN `growth_stage` VARCHAR(100) NULL DEFAULT NULL AFTER `related_plant`,
  ADD COLUMN `climate_risk` VARCHAR(500) NULL DEFAULT NULL AFTER `growth_stage`,
  ADD COLUMN `actions_taken` TEXT NULL DEFAULT NULL AFTER `climate_risk`,
  ADD COLUMN `harvest_quantity` DECIMAL(12,3) NULL DEFAULT NULL AFTER `actions_taken`,
  ADD COLUMN `harvest_unit` VARCHAR(40) NULL DEFAULT NULL AFTER `harvest_quantity`;
