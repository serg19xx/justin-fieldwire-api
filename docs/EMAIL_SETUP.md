# Email Setup Guide for FieldWire 2FA

This guide explains how to configure email services for the two-factor authentication system.

## Overview

The FieldWire API supports two email delivery methods:
1. **SendGrid** (Primary) - Recommended for production
2. **PHPMailer** (Fallback) - Works with any SMTP server

## Option 1: SendGrid Setup (Recommended)

### Step 1: Create SendGrid Account
1. Go to [SendGrid.com](https://sendgrid.com)
2. Sign up for a free account (100 emails/day)
3. Verify your email address

### Step 2: Get API Key
1. Navigate to **Settings > API Keys**
2. Click **Create API Key**
3. Choose **Restricted Access** and select **Mail Send**
4. Copy the generated API key

### Step 3: Configure Environment
Add to your `.env` file:
```env
SENDGRID_API_KEY=SG.your_actual_api_key_here
SENDGRID_FROM_EMAIL=noreply@fieldwire.com
SENDGRID_FROM_NAME=FieldWire
```

### Step 4: Verify Sender (Optional but Recommended)
1. Go to **Settings > Sender Authentication**
2. Verify your domain or at least a single sender
3. This improves deliverability

## Option 2: PHPMailer Setup (Fallback)

### Step 1: Choose SMTP Provider

#### Gmail
```env
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your_email@gmail.com
SMTP_PASSWORD=your_app_password_here
SMTP_ENCRYPTION=tls
SMTP_FROM_EMAIL=your_email@gmail.com
SMTP_FROM_NAME=FieldWire
```

**Note:** For Gmail, you need to:
1. Enable 2-factor authentication
2. Generate an "App Password"
3. Use the app password instead of your regular password

#### Outlook/Hotmail
```env
SMTP_HOST=smtp-mail.outlook.com
SMTP_PORT=587
SMTP_USERNAME=your_email@outlook.com
SMTP_PASSWORD=your_password
SMTP_ENCRYPTION=tls
SMTP_FROM_EMAIL=your_email@outlook.com
SMTP_FROM_NAME=FieldWire
```

#### Custom SMTP Server
```env
SMTP_HOST=your-smtp-server.com
SMTP_PORT=587
SMTP_USERNAME=your_username
SMTP_PASSWORD=your_password
SMTP_ENCRYPTION=tls
SMTP_FROM_EMAIL=noreply@yourdomain.com
SMTP_FROM_NAME=FieldWire
```

## Testing Email Configuration

### Test SendGrid
```bash
curl -X POST http://localhost:8000/api/v1/2fa/send-code \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "delivery_method": "email"
  }'
```

### Test PHPMailer
```bash
curl -X POST http://localhost:8000/api/v1/2fa/send-code \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "delivery_method": "email"
  }'
```

## Troubleshooting

### SendGrid Issues
1. **Invalid API Key**: Check your API key in SendGrid dashboard
2. **Authentication Failed**: Verify sender email is authenticated
3. **Rate Limited**: Check your SendGrid plan limits

### PHPMailer Issues
1. **Connection Failed**: Check SMTP host and port
2. **Authentication Failed**: Verify username/password
3. **SSL/TLS Issues**: Try different encryption settings

### Common Error Messages
- `"Failed to send verification code"` - Check email service configuration
- `"Email not found for this user"` - User doesn't have email in database
- `"Invalid delivery method"` - Use "sms" or "email"

## Security Considerations

1. **API Keys**: Never commit API keys to version control
2. **App Passwords**: Use app-specific passwords for Gmail
3. **Environment Variables**: Store all credentials in `.env` files
4. **Rate Limiting**: Implement rate limiting for email sending
5. **Logging**: Monitor email sending logs for security

## Production Recommendations

1. **Use SendGrid**: Better deliverability and monitoring
2. **Verify Domain**: Authenticate your sending domain
3. **Monitor Bounces**: Set up bounce handling
4. **Rate Limiting**: Implement proper rate limiting
5. **Backup Provider**: Configure PHPMailer as fallback

## Development Mode

In development mode, emails are logged instead of being sent:
```
MOCK EMAIL: Verification code would be sent
{
  "to": "user@example.com",
  "code": "123456",
  "message": "Hello User,\n\nYour FieldWire verification code is: 123456..."
}
```

This allows testing without sending actual emails.
