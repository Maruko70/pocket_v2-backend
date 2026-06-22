-- Import from legacy staging DB into clean import DB.
-- Source DB: pocket_legacy_stage
-- Target DB: pocket_v2_import
--
-- This script PRESERVES legacy IDs and maps legacy columns to the clean schema.
-- Run with:
--   mysql -u root -h 127.0.0.1 -P 3306 pocket_v2_import < scripts/import_stage_to_clean.sql

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------
-- complexes (complixes -> complexes)
-- -----------------------
INSERT INTO complexes (id, name, bio, logo, phone, address, booking_method, is_active, created_at, updated_at)
SELECT
  c.id,
  c.name,
  NULL AS bio,
  NULLIF(LEFT(c.logo, 255), '') AS logo,
  NULLIF(c.phone, '') AS phone,
  NULLIF(c.location, '') AS address,
  'slots' AS booking_method,
  CASE WHEN c.status = 1 THEN 1 ELSE 0 END AS is_active,
  c.created_at,
  c.updated_at
FROM pocket_legacy_stage.complixes c;

-- -----------------------
-- doctors (doctors -> doctors)
-- -----------------------
INSERT INTO doctors (id, name, bio, image, status, created_at, updated_at)
SELECT
  d.id,
  d.name,
  NULLIF(d.about, '') AS bio,
  CASE
    WHEN d.profile IS NULL THEN NULL
    WHEN d.profile LIKE '%default-user-image%' THEN NULL
    WHEN d.profile LIKE '%pngitem.com/pimgs/m/150-1503945%' THEN NULL
    ELSE LEFT(d.profile, 255)
  END AS image,
  CASE WHEN d.status = 1 THEN 'active' ELSE 'suspended' END AS status,
  d.created_at,
  d.updated_at
FROM pocket_legacy_stage.doctors d;

-- -----------------------
-- users (users -> users)
-- -----------------------
INSERT INTO users (id, name, phone, date_of_birth, password, status, verified_at, remember_token, created_at, updated_at)
SELECT
  u.id,
  u.name,
  u.phone,
  STR_TO_DATE(NULLIF(u.birthdate, ''), '%d-%m-%Y') AS date_of_birth,
  u.password,
  CASE WHEN u.status = 1 THEN 'active' ELSE 'suspended' END AS status,
  u.phone_verified_at AS verified_at,
  u.remember_token,
  u.created_at,
  u.updated_at
FROM pocket_legacy_stage.users u;

-- -----------------------
-- doctor_complex (complix_doctors -> doctor_complex)
-- -----------------------
INSERT IGNORE INTO doctor_complex (doctor_id, complex_id, created_at, updated_at)
SELECT DISTINCT
  cd.doctor_id,
  cd.complix_id AS complex_id,
  cd.created_at,
  cd.updated_at
FROM pocket_legacy_stage.complix_doctors cd;

-- -----------------------
-- reservations (recs -> reservations)
-- Notes:
-- - legacy date/time are varchar; we cast them.
-- - legacy complixid can be NULL; we skip those rows.
-- - ticket_number is NULL (not present in legacy).
-- -----------------------
INSERT INTO reservations (
  id,
  user_id,
  doctor_id,
  complex_id,
  date,
  time,
  ticket_number,
  patient_name,
  patient_phone,
  status,
  created_at,
  updated_at,
  created_by_admin_user_id,
  created_source
)
SELECT
  r.id,
  r.userid AS user_id,
  r.doctorid AS doctor_id,
  r.complixid AS complex_id,
  STR_TO_DATE(r.date, '%Y-%m-%d') AS date,
  TIME(STR_TO_DATE(r.time, '%H:%i')) AS time,
  NULL AS ticket_number,
  r.name AS patient_name,
  r.phone AS patient_phone,
  CASE r.status
    WHEN 1 THEN 'pending'
    WHEN 2 THEN 'confirmed'
    WHEN 3 THEN 'cancelled'
    WHEN 5 THEN 'completed'
    ELSE 'pending'
  END AS status,
  r.created_at,
  r.updated_at,
  NULL AS created_by_admin_user_id,
  NULL AS created_source
FROM pocket_legacy_stage.recs r
WHERE r.complixid IS NOT NULL
  AND EXISTS (SELECT 1 FROM users u2 WHERE u2.id = r.userid)
  AND EXISTS (SELECT 1 FROM doctors d2 WHERE d2.id = r.doctorid)
  AND EXISTS (SELECT 1 FROM complexes c2 WHERE c2.id = r.complixid);

SET FOREIGN_KEY_CHECKS = 1;

