<?php
declare(strict_types=1);

require_once __DIR__ . '/sql_compat.php';

function run_migrations(PDO $pdo, ?array $config = null): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (db_is_sqlite($config)) {
        return;
    }

    $addColumn = static function (PDO $pdo, string $table, string $column, string $definition): void {
        $exists = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}");
        }
    };

    $addColumn($pdo, 'assignments', 'lock_after_due', 'TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn($pdo, 'submissions', 'file_path', 'VARCHAR(512) DEFAULT NULL');
    $addColumn($pdo, 'submissions', 'file_name', 'VARCHAR(255) DEFAULT NULL');
    $addColumn($pdo, 'submissions', 'is_late', 'TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn($pdo, 'quizzes', 'max_attempts', 'INT UNSIGNED NOT NULL DEFAULT 1');
    $addColumn($pdo, 'quizzes', 'lock_after_due', 'TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn($pdo, 'quiz_attempts', 'essay_scores', 'JSON DEFAULT NULL');
    $addColumn($pdo, 'quiz_attempts', 'needs_grading', 'TINYINT(1) NOT NULL DEFAULT 0');
    $addColumn($pdo, 'announcements', 'published', 'TINYINT(1) NOT NULL DEFAULT 1');
    $addColumn($pdo, 'quiz_questions', 'points', 'DECIMAL(8,2) DEFAULT NULL');
    $addColumn($pdo, 'users', 'password_reset_token', 'VARCHAR(64) DEFAULT NULL');
    $addColumn($pdo, 'users', 'password_reset_expires', 'DATETIME DEFAULT NULL');
    $addColumn($pdo, 'users', 'username', 'VARCHAR(64) DEFAULT NULL');
    $addColumn($pdo, 'users', 'admin_managed_password', 'TINYINT(1) NOT NULL DEFAULT 0');

    try {
        $pdo->exec('ALTER TABLE users MODIFY email VARCHAR(255) NULL');
    } catch (Throwable) {
        // Already nullable
    }
    try {
        $idx = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'uq_users_username'")->fetch();
        if (!$idx) {
            $pdo->exec('CREATE UNIQUE INDEX uq_users_username ON users (username)');
        }
    } catch (Throwable) {
        // Index may already exist under another name
    }
    $addColumn($pdo, 'discussions', 'points', 'DECIMAL(8,2) DEFAULT NULL');
    $addColumn($pdo, 'assignments', 'group_id', 'INT UNSIGNED DEFAULT NULL');
    $addColumn($pdo, 'assignments', 'description_format', "ENUM('text','html') NOT NULL DEFAULT 'text'");
    $addColumn($pdo, 'announcements', 'body_format', "ENUM('text','html') NOT NULL DEFAULT 'text'");
    $addColumn($pdo, 'discussions', 'prompt_format', "ENUM('text','html') NOT NULL DEFAULT 'text'");
    $addColumn($pdo, 'quizzes', 'group_id', 'INT UNSIGNED DEFAULT NULL');
    $addColumn($pdo, 'discussions', 'group_id', 'INT UNSIGNED DEFAULT NULL');
    $addColumn($pdo, 'quizzes', 'description_format', "ENUM('text','html') NOT NULL DEFAULT 'text'");

    $tableExists = static function (PDO $pdo, string $table): bool {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return (bool) $stmt->fetch();
    };

    if (!$tableExists($pdo, 'notifications')) {
        $pdo->exec(
            'CREATE TABLE notifications (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              course_id INT UNSIGNED DEFAULT NULL,
              kind VARCHAR(32) NOT NULL,
              title VARCHAR(255) NOT NULL,
              body TEXT,
              link VARCHAR(512) DEFAULT NULL,
              read_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    if (!$tableExists($pdo, 'submission_comments')) {
        $pdo->exec(
            'CREATE TABLE submission_comments (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              submission_id INT UNSIGNED NOT NULL,
              user_id INT UNSIGNED NOT NULL,
              content TEXT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
              FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    if (!$tableExists($pdo, 'assignment_groups')) {
        $pdo->exec(
            'CREATE TABLE assignment_groups (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              course_id INT UNSIGNED NOT NULL,
              name VARCHAR(255) NOT NULL,
              weight DECIMAL(5,2) NOT NULL DEFAULT 0,
              position INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    if (!$tableExists($pdo, 'discussion_grades')) {
        $pdo->exec(
            'CREATE TABLE discussion_grades (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              discussion_id INT UNSIGNED NOT NULL,
              user_id INT UNSIGNED NOT NULL,
              points DECIMAL(8,2) DEFAULT NULL,
              feedback TEXT,
              graded_at DATETIME DEFAULT NULL,
              UNIQUE KEY uq_disc_grade (discussion_id, user_id),
              FOREIGN KEY (discussion_id) REFERENCES discussions(id) ON DELETE CASCADE,
              FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    try {
        $pdo->exec(
            "ALTER TABLE quiz_questions MODIFY question_type ENUM('choice','essay','true_false','multi_select','matching') NOT NULL DEFAULT 'choice'"
        );
    } catch (Throwable) {
        // Already migrated
    }

    try {
        $pdo->exec(
            "ALTER TABLE module_items MODIFY item_type ENUM('page','assignment','quiz','discussion','announcement','external','file','lti') NOT NULL"
        );
    } catch (Throwable) {
        // Already migrated
    }

    $addColumn($pdo, 'assignments', 'rubric_id', 'INT UNSIGNED DEFAULT NULL');
    $addColumn($pdo, 'assignments', 'attachment_path', 'VARCHAR(512) DEFAULT NULL');
    $addColumn($pdo, 'assignments', 'attachment_name', 'VARCHAR(255) DEFAULT NULL');
    $addColumn($pdo, 'submissions', 'rubric_scores', 'JSON DEFAULT NULL');

    if (!$tableExists($pdo, 'comment_bank')) {
        $pdo->exec(
            'CREATE TABLE comment_bank (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              course_id INT UNSIGNED DEFAULT NULL,
              user_id INT UNSIGNED NOT NULL,
              title VARCHAR(255) NOT NULL,
              body TEXT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
              FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    if (!$tableExists($pdo, 'rubrics')) {
        $pdo->exec(
            'CREATE TABLE rubrics (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              course_id INT UNSIGNED NOT NULL,
              title VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    if (!$tableExists($pdo, 'rubric_criteria')) {
        $pdo->exec(
            'CREATE TABLE rubric_criteria (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              rubric_id INT UNSIGNED NOT NULL,
              description VARCHAR(512) NOT NULL,
              points DECIMAL(8,2) NOT NULL DEFAULT 0,
              position INT NOT NULL DEFAULT 0,
              FOREIGN KEY (rubric_id) REFERENCES rubrics(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    if (!$tableExists($pdo, 'external_tools')) {
        $pdo->exec(
            'CREATE TABLE external_tools (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              course_id INT UNSIGNED NOT NULL,
              name VARCHAR(255) NOT NULL,
              launch_url VARCHAR(1024) NOT NULL,
              consumer_key VARCHAR(255) NOT NULL,
              shared_secret VARCHAR(255) NOT NULL,
              custom_params TEXT,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    if (!$tableExists($pdo, 'api_tokens')) {
        $pdo->exec(
            'CREATE TABLE api_tokens (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              label VARCHAR(255) NOT NULL,
              token_hash VARCHAR(64) NOT NULL,
              token_prefix VARCHAR(12) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              last_used_at DATETIME DEFAULT NULL,
              UNIQUE KEY uq_api_token_hash (token_hash),
              FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }
}