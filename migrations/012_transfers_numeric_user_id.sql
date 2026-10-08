-- Step 4 of 5: transfers.user_id becomes the new integer.
-- A person who no longer exists, or a blank value, becomes the "Missing user" placeholder.

ALTER TABLE `transfers` ADD COLUMN `user_num` int unsigned NULL;

UPDATE `transfers`
   SET `user_num` = COALESCE(
           (SELECT u.`id_num` FROM `users` u WHERE u.`user_id` = `transfers`.`user_id` LIMIT 1),
           (SELECT m.`id_num` FROM `users` m WHERE m.`username` = 'missing-user' LIMIT 1));

ALTER TABLE `transfers` DROP COLUMN `user_id`;

ALTER TABLE `transfers` CHANGE `user_num` `user_id` int unsigned NOT NULL;
