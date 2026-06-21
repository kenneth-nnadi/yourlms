# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-06-21

### Added

- Open-source release of YourLMS — lightweight PHP LMS for self-hosted teaching
- Courses, modules, pages, assignments, quizzes, discussions, announcements, files
- Canvas-style publishing workflow (modules, Go live, student preview)
- IMS Common Cartridge import and JSON/ZIP course backup/restore
- Weighted assignment groups, gradebook, rubrics, comment bank
- In-app notifications, optional SMTP email
- Student preview, bulk enrollment CSV, API tokens for instructors
- SQLite shared-hosting installer (`install.php`) and MySQL/XAMPP path (`setup.php`)
- Mobile-responsive navigation and discussion reply threading
- Integration and deep audit test scripts

### Security

- CSRF protection, rate limiting, security headers, upload path checks
- Documented security reporting process (`SECURITY.md`)
- Server-side CSRF tokens on course import forms

### Fixed

- XAMPP setup wizard `config()` error during install
- Database credential form on `setup.php` with `config.local.php` persistence
- Writable-folder checks before marking installation complete
- Misleading CSRF error when uploads exceeded PHP size limits

### Changed

- Setup auto-configures up to **1 GB** course import limits (`.htaccess`, `.user.ini`, `php.ini` when writable)
- Default application upload limit raised to 1024 MB

[1.0.0]: https://github.com/kenneth-nnadi/yourlms/releases/tag/v1.0.0