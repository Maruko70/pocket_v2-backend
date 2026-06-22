-- Merge legacy dump data into current clean DB (FILL MISSING ONLY).
--
-- Source (staging) DB: CHANGE THIS to your staging database name:
--   pocket_legacy_stage
--
-- Target (clean/live) DB: pocket_v2
--
-- Guarantees:
-- - Inserts only when missing. Does NOT overwrite existing records.
-- - Users are inserted by unique phone (not by legacy id).
-- - Doctors/complexes are inserted only if that ID doesn't exist.
-- - doctor_complex uses INSERT IGNORE (unique pair).
-- - Reservations insert only if a matching reservation doesn't already exist
--   (dedupe key: doctor_id + complex_id + date + time + patient_phone).
--
-- Run:
--   cmd /c '"C:\xampp\mysql\bin\mysql.exe" -h 127.0.0.1 -P 3306 -u root pocket_v2 < "E:\wingz\projects\pocket\backend\scripts\merge_fill_missing.sql"'

SET @STAGE_DB := 'pocket_legacy_stage';

-- MySQL/MariaDB don't allow using @STAGE_DB as a schema name without dynamic SQL.
-- So, we hardcode the schema name in queries below.
-- IMPORTANT: if your staging DB name differs, find/replace it in this file.

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------
-- complexes (legacy complixes -> clean complexes) [insert missing by id]
-- ------------------------------------------------------------------
INSERT INTO pocket_v2.complexes (id, name, bio, logo, phone, address, booking_method, is_active, created_at, updated_at)
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
FROM pocket_legacy_stage.complixes c
LEFT JOIN pocket_v2.complexes x ON x.id = c.id
WHERE x.id IS NULL;

-- ------------------------------------------------------------------
-- doctors (legacy doctors -> clean doctors) [insert missing by id]
-- ------------------------------------------------------------------
INSERT INTO pocket_v2.doctors (id, name, bio, image, status, created_at, updated_at)
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
FROM pocket_legacy_stage.doctors d
LEFT JOIN pocket_v2.doctors x ON x.id = d.id
WHERE x.id IS NULL;

-- ------------------------------------------------------------------
-- users (legacy users -> clean users) [insert missing by phone]
-- ------------------------------------------------------------------
INSERT INTO pocket_v2.users (name, phone, date_of_birth, password, status, verified_at, remember_token, created_at, updated_at)
SELECT
  u.name,
  u.phone,
  STR_TO_DATE(NULLIF(u.birthdate, ''), '%d-%m-%Y') AS date_of_birth,
  u.password,
  CASE WHEN u.status = 1 THEN 'active' ELSE 'suspended' END AS status,
  u.phone_verified_at AS verified_at,
  u.remember_token,
  u.created_at,
  u.updated_at
FROM pocket_legacy_stage.users u
LEFT JOIN pocket_v2.users x ON x.phone = u.phone
WHERE x.id IS NULL;

-- ------------------------------------------------------------------
-- doctor_complex (legacy complix_doctors -> clean doctor_complex)
-- ------------------------------------------------------------------
INSERT IGNORE INTO pocket_v2.doctor_complex (doctor_id, complex_id, created_at, updated_at)
SELECT DISTINCT
  cd.doctor_id,
  cd.complix_id AS complex_id,
  cd.created_at,
  cd.updated_at
FROM pocket_legacy_stage.complix_doctors cd
WHERE EXISTS (SELECT 1 FROM pocket_v2.doctors d WHERE d.id = cd.doctor_id)
  AND EXISTS (SELECT 1 FROM pocket_v2.complexes c WHERE c.id = cd.complix_id);

-- ------------------------------------------------------------------
-- reservations (legacy recs -> clean reservations) [fill missing by dedupe key]
-- Map legacy user->current user via phone to avoid id collisions.
-- ------------------------------------------------------------------
INSERT INTO pocket_v2.reservations (
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
  u2.id AS user_id,
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
JOIN pocket_legacy_stage.users u ON u.id = r.userid
JOIN pocket_v2.users u2 ON u2.phone = u.phone
LEFT JOIN pocket_v2.reservations ex ON
  ex.doctor_id = r.doctorid
  AND ex.complex_id = r.complixid
  AND ex.date = STR_TO_DATE(r.date, '%Y-%m-%d')
  AND ex.patient_phone = r.phone
  AND (
    (ex.time IS NULL AND r.time IS NULL)
    OR (ex.time = TIME(STR_TO_DATE(r.time, '%H:%i')))
  )
WHERE r.complixid IS NOT NULL
  AND ex.id IS NULL
  AND EXISTS (SELECT 1 FROM pocket_v2.doctors d WHERE d.id = r.doctorid)
  AND EXISTS (SELECT 1 FROM pocket_v2.complexes c WHERE c.id = r.complixid);

SET FOREIGN_KEY_CHECKS = 1;

