-- Add new fields to fw_users table for worker information
-- Date: 2025-10-03

-- Add date of birth field
ALTER TABLE fw_users ADD COLUMN dob DATE DEFAULT NULL;

-- Add gender field (enum)
ALTER TABLE fw_users ADD COLUMN gender ENUM('Male','Female') DEFAULT NULL;

-- Add nationality field
ALTER TABLE fw_users ADD COLUMN nationality VARCHAR(100) DEFAULT NULL;

-- Add country of origin field
ALTER TABLE fw_users ADD COLUMN country_of_origin VARCHAR(100) DEFAULT NULL;

-- Add workforce group field
ALTER TABLE fw_users ADD COLUMN workforce_group VARCHAR(100) DEFAULT NULL;

-- Add indexes for better performance
CREATE INDEX idx_dob ON fw_users(dob);
CREATE INDEX idx_gender ON fw_users(gender);
CREATE INDEX idx_nationality ON fw_users(nationality);
CREATE INDEX idx_country_of_origin ON fw_users(country_of_origin);
CREATE INDEX idx_workforce_group ON fw_users(workforce_group);

-- Update the view to include new fields
CREATE OR REPLACE ALGORITHM = UNDEFINED VIEW fw_v_users AS 
SELECT 
    u.id AS id, 
    u.email AS email, 
    u.password_hash AS password_hash, 
    u.first_name AS first_name, 
    u.last_name AS last_name, 
    u.phone AS phone, 
    u.role_id AS role_id, 
    u.job_title AS job_title, 
    u.status AS status, 
    u.status_reason AS status_reason, 
    u.status_details AS status_details, 
    u.additional_info AS additional_info, 
    u.full_img_url AS full_img_url, 
    u.avatar_url AS avatar_url, 
    u.two_factor_enabled AS two_factor_enabled, 
    u.two_factor_secret AS two_factor_secret, 
    u.last_login AS last_login, 
    u.status_changed_at AS status_changed_at,
    u.status_end_at AS status_end_at,
    u.dob AS dob,
    u.gender AS gender,
    u.nationality AS nationality,
    u.country_of_origin AS country_of_origin,
    u.workforce_group AS workforce_group,
    u.created_at AS created_at, 
    u.updated_at AS updated_at, 
    u.invitation_status AS invitation_status, 
    u.invitation_token AS invitation_token, 
    u.invitation_sent_at AS invitation_sent_at, 
    u.invitation_expires_at AS invitation_expires_at, 
    u.invited_by AS invited_by, 
    u.registration_completed_at AS registration_completed_at, 
    u.invitation_attempts AS invitation_attempts, 
    u.last_reminder_sent_at AS last_reminder_sent_at, 
    u.archived_at AS archived_at, 
    r.code AS role_code, 
    r.name AS role_name, 
    r.category AS role_category, 
    r.description AS role_description 
FROM (fw_users u LEFT JOIN fw_glob_roles r ON (u.role_id = r.id));
