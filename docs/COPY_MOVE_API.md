# Copy/Move API Endpoints

## Overview
API endpoints for copying and moving files and folders in the Fieldwire application. Copy operations create physical copies of files on disk with unique names, while move operations only update database records.

## File Operations

### Move File to Different Folder
**Endpoint:** `PUT /api/v1/plan/files/{fileId}/move`

**Request:**
```json
{
  "folder_id": 123
}
```

**Response (Success - 200):**
```json
{
  "id": 456,
  "file_name": "document_1234567890_abc123.pdf",
  "original_name": "document.pdf",
  "file_path": "/uploads/plan/document_1234567890_abc123.pdf",
  "folder_id": 123,
  "file_size": 1024000,
  "mime_type": "application/pdf",
  "category": "document",
  "description": "Project documentation",
  "version": "1.0",
  "uploaded_by": 1,
  "uploaded_at": "2024-01-15T10:30:00Z",
  "updated_at": "2024-01-15T10:30:00Z"
}
```

### Copy File to Different Folder
**Endpoint:** `POST /api/v1/plan/files/{fileId}/copy`

**Request:**
```json
{
  "folder_id": 123,
  "file_name": "document_copy.pdf"  // Optional: new name for the copy
}
```

**Response (Success - 201):**
```json
{
  "id": 789,
  "file_name": "document_1234567890_abc123.pdf",
  "original_name": "document_copy.pdf",
  "file_path": "/uploads/plan/document_1234567890_abc123.pdf",
  "folder_id": 123,
  "file_size": 1024000,
  "mime_type": "application/pdf",
  "category": "document",
  "description": "Project documentation",
  "version": "1.0",
  "uploaded_by": 1,
  "uploaded_at": "2024-01-15T10:30:00Z",
  "updated_at": "2024-01-15T10:30:00Z"
}
```

## Folder Operations

### Move Folder to Different Parent
**Endpoint:** `PUT /api/v1/plan/folders/{folderId}/move`

**Request:**
```json
{
  "parent_id": 456  // null for root level
}
```

**Response (Success - 200):**
```json
{
  "id": 123,
  "name": "Project Documents",
  "parent_id": 456,
  "project_id": 1,
  "created_at": "2024-01-15T10:30:00Z",
  "updated_at": "2024-01-15T10:30:00Z",
  "children": []
}
```

### Copy Folder to Different Parent
**Endpoint:** `POST /api/v1/plan/folders/{folderId}/copy`

**Request:**
```json
{
  "parent_id": 456,
  "name": "Project Documents Copy"  // Optional: new name for the copy
}
```

**Response (Success - 201):**
```json
{
  "id": 789,
  "name": "Project Documents Copy",
  "parent_id": 456,
  "project_id": 1,
  "created_at": "2024-01-15T10:30:00Z",
  "updated_at": "2024-01-15T10:30:00Z",
  "children": []
}
```

## Error Responses

### Common Error Codes

- `400 Bad Request` - Invalid folder_id or file_id
- `404 Not Found` - File or folder not found
- `403 Forbidden` - User doesn't have permission
- `409 Conflict` - File/folder with same name already exists
- `400 Bad Request` - Circular reference (for folder moves)
- `400 Bad Request` - Self-reference (trying to move folder to itself)
- `400 Bad Request` - Self-copy (trying to copy folder to itself)
- `400 Bad Request` - Circular copy (trying to copy folder to its subfolder)

### Error Response Format
```json
{
  "error": "Bad Request",
  "message": "Invalid folder_id provided",
  "code": "MISSING_FOLDER_ID"
}
```

## Business Logic

### File Operations
1. **Move File:** Updates `folder_id` in database, no physical file movement. Can move to same folder (no-op).
2. **Copy File:** Creates new database record with unique `file_path` and `file_name`, different `folder_id`. Can copy to same folder with auto-renaming.

### Folder Operations
1. **Move Folder:** Updates `parent_id` in database, prevents circular references. All children remain linked automatically. Can move to same parent (no-op).
2. **Copy Folder:** Creates new folder and recursively copies all contents (files and subfolders) with new IDs. Can copy to same parent with auto-renaming.

### Name Conflict Resolution
- **Move operations:** Only check for conflicts when moving to different location
- **Copy operations:** Always check for conflicts and auto-rename if needed
- Files: Appends `_1`, `_2`, etc. to `original_name` if conflict exists
- Folders: Appends `_1`, `_2`, etc. to folder name if conflict exists
- Physical file paths are unique for copied files (generated with timestamp and uniqid)

### Special Cases
- **Self-reference prevention:** Cannot move folder to itself
- **Self-copy prevention:** Cannot copy folder to itself
- **Circular reference prevention:** Cannot move/copy folder to its own subfolder
- **Same location moves:** Allowed (no-op operations)
- **Same location copies:** Allowed with auto-renaming (except self-copy)

## Security
- All endpoints require authentication via Bearer token
- Permission checks for source and destination folders
- Circular reference prevention for folder moves

## Database Schema
- Files: `fw_plan_files` table with `folder_id` foreign key
- Folders: `fw_plan_folders` table with `parent_id` self-reference
- No physical file operations - all data stored in database
