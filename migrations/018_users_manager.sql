-- A second kind of privileged account, the Asset Manager: can see and change the assets of every department and manage
-- the ordinary (non-admin, non-manager) user accounts, giving them read-only department access. Administrators are
-- unchanged and always win: an account with admin = 1 is an administrator whatever this column says.

ALTER TABLE `users`
  ADD COLUMN `manager` tinyint(1) NOT NULL DEFAULT 0 AFTER `admin`;
