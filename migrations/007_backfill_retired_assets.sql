-- One-time backfill: before retirement existed, a retired asset was moved into the
-- "RETIRE" department with a transfer whose department_to = 'RETIRE'. Mark every asset
-- currently in the RETIRE department as retired, taking the date, the person and the
-- reason from its most recent such transfer.
--
--   * retired_date / retired_by stay NULL when no matching transfer exists ("not recorded").
--   * The transfer's reason is kept verbatim in retired_notes, and also mapped to a disposal
--     method when it matches one of the known methods (otherwise the method is left empty).
--   * The assets stay in the RETIRE department; nothing is moved. An administrator picks a
--     real department when restoring one.
--   * Safe to run again: only assets not yet marked retired are touched.

UPDATE `assets`
   SET `retired_by` = (
           SELECT t.user_id FROM transfers t
            WHERE t.asset_id = assets.asset_id AND t.department_to = 'RETIRE'
            ORDER BY t.transfer_date DESC, t.transfer_id DESC LIMIT 1),
       `retired_notes` = NULLIF(TRIM((
           SELECT t.reason FROM transfers t
            WHERE t.asset_id = assets.asset_id AND t.department_to = 'RETIRE'
            ORDER BY t.transfer_date DESC, t.transfer_id DESC LIMIT 1)), ''),
       `disposal_method` = CASE LOWER(TRIM((
           SELECT t.reason FROM transfers t
            WHERE t.asset_id = assets.asset_id AND t.department_to = 'RETIRE'
            ORDER BY t.transfer_date DESC, t.transfer_id DESC LIMIT 1)))
           WHEN 'surplus'   THEN 'Surplus'
           WHEN 'recycled'  THEN 'Recycled'
           WHEN 'recycle'   THEN 'Recycled'
           WHEN 'sold'      THEN 'Sold'
           WHEN 'donated'   THEN 'Donated'
           WHEN 'donate'    THEN 'Donated'
           WHEN 'traded in' THEN 'Traded in'
           WHEN 'trade in'  THEN 'Traded in'
           WHEN 'scrapped'  THEN 'Scrapped'
           WHEN 'scrap'     THEN 'Scrapped'
           WHEN 'lost'      THEN 'Lost'
           WHEN 'stolen'    THEN 'Stolen'
           WHEN 'other'     THEN 'Other'
           ELSE NULL END,
       `retired_date` = (
           SELECT t.transfer_date FROM transfers t
            WHERE t.asset_id = assets.asset_id AND t.department_to = 'RETIRE'
            ORDER BY t.transfer_date DESC, t.transfer_id DESC LIMIT 1),
       `retired` = 1
 WHERE `retired` = 0
   AND `department_id` IN (SELECT d.department_id FROM departments d WHERE d.abbr = 'RETIRE');
