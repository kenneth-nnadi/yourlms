# Security Policy

## Supported versions

Security fixes are applied to the latest release on the default branch. Self-hosted deployments should track `main` or the newest tagged release.

## Reporting a vulnerability

**Please do not open public GitHub issues for security vulnerabilities.**

Email the maintainers with:

- Description of the issue and impact
- Steps to reproduce
- Your environment (PHP version, database, deployment type)
- Any suggested fix, if you have one

We aim to acknowledge reports within **5 business days** and will coordinate disclosure once a fix is available.

## Deployment guidance

YourLMS is designed for **self-hosted** use. Before exposing an instance to the internet:

1. **Change default demo passwords** created by `database/seed.sql` or the installer.
2. Run behind **HTTPS** and set secure session cookies (`config.local.php`).
3. Keep `config.local.php` and `.install-lock` out of version control.
4. Remove or block access to `install.php` and `setup.php` after installation.
5. Restrict write permissions on `uploads/` to what the web server needs.
6. Enable SMTP only with credentials stored in `config.local.php`, not in `config.php`.

## Built-in protections

- CSRF tokens on POST requests (with documented exemptions)
- Login and password-reset rate limiting
- Content Security Policy and related security headers
- HTML sanitization for user-authored rich text
- Upload path validation on downloads
- Role-based access for courses, grading, and Teach tools

## Out of scope

This is a lightweight LMS, not a full enterprise identity platform. For high-assurance deployments, place YourLMS behind your organization's SSO, WAF, and backup policies.