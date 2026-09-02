-- Paket A: manueller External-Contacts-Workflow LSZJ -> Vereinsflieger
-- MariaDB/MySQL, additive und rueckwaertsvertraeglich.
-- Vorher Datenbanksicherung erstellen.

ALTER TABLE `external_contacts`
  ADD COLUMN `vf_linked_at` datetime DEFAULT NULL AFTER `vf_exported_at`,
  ADD COLUMN `vf_user_no` varchar(32) DEFAULT NULL AFTER `vf_linked_at`,
  ADD KEY `idx_external_contacts_vf_state` (`is_active`,`vf_exported_at`,`vf_linked_at`),
  ADD KEY `idx_external_contacts_vf_user_no` (`vf_user_no`);
