-- Site-wide settings an administrator can change from the browser (site name, theme colours).
-- One row per setting; the Theme page is the only thing that writes them.

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 NOT NULL,
  `updated_date` datetime NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
