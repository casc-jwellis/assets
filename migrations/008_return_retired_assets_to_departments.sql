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

UPDATE `assets`
   SET `department_id` = (
           SELECT d.department_id FROM departments d
            WHERE d.abbr <> 'RETIRE'
              AND d.abbr = (SELECT t.department_from FROM transfers t
                             WHERE t.asset_id = assets.asset_id AND t.department_to = 'RETIRE'
                             ORDER BY t.transfer_date DESC, t.transfer_id DESC LIMIT 1)
            LIMIT 1)
 WHERE `retired` = 1
   AND `department_id` IN (SELECT d.department_id FROM departments d WHERE d.abbr = 'RETIRE')
   AND (SELECT d.department_id FROM departments d
         WHERE d.abbr <> 'RETIRE'
           AND d.abbr = (SELECT t.department_from FROM transfers t
                          WHERE t.asset_id = assets.asset_id AND t.department_to = 'RETIRE'
                          ORDER BY t.transfer_date DESC, t.transfer_id DESC LIMIT 1)
         LIMIT 1) IS NOT NULL;
