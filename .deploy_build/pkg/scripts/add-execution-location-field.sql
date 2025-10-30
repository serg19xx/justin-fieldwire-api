-- Добавление поля execution_location в таблицу fw_event_rules
ALTER TABLE fw_event_rules 
ADD COLUMN execution_location VARCHAR(20) DEFAULT NULL 
COMMENT 'Место выполнения правила: server - на сервере, auto - в системе автоматизации';

-- Обновление существующих записей (по умолчанию NULL)
UPDATE fw_event_rules SET execution_location = NULL WHERE execution_location IS NULL;
