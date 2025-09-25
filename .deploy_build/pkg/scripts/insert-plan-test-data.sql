-- Test folders for project_id = 10
INSERT INTO fw_plan_folders (name, parent_id, project_id) VALUES 
('Root', NULL, 10),
('Project Plans', 1, 10),
('Drawings', 2, 10),
('Specifications', 2, 10),
('Photos', 1, 10),
('Documents', 1, 10);

-- Test files
INSERT INTO fw_plan_files (file_name, original_name, file_path, folder_id, file_size, mime_type, category, description, version, uploaded_by) VALUES 
('plan.pdf', 'project_plan.pdf', '/uploads/plan.pdf', 2, 1024000, 'application/pdf', 'plans', 'Main project plan', '1.0', 1),
('drawing.dwg', 'elevation.dwg', '/uploads/drawing.dwg', 3, 2048000, 'application/dwg', 'drawings', 'Building elevation', '1.0', 1),
('photo.jpg', 'site_photo.jpg', '/uploads/photo.jpg', 5, 512000, 'image/jpeg', 'photos', 'Site photo', '1.0', 1);
