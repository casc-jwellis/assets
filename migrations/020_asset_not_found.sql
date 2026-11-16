-- "Not found": during an asset audit, someone checks for an asset and it is not where it should be. Like verified_date this
-- is a plain date column; the asset list shows whichever of the two is more recent, so an asset found again later is
-- simply verified again.

ALTER TABLE `assets`
  ADD COLUMN `not_found_date` datetime DEFAULT NULL AFTER `verified_date`;
