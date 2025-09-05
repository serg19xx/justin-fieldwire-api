-- Добавление полей для системы приглашений в таблицу fw_users
-- Выполнить на сервере для обновления структуры таблицы

USE yjyhtqh8_easyrx;

-- Добавляем поля для системы приглашений
ALTER TABLE fw_users 
ADD COLUMN invitation_status ENUM('invited', 'registered') DEFAULT 'registered' COMMENT 'Статус пользователя: invited - приглашен, registered - зарегистрирован',
ADD COLUMN invitation_token VARCHAR(255) NULL COMMENT 'Токен приглашения для регистрации',
ADD COLUMN invitation_sent_at TIMESTAMP NULL COMMENT 'Дата отправки приглашения',
ADD COLUMN invitation_expires_at TIMESTAMP NULL COMMENT 'Дата истечения приглашения',
ADD COLUMN invited_by INT NULL COMMENT 'ID администратора, отправившего приглашение',
ADD COLUMN registration_completed_at TIMESTAMP NULL COMMENT 'Дата завершения регистрации по приглашению';

-- Добавляем индексы для оптимизации запросов
ALTER TABLE fw_users 
ADD INDEX idx_invitation_status (invitation_status),
ADD INDEX idx_invitation_token (invitation_token),
ADD INDEX idx_invitation_expires (invitation_expires_at),
ADD INDEX idx_invited_by (invited_by);

-- Внешний ключ для invited_by (ссылка на fw_users.id) - добавляем позже если нужно
-- ALTER TABLE fw_users 
-- ADD CONSTRAINT fk_fw_users_invited_by 
-- FOREIGN KEY (invited_by) REFERENCES fw_users(id) ON DELETE SET NULL;

-- Обновляем существующих пользователей - они все зарегистрированные
UPDATE fw_users SET invitation_status = 'registered' WHERE invitation_status IS NULL;

-- Показываем результат
SELECT 'Invitation fields added successfully!' as result;
