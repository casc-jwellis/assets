-- Run once before using the web interface.
--
-- users.password is varchar(40), which only fits legacy MD5 (32) / SHA-1 (40)
-- hex digests. password_hash() output is ~60 characters (bcrypt) and longer for
-- other algorithms, so the column must be widened. 255 is the PHP-recommended size.
--
-- Existing legacy hashes keep working: they are verified once at login and then
-- transparently replaced with a password_hash() value.

ALTER TABLE `users`
  MODIFY `password` varchar(255) NOT NULL;
