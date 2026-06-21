# Architecture

YourLMS is a monolithic PHP application with server-rendered HTML, a thin JSON API, and optional SQLite or MySQL storage. It is intentionally small: no Node build step, no separate frontend framework, and no Redis queue.

## Stack

| Layer | Technology |
|-------|------------|
| Runtime | PHP 8.1+ |
| Database | MySQL/MariaDB (primary) or SQLite (shared hosting) |
| Web server | Apache recommended; any PHP-capable server works |
| Rich text | Quill editor, sanitized HTML on display |
| Sessions | PHP native sessions |

## Request flow

```
Browser → /yourlms/login.php (routed to public/login.php)
        → includes/bootstrap.php (config, session, PDO, migrations)
        → auth + role checks
        → page logic + includes/layout.php shell
        → HTML response
```

The web root is `public/`. Apache/nginx rewrites requests so URLs stay `/yourlms/...` without exposing `includes/`, `database/`, or `config.php`.

Admin/teach tools live under `public/admin/`. Course-facing pages (`assignment.php`, `quiz.php`, etc.) share course navigation via `render_course_shell_start()`.

## Data model (simplified)

```
courses
  ├── modules → module_items (pages, links to assignments/quizzes/discussions/files)
  ├── assignments → submissions
  ├── quizzes → quiz_questions → quiz_attempts
  ├── discussions → discussion_posts (threaded via parent_id)
  ├── announcements
  ├── course_files
  ├── assignment_groups (weighted grading)
  └── enrollments (user + role)
users
notifications
api_tokens
```

## Publishing model

Students only see content that is:

1. In a **published** module
2. With a **published** module item
3. For assignments/quizzes/discussions — linked into a module and marked live

Instructors use **Preview as student** to validate visibility without logging out.

## Import/export

- **IMS Common Cartridge** — restores Canvas-style structure into modules and linked items (`includes/imscc_importer.php`).
- **JSON/ZIP export** — portable `open-lms-course-v1` backup with uploaded files (`includes/course_export.php`, `includes/course_import.php`).

Submissions, quiz attempts, and enrollments are **not** included in course export by design.

## Key directories

| Path | Role |
|------|------|
| `public/` | Web root — PHP pages, `admin/`, `api/`, `assets/` |
| `includes/` | Bootstrap, auth, helpers, migrations, notifications, import/export |
| `database/` | Schema and seed SQL |
| `uploads/` | Runtime file storage (gitignored, outside `public/`) |
| `config.php` | Default configuration (outside `public/`) |
| `tests/` | Smoke and HTTP integration audits |

## Extending safely

- Add migrations in `includes/migrations.php` (MySQL) and mirror columns in `database/schema*.sql` for fresh installs.
- New gradeable types should integrate with `ref_publish_ui.php` and the gradebook export helpers.
- New POST endpoints must use CSRF unless explicitly exempted in `includes/csrf.php`.