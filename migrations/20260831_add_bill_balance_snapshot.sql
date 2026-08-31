SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bills'
    AND COLUMN_NAME = 'closing_balance'
);
SET @statement = IF(
  @column_exists = 0,
  'ALTER TABLE bills ADD COLUMN closing_balance DECIMAL(12,2) NULL AFTER amount_received',
  'DO 0'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
