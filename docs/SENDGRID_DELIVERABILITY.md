# SendGrid deliverability (reduce Spam folder)

Production uses **SendGrid only** (`EMAIL_PROVIDER=sendgrid`).

## Why Gmail puts messages in Spam today

| Issue | Current value |
|-------|----------------|
| From address | `justin.kearney@easyrx.ca` |
| Links in email | `fieldwire.medicalcontractor.ca` |
| Domain alignment | **Mismatch** — main spam signal |
| DKIM for SendGrid on `medicalcontractor.ca` | Not configured |

Code already disables click/open tracking and uses a plain transactional template.

## Fix (required for Inbox delivery)

Authenticate **`medicalcontractor.ca`** in SendGrid, then change the sender to the same domain.

### Step 1 — SendGrid

1. [SendGrid](https://app.sendgrid.com/) → **Settings** → **Sender Authentication**
2. **Authenticate Your Domain** → enter `medicalcontractor.ca`
3. Copy the **CNAME** records SendGrid shows

### Step 2 — cPanel DNS (Zone Editor)

Add all CNAME records from SendGrid (names differ per account), for example:

| Type | Name | Target |
|------|------|--------|
| CNAME | `em####.medicalcontractor.ca` | `u####.wl###.sendgrid.net` |
| CNAME | `s1._domainkey.medicalcontractor.ca` | `s1.domainkey.u####.wl###.sendgrid.net` |
| CNAME | `s2._domainkey.medicalcontractor.ca` | `s2.domainkey.u####.wl###.sendgrid.net` |

Wait 15–30 minutes, then click **Verify** in SendGrid.

### Step 3 — DMARC (recommended)

TXT record:

```
Host: _dmarc.medicalcontractor.ca
Value: v=DMARC1; p=none; rua=mailto:support@medicalcontractor.ca
```

### Step 4 — Update API env and deploy

In `env.production`:

```env
SENDGRID_FROM_EMAIL=noreply@medicalcontractor.ca
SENDGRID_FROM_NAME=FieldWire
SENDGRID_REPLY_TO=support@medicalcontractor.ca
```

```bash
bash deploy_fwapi_ssh.sh --update-env
```

No real mailbox is required for `noreply@` — only DNS authentication in SendGrid.

### Step 5 — Test

1. Request password reset on production
2. Check **Inbox** and **Spam**
3. If still in Spam once: open email → **Report not spam**

## Until DNS is done

Emails are delivered via SendGrid but may land in **Spam**. Search Gmail for:

`FieldWire password reset`

## SendGrid Activity Feed

SendGrid → **Activity** → filter by recipient → status should be **Delivered**.

Remove the address from **Suppressions** if it was bounced or marked spam earlier.
