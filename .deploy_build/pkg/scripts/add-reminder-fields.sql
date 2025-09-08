-- Добавление полей для системы повторных приглашений в таблицу fw_users
-- Выполнить на сервере для обновления структуры таблицы

USE yjyhtqh8_easyrx;

-- Добавляем поля для отслеживания повторных отправок
ALTER TABLE fw_users 
ADD COLUMN invitation_attempts INT DEFAULT 0 COMMENT 'Количество попыток отправки приглашения',
ADD COLUMN last_reminder_sent_at TIMESTAMP NULL COMMENT 'Дата последней повторной отправки',

-- Добавляем индексы для оптимизации запросов
ALTER TABLE fw_users 
ADD INDEX idx_invitation_attempts (invitation_attempts),
ADD INDEX idx_last_reminder_sent (last_reminder_sent_at),

-- Показываем результат
SELECT 'Reminder fields added successfully!' as result;

