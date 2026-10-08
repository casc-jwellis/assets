-- Lets an administrator revoke a person's access without deleting the account
-- (users are referenced by assets and transfers, so deleting would orphan history).
-- Disabled users cannot sign in, and an active session ends on its next request.

ALTER TABLE `users`
  ADD COLUMN `disabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `admin`;
