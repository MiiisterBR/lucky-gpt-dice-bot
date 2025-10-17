-- Quick Fix: Update golden_numbers to 7-digit and clear old data
-- Run this in phpMyAdmin or MySQL CLI

-- Step 1: Delete all old 3-digit golden numbers
DELETE FROM golden_numbers;

-- Step 2: Alter the column to CHAR(7) if not already
ALTER TABLE golden_numbers MODIFY COLUMN number CHAR(7) NOT NULL;

-- Step 3: (Optional) Insert a test 7-digit golden number for today
INSERT INTO golden_numbers (generated_at, number, valid_date, source, announced)
VALUES (NOW(), '3847261', CURDATE(), 'manual', 0);

-- Done! Now refresh your admin panel and you should see a 7-digit number
