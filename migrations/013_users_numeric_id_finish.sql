-- Step 5 of 5: drop the old text ID and make the new number the user_id.
-- After this, users.user_id is an auto-incrementing integer, and every table that refers to a
-- user uses the same integer.

ALTER TABLE `users` DROP INDEX `old_user_id`, DROP COLUMN `user_id`;

ALTER TABLE `users` CHANGE `id_num` `user_id` int unsigned NOT NULL AUTO_INCREMENT;
