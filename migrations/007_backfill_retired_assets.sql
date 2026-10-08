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
--
-- The most recent RETIRE transfer of every asset is worked out once (the derived table), then
-- joined. Looking it up per asset with a correlated subquery made the server rescan the whole
-- transfers table for each retired asset, which took about a minute on a few thousand assets.

UPDATE `assets` a
  LEFT JOIN (
        SELECT r.asset_id, r.user_id, r.reason, r.transfer_date
          FROM (SELECT t.asset_id, t.user_id, t.reason, t.transfer_date,
                       ROW_NUMBER() OVER (PARTITION BY t.asset_id
                                              ORDER BY t.transfer_date DESC, t.transfer_id DESC) AS rn
                  FROM transfers t
                 WHERE t.department_to = 'RETIRE') r
         WHERE r.rn = 1
       ) lt ON lt.asset_id = a.asset_id
   SET a.`retired_by` = lt.user_id,
       a.`retired_notes` = NULLIF(TRIM(lt.reason), ''),
       a.`disposal_method` = CASE LOWER(TRIM(lt.reason))
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
       a.`retired_date` = lt.transfer_date,
       a.`retired` = 1
 WHERE a.`retired` = 0
   AND a.`department_id` IN (SELECT d.department_id FROM departments d WHERE d.abbr = 'RETIRE');
