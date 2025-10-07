# Image Upload API Documentation

## Overview
API endpoints for managing user images. When a user uploads an image, the system automatically creates both an avatar (small version) and a full image (original version) from the same source file.

## Endpoints

### 1. Upload Image (Creates Both Avatar and Full Image)
**POST** `/api/v1/profile/avatar`

Upload a new image for the authenticated user. This creates both avatar and full image versions.

#### Headers
```
Authorization: Bearer <token>
Content-Type: multipart/form-data
```

#### Request Body
```
avatar: <file> (required)
```

#### Response
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Image uploaded successfully",
  "data": {
    "avatar_url": "/uploads/avatars/user_123_avatar_1234567890.jpg",
    "avatar_full_url": "http://localhost:8000/api/v1/avatar?file=user_123_avatar_1234567890.jpg",
    "full_image_url": "/uploads/avatars/user_123_full_1234567890.jpg",
    "full_image_full_url": "http://localhost:8000/api/v1/full-image?file=user_123_full_1234567890.jpg"
  }
}
```

#### Error Responses
- **400**: No file uploaded, invalid file format, or file too large
- **401**: Unauthorized (invalid or missing token)
- **500**: Internal server error

### 2. Get Full Image Info
**GET** `/api/v1/profile/full-image`

Get full image information for the authenticated user.

#### Headers
```
Authorization: Bearer <token>
```

#### Response
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Full image retrieved successfully",
  "data": {
    "full_image_url": "/uploads/full_images/user_123_full_1234567890.jpg",
    "full_url": "http://localhost:8000/api/v1/full-image?file=user_123_full_1234567890.jpg"
  }
}
```

#### Error Responses
- **404**: Full image not found
- **401**: Unauthorized (invalid or missing token)
- **500**: Internal server error

### 3. Serve Full Image File
**GET** `/api/v1/full-image?file=<filename>`

Serve the actual full image file (binary data).

#### Parameters
- `file` (query): The filename of the full image

#### Response
- **Content-Type**: `image/jpeg`, `image/png`, `image/gif`, or `image/webp`
- **Content-Length**: File size in bytes
- **Cache-Control**: `public, max-age=3600`

#### Error Responses
- **404**: File not found
- **500**: Internal server error

## File Validation Rules

### Supported Formats
- **Types**: `image/jpeg`, `image/jpg`, `image/png`, `image/gif`, `image/webp`
- **Extensions**: `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`
- **Max Size**: 10MB (10,485,760 bytes)

### File Naming Convention
- **Pattern**: `user_{user_id}_full_{timestamp}.{extension}`
- **Example**: `user_123_full_1758730569.jpg`

## Storage Structure
```
public/uploads/avatars/
├── user_1_avatar_1234567890.jpg    (avatar)
├── user_1_full_1234567891.jpg      (full image)
├── user_2_avatar_1234567892.png    (avatar)
└── user_2_full_1234567893.png      (full image)
```

## Database Schema
```sql
ALTER TABLE fw_v_users ADD COLUMN full_img_url VARCHAR(500) NULL AFTER avatar_url;
```

## Usage Examples

### Upload Full Image (JavaScript)
```javascript
const formData = new FormData();
formData.append('full_image', fileInput.files[0]);

fetch('/api/v1/profile/full-image', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ' + token
  },
  body: formData
})
.then(response => response.json())
.then(data => {
  console.log('Full image uploaded:', data.data.full_url);
});
```

### Display Full Image (HTML)
```html
<img src="http://localhost:8000/api/v1/full-image?file=user_123_full_1234567890.jpg" 
     alt="User Full Image" 
     style="max-width: 100%; height: auto;" />
```

## Differences from Avatar API

| Feature | Avatar | Full Image |
|---------|--------|------------|
| **Purpose** | Small profile picture | High-resolution photo |
| **Max Size** | 2MB | 10MB |
| **Storage** | `/uploads/avatars/` | `/uploads/avatars/` |
| **Naming** | `user_{id}_avatar_{timestamp}.{ext}` | `user_{id}_full_{timestamp}.{ext}` |
| **Usage** | Profile widgets, lists | Detailed view, galleries |

## Security Considerations
- **Authentication Required**: All endpoints require valid JWT token
- **File Validation**: Strict type and size validation
- **Path Security**: Filenames are generated server-side to prevent path traversal
- **Access Control**: Users can only access their own full images

## Error Handling
All endpoints return consistent error format:
```json
{
  "error_code": <number>,
  "status": "error",
  "message": "<error_description>",
  "data": null
}
```

## Implementation Notes
- **File Processing**: No image resizing or optimization (raw upload)
- **Caching**: Full images are cached for 1 hour
- **Cleanup**: Old full images should be cleaned up when new ones are uploaded
- **Backup**: Consider backup strategy for full images due to larger file sizes
