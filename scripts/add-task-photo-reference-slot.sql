-- PM reference pictures on a task ("explain the task with a picture").
-- Stored with the field photos; slot 'reference' is not tied to a work day.
-- Run once (or use scripts/run-add-task-photo-reference-slot.php, which is idempotent).

ALTER TABLE fw_task_field_photos
  MODIFY COLUMN `slot` ENUM('before','after','reference') NOT NULL;
