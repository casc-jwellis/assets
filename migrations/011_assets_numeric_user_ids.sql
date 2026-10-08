-- Step 3 of 5: assets.user_id (added by), updated_by and retired_by become the new integers.
-- A person who no longer exists, or a blank value, becomes the "Missing user" placeholder.
-- updated_by and retired_by stay empty (NULL) where nobody was recorded.

ALTER TABLE `assets`
  ADD COLUMN `user_num` int unsigned NULL,
  ADD COLUMN `updated_by_num` int unsigned NULL,
  ADD COLUMN `retired_by_num` int unsigned NULL;

UPDATE `assets`
   SET `user_num` = COALESCE(
           (SELECT u.`id_num` FROM `users` u WHERE u.`user_id` = `assets`.`user_id` LIMIT 1),
           (SELECT m.`id_num` FROM `users` m WHERE m.`username` = 'missing-user' LIMIT 1)),
       `updated_by_num` = CASE WHEN `assets`.`updated_by` IS NULL THEN NULL ELSE COALESCE(
           (SELECT u.`id_num` FROM `users` u WHERE u.`user_id` = `assets`.`updated_by` LIMIT 1),
           (SELECT m.`id_num` FROM `users` m WHERE m.`username` = 'missing-user' LIMIT 1)) END,
       `retired_by_num` = CASE WHEN `assets`.`retired_by` IS NULL THEN NULL ELSE COALESCE(
           (SELECT u.`id_num` FROM `users` u WHERE u.`user_id` = `assets`.`retired_by` LIMIT 1),
           (SELECT m.`id_num` FROM `users` m WHERE m.`username` = 'missing-user' LIMIT 1)) END;

ALTER TABLE `assets` DROP COLUMN `user_id`, DROP COLUMN `updated_by`, DROP COLUMN `retired_by`;

ALTER TABLE `assets`
  CHANGE `user_num` `user_id` int unsigned NOT NULL,
  CHANGE `updated_by_num` `updated_by` int unsigned NULL,
  CHANGE `retired_by_num` `retired_by` int unsigned NULL;
