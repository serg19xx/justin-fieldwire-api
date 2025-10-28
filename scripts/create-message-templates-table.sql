-- Таблица шаблонов сообщений
CREATE TABLE fw_message_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type ENUM('sms', 'email') NOT NULL,
    category ENUM('system', 'custom') NOT NULL DEFAULT 'custom',
    event_type VARCHAR(48) NOT NULL,
    subject VARCHAR(255) NULL COMMENT 'Тема письма (только для email)',
    body TEXT NOT NULL COMMENT 'Тело сообщения с переменными',
    variables JSON NULL COMMENT 'Описание доступных переменных',
    is_editable BOOLEAN DEFAULT TRUE COMMENT 'Можно ли редактировать (для системных шаблонов)',
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (event_type) REFERENCES fw_event_rules(event_type) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES fw_users(id) ON DELETE SET NULL,
    
    UNIQUE KEY unique_template (event_type, type, category, name),
    KEY idx_event_type (event_type),
    KEY idx_type (type),
    KEY idx_category (category),
    KEY idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
