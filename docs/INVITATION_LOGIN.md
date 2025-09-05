# Invitation Code Login System

## Overview

The system now supports login using invitation codes, allowing users to complete their registration and access their accounts using the invitation code sent via email.

## How It Works

### 1. User Receives Invitation
- User receives email with invitation code
- Email contains link to login page with invitation code
- User clicks link and is taken to login page

### 2. Login with Invitation Code
- User enters email and invitation code
- System validates invitation code
- If valid, user is logged in and status updated

### 3. Status Update
- User status changes from 'invited' to 'registered'
- Invitation fields are cleared (one-time use)
- Registration and login timestamps are set

## API Endpoint

### POST /api/v1/auth/login

#### Request Body (Invitation Code Login)
```json
{
    "email": "user@example.com",
    "invitation_code": "abc123def456"
}
```

#### Request Body (Regular Login)
```json
{
    "email": "user@example.com",
    "password": "password123"
}
```

#### Success Response (200)
```json
{
    "error_code": 0,
    "status": "success",
    "message": "Login successful",
    "data": {
        "user": {
            "id": 1,
            "email": "user@example.com",
            "first_name": "John",
            "last_name": "Doe",
            "user_type": "Employee",
            "status": "active",
            "registered_at": "2024-01-15 10:30:00",
            "last_login": "2024-01-15 10:30:00"
        },
        "token": "jwt_token_here",
        "expires_at": "2024-01-15 22:30:00"
    }
}
```

#### Error Response (401) - Invalid Invitation Code
```json
{
    "error_code": 401,
    "status": "error",
    "message": "Invalid invitation code or you have already registered. Please check your invitation email or contact support.",
    "data": null
}
```

#### Error Response (401) - Invalid Password
```json
{
    "error_code": 401,
    "status": "error",
    "message": "Invalid email or password",
    "data": null
}
```

## Database Changes

### User Status Update
When invitation code login is successful:

```sql
UPDATE fw_users SET 
    invitation_status = 'registered',
    invitation_token = NULL,
    invitation_sent_at = NULL,
    invitation_expires_at = NULL,
    invited_by = NULL,
    registered_at = NOW(),
    last_login = NOW()
WHERE id = ?
```

### Invitation Code Validation
```sql
SELECT * FROM fw_users 
WHERE email = ? 
AND invitation_token = ? 
AND invitation_status = 'invited' 
LIMIT 1
```

## Security Features

### 1. One-Time Use
- Invitation codes are deleted after successful use
- Cannot be reused even if user logs out

### 2. Expiration Check
- Codes expire after 7 days
- Expired codes are rejected

### 3. Status Validation
- Only users with 'invited' status can use invitation codes
- Already registered users cannot use invitation codes

### 4. Transactional Updates
- All database changes happen in a transaction
- Rollback on any error ensures data consistency

## Error Scenarios

### 1. Invalid Invitation Code
- Code doesn't exist in database
- Code has already been used
- User has already registered

### 2. Expired Invitation Code
- Code is older than 7 days
- User needs new invitation

### 3. Wrong Email
- Email doesn't match invitation code
- User needs to use correct email

### 4. Database Errors
- Transaction is rolled back
- User sees generic error message
- Detailed error is logged

## Logging

### Success Logs
```
INFO: Invitation code login successful
- user_id: 1
- email: user@example.com
- ip: 192.168.1.1
```

### Error Logs
```
WARNING: Failed invitation code login attempt
- email: user@example.com
- ip: 192.168.1.1
- login_type: invitation_code
```

### Debug Logs
```
DEBUG: Invitation code validation
- email: user@example.com
- invitation_code: abc123
- expires_at: 2024-01-15 10:30:00
```

## Backward Compatibility

- Regular password login continues to work
- Existing users are not affected
- API endpoint remains the same
- Only request body format changes

## Testing

The system has been tested for:
- ✅ Valid invitation code login
- ✅ Invalid invitation code rejection
- ✅ Expired invitation code rejection
- ✅ Regular password login (backward compatibility)
- ✅ Database transaction rollback
- ✅ Error message differentiation
- ✅ Comprehensive logging

## Benefits

1. **User Experience**: Simple one-click registration completion
2. **Security**: One-time use codes with expiration
3. **Data Integrity**: Transactional updates ensure consistency
4. **Auditability**: Comprehensive logging for tracking
5. **Flexibility**: Supports both invitation and password login
6. **Maintainability**: Clear error messages and logging
