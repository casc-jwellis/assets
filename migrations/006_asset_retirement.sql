-- Asset retirement. An asset is retired when `retired` = 1; it keeps its department and
-- location, so permissions and reports still work. Retired assets are hidden from the
-- default list and cannot be edited, moved or verified until an administrator restores them.
--
--   retired_date    : when it was retired (NULL = not recorded, e.g. old imported data)
--   retired_by      : user_id of whoever retired it (NULL = unknown)
--   disposal_method : one of the fixed list in the application (Surplus, Recycled, ...)
--   retired_notes   : free text, up to 255 characters

ALTER TABLE `assets`
  ADD COLUMN `retired` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `retired_date` datetime DEFAULT NULL,
  ADD COLUMN `retired_by` varchar(16) DEFAULT NULL,
  ADD COLUMN `disposal_method` varchar(16) DEFAULT NULL,
  ADD COLUMN `retired_notes` varchar(255) DEFAULT NULL,
  ADD KEY `retired` (`retired`);
