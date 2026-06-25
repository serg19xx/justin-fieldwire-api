-- Allow fewer than 3 offered slots when PM selects 1 or 2 options.
ALTER TABLE `fw_sms_meeting_invites`
  MODIFY `slot2_time` TIME NULL,
  MODIFY `slot3_time` TIME NULL;
