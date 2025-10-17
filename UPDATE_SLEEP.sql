-- Update sleep time to 4 seconds (4000ms)
-- Run this in phpMyAdmin or MySQL CLI

UPDATE settings SET `value` = '4000' WHERE `key` = 'sleep_ms_between_rolls';

-- Done! Sleep is now 4 seconds
