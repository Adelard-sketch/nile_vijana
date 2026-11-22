-- =====================================================================
-- reset_local.sql — Wipe and recreate the local nile_vijana database.
-- Run this in phpMyAdmin (select no database, paste and run).
-- Then import schema.sql, then seed.sql.
-- =====================================================================

DROP DATABASE IF EXISTS nile_vijana;
CREATE DATABASE nile_vijana
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE nile_vijana;
