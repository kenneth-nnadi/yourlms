# YourLMS

**A free, self-hosted learning management system — install in minutes, no command line required.**

YourLMS runs on your laptop or school server. Import a Canvas course, build modules, grade assignments, and keep teaching even when cloud platforms are down.

**GitHub:** [github.com/kenneth-nnadi/yourlms](https://github.com/kenneth-nnadi/yourlms) · MIT License

---

## Why we built this

In early 2026, a major cyber incident disrupted **Instructure** (the company behind Canvas) and affected **Canvas for Teachers** — the free tier used by educators worldwide — along with many international schools that relied on it every day.

Among the programs hit was the **NICE cybersecurity curriculum** used to train high school teachers. We lost not only years of course materials but also access to the platform that held them.

When the **Oregon NICE Teachers Summer Workshop** came around in 2026, we still had teachers in the room and a program to deliver — but no Canvas. We needed something that worked locally, under our control, and on short notice.

**Kenneth Nnadi** and **Dan Carrere** built YourLMS as that alternative. We are open-sourcing it so any educator can:

- Install their own LMS **free of charge**
- Host curriculum on hardware they control
- Import existing Canvas IMS exports
- Keep teaching when SaaS platforms are unavailable

You start with a **clean slate** — no pre-loaded courses. Import yours or build from scratch.

---

## Install in 3 steps (XAMPP — easiest)

Designed for **non-technical users**. No terminal commands required.

1. **Download** this folder and place it in XAMPP’s `htdocs` as `yourlms`  
   (Full path example: `C:\xampp\htdocs\yourlms` or `/Applications/XAMPP/htdocs/yourlms`)

2. **Start** Apache and MySQL in the XAMPP control panel.

3. **Open** in your browser:  
   `http://localhost/yourlms/setup.php`  
   Enter database details (XAMPP defaults are pre-filled), click **Install now**, then follow **Getting started**.

That’s it. See [INSTALL.md](INSTALL.md) for screenshots-style detail and troubleshooting.

**Shared hosting (no MySQL)?** Use `install.php` instead — stores everything in one SQLite file.

---

## After install

| Step | What to do |
|------|------------|
| 1 | Log in as instructor (`instructor@yourlms.test` / `password123`) |
| 2 | Open **Getting started** from the dashboard or Teach menu |
| 3 | Import your Canvas `.zip` (**Teach → Import IMS**) or create a course manually |
| 4 | Add students under **Teach → People** |
| 5 | Publish modules and use **Preview as student** |

Optional later: [custom domain & SSL](docs/ssl-and-domain.md)

---

## Demo accounts

| Email | Role | Password |
|-------|------|----------|
| `instructor@yourlms.test` | Site instructor | `password123` |
| `student@yourlms.test` | Student | `password123` |

**Change these before sharing your server with anyone.**

---

## Features

- Courses, modules, pages, files, assignments, quizzes, discussions
- Canvas-style **Go live** publishing and student preview
- IMS Common Cartridge import + JSON/ZIP backup/restore
- Gradebook, rubrics, weighted assignment groups
- In-app notifications (optional SMTP email)
- Mobile-friendly UI and threaded discussions
- API tokens for course/grade export

---

## Documentation

| Guide | Description |
|-------|-------------|
| [INSTALL.md](INSTALL.md) | Step-by-step for non-technical installers |
| [Getting started](getting-started.php) | In-app wizard after login |
| [docs/publishing.md](docs/publishing.md) | How students see your content |
| [docs/ssl-and-domain.md](docs/ssl-and-domain.md) | HTTPS and custom domain (optional) |
| [docs/architecture.md](docs/architecture.md) | Technical overview |
| [CONTRIBUTING.md](CONTRIBUTING.md) | For developers |
| [SECURITY.md](SECURITY.md) | Report vulnerabilities |

---

## Requirements

- PHP 8.1+ (`mbstring`, `zip`, `pdo_mysql` or `pdo_sqlite`)
- MySQL/MariaDB (XAMPP) **or** SQLite (shared hosting)
- Apache or any PHP web server

---

## Credits

Created for the **Oregon NICE Teachers Summer Workshop**, 2026 — when educators needed to keep teaching without the cloud.

**Kenneth Nnadi** · **Dan Carrere** · open-source contributors

## License

MIT — see [LICENSE](LICENSE).