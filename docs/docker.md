# Docker install

Run YourLMS in **Apache + PHP + MariaDB** containers. Good for developers, Linux servers, or anyone who prefers Docker over XAMPP.

**Requirements:** [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Mac/Windows) or Docker Engine + Compose v2 (Linux).

---

## Quick start

From the YourLMS repository root:

```bash
bash deploy/debian-home/setup.sh
```

The script will:

1. Create `~/yourlms-debian/` (or `$YOURLMS_HOME`) with database passwords in `.env`
2. Write `config.local.php` for the Docker network
3. Offer to start the stack

Then open **http://localhost:8080/** (or the port you chose).

**Demo login:** `instructor@yourlms.test` / `password123`

---

## Custom install location or port

```bash
YOURLMS_HOME=~/apps/yourlms YOURLMS_PORT=9080 bash deploy/debian-home/setup.sh
```

---

## Day-to-day commands

After setup, use the wrapper script:

```bash
~/yourlms-debian/yourlms start    # build & start containers
~/yourlms-debian/yourlms stop     # stop containers
~/yourlms-debian/yourlms restart  # rebuild & restart
~/yourlms-debian/yourlms logs     # follow logs
~/yourlms-debian/yourlms status   # container status
~/yourlms-debian/yourlms url      # print local URL
```

---

## What gets created

| Path | Purpose |
|------|---------|
| `~/yourlms-debian/.env` | Ports and database passwords (keep private) |
| `~/yourlms-debian/config.local.php` | App config mounted into the app container |
| `~/yourlms-debian/data/mysql/` | MariaDB data (persistent) |
| `~/yourlms-debian/docker-compose.yml` | Compose file copy |

YourLMS source code stays in the git clone (`APP_ROOT`); the container mounts it read/write for `uploads/`.

---

## First login

The database is initialized automatically from `database/schema.sql` and `database/seed.sql`. You do **not** need `setup.php` for Docker.

1. Open the URL printed by `yourlms start`
2. Log in as instructor (see demo accounts in README)
3. Open **Getting started** and import or build your first course

---

## Production notes

- Put a reverse proxy (Caddy, nginx, Traefik) in front for HTTPS
- Back up `~/yourlms-debian/data/mysql/` and `uploads/` regularly
- Rotate demo passwords before exposing the stack on a network
- Set `base_url` in `config.local.php` if serving from a subpath

---

## Troubleshooting

| Problem | Try this |
|---------|----------|
| Port 8080 in use | Re-run setup with `YOURLMS_PORT=9080` or change `.env` |
| Database connection error | `yourlms restart` and wait for MariaDB healthcheck |
| Upload fails | Ensure `uploads/` is writable; Docker entrypoint sets ownership |
| Reinstall from scratch | `yourlms uninstall` (removes `~/yourlms-debian`) then run `setup.sh` again |

See also [deployment.md](deployment.md) for backups and production checklist.