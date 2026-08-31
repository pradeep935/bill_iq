-- BillIQ purchase item HSN/GST snapshot columns.
-- Run only if these columns are not already present on purchase_items.
-- Existing purchase rows remain NULL; no historical data is fabricated.

ALTER TABLE `purchase_items`
    ADD COLUMN `hsn_code_snapshot` VARCHAR(20) NULL AFTER `unit_id`,
    ADD COLUMN `hsn_code_type_snapshot` VARCHAR(10) NULL AFTER `hsn_code_snapshot`,
    ADD COLUMN `hsn_description_snapshot` TEXT NULL AFTER `hsn_code_type_snapshot`,
    ADD COLUMN `hsn_tax_rate_id` BIGINT UNSIGNED NULL AFTER `hsn_description_snapshot`,
    ADD COLUMN `taxability_snapshot` VARCHAR(20) NULL AFTER `hsn_tax_rate_id`,
    ADD COLUMN `tax_source` VARCHAR(40) NULL AFTER `taxability_snapshot`,
    ADD COLUMN `notification_number` VARCHAR(255) NULL AFTER `tax_source`,
    ADD COLUMN `tax_rule_description` TEXT NULL AFTER `notification_number`,
    ADD INDEX `purchase_items_hsn_tax_rate_id_index` (`hsn_tax_rate_id`),
    ADD INDEX `purchase_items_taxability_snapshot_index` (`taxability_snapshot`),
    ADD INDEX `purchase_items_tax_source_index` (`tax_source`);
