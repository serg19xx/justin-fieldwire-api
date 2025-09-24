-- Folders table
CREATE TABLE fw_plan_folders (
  id SERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  parent_id INTEGER REFERENCES fw_plan_folders(id) ON DELETE CASCADE,
  project_id INTEGER NOT NULL,
  created_at TIMESTAMP DEFAULT NOW(),
  updated_at TIMESTAMP DEFAULT NOW()
);

-- Files table
CREATE TABLE fw_plan_files (
  id SERIAL PRIMARY KEY,
  file_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL UNIQUE,
  folder_id INTEGER NOT NULL REFERENCES fw_plan_folders(id) ON DELETE CASCADE,
  file_size BIGINT NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  category VARCHAR(100),
  description TEXT,
  version VARCHAR(50),
  uploaded_by INTEGER NOT NULL,
  uploaded_at TIMESTAMP DEFAULT NOW(),
  updated_at TIMESTAMP DEFAULT NOW()
);

-- Indexes
CREATE INDEX idx_fw_plan_folders_parent_id ON fw_plan_folders(parent_id);
CREATE INDEX idx_fw_plan_folders_project_id ON fw_plan_folders(project_id);
CREATE INDEX idx_fw_plan_folders_name ON fw_plan_folders(name);
CREATE INDEX idx_fw_plan_files_folder_id ON fw_plan_files(folder_id);
CREATE INDEX idx_fw_plan_files_uploaded_by ON fw_plan_files(uploaded_by);
CREATE INDEX idx_fw_plan_files_category ON fw_plan_files(category);
