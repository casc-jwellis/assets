-- A short log of the notification emails the app tried to send, so an administrator can see what went out
-- and why something did not (Email page). The SMTP settings themselves live in the `settings` table (015).

CREATE TABLE IF NOT EXISTS `email_log` (
  `log_id` int unsigned NOT NULL AUTO_INCREMENT,
  `created_date` datetime NOT NULL,
  `event` varchar(24) NOT NULL,
  `subject` varchar(200) CHARACTER SET utf8mb4 NOT NULL,
  `recipients` varchar(600) CHARACTER SET utf8mb4 NOT NULL,
  `status` varchar(12) NOT NULL,
  `detail` varchar(500) CHARACTER SET utf8mb4 NOT NULL DEFAULT '',
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
