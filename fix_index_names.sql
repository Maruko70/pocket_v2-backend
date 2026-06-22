-- SQL script to fix long index names in existing database
-- Run this if you've already migrated before the fix

-- Fix doctor_status_history table indexes
ALTER TABLE `doctor_status_history` 
  DROP INDEX IF EXISTS `doctor_status_history_doctor_id_from_date_to_date_index`,
  DROP INDEX IF EXISTS `doctor_status_history_is_auto_reactivate_to_date_processed_at_index`,
  DROP INDEX IF EXISTS `doctor_status_history_processed_at_index`;

ALTER TABLE `doctor_status_history`
  ADD INDEX `idx_doctor_status_doctor_dates` (`doctor_id`, `from_date`, `to_date`),
  ADD INDEX `idx_doctor_status_auto_reactivate` (`is_auto_reactivate`, `to_date`, `processed_at`),
  ADD INDEX `idx_doctor_status_processed` (`processed_at`);

-- Fix doctor_availability table indexes (if needed)
ALTER TABLE `doctor_availability`
  DROP INDEX IF EXISTS `doctor_availability_doctor_id_complex_id_date_index`,
  DROP INDEX IF EXISTS `doctor_availability_date_is_booked_index`,
  DROP INDEX IF EXISTS `doctor_availability_doctor_id_date_is_booked_index`,
  DROP INDEX IF EXISTS `doctor_availability_is_booked_index`;

ALTER TABLE `doctor_availability`
  ADD INDEX `idx_availability_doctor_complex_date` (`doctor_id`, `complex_id`, `date`),
  ADD INDEX `idx_availability_date_booked` (`date`, `is_booked`),
  ADD INDEX `idx_availability_doctor_date_booked` (`doctor_id`, `date`, `is_booked`),
  ADD INDEX `idx_availability_booked` (`is_booked`);

-- Fix reservations table indexes (if needed)
ALTER TABLE `reservations`
  DROP INDEX IF EXISTS `reservations_user_id_status_index`,
  DROP INDEX IF EXISTS `reservations_doctor_id_date_time_index`,
  DROP INDEX IF EXISTS `reservations_date_status_index`,
  DROP INDEX IF EXISTS `reservations_complex_id_date_index`,
  DROP INDEX IF EXISTS `reservations_status_index`,
  DROP INDEX IF EXISTS `reservations_date_index`;

ALTER TABLE `reservations`
  ADD INDEX `idx_reservations_user_status` (`user_id`, `status`),
  ADD INDEX `idx_reservations_doctor_datetime` (`doctor_id`, `date`, `time`),
  ADD INDEX `idx_reservations_date_status` (`date`, `status`),
  ADD INDEX `idx_reservations_complex_date` (`complex_id`, `date`),
  ADD INDEX `idx_reservations_status` (`status`),
  ADD INDEX `idx_reservations_date` (`date`);

-- Fix doctors table indexes (if needed)
ALTER TABLE `doctors`
  DROP INDEX IF EXISTS `doctors_status_created_at_index`;

ALTER TABLE `doctors`
  ADD INDEX `idx_doctors_status_created` (`status`, `created_at`);

-- Fix notifications table indexes (if needed)
ALTER TABLE `notifications`
  DROP INDEX IF EXISTS `notifications_user_id_sent_at_index`;

ALTER TABLE `notifications`
  ADD INDEX `idx_notifications_user_sent` (`user_id`, `sent_at`);

-- Fix reviews table indexes (if needed)
ALTER TABLE `reviews`
  DROP INDEX IF EXISTS `reviews_doctor_id_rating_index`;

ALTER TABLE `reviews`
  ADD INDEX `idx_reviews_doctor_rating` (`doctor_id`, `rating`);

-- Fix activity_logs table indexes (if needed)
ALTER TABLE `activity_logs`
  DROP INDEX IF EXISTS `activity_logs_admin_user_id_created_at_index`,
  DROP INDEX IF EXISTS `activity_logs_model_type_model_id_index`;

ALTER TABLE `activity_logs`
  ADD INDEX `idx_activity_logs_admin_created` (`admin_user_id`, `created_at`),
  ADD INDEX `idx_activity_logs_model` (`model_type`, `model_id`);

