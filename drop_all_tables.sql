-- SQL script to drop all tables in correct order (child tables first)
-- Use this if you need to manually reset the database

SET FOREIGN_KEY_CHECKS = 0;

-- Drop child tables first
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `user_favorites`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `doctor_status_history`;
DROP TABLE IF EXISTS `reservations`;
DROP TABLE IF EXISTS `doctor_availability`;
DROP TABLE IF EXISTS `doctor_major`;
DROP TABLE IF EXISTS `doctor_complex`;
DROP TABLE IF EXISTS `admin_users`;

-- Drop parent tables
DROP TABLE IF EXISTS `doctors`;
DROP TABLE IF EXISTS `complexes`;
DROP TABLE IF EXISTS `majors`;
DROP TABLE IF EXISTS `users`;

-- Drop Laravel system tables
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `cache`;
DROP TABLE IF EXISTS `cache_locks`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `job_batches`;
DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `migrations`;

SET FOREIGN_KEY_CHECKS = 1;

