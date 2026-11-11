-- =====================================================================
-- Nile Vijana Creatives — MySQL / MariaDB schema
-- Works on MySQL 8+ and MariaDB 10.4+ (XAMPP ships MariaDB).
-- Money is stored as whole Ugandan shillings (UGX, no decimals).
-- Import in phpMyAdmin (Import tab) or:  mysql -u root < database/schema.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS nile_vijana
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nile_vijana;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  phone         VARCHAR(20)  NULL UNIQUE,          -- e.g. 250781234567
  email         VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,                 -- NULL for phone-code-only users
  country       CHAR(2)      NOT NULL DEFAULT 'UG',
  language      VARCHAR(5)   NOT NULL DEFAULT 'en', -- en, sw, fr
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id    BIGINT UNSIGNED NOT NULL,
  role       ENUM('fan','artist','producer','studio_owner','admin') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role),
  CONSTRAINT fk_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL UNIQUE,            -- sha256 of the bearer token
  expires_at   DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS otp_codes (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone      VARCHAR(20) NOT NULL,
  code_hash  CHAR(64) NOT NULL,
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip         VARCHAR(45) NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_otp_phone (phone, created_at),
  KEY idx_otp_ip (ip, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS creator_profiles (
  user_id         BIGINT UNSIGNED PRIMARY KEY,
  stage_name      VARCHAR(80)  NOT NULL,
  slug            VARCHAR(90)  NOT NULL UNIQUE,     -- public page: /@slug
  type            ENUM('artist','producer','both') NOT NULL DEFAULT 'artist',
  bio             TEXT NULL,
  avatar_path     VARCHAR(255) NULL,
  city            VARCHAR(80)  NULL,
  verified        TINYINT(1)   NOT NULL DEFAULT 0,
  payout_phone    VARCHAR(20)  NULL,
  payout_provider VARCHAR(30)  NULL,               
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_creator_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Studios and bookings
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS studios (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id     BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  slug         VARCHAR(140) NOT NULL UNIQUE,
  description  TEXT NULL,
  city         VARCHAR(80)  NOT NULL,
  address      VARCHAR(255) NULL,
  latitude     DECIMAL(9,6) NULL,
  longitude    DECIMAL(9,6) NULL,
  phone        VARCHAR(20)  NULL,
  equipment    TEXT NULL,
  amenities    TEXT NULL,
  backup_power TINYINT(1) NOT NULL DEFAULT 0,
  cover_path   VARCHAR(255) NULL,
  status       ENUM('pending','verified','rejected','suspended') NOT NULL DEFAULT 'pending',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_studios_city (city, status),
  CONSTRAINT fk_studios_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS studio_rooms (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  studio_id         BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(120) NOT NULL,
  description       TEXT NULL,
  hourly_rate       INT UNSIGNED NOT NULL,           
  min_hours         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  engineer_included TINYINT(1) NOT NULL DEFAULT 0,
  photo_path        VARCHAR(255) NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rooms_studio FOREIGN KEY (studio_id) REFERENCES studios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Weekly opening hours. weekday: 1 = Monday ... 7 = Sunday (ISO-8601)
CREATE TABLE IF NOT EXISTS room_hours (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id    BIGINT UNSIGNED NOT NULL,
  weekday    TINYINT UNSIGNED NOT NULL,
  open_time  TIME NOT NULL,
  close_time TIME NOT NULL,
  UNIQUE KEY uq_room_weekday (room_id, weekday),
  CONSTRAINT fk_hours_room FOREIGN KEY (room_id) REFERENCES studio_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_blocked_dates (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id      BIGINT UNSIGNED NOT NULL,
  blocked_date DATE NOT NULL,
  reason       VARCHAR(255) NULL,
  UNIQUE KEY uq_room_blocked (room_id, blocked_date),
  CONSTRAINT fk_blocked_room FOREIGN KEY (room_id) REFERENCES studio_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bookings (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id         BIGINT UNSIGNED NOT NULL,
  artist_id       BIGINT UNSIGNED NOT NULL,
  start_at        DATETIME NOT NULL,
  end_at          DATETIME NOT NULL,
  hours           TINYINT UNSIGNED NOT NULL,
  hourly_rate     INT UNSIGNED NOT NULL,             -- snapshot at booking time
  total           INT UNSIGNED NOT NULL,
  deposit         INT UNSIGNED NOT NULL,
  amount_paid     INT UNSIGNED NOT NULL DEFAULT 0,   -- paid in the app so far
  status          ENUM('held','confirmed','completed','cancelled','no_show','expired') NOT NULL DEFAULT 'held',
  cancelled_by    ENUM('artist','studio','admin','system') NULL,
  hold_expires_at DATETIME NULL,
  notes           VARCHAR(500) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_bookings_room_time (room_id, start_at),
  KEY idx_bookings_artist (artist_id),
  CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES studio_rooms(id),
  CONSTRAINT fk_bookings_artist FOREIGN KEY (artist_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Music catalogue
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS albums (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  creator_id   BIGINT UNSIGNED NOT NULL,
  title        VARCHAR(160) NOT NULL,
  cover_path   VARCHAR(255) NULL,
  release_date DATE NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_albums_creator FOREIGN KEY (creator_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tracks (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  creator_id    BIGINT UNSIGNED NOT NULL,
  album_id      BIGINT UNSIGNED NULL,
  title         VARCHAR(160) NOT NULL,
  genre         VARCHAR(60)  NULL,
  mood          VARCHAR(60)  NULL,
  language      VARCHAR(30)  NULL,
  duration_sec  INT UNSIGNED NULL,
  original_path VARCHAR(255) NOT NULL,              -- private, full quality (sold)
  stream_path   VARCHAR(255) NULL,                  -- private, low bitrate (free streaming)
  preview_path  VARCHAR(255) NULL,                  -- short clip
  cover_path    VARCHAR(255) NULL,                  -- public image
  price         INT UNSIGNED NOT NULL DEFAULT 0,    -- 0 = stream only, not for sale
  status        ENUM('processing','in_review','live','rejected','removed') NOT NULL DEFAULT 'processing',
  play_count    INT UNSIGNED NOT NULL DEFAULT 0,
  released_at   DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tracks_status (status, released_at),
  KEY idx_tracks_creator (creator_id),
  CONSTRAINT fk_tracks_creator FOREIGN KEY (creator_id) REFERENCES users(id),
  CONSTRAINT fk_tracks_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS beats (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  producer_id  BIGINT UNSIGNED NOT NULL,
  title        VARCHAR(160) NOT NULL,
  bpm          SMALLINT UNSIGNED NULL,
  musical_key  VARCHAR(12)  NULL,                   -- e.g. "A min", "F# maj"
  genre        VARCHAR(60)  NULL,                   -- Afrobeat, Amapiano, Drill, Gospel...
  mood         VARCHAR(60)  NULL,
  tags         VARCHAR(255) NULL,
  mp3_path     VARCHAR(255) NOT NULL,               -- private
  wav_path     VARCHAR(255) NULL,                   -- private
  stems_path   VARCHAR(255) NULL,                   -- private (zip)
  preview_path VARCHAR(255) NULL,                   -- tagged short preview
  cover_path   VARCHAR(255) NULL,
  status       ENUM('processing','in_review','live','sold_exclusive','rejected','removed') NOT NULL DEFAULT 'processing',
  play_count   INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_beats_status (status, created_at),
  KEY idx_beats_producer (producer_id),
  CONSTRAINT fk_beats_producer FOREIGN KEY (producer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS license_tiers (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  beat_id    BIGINT UNSIGNED NOT NULL,
  tier       ENUM('mp3_lease','wav_lease','unlimited','exclusive') NOT NULL,
  price      INT UNSIGNED NOT NULL,
  stream_cap INT UNSIGNED NULL,                     -- NULL = unlimited streams
  active     TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_beat_tier (beat_id, tier),
  CONSTRAINT fk_tiers_beat FOREIGN KEY (beat_id) REFERENCES beats(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Orders, payments and money
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  buyer_id   BIGINT UNSIGNED NOT NULL,
  total      INT UNSIGNED NOT NULL,
  currency   CHAR(3) NOT NULL DEFAULT 'UGX',
  status     ENUM('pending','paid','failed','expired','refunded') NOT NULL DEFAULT 'pending',
  phone      VARCHAR(20) NOT NULL,
  provider   VARCHAR(30) NOT NULL,
  paid_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_orders_buyer (buyer_id, created_at),
  KEY idx_orders_status (status, created_at),
  CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        BIGINT UNSIGNED NOT NULL,
  item_type       ENUM('track','license','tip') NOT NULL,
  track_id        BIGINT UNSIGNED NULL,
  beat_id         BIGINT UNSIGNED NULL,
  license_tier_id BIGINT UNSIGNED NULL,
  tier            ENUM('mp3_lease','wav_lease','unlimited','exclusive') NULL, -- snapshot
  seller_id       BIGINT UNSIGNED NOT NULL,
  title           VARCHAR(200) NOT NULL,             -- snapshot for receipts
  price           INT UNSIGNED NOT NULL,
  commission      INT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_items_seller (seller_id),
  KEY idx_items_beat (beat_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_seller FOREIGN KEY (seller_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- One row per mobile money collection attempt (pawaPay "deposit").
CREATE TABLE IF NOT EXISTS payments (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id       CHAR(36) NOT NULL UNIQUE,          -- UUID sent to pawaPay as depositId
  purpose         ENUM('order','booking_deposit','booking_balance') NOT NULL,
  order_id        BIGINT UNSIGNED NULL,
  booking_id      BIGINT UNSIGNED NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  gateway         VARCHAR(20) NOT NULL,              -- pawapay | sandbox
  provider        VARCHAR(30) NOT NULL,              -- MTN_MOMO_RWA, AIRTEL_RWA ...
  phone           VARCHAR(20) NOT NULL,
  amount          INT UNSIGNED NOT NULL,
  currency        CHAR(3) NOT NULL DEFAULT 'UGX',
  status          ENUM('initiated','accepted','completed','failed') NOT NULL DEFAULT 'initiated',
  failure_reason  VARCHAR(255) NULL,
  provider_txn_id VARCHAR(100) NULL,
  last_checked_at DATETIME NULL,
  completed_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_payments_status (status, created_at),
  KEY idx_payments_order (order_id),
  KEY idx_payments_booking (booking_id),
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Append-only ledger. Never UPDATE or DELETE rows here.
-- A wallet balance = SUM(amount) of a user's rows.
--   account 'user'     -> a person's wallet (user_id set)
--   account 'platform' -> Nile Vijana commission (user_id NULL)
--   account 'escrow'   -> studio deposits held until the session (ref = booking)
CREATE TABLE IF NOT EXISTS ledger_entries (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account    ENUM('user','platform','escrow') NOT NULL,
  user_id    BIGINT UNSIGNED NULL,
  amount     BIGINT NOT NULL,                        -- + credit, - debit
  currency   CHAR(3) NOT NULL DEFAULT 'UGX',
  type       ENUM('sale','tip','commission','refund','booking_escrow','booking_release','booking_refund','payout','payout_reversal') NOT NULL,
  ref_type   VARCHAR(30) NOT NULL,                   -- order_item, order, booking, payout
  ref_id     BIGINT UNSIGNED NOT NULL,
  memo       VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ledger_account (account, user_id),
  KEY idx_ledger_ref (ref_type, ref_id),
  KEY idx_ledger_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payouts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id       CHAR(36) NOT NULL UNIQUE,          -- UUID sent to pawaPay as payoutId
  user_id         BIGINT UNSIGNED NOT NULL,
  amount          INT UNSIGNED NOT NULL,
  currency        CHAR(3) NOT NULL DEFAULT 'UGX',
  phone           VARCHAR(20) NOT NULL,
  provider        VARCHAR(30) NOT NULL,
  gateway         VARCHAR(20) NULL,                  -- set when an admin approves it
  status          ENUM('requested','processing','completed','failed','rejected') NOT NULL DEFAULT 'requested',
  failure_reason  VARCHAR(255) NULL,
  provider_txn_id VARCHAR(100) NULL,
  approved_by     BIGINT UNSIGNED NULL,
  last_checked_at DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_payouts_status (status, created_at),
  KEY idx_payouts_user (user_id),
  CONSTRAINT fk_payouts_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Engagement
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS play_events (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id       BIGINT UNSIGNED NOT NULL,
  listener_id    BIGINT UNSIGNED NULL,              -- NULL for anonymous listeners
  session_id     VARCHAR(64) NOT NULL,
  seconds_played INT UNSIGNED NOT NULL,
  counted        TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = counts as a play (>= 30 s)
  city           VARCHAR(80) NULL,
  device         VARCHAR(40) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_plays_track_time (track_id, created_at),
  KEY idx_plays_session (session_id, track_id, created_at),
  CONSTRAINT fk_plays_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS follows (
  follower_id BIGINT UNSIGNED NOT NULL,
  creator_id  BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (follower_id, creator_id),
  KEY idx_follows_creator (creator_id),
  CONSTRAINT fk_follows_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_follows_creator FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Promotion and distribution opportunities for artists
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS opportunities (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_by   BIGINT UNSIGNED NOT NULL,
  category     ENUM('promotion','distribution') NOT NULL,
  partner_name VARCHAR(120) NOT NULL,
  partner_url  VARCHAR(500) NULL,
  title        VARCHAR(160) NOT NULL,
  description  TEXT NOT NULL,
  eligibility  TEXT NULL,
  location     VARCHAR(100) NULL,
  deadline     DATE NULL,
  status       ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_opportunities_public (status, category, deadline),
  CONSTRAINT fk_opportunities_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS opportunity_applications (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  opportunity_id BIGINT UNSIGNED NOT NULL,
  creator_id     BIGINT UNSIGNED NOT NULL,
  pitch          TEXT NOT NULL,
  status         ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  decision_note  VARCHAR(1000) NULL,
  decided_by     BIGINT UNSIGNED NULL,
  decided_at     DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_opportunity_application (opportunity_id, creator_id),
  KEY idx_applications_creator (creator_id, created_at),
  KEY idx_applications_status (opportunity_id, status, created_at),
  CONSTRAINT fk_applications_opportunity FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
  CONSTRAINT fk_applications_creator FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_applications_decider FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Music mentors and session requests
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mentor_profiles (
  user_id      BIGINT UNSIGNED PRIMARY KEY,
  headline     VARCHAR(160) NOT NULL,
  specialties  VARCHAR(500) NOT NULL,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mentors_active (active),
  CONSTRAINT fk_mentor_profile_creator FOREIGN KEY (user_id) REFERENCES creator_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mentor_bookings (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mentor_id       BIGINT UNSIGNED NOT NULL,
  mentee_id       BIGINT UNSIGNED NOT NULL,
  start_at        DATETIME NOT NULL,
  end_at          DATETIME NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  subject         VARCHAR(160) NOT NULL,
  message         VARCHAR(1000) NULL,
  status          ENUM('requested','confirmed','declined','cancelled','completed') NOT NULL DEFAULT 'requested',
  mentor_note     VARCHAR(1000) NULL,
  responded_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mentor_bookings_time (mentor_id, status, start_at),
  KEY idx_mentor_bookings_mentee (mentee_id, created_at),
  CONSTRAINT fk_mentor_bookings_mentor FOREIGN KEY (mentor_id) REFERENCES mentor_profiles(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_mentor_bookings_mentee FOREIGN KEY (mentee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS likes (
  user_id    BIGINT UNSIGNED NOT NULL,
  track_id   BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, track_id),
  KEY idx_likes_track (track_id),
  CONSTRAINT fk_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_likes_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  target_type ENUM('studio','beat','track') NOT NULL,
  target_id   BIGINT UNSIGNED NOT NULL,
  rating      TINYINT UNSIGNED NOT NULL,            -- 1..5
  comment     TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review_once (user_id, target_type, target_id),
  KEY idx_reviews_target (target_type, target_id),
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id     BIGINT UNSIGNED NOT NULL,
  target_type     ENUM('track','beat','studio','user') NOT NULL,
  target_id       BIGINT UNSIGNED NOT NULL,
  reason          ENUM('copyright','abuse','fraud','other') NOT NULL,
  details         TEXT NULL,
  status          ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolved_by     BIGINT UNSIGNED NULL,
  resolution_note VARCHAR(500) NULL,
  resolved_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reports_status (status, created_at),
  CONSTRAINT fk_reports_user FOREIGN KEY (reporter_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS downloads (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_item_id BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  file_kind     VARCHAR(12) NOT NULL,               -- mp3, wav, stems, track, license
  ip            VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_downloads_item (order_item_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Background jobs (run by: php bin/worker.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jobs (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type       VARCHAR(40) NOT NULL,                  -- process_track, process_beat
  payload    TEXT NOT NULL,                         -- JSON
  status     ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  run_after  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_jobs_queue (status, run_after)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
