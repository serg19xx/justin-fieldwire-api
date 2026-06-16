-- Presence flag: when true, event blocks overlapping presence events for the same user.

ALTER TABLE `fw_calendar_events`
  ADD COLUMN `requires_presence` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = user must be there; checked for time conflicts'
    AFTER `all_day`;
