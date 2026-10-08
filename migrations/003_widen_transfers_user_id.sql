-- transfers.user_id is an int, but users.user_id is varchar(16). Make them the same
-- type so a transfer can be attributed to any user. Existing numeric IDs convert
-- to their text form (note: an ID with leading zeros, e.g. "007", was stored as 7
-- and stays "7", so those old rows show the raw ID instead of a name).

ALTER TABLE `transfers`
  MODIFY `user_id` varchar(16) NOT NULL;
