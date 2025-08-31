# Two-Factor Authentication (2FA) API Documentation

## Overview

The 2FA API provides endpoints for sending SMS verification codes, verifying codes, and managing 2FA settings for users.

## Base URL

- Development: `http://localhost:8000`
- Production: `https://medicalcontractor.ca`

## Authentication

All 2FA endpoints use the unified API response format.

## Endpoints

### 1. Send Verification Code

Sends a 6-digit verification code via SMS or email to the user's contact information.

**Endpoint:** `POST /api/v1/2fa/send-code`

**Request Body:**
```json
{
  "email": "user@example.com",
  "delivery_method": "sms"
}
```

**Delivery Methods:**
- `"sms"` - Send code via SMS to user's phone number
- `"email"` - Send code via email to user's email address

**Response (Success):**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Verification code sent successfully",
  "data": {
    "user_id": 1,
    "delivery_method": "sms",
    "contact_info": "+123456****",
    "expires_at": "2025-08-31 14:19:46"
  }
}
```

**Response (Error):**
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Phone number not found for this user",
  "data": null
}
```

**Error Codes:**
- `400`: Invalid input data, contact method not found, or invalid format
- `404`: User not found
- `500`: Failed to send verification code or internal server error

### 2. Verify Code

Verifies the 6-digit code sent via SMS and returns a JWT token upon success.

**Endpoint:** `POST /api/v1/2fa/verify-code`

**Request Body:**
```json
{
  "user_id": 1,
  "code": "123456"
}
```

**Response (Success):**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Verification successful",
  "data": {
    "user": {
      "id": 1,
      "email": "admin@medicalcontractor.ca",
      "first_name": "Justin",
      "last_name": "Admin",
      "name": "Justin Admin",
      "phone": "+1234567890",
      "user_type": "System Administrator",
      "job_title": "System Administrator",
      "status": "active",
      "additional_info": null,
      "avatar_url": null,
      "two_factor_enabled": true,
      "last_login": "2025-08-31 08:06:20"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_at": "2025-08-31T15:10:10+02:00"
  }
}
```

**Response (Error):**
```json
{
  "error_code": 401,
  "status": "error",
  "message": "Invalid or expired verification code",
  "data": null
}
```

**Error Codes:**
- `400`: Invalid input data
- `401`: Invalid or expired verification code
- `404`: User not found
- `500`: Internal server error

### 3. Enable 2FA

Enables 2FA for a user by setting their phone number and enabling the feature.

**Endpoint:** `POST /api/v1/2fa/enable`

**Request Body:**
```json
{
  "user_id": 1,
  "phone": "+1234567890"
}
```

**Response (Success):**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "2FA enabled successfully",
  "data": {
    "user_id": 1,
    "phone": "+123456****",
    "two_factor_enabled": true
  }
}
```

**Response (Error):**
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Invalid phone number format",
  "data": null
}
```

**Error Codes:**
- `400`: Invalid input data or phone number format
- `500`: Internal server error

### 4. Disable 2FA

Disables 2FA for a user.

**Endpoint:** `POST /api/v1/2fa/disable`

**Request Body:**
```json
{
  "user_id": 1
}
```

**Response (Success):**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "2FA disabled successfully",
  "data": {
    "user_id": 1,
    "two_factor_enabled": false
  }
}
```

**Response (Error):**
```json
{
  "error_code": 400,
  "status": "error",
  "message": "User ID is required",
  "data": null
}
```

**Error Codes:**
- `400`: Invalid input data
- `500`: Internal server error

## Legacy Endpoints

For backward compatibility, the following legacy endpoints are also available:

- `POST /2fa/send-code`
- `POST /2fa/verify-code`

These endpoints have the same functionality as their `/api/v1/` counterparts.

## Contact Information Format

### Phone Number Format
The API accepts phone numbers in various formats and automatically converts them to international format:

- `1234567890` → `+11234567890` (US number)
- `+1234567890` → `+1234567890` (already international)
- `(123) 456-7890` → `+11234567890` (formatted US number)

### Email Format
Standard email format is supported:
- `user@example.com`
- `user.name@domain.com`

## Verification Code

- **Length:** 6 digits
- **Validity:** 10 minutes
- **Usage:** One-time use (marked as used after verification)
- **Format:** Numeric only (e.g., `123456`)

## Development Mode

In development mode, messages are logged instead of being sent:

### SMS Logging
```
MOCK SMS: Verification code would be sent
{
  "to": "+1234567890",
  "code": "123456",
  "message": "Your FieldWire verification code is: 123456. Valid for 10 minutes."
}
```

