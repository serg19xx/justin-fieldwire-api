# Security and Deployment Guide

## 🔒 Security Best Practices

### Environment Variables
All sensitive data has been removed from the codebase. You must set up your own environment variables:

1. **Copy the example file:**
   ```bash
   cp env.example .env
   ```

2. **Update with your actual values:**
   - Database credentials
   - API keys (SendGrid, Twilio)
   - JWT secret
   - Email credentials

### Required Environment Variables

#### Database Configuration
```env
DB_HOST=your_database_host
DB_PORT=3306
DB_NAME=your_database_name
DB_USERNAME=your_database_username
DB_PASSWORD=your_database_password
```

#### Twilio Configuration
```env
TWILIO_ACCOUNT_SID=your_twilio_account_sid
TWILIO_AUTH_TOKEN=your_twilio_auth_token
TWILIO_PHONE_NUMBER=your_twilio_phone_number
```

#### SendGrid Configuration
```env
SENDGRID_API_KEY=your_sendgrid_api_key
SENDGRID_FROM_EMAIL=your_email@example.com
SENDGRID_FROM_NAME='Your Name'
```

#### SMTP Configuration (Gmail)
```env
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your_email@gmail.com
SMTP_PASSWORD=your_app_password
SMTP_ENCRYPTION=tls
```

#### JWT Configuration
```env
JWT_SECRET=your-strong-jwt-secret-key
```

## 🚀 Deployment Steps

### 1. Production Server Setup
```bash
# Clone the repository
git clone https://github.com/your-username/fieldwire-api.git
cd fieldwire-api

# Install dependencies
composer install --no-dev --optimize-autoloader

# Set up environment
cp env.example .env
# Edit .env with your production values
```

### 2. Database Setup
```bash
# Create database
mysql -u root -p
CREATE DATABASE your_database_name;
CREATE USER 'your_username'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON your_database_name.* TO 'your_username'@'localhost';
FLUSH PRIVILEGES;
```

### 3. File Permissions
```bash
# Set proper permissions
chmod 755 public/
chmod 777 logs/
chmod 777 public/uploads/
```

### 4. Web Server Configuration
Configure your web server (Apache/Nginx) to point to the `public/` directory.

## 🔐 Security Checklist

- [ ] All environment files are in .gitignore
- [ ] No hardcoded secrets in code
- [ ] Strong JWT secret generated
- [ ] Database credentials are secure
- [ ] API keys are properly configured
- [ ] HTTPS is enabled in production
- [ ] CORS is properly configured
- [ ] File uploads are restricted
- [ ] Logs don't contain sensitive data

## 📝 Environment File Templates

### Development (.env)
```env
APP_ENV=development
APP_DEBUG=true
# ... other development settings
```

### Production (.env)
```env
APP_ENV=production
APP_DEBUG=false
# ... other production settings
```

## 🚨 Important Security Notes

1. **Never commit .env files** - They contain sensitive information
2. **Use strong passwords** - Generate random, complex passwords
3. **Rotate API keys regularly** - Change them periodically
4. **Monitor logs** - Check for suspicious activity
5. **Keep dependencies updated** - Regular security updates
6. **Use HTTPS** - Always encrypt data in transit
7. **Backup regularly** - Keep secure backups of your data

## 🔧 Troubleshooting

### Database Connection Issues
- Check database credentials
- Verify database server is running
- Check firewall settings
- Ensure user has proper permissions

### API Key Issues
- Verify API keys are correct
- Check API key permissions
- Ensure services are enabled

### File Upload Issues
- Check directory permissions
- Verify upload limits
- Check file type restrictions
