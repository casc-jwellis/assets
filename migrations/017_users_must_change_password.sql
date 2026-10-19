-- New users are given a generated temporary password by email. This flag makes them choose their own the first
-- time they sign in: until it is cleared, every page except "change password" sends them there.

ALTER TABLE `users`
  ADD COLUMN `must_change_password` tinyint(1) NOT NULL DEFAULT 0 AFTER `disabled`;
