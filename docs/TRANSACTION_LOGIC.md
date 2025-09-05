# Transaction Logic for Worker Invitations

## Overview

The worker invitation system now uses database transactions to ensure data consistency between database operations and email sending.

## Implementation

### Location
- File: `src/Controllers/WorkerController.php`
- Method: `sendInvitation()`

### Transaction Flow

```php
// 1. Start transaction
$connection->beginTransaction();

try {
    // 2. Database operations (INSERT or UPDATE)
    if ($existingUser) {
        // Update existing user
        $connection->executeStatement($updateSql, $params);
    } else {
        // Insert new user
        $connection->executeStatement($insertSql, $params);
    }
    
    // 3. Send email
    $emailSent = $this->emailService->sendWorkerInvitation(...);
    
    if (!$emailSent) {
        // 4a. Email failed - rollback transaction
        $connection->rollBack();
        return error_response();
    }
    
    // 4b. Email succeeded - commit transaction
    $connection->commit();
    return success_response();
    
} catch (Exception $e) {
    // 5. Any error - rollback transaction
    $connection->rollBack();
    return error_response();
}
```

## Benefits

### ACID Properties
- **Atomicity**: Either both database and email operations succeed, or both fail
- **Consistency**: Database and email are always in sync
- **Isolation**: No partial states visible to other users
- **Durability**: Committed changes are permanent

### Error Handling
- **Database errors**: Transaction rolled back, no email sent
- **Email errors**: Transaction rolled back, database changes undone
- **Network errors**: Transaction rolled back, consistent state maintained

### Logging
- **Success**: Detailed success logs with user info
- **Email failure**: Error logs with rollback notification
- **Database failure**: Error logs with rollback notification

## Response Codes

### Success (201)
```json
{
    "error_code": 0,
    "status": "success",
    "message": "Invitation sent successfully",
    "data": {
        "invitation_token": "...",
        "expires_at": "..."
    }
}
```

### Email Failure (500)
```json
{
    "error_code": 500,
    "status": "error",
    "message": "Failed to send invitation email",
    "data": null
}
```

### Database Failure (500)
```json
{
    "error_code": 500,
    "status": "error",
    "message": "Failed to send invitation: [error details]",
    "data": null
}
```

## Testing

The transaction logic has been tested for:
- ✅ Database connection and transaction support
- ✅ Successful transaction flow
- ✅ Rollback on email failure
- ✅ Rollback on database error
- ✅ Proper error handling and logging

## Dependencies

- Doctrine DBAL for database transactions
- PHPMailer/SendGrid for email sending
- Monolog for logging
- FlightPHP for HTTP responses
