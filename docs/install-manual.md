# Manual install (without XAMPP)

Use this guide when you already have **PHP**, a **web server**, and **MySQL/MariaDB** — or when XAMPP is not an option.

YourLMS also supports **SQLite-only** shared hosting via `install.php` (no MySQL). See [INSTALL.md](../INSTALL.md#shared-hosting-no-mysql).

---

## Requirements

- PHP **8.1+** with extensions: `mbstring`, `zip`, `pdo_mysql` (or `pdo_sqlite`)
- MySQL 5.7+ / MariaDB 10.3+ **or** SQLite for `install.php`
- Apache (recommended), nginx, or Caddy
- Writable `uploads/` and project root (for `config.local.php`)

---

## Linux (Debian / Ubuntu)

### 1. Install packages

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php php-mysql php-mbstring php-zip php-xml php-curl mariadb-server
sudo a2enmod rewrite
sudo systemctl enable --now apache2 mariadb
```

### 2. Create database

```bash
sudo mysql -e "CREATE DATABASE yourlms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'yourlms'@'localhost' IDENTIFIED BY 'choose-a-strong-password';"
sudo mysql -e "GRANT ALL ON yourlms.* TO 'yourlms'@'localhost'; FLUSH PRIVILEGES;"
```

### 3. Deploy YourLMS

```bash
cd /var/www
sudo git clone https://github.com/kenneth-nnadi/yourlms.git yourlms
cd yourlms
sudo chown -R www-data:www-data uploads data
sudo chmod -R 775 uploads
```

### 4. Run the web installer

Open:

`http://your-server/yourlms/setup.php`

Enter database host `127.0.0.1`, database `yourlms`, user `yourlms`, and your password. Click **Install now**.

### 5. Secure the site

- Change demo passwords under **Teach → People**
- Enable HTTPS — see [ssl-and-domain.md](ssl-and-domain.md)
- Block or remove `setup.php` after installation on a public server

---

## macOS (Homebrew — no XAMPP)

### 1. Install services

```bash
brew install php mysql httpd
brew services start mysql
brew services start httpd
```

Point Apache document root at YourLMS (or symlink into `~/Sites`). Example:

```bash
git clone https://github.com/kenneth-nnadi/yourlms.git ~/Sites/yourlms
chmod -R 775 ~/Sites/yourlms/uploads
```

Create the database:

```bash
mysql -u root -e "CREATE DATABASE yourlms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Web installer

Visit `http://localhost:8080/yourlms/setup.php` (port depends on your `httpd` config) and complete the form.

---

## nginx + PHP-FPM

1. Clone YourLMS to e.g. `/var/www/yourlms`
2. Configure a server block with `root /var/www/yourlms` and `try_files` → `index.php`
3. Pass `.php` to PHP-FPM
4. Ensure `.htaccess` rules are mirrored if you rely on upload limits (or set `upload_max_filesize` in `php.ini` / pool config)
5. Run `setup.php` in the browser

Example PHP-FPM pool snippet:

```ini
php_admin_value[upload_max_filesize] = 1024M
php_admin_value[post_max_size] = 1024M
php_admin_value[memory_limit] = 1280M
```

---

## Windows (without XAMPP)

Options:

1. **Docker** — easiest cross-platform path: [docker.md](docker.md)
2. **WAMP / Laragon** — same steps as XAMPP: copy `yourlms` into `www`, start Apache + MySQL, open `setup.php`
3. **IIS + PHP** — install PHP and MySQL separately, deploy files, browse to `setup.php`

---

## Shared hosting (no MySQL, no shell)

1. Upload all files to `public_html/yourlms`
2. Set `data/` and `uploads/` to writable (FTP or panel)
3. Visit `https://yoursite.com/yourlms/install.php`
4. Delete `install.php` when finished

---

## After install

| Step | Action |
|------|--------|
| 1 | Log in: `instructor@yourlms.test` / `password123` |
| 2 | **Getting started** → import Canvas IMS or create a course |
| 3 | Change demo passwords before going public |

Database credentials live in `config.local.php`. Course imports support up to **1 GB** when PHP limits are configured (setup does this automatically on XAMPP).