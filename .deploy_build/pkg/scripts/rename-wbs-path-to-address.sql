-- Rename wbs_path -> address for tasks and task templates (align with current frontend).
-- Run after backup. Order: tasks, then templates.

-- fw_prj_tasks: legacy values were often JSON-encoded strings (e.g. "1.1.1"); normalize to plain text where possible.
ALTER TABLE fw_prj_tasks
    CHANGE COLUMN wbs_path address VARCHAR(500) NULL COMMENT 'Site / object address (plain text)';

UPDATE fw_prj_tasks
SET address = JSON_UNQUOTE(address)
WHERE address IS NOT NULL
  AND JSON_VALID(address)
  AND JSON_TYPE(JSON_EXTRACT(address, '$')) = 'STRING';

-- fw_task_templates: widen to match UI (500 chars)
ALTER TABLE fw_task_templates
    CHANGE COLUMN wbs_path address VARCHAR(500) NULL COMMENT 'Default task address (site / object)';
