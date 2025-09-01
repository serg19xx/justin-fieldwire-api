# Swagger API Documentation Setup

This document explains how to set up and use Swagger API documentation in the FieldWire API project.

## Overview

Swagger (OpenAPI) provides interactive API documentation that allows developers to:
- View all available API endpoints
- Test API calls directly from the browser
- Understand request/response schemas
- Generate client SDKs

## Files Structure

```
src/
├── Swagger/
│   └── OpenApiSpec.php          # Base OpenAPI specification
├── Controllers/
│   ├── AuthController.php       # Authentication endpoints
│   ├── ProfileController.php    # Profile management endpoints
│   └── ...                     # Other controllers
public/
├── swagger.php                  # OpenAPI JSON generator
└── swagger-ui.php              # Swagger UI interface
```

## Installation

### 1. Install Dependencies

```bash
composer require zircote/swagger-php
```

### 2. Update composer.json

Ensure your `composer.json` includes:

```json
{
    "require": {
        "zircote/swagger-php": "^4.7"
    }
}
```

## Usage

### Access Documentation

- **Swagger UI**: `http://your-domain.com/docs` or `http://your-domain.com/api-docs`
- **OpenAPI JSON**: `http://your-domain.com/swagger.json`

### Adding Documentation to Controllers

#### Basic Structure

```php
<?php

namespace App\Controllers;

use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Controller Name",
 *     description="Controller description"
 * )
 */
class YourController
{
    /**
     * @OA\Get(
     *     path="/endpoint",
     *     summary="Endpoint summary",
     *     description="Detailed description",
     *     tags={"Controller Name"},
     *     @OA\Response(
     *         response=200,
     *         description="Success response",
     *         @OA\JsonContent(...)
     *     )
     * )
     */
    public function yourMethod()
    {
        // Implementation
    }
}
```

#### Common Annotations

- `@OA\Tag` - Group endpoints by category
- `@OA\Get`, `@OA\Post`, `@OA\Put`, `@OA\Delete` - HTTP methods
- `@OA\RequestBody` - Request body schema
- `@OA\Response` - Response schemas
- `@OA\Property` - Define object properties
- `@OA\Security` - Authentication requirements

## Configuration

### Base OpenAPI Specification

The base specification is defined in `src/Swagger/OpenApiSpec.php` and includes:

- API title and version
- Server configurations
- Security schemes (JWT Bearer)
- Global tags
- Contact information

### Customization

#### Styling

Customize Swagger UI appearance by modifying CSS in `public/swagger-ui.php`:

```css
.swagger-ui .topbar {
    background-color: #2c3e50;
}
```

#### Configuration Options

Modify Swagger UI behavior in `public/swagger-ui.php`:

```javascript
const ui = SwaggerUIBundle({
    url: './swagger.php',
    dom_id: '#swagger-ui',
    deepLinking: true,
    docExpansion: 'list',
    filter: true,
    tryItOutEnabled: true
});
```

## Best Practices

### 1. Consistent Documentation

- Use consistent response formats
- Document all possible response codes
- Provide meaningful examples
- Use descriptive property names

### 2. Security

- Document authentication requirements
- Use `@OA\Security` annotations
- Include authorization headers in examples

### 3. Validation

- Specify required fields
- Use proper data types and formats
- Include validation rules in descriptions

### 4. Examples

- Provide realistic example data
- Show both success and error responses
- Include edge cases

## Troubleshooting

### Common Issues

1. **Annotations not recognized**
   - Ensure `zircote/swagger-php` is installed
   - Check namespace imports
   - Verify annotation syntax

2. **Swagger UI not loading**
   - Check file paths in routes
   - Verify PHP errors in logs
   - Check file permissions

3. **Missing endpoints**
   - Ensure controllers are scanned
   - Check annotation syntax
   - Verify route registration

### Debug Mode

Enable debug mode in `public/swagger.php`:

```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## Examples

### Authentication Endpoint

```php
/**
 * @OA\Post(
 *     path="/auth/login",
 *     summary="User login",
 *     tags={"Authentication"},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"email","password"},
 *             @OA\Property(property="email", type="string", format="email"),
 *             @OA\Property(property="password", type="string")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Login successful",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean"),
 *             @OA\Property(property="token", type="string")
 *         )
 *     )
 * )
 */
```

### Protected Endpoint

```php
/**
 * @OA\Get(
 *     path="/profile",
 *     summary="Get user profile",
 *     tags={"Profile"},
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(
 *         response=200,
 *         description="Profile retrieved",
 *         @OA\JsonContent(...)
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Unauthorized"
 *     )
 * )
 */
```

## Maintenance

### Regular Updates

- Keep Swagger annotations in sync with code changes
- Update examples when API changes
- Review and improve documentation quality
- Test documentation endpoints regularly

### Version Control

- Include Swagger files in version control
- Document breaking changes
- Maintain backward compatibility when possible
- Use semantic versioning for API changes
