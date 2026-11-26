SET @farm_activity_column = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'farm_activities'
    AND column_name = 'plant_id'
);
SET @farm_activity_sql = IF(
  @farm_activity_column = 0,
  'ALTER TABLE farm_activities ADD COLUMN plant_id CHAR(36) NULL DEFAULT NULL AFTER user_id',
  'SELECT 1'
);
PREPARE farm_activity_statement FROM @farm_activity_sql;
EXECUTE farm_activity_statement;
DEALLOCATE PREPARE farm_activity_statement;

SET @farm_activity_constraint = (
  SELECT COUNT(*)
  FROM information_schema.referential_constraints
  WHERE constraint_schema = DATABASE()
    AND constraint_name = 'fk_farm_activities_plant'
);
SET @farm_activity_sql = IF(
  @farm_activity_constraint = 0,
  'ALTER TABLE farm_activities ADD CONSTRAINT fk_farm_activities_plant FOREIGN KEY (user_id, plant_id) REFERENCES plants (user_id, id) ON UPDATE CASCADE ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE farm_activity_statement FROM @farm_activity_sql;
EXECUTE farm_activity_statement;
DEALLOCATE PREPARE farm_activity_statement;

SET @farm_activity_index = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'farm_activities'
    AND index_name = 'idx_farm_activities_plant_date'
);
SET @farm_activity_sql = IF(
  @farm_activity_index = 0,
  'CREATE INDEX idx_farm_activities_plant_date ON farm_activities (user_id, plant_id, activity_date)',
  'SELECT 1'
);
PREPARE farm_activity_statement FROM @farm_activity_sql;
EXECUTE farm_activity_statement;
DEALLOCATE PREPARE farm_activity_statement;
