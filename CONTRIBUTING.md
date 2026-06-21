# Contributing to YourLMS

Thank you for helping improve YourLMS. This project exists so educators can run a capable, self-hosted LMS without depending on a single cloud vendor.

## Ways to contribute

- **Bug reports** — open an issue with steps to reproduce, expected behavior, and your environment (PHP version, MySQL/SQLite, browser).
- **Feature ideas** — describe the teaching workflow you are trying to support.
- **Pull requests** — fix bugs, improve docs, add tests, or refine UI/UX.
- **Curriculum samples** — share anonymized course export examples via a separate release or link (do not commit large `.zip` files to the main repo).

## Development setup

1. PHP 8.1+ with `pdo_mysql` or `pdo_sqlite`, `zip`, and `mbstring`.
2. Clone the repository and copy `config.local.php.example` to `config.local.php` if needed.
3. For local MySQL (XAMPP):
   ```bash
   mysql -u root -e "CREATE DATABASE IF NOT EXISTS yourlms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root yourlms < database/schema.sql
   mysql -u root yourlms < database/seed.sql
   ```
4. Set `base_url` in `config.php` to match your local path.
5. Ensure `uploads/` is writable by the web server.

## Code style

- Match existing PHP patterns: strict types where present, small focused functions, minimal drive-by refactors.
- Sanitize user HTML on output; never echo unsanitized rich text.
- Use CSRF fields on new POST forms (see `includes/csrf.php`).
- Prefer extending helpers in `includes/` over duplicating logic in page files.

## Tests

```bash
php tests/run.php                  # unit/smoke tests
php tests/integration_audit.php    # HTTP page-load checks (needs running server)
php tests/deep_audit.php           # feature + API checks (needs running server)
```

Set `YOURLMS_BASE` when your install is not at `http://localhost/yourlms`:

```bash
YOURLMS_BASE=http://localhost/yourlms php tests/integration_audit.php
```

Optional PHPUnit:

```bash
composer install
composer test
```

## Pull request checklist

- [ ] Change is scoped to the issue or feature described
- [ ] `php tests/run.php` passes
- [ ] No secrets, local config, or large binaries added
- [ ] README or `docs/` updated if behavior or setup changed
- [ ] Demo/default passwords unchanged unless intentionally documented

## Community standards

Be respectful and constructive. This project serves teachers, students, and program coordinators — keep discussions practical and inclusive.