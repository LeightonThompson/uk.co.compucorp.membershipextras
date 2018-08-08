SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS `membershipextras_contribrecur_lineitem`;

SET FOREIGN_KEY_CHECKS=1;
CREATE TABLE `membershipextras_contribrecur_lineitem` (
  `id` int unsigned NOT NULL AUTO_INCREMENT  COMMENT 'Discount Item ID',
  `contribution_recur_id` int unsigned NOT NULL   COMMENT 'ID of the recurring contribution.',
  `line_item_id` int unsigned NOT NULL   COMMENT 'ID of the line item related to the recurring contribution.',
  `start_date` datetime NULL   COMMENT 'Start date of the period for the membership/recurring contribution.',
  `end_date` datetime NULL   COMMENT 'End date of the period for the membership/recurring contribution.',
  `auto_renew` tinyint NULL   COMMENT 'If the line-item should be auto-renewed or not.',

  PRIMARY KEY (`id`),

  UNIQUE INDEX `index_contribrecurid_lineitemid` (
    contribution_recur_id,
    line_item_id
  ),

  CONSTRAINT FK_membershipextras_contribrecur_lineitem_contribution_recur_id
    FOREIGN KEY (`contribution_recur_id`) REFERENCES `civicrm_contribution_recur`(`id`)
    ON DELETE CASCADE,

  CONSTRAINT FK_membershipextras_contribrecur_lineitem_line_item_id
    FOREIGN KEY (`line_item_id`) REFERENCES `civicrm_line_item`(`id`)
    ON DELETE CASCADE  
);
