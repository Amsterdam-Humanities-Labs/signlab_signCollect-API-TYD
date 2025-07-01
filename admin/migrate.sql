-- Migration script to add tyd_app_ready column to form_data table
-- Run this SQL script to prepare the database for the admin interface

-- Add tyd_app_ready column if it doesn't exist
ALTER TABLE form_data 
ADD COLUMN IF NOT EXISTS tyd_app_ready INT DEFAULT 0 
COMMENT 'Publication ready status: 0 = not ready, 1 = ready for app';

-- Create index for better performance on filtering
CREATE INDEX IF NOT EXISTS idx_form_data_tyd_app_ready ON form_data(tyd_app_ready);
CREATE INDEX IF NOT EXISTS idx_form_data_extern_ready ON form_data(extern, tyd_app_ready);

-- Show current stats
SELECT 
    COUNT(*) as total_extern_records,
    SUM(CASE WHEN tyd_app_ready = 1 THEN 1 ELSE 0 END) as ready_count,
    SUM(CASE WHEN tyd_app_ready = 0 THEN 1 ELSE 0 END) as not_ready_count
FROM form_data 
WHERE extern = '1';

-- Show sample data
SELECT id, glos, extern, tyd_app_ready, 
       CASE 
           WHEN tyd_app_ready = 1 THEN 'Ready' 
           ELSE 'Not Ready' 
       END as status
FROM form_data 
WHERE extern = '1' 
LIMIT 10;