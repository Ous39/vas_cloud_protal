USE HeraTesting;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS `RessourceSharing`;
CREATE TABLE `RessourceSharing` LIKE HeraProduction.`RessourceSharing`;
INSERT INTO `RessourceSharing` SELECT * FROM HeraProduction.`RessourceSharing`;

DROP TABLE IF EXISTS `agent_queue`;
CREATE TABLE `agent_queue` LIKE HeraProduction.`agent_queue`;
INSERT INTO `agent_queue` SELECT * FROM HeraProduction.`agent_queue`;

DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` LIKE HeraProduction.`audit_log`;
INSERT INTO `audit_log` SELECT * FROM HeraProduction.`audit_log`;

DROP TABLE IF EXISTS `authentication_data`;
CREATE TABLE `authentication_data` LIKE HeraProduction.`authentication_data`;
INSERT INTO `authentication_data` SELECT * FROM HeraProduction.`authentication_data`;

DROP TABLE IF EXISTS `channel_service_code`;
CREATE TABLE `channel_service_code` LIKE HeraProduction.`channel_service_code`;
INSERT INTO `channel_service_code` SELECT * FROM HeraProduction.`channel_service_code`;

DROP TABLE IF EXISTS `esim_profile`;
CREATE TABLE `esim_profile` LIKE HeraProduction.`esim_profile`;
INSERT INTO `esim_profile` SELECT * FROM HeraProduction.`esim_profile`;

DROP TABLE IF EXISTS `resource_sharing`;
CREATE TABLE `resource_sharing` LIKE HeraProduction.`resource_sharing`;
INSERT INTO `resource_sharing` SELECT * FROM HeraProduction.`resource_sharing`;

DROP TABLE IF EXISTS `sales_invoice`;
CREATE TABLE `sales_invoice` LIKE HeraProduction.`sales_invoice`;
INSERT INTO `sales_invoice` SELECT * FROM HeraProduction.`sales_invoice`;

DROP TABLE IF EXISTS `sales_order`;
CREATE TABLE `sales_order` LIKE HeraProduction.`sales_order`;
INSERT INTO `sales_order` SELECT * FROM HeraProduction.`sales_order`;

DROP TABLE IF EXISTS `sales_order_items`;
CREATE TABLE `sales_order_items` LIKE HeraProduction.`sales_order_items`;
INSERT INTO `sales_order_items` SELECT * FROM HeraProduction.`sales_order_items`;

DROP TABLE IF EXISTS `sso_data`;
CREATE TABLE `sso_data` LIKE HeraProduction.`sso_data`;
INSERT INTO `sso_data` SELECT * FROM HeraProduction.`sso_data`;

DROP TABLE IF EXISTS `subscription`;
CREATE TABLE `subscription` LIKE HeraProduction.`subscription`;
INSERT INTO `subscription` SELECT * FROM HeraProduction.`subscription`;

DROP TABLE IF EXISTS `subscription_new`;
CREATE TABLE `subscription_new` LIKE HeraProduction.`subscription_new`;
INSERT INTO `subscription_new` SELECT * FROM HeraProduction.`subscription_new`;

DROP TABLE IF EXISTS `subscription_plan`;
CREATE TABLE `subscription_plan` LIKE HeraProduction.`subscription_plan`;
INSERT INTO `subscription_plan` SELECT * FROM HeraProduction.`subscription_plan`;

DROP TABLE IF EXISTS `unique_number_subscription`;
CREATE TABLE `unique_number_subscription` LIKE HeraProduction.`unique_number_subscription`;
INSERT INTO `unique_number_subscription` SELECT * FROM HeraProduction.`unique_number_subscription`;

DROP TABLE IF EXISTS `vas_offers`;
CREATE TABLE `vas_offers` LIKE HeraProduction.`vas_offers`;
INSERT INTO `vas_offers` SELECT * FROM HeraProduction.`vas_offers`;

DROP TABLE IF EXISTS `vas_offers20230103`;
CREATE TABLE `vas_offers20230103` LIKE HeraProduction.`vas_offers20230103`;
INSERT INTO `vas_offers20230103` SELECT * FROM HeraProduction.`vas_offers20230103`;

DROP TABLE IF EXISTS `vas_offers20250820`;
CREATE TABLE `vas_offers20250820` LIKE HeraProduction.`vas_offers20250820`;
INSERT INTO `vas_offers20250820` SELECT * FROM HeraProduction.`vas_offers20250820`;

DROP TABLE IF EXISTS `vas_subscriptions`;
CREATE TABLE `vas_subscriptions` LIKE HeraProduction.`vas_subscriptions`;
INSERT INTO `vas_subscriptions` SELECT * FROM HeraProduction.`vas_subscriptions`;

DROP TABLE IF EXISTS `voting_contestant`;
CREATE TABLE `voting_contestant` LIKE HeraProduction.`voting_contestant`;
INSERT INTO `voting_contestant` SELECT * FROM HeraProduction.`voting_contestant`;

DROP TABLE IF EXISTS `voting_service`;
CREATE TABLE `voting_service` LIKE HeraProduction.`voting_service`;
INSERT INTO `voting_service` SELECT * FROM HeraProduction.`voting_service`;

SET FOREIGN_KEY_CHECKS=1;
