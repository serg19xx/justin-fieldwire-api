-- Добавляем поле is_editable в таблицу fw_message_templates
ALTER TABLE fw_message_templates 
ADD COLUMN is_editable BOOLEAN DEFAULT TRUE COMMENT 'Можно ли редактировать (для системных шаблонов)';

-- Добавляем индексы для оптимизации
ALTER TABLE fw_message_templates 
ADD KEY idx_event_type (event_type),
ADD KEY idx_type (type),
ADD KEY idx_category (category),
ADD KEY idx_is_active (is_active);

-- Добавляем уникальный ключ для предотвращения дублирования
ALTER TABLE fw_message_templates 
ADD UNIQUE KEY unique_template (event_type, type, category, name);
