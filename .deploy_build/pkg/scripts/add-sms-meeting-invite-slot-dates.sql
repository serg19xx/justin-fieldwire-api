-- Per-slot dates for meeting invites (slots may fall on different days within the booking window).
ALTER TABLE `fw_sms_meeting_invites`
  ADD COLUMN `slot1_date` DATE NULL AFTER `meeting_date`,
  ADD COLUMN `slot2_date` DATE NULL AFTER `slot1_time`,
  ADD COLUMN `slot3_date` DATE NULL AFTER `slot2_time`;

UPDATE `fw_sms_meeting_invites`
SET
  `slot1_date` = COALESCE(`slot1_date`, `meeting_date`),
  `slot2_date` = COALESCE(`slot2_date`, `meeting_date`),
  `slot3_date` = COALESCE(`slot3_date`, `meeting_date`)
WHERE `slot1_date` IS NULL OR `slot2_date` IS NULL OR `slot3_date` IS NULL;
