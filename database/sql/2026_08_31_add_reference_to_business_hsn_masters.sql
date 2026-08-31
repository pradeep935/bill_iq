ALTER TABLE hsn_masters
  ADD COLUMN reference_hsn_master_id BIGINT UNSIGNED NULL AFTER business_id,
  ADD INDEX hsn_masters_reference_hsn_master_id_index (reference_hsn_master_id);
