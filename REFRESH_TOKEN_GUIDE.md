# 🔄 Refresh Token System - Complete Guide

## Overview

The refresh token system provides secure, long-lived authentication for FieldWire API. It uses a two-token approach:
- **Access Token (JWT)**: Short-lived (30 minutes), sent in Authorization header
- **Refresh Token**: Long-lived (30 days), stored in httpOnly cookie

---

## 🏗️ Architecture

### Database Table: `fw_refresh_tokens`

```sql
CREATE TABLE `fw_refresh_tokens` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token` VARCHAR(255) UNIQUE NOT NULL,
  `expires_at` INT(11) NOT NULL,
  `created_at` INT(11) NOT NULL,
  `last_used_at` INT(11) NULL,
  `revoked` TINYINT(1) DEFAULT 0,
  `revoked_at` INT(11) NULL,
  `user_agent` VARCHAR(255) NULL,
  `ip_address` VARCHAR(45) NULL,
  FOREIGN KEY (`user_id`) REFERENCES `fw_users`(`id`) ON DELETE CASCADE
);
```

---

## 🔐 Security Features

1. **httpOnly Cookie**: Prevents JavaScript access (XSS protection)
2. **Secure Flag**: HTTPS only (production)
3. **SameSite**: CSRF protection
4. **Database Storage**: Can be revoked anytime
5. **User Agent & IP Tracking**: Detect suspicious activity
6. **30-day Expiry**: Automatic cleanup
7. **Revocation on Password Change**: Forces re-authentication

---

## 📡 API Endpoints

### 1. POST `/api/v1/auth/login`
**Purpose**: Login and get tokens

**Request**:
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response** (200):
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Login successful",
  "data": {
    "user": { ... },
    "token": "eyJ0eXAiOiJKV1Q...",
    "expires_at": "2025-10-17T18:30:00+00:00"
  }
}
```

**Side Effect**: Sets `refresh_token` httpOnly cookie (30 days)

---

### 2. POST `/api/v1/auth/refresh-token`
**Purpose**: Get new access token using refresh token

**Request**:
- No body required (uses cookie)
- No Authorization header needed

```json
{}
```

**Response** (200):
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Token refreshed successfully",
  "data": {
    "token": "eyJ0eXAiOiJKV1Q...",
    "expires_at": "2025-10-17T18:30:00+00:00"
  }
}
```

**Response** (400 - No Cookie):
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Refresh token not found",
  "data": null
}
```

**Response** (401 - Expired/Invalid):
```json
{
  "error_code": 401,
  "status": "error",
  "message": "Refresh token expired or invalid",
  "data": null
}
```

---

### 3. POST `/api/v1/auth/logout`
**Purpose**: Logout and revoke tokens

**Request**:
```http
POST /api/v1/auth/logout
Authorization: Bearer eyJ0eXAiOiJKV1Q...
```

**Response** (200):
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Logout successful",
  "data": null
}
```

**Side Effects**:
- Revokes refresh token in database
- Deletes `refresh_token` cookie

---

## 🔄 Token Flow

```
┌─────────┐                  ┌─────────┐                  ┌──────────┐
│ Client  │                  │   API   │                  │ Database │
└────┬────┘                  └────┬────┘                  └────┬─────┘
     │                            │                            │
     │ 1. POST /login             │                            │
     ├───────────────────────────>│                            │
     │                            │ 2. Validate credentials    │
     │                            ├───────────────────────────>│
     │                            │                            │
     │                            │ 3. Create access token     │
     │                            │    (JWT, 30 min)           │
     │                            │                            │
     │                            │ 4. Create refresh token    │
     │                            │    (random, 30 days)       │
     │                            ├───────────────────────────>│
     │                            │                            │
     │ 5. Return access token +   │                            │
     │    Set httpOnly cookie     │                            │
     │<───────────────────────────┤                            │
     │                            │                            │
     │ 6. Use access token for    │                            │
     │    API calls (30 min)      │                            │
     ├───────────────────────────>│                            │
     │                            │                            │
     │ 7. Access token expires    │                            │
     │    (after 30 min)          │                            │
     │                            │                            │
     │ 8. POST /refresh-token     │                            │
     │    (with cookie)           │                            │
     ├───────────────────────────>│                            │
     │                            │ 9. Validate refresh token  │
     │                            ├───────────────────────────>│
     │                            │                            │
     │                            │ 10. Generate new access    │
     │                            │     token (JWT, 30 min)    │
     │                            │                            │
     │ 11. Return new access token│                            │
     │<───────────────────────────┤                            │
     │                            │                            │
     │ 12. Continue using API     │                            │
     │     with new token         │                            │
     │                            │                            │
