# Custom domain and SSL (optional)

YourLMS works on **localhost** immediately after install. You only need this guide when you want to put YourLMS on the **internet** with your own address and a secure padlock (HTTPS).

## Overview

| Stage | URL example | SSL |
|-------|-------------|-----|
| Local testing | `http://localhost/yourlms` | Not required |
| School server | `https://learn.yourschool.edu/yourlms` | Recommended |
| Subdomain | `https://lms.yourschool.org` | Recommended |

## Step 1 — Point your domain

Ask your IT team or hosting provider to point a domain or subdomain to the server where YourLMS files live.

Examples:

- **A record** — `lms.yourschool.org` → server IP address
- **Subfolder** — `yourschool.edu/yourlms` (upload files to that path)

Update `base_url` in `config.php` to match (e.g. `/yourlms` or `` if at the domain root).

## Step 2 — Enable HTTPS

Common options:

- **Let’s Encrypt** — free certificates; many hosts offer one-click SSL
- **School IT certificate** — install on Apache/nginx per your district policy
- **Cloudflare** — SSL in front of your server (Flexible or Full mode)

Copy `config.local.php.example` to `config.local.php` and set:

```php
'session' => [
    'secure' => true,
],
```

YourLMS auto-enables secure cookies when it detects HTTPS if `auto_secure` is true (default).

## Step 3 — Harden the install

- [ ] Change all demo passwords
- [ ] Block or remove `setup.php` and `install.php` on production
- [ ] Keep `config.local.php` out of git and off public downloads
- [ ] Back up database and `uploads/` regularly
- [ ] Optional: SMTP in `config.local.php` for email notifications

## Step 4 — Test

1. Visit your HTTPS URL and log in.
2. Upload a small file in a course to confirm `uploads/` is writable.
3. Use **Preview as student** on a published module.

## When to stay on localhost

- Classroom demos on one laptop
- Offline workshops without internet
- Development and curriculum building before go-live

You can always move to a domain later — export courses as JSON/ZIP backups first.