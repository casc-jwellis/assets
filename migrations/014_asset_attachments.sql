-- Files (photos, PDFs, text) attached to assets.
--
-- The files themselves live outside the web root in storage/attachments/ under random names; this table
-- records which asset each one belongs to, its original name, detected type and size, and who added it.
-- Back up storage/attachments/ together with the database: a database dump does not contain the files.

CREATE TABLE IF NOT EXISTS `asset_attachments` (
  `attachment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int unsigned NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
  `stored_name` varchar(64) NOT NULL,
  `mime` varchar(100) NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `has_thumb` tinyint(1) NOT NULL DEFAULT 0,
  `caption` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '',
  `uploaded_by` int unsigned NOT NULL,
  `uploaded_date` datetime NOT NULL,
  PRIMARY KEY (`attachment_id`),
  UNIQUE KEY `stored_name` (`stored_name`),
  KEY `asset_id` (`asset_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