```

---

## 💻 Frontend Implementation

### Axios Interceptor (Recommended)

```javascript
import axios from 'axios';

// Response interceptor
axios.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;

    // If 401 and haven't tried refresh yet
    if (error.response?.status === 401 && !originalRequest._retry) {
      originalRequest._retry = true;

      try {
        // Call refresh-token endpoint
        const { data } = await axios.post('/api/v1/auth/refresh-token', {}, {
          withCredentials: true // Important: send cookies
        });

        // Update access token
        const newToken = data.data.token;
        localStorage.setItem('access_token', newToken);

        // Retry original request with new token
        originalRequest.headers.Authorization = `Bearer ${newToken}`;
        return axios(originalRequest);
      } catch (refreshError) {
        // Refresh failed - logout user
        localStorage.removeItem('access_token');
        window.location.href = '/login';
        return Promise.reject(refreshError);
      }
    }

    return Promise.reject(error);
  }
);
```

### Automatic Refresh Before Expiry

```javascript
// Check token expiry and refresh proactively
function scheduleTokenRefresh() {
  const token = localStorage.getItem('access_token');
  if (!token) return;

  const payload = JSON.parse(atob(token.split('.')[1]));
  const expiresAt = payload.exp * 1000;
  const now = Date.now();
  const refreshIn = expiresAt - now - (5 * 60 * 1000); // 5 min before expiry

  if (refreshIn > 0) {
    setTimeout(async () => {
      try {
        const { data } = await axios.post('/api/v1/auth/refresh-token', {}, {
          withCredentials: true
        });
        localStorage.setItem('access_token', data.data.token);
        scheduleTokenRefresh(); // Schedule next refresh
      } catch (error) {
        console.error('Token refresh failed', error);
      }
    }, refreshIn);
  }
}

// Call after login
scheduleTokenRefresh();
```

---

## 🔧 Configuration

### Environment Variables

```bash
# .env file
COOKIE_DOMAIN=                    # Empty for localhost, or .yourdomain.com
JWT_SECRET=your-secret-key-here   # Strong secret for JWT signing
```

---

## 🧪 Testing

### Manual Test with cURL

```bash
# 1. Login
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}' \
  -c cookies.txt

# 2. Extract access token from response

# 3. Refresh token
curl -X POST http://localhost:8000/api/v1/auth/refresh-token \
  -H "Content-Type: application/json" \
  -d '{}' \
  -b cookies.txt

# 4. Logout
curl -X POST http://localhost:8000/api/v1/auth/logout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -b cookies.txt
```

---

## 🛡️ Security Best Practices

1. **Always use HTTPS in production**
2. **Set COOKIE_DOMAIN correctly**
3. **Rotate JWT_SECRET periodically**
4. **Monitor suspicious activity** (multiple IPs, user agents)
5. **Implement rate limiting** on refresh endpoint
6. **Clean up expired tokens** (cron job)
7. **Revoke tokens on password change**
8. **Revoke tokens on security breach**

---

## 🧹 Maintenance

### Clean Up Expired Tokens (Cron Job)

```sql
-- Run daily
DELETE FROM fw_refresh_tokens 
WHERE expires_at < UNIX_TIMESTAMP() 
OR (revoked = 1 AND revoked_at < UNIX_TIMESTAMP() - 86400 * 30);
```

### Monitor Active Sessions

```sql
-- Active refresh tokens per user
SELECT u.email, COUNT(rt.id) as active_tokens, MAX(rt.last_used_at) as last_activity
FROM fw_users u
LEFT JOIN fw_refresh_tokens rt ON u.id = rt.user_id AND rt.revoked = 0 AND rt.expires_at > UNIX_TIMESTAMP()
GROUP BY u.id
ORDER BY active_tokens DESC;
```

---

## 📊 Monitoring

### Metrics to Track

- **Refresh token usage rate**: How often users refresh
- **Token expiry rate**: How many tokens expire unused
- **Failed refresh attempts**: Potential security issues
- **Active sessions per user**: Detect account sharing
- **Token lifetime**: Average time before revocation

---

## ✅ Summary

### Token Lifetimes:
- **Access Token (JWT)**: 30 minutes
- **Refresh Token**: 30 days

### Storage:
- **Access Token**: localStorage (frontend)
- **Refresh Token**: httpOnly cookie + database

### Security:
- ✅ XSS Protection (httpOnly)
- ✅ CSRF Protection (SameSite)
- ✅ Token Revocation (database)
- ✅ Automatic Cleanup (expiry)
- ✅ Activity Tracking (IP, user agent)

---

**Version**: 1.0  
**Last Updated**: October 17, 2025  
**Status**: ✅ Production Ready

