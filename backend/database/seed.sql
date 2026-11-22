-- =====================================================================
-- Demo data — LOCAL version (plain table names, no USE statement).
-- Run AFTER schema.sql on your local nile_vijana database.
-- Passwords:
--   admin@nilevijana.test  ->  Admin@123
--   everyone else           ->  Demo@123
-- =====================================================================
SET NAMES utf8mb4;

INSERT INTO users (id, name, username, phone, email, email_verified_at, country, password_hash) VALUES
 (1, 'Nile Vijana Admin', 'admin',    '233240000001', 'admin@nilevijana.test',    NOW(), 'GH', '$2y$10$L8lr9cm7bbYXgGwFAQ6k5.1J1Iuk5A5o5VPoWgnhh3R9TuE9l7UPC'),
 (2, 'Kwabena Owusu',     'producer', '233240000002', 'producer@nilevijana.test', NOW(), 'GH', '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC'),
 (3, 'Efua Mensah',       'artist',   '233240000003', 'artist@nilevijana.test',   NOW(), 'GH', '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC'),
 (4, 'Yaw Boateng',       'studio',   '233240000004', 'studio@nilevijana.test',   NOW(), 'GH', '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC'),
 (5, 'Abena Darko',       'fan',      '233240000005', 'fan@nilevijana.test',      NOW(), 'GH', '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC'),
 (6, 'Harmony Voices',    'choir',    '233240000006', 'choir@nilevijana.test',    NOW(), 'GH', '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC'),
 (7, 'Kojo Addo',         'manager',  '233240000007', 'manager@nilevijana.test',  NOW(), 'GH', '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC'),
 (8, 'Akosua Nyarko',     'coach',    '233240000008', 'coach@nilevijana.test',    NOW(), 'GH', '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC');

INSERT INTO user_roles (user_id, role) VALUES
 (1,'fan'),(1,'admin'),
 (2,'fan'),(2,'producer'),
 (3,'fan'),(3,'artist'),
 (4,'fan'),(4,'studio_owner'),
 (5,'fan'),
 (6,'fan'),(6,'choir'),
 (7,'fan'),(7,'manager'),
 (8,'fan'),(8,'vocal_coach');

INSERT INTO creator_profiles (user_id, stage_name, slug, type, bio, city, verified, payout_phone, payout_provider) VALUES
 (2, 'Kobby Keys',     'kobby-keys',     'producer',    'Afrobeats, Highlife and Amapiano producer from Kumasi.', 'Kumasi',     1, '233240000002', 'MTN'),
 (3, 'Efua Mensah',    'efua-mensah',    'artist',      'Afro-pop and Highlife singer-songwriter from Accra.',   'Accra',      1, '233240000003', 'MTN'),
 (6, 'Harmony Voices', 'harmony-voices', 'choir',       'Gospel choir from Cape Coast singing in Fante, Twi and English.', 'Cape Coast', 1, '233240000006', 'MTN'),
 (7, 'Kojo Addo',      'kojo-addo',      'manager',     'Artist manager working with Accra and Kumasi acts.',   'Accra',      1, NULL, NULL),
 (8, 'Akosua Nyarko',  'akosua-nyarko',  'vocal_coach', 'Vocal coach for choirs and solo artists.',             'Accra',      1, NULL, NULL);

UPDATE creator_profiles SET genres = 'Afrobeats, Highlife, Amapiano' WHERE user_id = 2;
UPDATE creator_profiles SET genres = 'Afro-pop, Highlife' WHERE user_id = 3;
UPDATE creator_profiles SET genres = 'Gospel, Traditional', members_count = 32 WHERE user_id = 6;
UPDATE creator_profiles SET company = 'Addo Music Management' WHERE user_id = 7;
UPDATE creator_profiles SET experience_years = 9, specialization = 'Breath control, gospel runs, stage presence' WHERE user_id = 8;

INSERT INTO manager_artists (manager_id, artist_id) VALUES (7, 3), (7, 6);

INSERT INTO mentor_profiles (user_id, headline, specialties, active) VALUES
 (8, 'Vocal coach for choirs and solo artists', 'Breath control, gospel runs, stage presence', 1);

INSERT INTO studios (id, owner_id, name, slug, description, city, address, phone, equipment, amenities, backup_power, status) VALUES
 (1, 4, 'Osu Sound Lab', 'osu-sound-lab',
  'Recording, mixing and mastering for independent artists in the heart of Osu.',
  'Accra', 'Oxford Street, Osu, Accra', '233240000004',
  'Neumann TLM 103, Universal Audio Apollo Twin, Yamaha HS8 monitors, Logic Pro, FL Studio',
  'Air conditioning, Wi-Fi, lounge, parking', 1, 'verified');

INSERT INTO studio_rooms (id, studio_id, name, description, hourly_rate, min_hours, engineer_included) VALUES
 (1, 1, 'Room A - Vocal booth', 'Treated vocal booth with engineer.', 150, 2, 1),
 (2, 1, 'Room B - Mix room',    'Mixing and mastering room.',         250, 1, 1);

INSERT INTO room_hours (room_id, weekday, open_time, close_time) VALUES
 (1,1,'09:00','21:00'),(1,2,'09:00','21:00'),(1,3,'09:00','21:00'),(1,4,'09:00','21:00'),
 (1,5,'09:00','21:00'),(1,6,'09:00','21:00'),(1,7,'12:00','18:00'),
 (2,1,'09:00','21:00'),(2,2,'09:00','21:00'),(2,3,'09:00','21:00'),(2,4,'09:00','21:00'),
 (2,5,'09:00','21:00'),(2,6,'09:00','21:00'),(2,7,'12:00','18:00');

INSERT INTO featured_spotify_embeds (title, embed_type, spotify_id, display_order) VALUES
 ('Featured song 1',     'track',    '3xo7tCrLjBGrg1QbVUasFX', 1),
 ('Featured song 2',     'track',    '4iJJOsuXC9Y3Xp3wrmfl24', 2),
 ('Featured playlist 1', 'playlist', '37i9dQZF1DXcBWIGoYBM5M', 1),
 ('Featured playlist 2', 'playlist', '37i9dQZF1DWYkaDif7Ztbp', 2)
ON DUPLICATE KEY UPDATE
  title         = VALUES(title),
  display_order = VALUES(display_order),
  is_active     = 1;
