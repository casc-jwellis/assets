-- Step 2 of 5: permissions.user_id becomes the new integer.
-- Permission rows for people who no longer exist are dropped (they grant access to nobody).

DELETE FROM `permissions`
 WHERE `user_id` = '' OR `user_id` NOT IN (SELECT `user_id` FROM `users`);

ALTER TABLE `permissions` ADD COLUMN `user_num` int unsigned NULL;

UPDATE `permissions`
   SET `user_num` = (SELECT u.`id_num` FROM `users` u WHERE u.`user_id` = `permissions`.`user_id` LIMIT 1);

ALTER TABLE `permissions` DROP PRIMARY KEY, DROP COLUMN `user_id`;

ALTER TABLE `permissions`
  CHANGE `user_num` `user_id` int unsigned NOT NULL,
  ADD PRIMARY KEY (`user_id`, `department_id`);
