# Install YourLMS (non-technical guide)

YourLMS is designed so a teacher or coordinator can install it **without using the command line**. This guide uses **XAMPP**, which is free and works on Windows and Mac.

---

## What you need

- A computer with **XAMPP** installed ([apachefriends.org](https://www.apachefriends.org))
- This **YourLMS** folder unzipped into XAMPP’s `htdocs` directory
- About 10 minutes

---

## Step 1 — Put YourLMS in the right folder

1. Unzip the download.
2. Rename the folder to **`yourlms`** (all lowercase).
3. Move it inside XAMPP’s web folder:
   - **Windows:** `C:\xampp\htdocs\yourlms`
   - **Mac:** `/Applications/XAMPP/htdocs/yourlms`

The folder should contain files like `setup.php`, `config.php`, and `assets/`.

---

## Step 2 — Start the server

1. Open the **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

Both should show a green “Running” status.

---

## Step 3 — Run the installer

1. Open your web browser (Chrome, Firefox, Safari, or Edge).
2. Go to: **`http://localhost/yourlms/setup.php`**
3. Enter your **database details** (XAMPP defaults are pre-filled):
   - **Database name:** `yourlms` (created automatically if it does not exist)
   - **Username:** `root`
   - **Password:** leave empty on a default XAMPP install
   - **Host:** `127.0.0.1` · **Port:** `3306`
   - **Site address path:** `/yourlms` (must match your folder name in `htdocs`)
4. Click **Install now** and wait for green success messages.

Your database credentials are saved to **`config.local.php`** in the YourLMS folder. To change them later, edit that file and restart Apache if the site stops connecting.

If you see an error:

- Make sure **MySQL is running** in XAMPP.
- Double-check username, password, and database name.
- On Mac, if uploads fail later, you may need to allow write access to the `uploads` folder (see troubleshooting below).

---

## Step 4 — Log in

1. Go to **`http://localhost/yourlms/`** or click through from the setup page.
2. Log in with:
   - **Email:** `instructor@yourlms.test`
   - **Password:** `password123`

---

## Step 5 — Set up your first course

1. Open **Getting started** (linked from setup or the dashboard banner).
2. Choose one path:
   - **Import from Canvas:** Export your course as an IMS `.zip` from Canvas, then use **Teach → Import IMS**.
   - **Start fresh:** **Teach → Courses** to create a course, then **Teach → Modules** to add content.

There is **no sample curriculum** in the download — you begin with a clean slate.

---

## Shared hosting (no MySQL)

If your web host does not provide MySQL:

1. Upload all files to e.g. `public_html/yourlms`.
2. Make sure `data/` and `uploads/` are writable.
3. Visit **`https://yoursite.com/yourlms/install.php`** and follow the wizard.
4. Delete `install.php` when finished.

---

## Optional: custom website address & HTTPS

YourLMS works on `localhost` without any extra setup. When you want a real domain (e.g. `learn.yourschool.org`) with a padlock (HTTPS), read [docs/ssl-and-domain.md](docs/ssl-and-domain.md).

---

## Troubleshooting

| Problem | Try this |
|---------|----------|
| Blank page or “connection refused” | Start Apache in XAMPP |
| Database error on install | Start MySQL in XAMPP |
| File uploads don’t work | Set `uploads` folder permissions to writable (777 on Mac XAMPP if needed) |
| Wrong URL | Folder name must match **Site address path** on the setup form (default `/yourlms`) |
| Wrong database password | Edit `config.local.php` → `db` section, or re-run setup after removing `.setup-complete` |
| Already installed | Delete `.setup-complete` in the folder only if you want to re-run setup on a fresh database |

---

## Security before going public

- Change demo passwords under **Teach → People** or create new accounts and delete demos.
- Use HTTPS on the internet — see [docs/ssl-and-domain.md](docs/ssl-and-domain.md).
- Remove `setup.php` access or block it after installation on a public server.

---

## Need help?

Open an issue at [github.com/kennethnnadi/yourlms](https://github.com/kennethnnadi/yourlms/issues).