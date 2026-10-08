-- Remove the legacy `tassets` table. Nothing in the application uses it.
--
-- WARNING: this permanently deletes the table and all of its rows. Back up the
-- database first if you might want that data. IF EXISTS makes it a no-op on
-- databases that never had the table (e.g. fresh installs from assets.schema.sql).

DROP TABLE IF EXISTS `tassets`;
