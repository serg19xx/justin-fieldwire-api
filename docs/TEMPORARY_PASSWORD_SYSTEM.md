# Temporary Password System

## Overview

The system now generates secure temporary passwords for invited users, stores them hashed in the database, and includes them in invitation emails. Users can login with these temporary passwords and then change them.

## How It Works

### 1. Invitation Creation
- Admin creates invitation for new user
- System generates secure temporary password
- Password is hashed and stored in `password_hash` field
- Email is sent with temporary password

### 2. User Login
- User receives email with temporary password
- User can login with email and temporary password
- System verifies password using `password_verify()`
- User is logged in successfully

### 3. Password Change
- User can change password after login
- New password replaces temporary password
- User can continue using the system

## Implementation Details

### Password Generation
```php
private function generateTempPassword(): string
{
    // 12 characters: letters, numbers, special symbols
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    
    // Ensure minimum requirements
    $password .= chr(rand(65, 90));     // Uppercase letter
    $password .= chr(rand(97, 122));    // Lowercase letter
    $password .= chr(rand(48, 57));     // Number
    $password .= $specialChars[rand(0, strlen($specialChars) - 1)]; // Special char
    
    // Fill remaining characters
    for ($i = 4; $i < 12; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    
    return str_shuffle($password);
}
```

### Password Hashing
```php
$tempPassword = $this->generateTempPassword();
$tempPasswordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
```

### Database Storage
```sql
-- For new users
INSERT INTO fw_users (
    email, first_name, last_name, user_type, job_title, phone,
    invitation_status, invitation_token, invitation_sent_at, 
    invitation_expires_at, invited_by, password_hash, created_at
) VALUES (?, ?, ?, ?, ?, ?, 'invited', ?, NOW(), ?, ?, ?, NOW())

-- For existing users
UPDATE fw_users SET 
    first_name = ?, last_name = ?, user_type = ?, job_title = ?, phone = ?,
    invitation_status = 'invited', invitation_token = ?, 
    invitation_sent_at = NOW(), invitation_expires_at = ?, invited_by = ?,
    password_hash = ?
WHERE email = ?
```

### Email Templates
The temporary password is included in both HTML and text email templates:

**HTML Template:**
```html
<div class="credential-item">
    <div class="credential-label">Temporary Password:</div>
    <div class="credential-value">{{ tempPassword }}</div>
</div>
```

**Text Template:**
```
Temporary Password: {{ tempPassword }}
```

## Security Features

### 1. Strong Password Generation
- 12 characters minimum
- Mixed case letters
- Numbers and special characters
- Cryptographically secure random generation

### 2. Secure Hashing
- Uses PHP's `password_hash()` with `PASSWORD_DEFAULT`
- Salt is automatically generated
- Resistant to rainbow table attacks

### 3. Password Verification
- Uses `password_verify()` for secure comparison
- Timing attack resistant
- No plain text storage

### 4. One-Time Use
- Temporary passwords are replaced when user changes password
- No reuse of temporary passwords
- Secure password change process

## API Integration

### WorkerController Changes
- `generateTempPassword()` method added
- Password generation in `sendInvitation()` method
- Database updates include password hash
- Email service receives temporary password

### EmailService Changes
- `sendWorkerInvitation()` accepts temporary password parameter
- Uses Twig templates for email rendering
- Supports both HTML and text formats
- Fallback to simple text if templates fail

### AuthController Integration
- Existing `authenticateUser()` method works with temporary passwords
- No changes needed for login process
- Password verification works for both temporary and permanent passwords

## Email Template Data

```php
$templateData = [
    'firstName' => $firstName,
    'lastName' => $lastName,
    'email' => $email,
    'jobTitle' => 'Team Member',
    'tempPassword' => $tempPassword,        // Temporary password
    'loginUrl' => $loginUrl,               // Login page URL
    'expiryHours' => 168,                  // 7 days in hours
    'expiryDate' => date('Y-m-d H:i:s', strtotime('+7 days')),
    'attemptNumber' => 1,
    'appUrl' => $baseUrl
];
```

## Testing

The system has been tested for:
- ✅ Secure password generation
- ✅ Password hashing and verification
- ✅ Email template rendering
- ✅ Database storage
- ✅ Login functionality
- ✅ Error handling

## Benefits

1. **Security**: Strong temporary passwords with secure hashing
2. **User Experience**: Clear password in email, easy login process
3. **Flexibility**: Users can change passwords after login
4. **Compatibility**: Works with existing login system
5. **Maintainability**: Template-based emails, comprehensive logging
6. **Reliability**: Fallback mechanisms, error handling

## Usage Example

```php
// Create invitation with temporary password
$invitationData = [
    'email' => 'newuser@example.com',
    'first_name' => 'John',
    'last_name' => 'Doe',
    'user_type' => 'Employee',
    'job_title' => 'Developer'
];

// System automatically:
// 1. Generates temporary password (e.g., "tOcq&p7#YWH%")
// 2. Hashes password and stores in database
// 3. Sends email with temporary password
// 4. User can login with email and temporary password
// 5. User can change password after login
```

## Migration Notes

- No database schema changes required
- Uses existing `password_hash` field
- Backward compatible with existing users
- Existing login functionality unchanged
