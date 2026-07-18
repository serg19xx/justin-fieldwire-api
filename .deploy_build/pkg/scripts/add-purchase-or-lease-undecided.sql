-- Allow Undecided for purchase_or_lease
ALTER TABLE `fw_projects`
  MODIFY COLUMN `purchase_or_lease` ENUM('Purchase', 'Lease', 'Undecided') DEFAULT 'Purchase';
