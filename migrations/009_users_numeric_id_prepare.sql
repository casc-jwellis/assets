-- Step 1 of 5: turn users.user_id into an auto-incrementing integer.
--
-- The old text IDs are not kept. During the conversion the old column stays as `user_id` (indexed, so
-- the next steps can look people up by it) while the new number lives in `id_num`; migration 013
-- drops the old column and renames `id_num` to `user_id`. Migrations 010-012 re-point the other
-- tables at the new numbers.
--
-- Back up the database before applying 009-013. MySQL cannot roll back schema changes, so a failure
-- part-way means restoring the backup.

ALTER TABLE `users`
  DROP PRIMARY KEY,
  ADD COLUMN `id_num` int unsigned NOT NULL AUTO_INCREMENT FIRST,
  ADD PRIMARY KEY (`id_num`),
  ADD KEY `old_user_id` (`user_id`);

-- One disabled placeholder user, "Missing user". Anything that pointed at a person who no longer
-- exists (or had a blank ID) is attached to it, so the history stays readable. Its old ID is the
-- empty string, which is also what blank references already contain.
INSERT INTO `users` (`user_id`, `username`, `password`, `firstname`, `lastname`, `email`, `department_id`, `admin`, `disabled`, `timezone`, `lastlogin`)
SELECT '', 'missing-user', '', '', 'Missing user', '', 0, 0, 1, 'UTC', '1970-01-01 00:00:00' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'missing-user');
