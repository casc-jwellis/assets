-- Support for editing assets.
--   updated_date / updated_by : who last changed the asset and when (shown on the detail page)
--   version                   : bumped on every save; a save is refused if the version in the
--                               database differs from the one the person started editing, so two
--                               people can never silently overwrite each other's changes.

ALTER TABLE `assets`
  ADD COLUMN `updated_date` datetime DEFAULT NULL,
  ADD COLUMN `updated_by` varchar(16) DEFAULT NULL,
  ADD COLUMN `version` int UNSIGNED NOT NULL DEFAULT 0;
