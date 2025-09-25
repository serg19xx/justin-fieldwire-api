# Rename API Endpoints

## Overview
API endpoints for renaming files and folders, and updating file descriptions in the Fieldwire application.

## File Rename
**Endpoint:** `PUT /api/v1/plan/files/{fileId}/rename`

### Request Body:
```json
{
  "new_name": "new_filename.pdf"
}
```

### Response (Success - 200):
```json
{
  "id": 26,
  "file_name": "Conneticut-Table 1_1758664823_68d31877121ca_1758730569_68d419492dabe.csv",
  "original_name": "new_filename.pdf",
  "file_path": "/uploads/plan/Conneticut-Table 1_1758664823_68d31877121ca_1758730569_68d419492dabe.csv",
  "folder_id": 2,
  "file_size": 23266,
  "mime_type": "text/csv",
  "category": "document",
  "description": "This is a test CSV file with Connecticut data",
  "version": "1.0",
  "uploaded_by": 1,
  "uploaded_at": "2025-09-24T12:16:09Z",
  "updated_at": "2025-09-24T13:05:36Z"
}
```

## Folder Rename
**Endpoint:** `PUT /api/v1/plan/folders/{folderId}/rename`

### Request Body:
```json
{
  "new_name": "New Folder Name"
}
```

### Response (Success - 200):
```json
{
  "id": 19,
  "name": "New Folder Name",
  "parent_id": 1,
  "project_id": 10,
  "created_at": "2025-09-24T12:16:15Z",
  "updated_at": "2025-09-24T13:05:42Z"
}
```

## File Description Update
**Endpoint:** `PUT /api/v1/plan/files/{fileId}/description`

### Request Body:
```json
{
  "description": "Updated file description with more details about the content"
}
```

### Response (Success - 200):
```json
{
  "id": 26,
  "file_name": "Conneticut-Table 1_1758664823_68d31877121ca_1758730569_68d419492dabe.csv",
  "original_name": "Renamed File.csv",
  "file_path": "/uploads/plan/Conneticut-Table 1_1758664823_68d31877121ca_1758730569_68d419492dabe.csv",
  "folder_id": 2,
  "file_size": 23266,
  "mime_type": "text/csv",
  "category": "document",
  "description": "Updated file description with more details about the content",
  "version": "1.0",
  "uploaded_by": 1,
  "uploaded_at": "2025-09-24T12:16:09Z",
  "updated_at": "2025-09-24T13:05:48Z"
}
```

## Error Responses

### Common Error Codes

- `400 Bad Request` - Invalid input (empty name, too long, invalid characters)
- `404 Not Found` - File or folder not found
- `409 Conflict` - Name already exists in the same location
- `500 Internal Server Error` - Server error

### Error Response Format
```json
{
  "error": "Bad Request",
  "message": "New name contains invalid characters",
  "code": "INVALID_CHARACTERS"
}
```

### Specific Error Codes

#### Name Validation Errors
- `EMPTY_NAME` - New name cannot be empty
- `NAME_TOO_LONG` - New name is too long (max 255 characters)
- `INVALID_CHARACTERS` - New name contains forbidden characters

#### Description Validation Errors
- `DESCRIPTION_TOO_LONG` - Description is too long (max 1000 characters)

#### Conflict Errors
- `NAME_CONFLICT` - File/folder with same name already exists in location

#### Not Found Errors
- `FILE_NOT_FOUND` - File with specified ID not found
- `FOLDER_NOT_FOUND` - Folder with specified ID not found

## Business Logic

### Name Validation Rules
- **Minimum length:** 1 character (after trimming)
- **Maximum length:** 255 characters
- **Allowed characters:** Letters, numbers, spaces, hyphens, underscores, dots
- **Forbidden characters:** `/\:*?"<>|`
- **Whitespace:** Trimmed from beginning and end

### Description Validation Rules
- **Maximum length:** 1000 characters
- **Allow empty:** Yes, empty descriptions are allowed
- **Whitespace:** Trimmed from beginning and end

### Conflict Resolution
- **File rename:** Check if file with same `file_name` exists in same folder
- **Folder rename:** Check if folder with same name exists in same parent
- **Case sensitive:** Yes, exact match required
- **Return 409 Conflict** if name already exists

### Database Updates
- **Update `updated_at`** timestamp automatically
- **Maintain referential integrity** - no foreign key changes
- **Log changes** in application logs

## Security
- **Authentication required:** All endpoints require Bearer token
- **Authorization:** Users can only rename files/folders in projects they have access to
- **Input sanitization:** All inputs are trimmed and validated
- **SQL injection protection:** All queries use prepared statements

## Implementation Details

### File Rename Logic
1. Validate new name (length, characters)
2. Check if file exists
3. Check for name conflict in same folder
4. Update `file_name` field only
5. Return updated file data

### Folder Rename Logic
1. Validate new name (length, characters)
2. Check if folder exists
3. Check for name conflict in same parent
4. Update `name` field only
5. Return updated folder data

### Description Update Logic
1. Validate description length
2. Check if file exists
3. Update `description` field only
4. Return updated file data

## Testing Examples

### Successful Rename
```bash
curl -X PUT http://localhost:8080/api/v1/plan/files/26/rename \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"new_name": "My Document.pdf"}'
```

### Invalid Characters
```bash
curl -X PUT http://localhost:8080/api/v1/plan/files/26/rename \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"new_name": "Invalid/File:Name.pdf"}'
# Returns: {"error": "Bad Request", "message": "New name contains invalid characters", "code": "INVALID_CHARACTERS"}
```

### Update Description
```bash
curl -X PUT http://localhost:8080/api/v1/plan/files/26/description \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"description": "This is an important document"}'
```
