-- Select the BillIQ database in phpMyAdmin before running.
-- Existing columns and indexes are preserved.

SET @batch_sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'opening_stock_items' AND column_name = 'condition_status'), 'SELECT 1', 'ALTER TABLE `opening_stock_items` ADD COLUMN condition_status VARCHAR(30) NOT NULL DEFAULT ''saleable''');
PREPARE batch_stmt FROM @batch_sql;
EXECUTE batch_stmt;
DEALLOCATE PREPARE batch_stmt;

SET @batch_sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'batch_histories' AND column_name = 'operation_token'), 'SELECT 1', 'ALTER TABLE `batch_histories` ADD COLUMN operation_token CHAR(36) NULL');
PREPARE batch_stmt FROM @batch_sql;
EXECUTE batch_stmt;
DEALLOCATE PREPARE batch_stmt;

SET @batch_sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'batch_histories' AND column_name = 'request_hash'), 'SELECT 1', 'ALTER TABLE `batch_histories` ADD COLUMN request_hash VARCHAR(64) NULL');
PREPARE batch_stmt FROM @batch_sql;
EXECUTE batch_stmt;
DEALLOCATE PREPARE batch_stmt;

SET @batch_sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_ledgers' AND column_name = 'posted_at'), 'SELECT 1', 'ALTER TABLE `stock_ledgers` ADD COLUMN posted_at TIMESTAMP NULL AFTER transaction_date');
PREPARE batch_stmt FROM @batch_sql;
EXECUTE batch_stmt;
DEALLOCATE PREPARE batch_stmt;

SET @batch_sql = IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'batch_histories' AND index_name = 'batch_operation_idempotency'), 'SELECT 1', 'ALTER TABLE `batch_histories` ADD UNIQUE INDEX batch_operation_idempotency (business_id, operation_token)');
PREPARE batch_stmt FROM @batch_sql;
EXECUTE batch_stmt;
DEALLOCATE PREPARE batch_stmt;

SET @batch_sql = IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'stock_ledgers' AND index_name = 'stock_ledgers_posted_at_index'), 'SELECT 1', 'ALTER TABLE `stock_ledgers` ADD INDEX stock_ledgers_posted_at_index (posted_at)');
PREPARE batch_stmt FROM @batch_sql;
EXECUTE batch_stmt;
DEALLOCATE PREPARE batch_stmt;

UPDATE stock_ledgers SET posted_at = created_at WHERE posted_at IS NULL AND created_at IS NOT NULL;

INSERT INTO permissions (name, module, description, created_at, updated_at)
SELECT 'batch.adjust', 'inventory', 'Batch Adjust', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'batch.adjust');

INSERT INTO permissions (name, module, description, created_at, updated_at)
SELECT 'batch.reclassify', 'inventory', 'Batch Reclassify', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'batch.reclassify');

INSERT INTO permissions (name, module, description, created_at, updated_at)
SELECT 'batch.writeoff', 'inventory', 'Batch Writeoff', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'batch.writeoff');

-- Existing role permissions are unchanged.
