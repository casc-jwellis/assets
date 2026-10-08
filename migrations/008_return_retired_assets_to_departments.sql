-- Retired assets now keep their real department, but the ones imported by migration 007 are
-- still sitting in the legacy "RETIRE" department. Move each of them back to the department
-- named in its most recent transfer to RETIRE (that transfer's department_from).
--
--   * Only assets that are retired AND currently in the RETIRE department are touched.
--   * An asset is left where it is if its history names no department, or one that no longer
--     exists, so nothing is guessed. To list any that remain:
--       SELECT a.asset_number FROM assets a JOIN departments d ON d.department_id = a.department_id
--        WHERE a.retired = 1 AND d.abbr = 'RETIRE';
--   * Only the department changes; building and room stay as they are, and no transfer rows are
--     written (this corrects the data, it is not a physical move).
--   * Safe to run again: moved assets are no longer in RETIRE.
--
-- The most recent RETIRE transfer of every asset is worked out once (the derived table), then
-- joined; see the note in migration 007 for why this is not a per-asset subquery.

UPDATE `assets` a
  JOIN (
        SELECT r.asset_id, r.department_from
          FROM (SELECT t.asset_id, t.department_from,
                       ROW_NUMBER() OVER (PARTITION BY t.asset_id
                                              ORDER BY t.transfer_date DESC, t.transfer_id DESC) AS rn
                  FROM transfers t
                 WHERE t.department_to = 'RETIRE') r
         WHERE r.rn = 1
       ) lt ON lt.asset_id = a.asset_id
  JOIN (
        SELECT d.abbr, MIN(d.department_id) AS department_id
          FROM departments d
         WHERE d.abbr <> 'RETIRE'
         GROUP BY d.abbr
       ) nd ON nd.abbr = lt.department_from
   SET a.`department_id` = nd.department_id
 WHERE a.`retired` = 1
   AND a.`department_id` IN (SELECT d.department_id FROM departments d WHERE d.abbr = 'RETIRE');
