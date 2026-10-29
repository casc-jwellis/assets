-- "Forgot password" on the sign-in page: a one-time link is emailed to the account's address. Only a hash of the link's
-- secret is kept here (so reading this table does not let anyone reset a password), each row is good for one hour, and
-- asking again replaces the earlier link.

CREATE TABLE IF NOT EXISTS `password_resets` (
  `reset_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_date` datetime NOT NULL,
  `expires_date` datetime NOT NULL,
  `requested_ip` varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  KEY `requested_ip` (`requested_ip`, `created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
