-- Create fw_prj_task_assignees table for storing task assignments
-- Date: 2025-01-27

CREATE TABLE IF NOT EXISTS fw_prj_task_assignees (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT(20) UNSIGNED NOT NULL,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_task_user (task_id, user_id),
    KEY idx_task_id (task_id),
    KEY idx_user_id (user_id),
    CONSTRAINT fk_task_assignees_task FOREIGN KEY (task_id) REFERENCES fw_prj_tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_task_assignees_user FOREIGN KEY (user_id) REFERENCES fw_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

