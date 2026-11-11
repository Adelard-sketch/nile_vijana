-- =====================================================================
-- Demo data for development and the class demo.
-- All names are fictional. Passwords:
--   admin@nilevijana.test  ->  Admin@123
--   everyone else           ->  Demo@123
-- Run AFTER schema.sql.
-- =====================================================================
USE nile_vijana;
SET NAMES utf8mb4;

INSERT INTO users (id, name, phone, email, password_hash) VALUES
 (1, 'Nile Vijana Admin', '250780000001', 'admin@nilevijana.test',    '$2y$10$L8lr9cm7bbYXgGwFAQ6k5.1J1Iuk5A5o5VPoWgnhh3R9TuE9l7UPC'),
 (2, 'Eric Producer',     '250780000002', 'producer@nilevijana.test', '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC'),
 (3, 'Aline Artist',      '250780000003', 'artist@nilevijana.test',   '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC'),
 (4, 'Jean Studio Owner', '250780000004', 'studio@nilevijana.test',   '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC'),
 (5, 'Grace Fan',         '250780000005', 'fan@nilevijana.test',      '$2y$10$fbndvLk./5OVT2sI5Qbrke3XWW//b09R50Zl2F6Ib9KPGiiedcNQC');

INSERT INTO user_roles (user_id, role) VALUES
 (1,'fan'),(1,'admin'),
 (2,'fan'),(2,'producer'),
 (3,'fan'),(3,'artist'),
 (4,'fan'),(4,'studio_owner'),
 (5,'fan');

INSERT INTO creator_profiles (user_id, stage_name, slug, type, bio, city, verified, payout_phone, payout_provider) VALUES
 (2, 'Kivu Beats', 'kivu-beats', 'producer', 'Afrobeat and Amapiano producer from Kigali.', 'Kigali', 1, '250780000002', 'MTN_MOMO_RWA'),
 (3, 'Aline U',    'aline-u',    'artist',   'Afro-pop singer and songwriter.',            'Kigali', 1, '250780000003', 'MTN_MOMO_RWA');

INSERT INTO studios (id, owner_id, name, slug, description, city, address, phone, equipment, amenities, backup_power, status) VALUES
 (1, 4, 'Inzozi Sound Lab', 'inzozi-sound-lab',
  'Recording, mixing and mastering for independent artists.',
  'Kigali', 'Kimihurura, Kigali', '250780000004',
  'Neumann TLM 103, Universal Audio Apollo Twin, Yamaha HS8 monitors, Logic Pro, FL Studio',
  'Air conditioning, Wi-Fi, lounge, parking', 1, 'verified');

INSERT INTO studio_rooms (id, studio_id, name, description, hourly_rate, min_hours, engineer_included) VALUES
 (1, 1, 'Room A - Vocal booth', 'Treated vocal booth with engineer.', 15000, 2, 1),
 (2, 1, 'Room B - Mix room',    'Mixing and mastering room.',         20000, 1, 1);

-- Opening hours: Monday-Saturday 09:00-21:00, Sunday 12:00-18:00
INSERT INTO room_hours (room_id, weekday, open_time, close_time) VALUES
 (1,1,'09:00','21:00'),(1,2,'09:00','21:00'),(1,3,'09:00','21:00'),(1,4,'09:00','21:00'),
 (1,5,'09:00','21:00'),(1,6,'09:00','21:00'),(1,7,'12:00','18:00'),
 (2,1,'09:00','21:00'),(2,2,'09:00','21:00'),(2,3,'09:00','21:00'),(2,4,'09:00','21:00'),
 (2,5,'09:00','21:00'),(2,6,'09:00','21:00'),(2,7,'12:00','18:00');