### Email Logging
```
MOCK EMAIL: Verification code would be sent
{
  "to": "user@example.com",
  "code": "123456",
  "message": "Hello User,\n\nYour FieldWire verification code is: 123456..."
}
```

## Email Configuration

The system supports two email delivery methods:

### SendGrid (Primary)
- **Service:** SendGrid API
- **Configuration:** `SENDGRID_API_KEY`, `SENDGRID_FROM_EMAIL`, `SENDGRID_FROM_NAME`
- **Advantages:** High deliverability, easy setup, free tier available
- **Fallback:** Automatically falls back to PHPMailer if SendGrid fails

### PHPMailer (Fallback)
- **Service:** SMTP via PHPMailer
- **Configuration:** `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`
- **Advantages:** Works with any SMTP server, no external dependencies
- **Usage:** Gmail, Outlook, custom SMTP servers

### Configuration Example
```env
# SendGrid Configuration
SENDGRID_API_KEY=your_sendgrid_api_key_here
SENDGRID_FROM_EMAIL=noreply@fieldwire.com
SENDGRID_FROM_NAME=FieldWire

# PHPMailer Configuration (Fallback)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your_email@gmail.com
SMTP_PASSWORD=your_app_password_here
SMTP_ENCRYPTION=tls
SMTP_FROM_EMAIL=noreply@fieldwire.com
SMTP_FROM_NAME=FieldWire
```

## Database Schema

### two_factor_codes Table

```sql
CREATE TABLE `two_factor_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code` varchar(6) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `code` (`code`),
  KEY `expires_at` (`expires_at`),
  KEY `used` (`used`),
  CONSTRAINT `fk_two_factor_codes_user_id` FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE
);
```

### fw_users Table Updates

The `fw_users` table should have the following fields for 2FA:

- `phone` (varchar): User's phone number
- `email` (varchar): User's email address
- `two_factor_enabled` (tinyint): Whether 2FA is enabled (0 or 1)

## Security Considerations

1. **Code Expiration:** Verification codes expire after 10 minutes
2. **One-time Use:** Codes are marked as used after verification
3. **Rate Limiting:** Consider implementing rate limiting for code sending
4. **Contact Validation:** Phone numbers and emails are validated for proper format
5. **JWT Tokens:** Tokens expire after 1 hour
6. **Logging:** All 2FA activities are logged for security monitoring

## Integration with Frontend

### Typical 2FA Flow

1. User enters email and password
2. If 2FA is enabled, show delivery method selection
3. User chooses SMS or email and requests code
4. User enters the 6-digit code
5. Verify code and return JWT token
6. Use JWT token for subsequent API calls

### Frontend Implementation Example

```javascript
// Step 1: Send verification code via SMS
const sendCodeSMS = async (email) => {
  const response = await fetch('/api/v1/2fa/send-code', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ 
      email: email,
      delivery_method: 'sms'
    })
  });
  return response.json();
};

// Step 1: Send verification code via email
const sendCodeEmail = async (email) => {
  const response = await fetch('/api/v1/2fa/send-code', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ 
      email: email,
      delivery_method: 'email'
    })
  });
  return response.json();
};

// Step 2: Verify code
const verifyCode = async (userId, code) => {
  const response = await fetch('/api/v1/2fa/verify-code', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: userId, code })
  });
  return response.json();
};

// Step 3: Handle delivery method selection
const handleDeliveryMethodSelection = async (email, method) => {
  let result;
  
  if (method === 'sms') {
    result = await sendCodeSMS(email);
  } else if (method === 'email') {
    result = await sendCodeEmail(email);
  }
  
  if (result.status === 'success') {
    console.log('Code sent to:', result.data.contact_info);
    // Show code input form
  } else {
    console.error('Error:', result.message);
  }
};

// Step 4: Store token and proceed
const handleVerification = async (userId, code) => {
  const result = await verifyCode(userId, code);
  if (result.status === 'success') {
    localStorage.setItem('token', result.data.token);
    localStorage.setItem('user', JSON.stringify(result.data.user));
    // Redirect to dashboard or main app
  }
};
```

## Error Handling

Always check the `error_code` and `status` fields in the response:

```javascript
const response = await fetch('/api/v1/2fa/send-code', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ 
    email: 'user@example.com',
    delivery_method: 'sms'
  })
});

const result = await response.json();

if (result.status === 'success') {
  // Handle success
  console.log('Code sent to:', result.data.contact_info);
} else {
  // Handle error
  console.error('Error:', result.message);
  // Show appropriate error message to user
}
```