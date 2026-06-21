# Deployment

YourLMS supports three common paths: **local XAMPP (MySQL)**, **shared hosting (SQLite)**, and **Docker**.

## XAMPP / local MySQL (recommended for development)

1. Copy the project into `htdocs/yourlms`.
2. Create the database and load schema:
   ```bash
   mysql -u root -e "CREATE DATABASE IF NOT EXISTS yourlms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root yourlms < database/schema.sql
   mysql -u root yourlms < database/seed.sql
   ```
3. Open `http://localhost/yourlms/setup.php` and click **Install now**.
4. Enter database name, username, and password (XAMPP defaults: database `yourlms`, user `root`, empty password). Settings are saved to `config.local.php`.

### Change database credentials later

Edit `config.local.php` in the project folder (created during setup):

```php
'db' => [
    'host' => '127.0.0.1',
    'name' => 'yourlms',
    'user' => 'your_db_user',
    'pass' => 'your_db_password',
],
```

Do not commit `config.local.php` to git. Restart Apache after changes if the site stops connecting.
5. Make `uploads/` writable: `chmod -R 775 uploads` (or `777` on restrictive Mac/XAMPP setups).
6. **Change demo passwords** before sharing the network.

## Shared hosting (SQLite)

1. Upload files to `public_html/yourlms`.
2. Ensure `data/` and `uploads/` are writable.
3. Visit `install.php`, complete the wizard, then **delete `install.php`**.
4. Copy secrets to `config.local.php` (see `config.local.php.example`).

## Docker

See `deploy/debian-home/docker-compose.yml` for a containerized stack. Mount persistent volumes for `uploads/` and the database.

## Production checklist

- [ ] HTTPS enabled; `session.secure` set in `config.local.php`
- [ ] Default demo accounts removed or passwords rotated
- [ ] `install.php` / `setup.php` removed or blocked after setup
- [ ] `config.local.php` not web-accessible (`.htaccess` blocks `config.php`; keep local overrides out of git)
- [ ] SMTP credentials in `config.local.php` only
- [ ] Regular backups of database + `uploads/`
- [ ] Import course content from IMS or JSON export after install

## Backups

| Deployment | Back up |
|------------|---------|
| MySQL | Database dump + `uploads/` directory |
| SQLite | `data/yourlms.sqlite` + `uploads/` |

Use **Teach → Export course** for portable course content independent of student grades.

## Timezone

Leave `timezone` empty in `config.php` to auto-align PHP with MySQL system time. Set an explicit IANA zone (e.g. `America/Los_Angeles`) for fixed regional display.