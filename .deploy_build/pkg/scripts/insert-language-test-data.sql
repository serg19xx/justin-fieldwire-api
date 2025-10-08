-- Insert test data for languages
-- Date: 2025-10-03

-- Insert sample languages
INSERT INTO fw_languages (name) VALUES 
('English'),
('French'),
('Spanish'),
('German'),
('Italian'),
('Portuguese'),
('Russian'),
('Chinese'),
('Japanese'),
('Arabic'),
('Hindi'),
('Ukrainian');

-- Insert sample worker languages for user ID 47 (pm1@example.com)
INSERT INTO fw_worker_languages (worker_id, language_id, prof_level) VALUES 
(47, 1, 'Fluent'),  -- English
(47, 2, 'Intermidiate'),  -- French
(47, 3, 'Basic');  -- Spanish

-- Insert sample worker languages for user ID 48 (if exists)
INSERT INTO fw_worker_languages (worker_id, language_id, prof_level) VALUES 
(48, 1, 'Fluent'),  -- English
(48, 4, 'Intermidiate'),  -- German
(48, 7, 'Basic');  -- Russian
