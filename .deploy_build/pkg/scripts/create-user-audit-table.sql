-- Create user audit log table for detailed user activity tracking
CREATE TABLE IF NOT EXISTS fw_user_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    action_type ENUM(
        'login', 
        'logout', 
        'login_failed', 
        'session_start', 
        'session_end', 
        'password_change', 
        'profile_update',
        '2fa_enabled',
        '2fa_disabled',
        'api_call'
    ) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    session_id VARCHAR(255),
    duration_seconds INT NULL,
    success BOOLEAN DEFAULT TRUE,
    error_message VARCHAR(500) NULL,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_user_id (user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_created_at (created_at),
    INDEX idx_success (success),
    INDEX idx_user_action (user_id, action_type),
    INDEX idx_user_date (user_id, created_at),
    
    -- Foreign key constraint (optional, depends on your user table structure)
    -- FOREIGN KEY (user_id) REFERENCES fw_users(id) ON DELETE CASCADE
);

-- Add comments for documentation
ALTER TABLE fw_user_audit_log 
COMMENT = 'User activity audit log for security and analytics purposes';

-- Create a view for easy querying of user sessions
CREATE OR REPLACE VIEW v_user_sessions AS
SELECT 
    user_id,
    session_id,
    MIN(created_at) as session_start,
    MAX(created_at) as session_end,
    TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) as session_duration_seconds,
    COUNT(*) as total_actions,
    SUM(CASE WHEN action_type = 'login' THEN 1 ELSE 0 END) as login_count,
    SUM(CASE WHEN action_type = 'logout' THEN 1 ELSE 0 END) as logout_count,
    MAX(ip_address) as ip_address,
    MAX(user_agent) as user_agent
FROM fw_user_audit_log 
WHERE action_type IN ('login', 'logout', 'session_start', 'session_end')
GROUP BY user_id, session_id
ORDER BY session_start DESC;

-- Create a view for daily user activity summary
CREATE OR REPLACE VIEW v_daily_user_activity AS
SELECT 
    user_id,
    DATE(created_at) as activity_date,
    COUNT(*) as total_actions,
    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_actions,
    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_actions,
    COUNT(DISTINCT action_type) as unique_action_types,
    GROUP_CONCAT(DISTINCT action_type) as action_types,
    MIN(created_at) as first_action,
    MAX(created_at) as last_action,
    TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) as activity_duration_seconds
FROM fw_user_audit_log 
GROUP BY user_id, DATE(created_at)
ORDER BY activity_date DESC, user_id;
