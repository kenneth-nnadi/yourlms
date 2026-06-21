<?php
declare(strict_types=1);

function api_json_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }
    return $_GET['api_token'] ?? null;
}

function api_authenticate(PDO $pdo): array
{
    $token = api_bearer_token();
    if (!$token || strlen($token) < 20) {
        api_json_response(401, ['error' => 'Missing or invalid API token. Use Authorization: Bearer <token>.']);
    }
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT t.*, u.id AS uid, u.email, u.full_name, u.role FROM api_tokens t
         JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        api_json_response(401, ['error' => 'Invalid API token.']);
    }
    if (!user_is_site_instructor(['role' => $row['role']])) {
        api_json_response(403, ['error' => 'API access requires site instructor role.']);
    }
    $pdo->prepare('UPDATE api_tokens SET last_used_at = ' . db_now_sql() . ' WHERE id = ?')->execute([(int) $row['id']]);
    return [
        'id' => (int) $row['uid'],
        'email' => $row['email'],
        'full_name' => $row['full_name'],
        'role' => $row['role'],
    ];
}

function api_create_token(PDO $pdo, int $userId, string $label): array
{
    $token = 'ylms_' . bin2hex(random_bytes(24));
    $hash = hash('sha256', $token);
    $prefix = substr($token, 0, 12);
    $pdo->prepare('INSERT INTO api_tokens (user_id, label, token_hash, token_prefix) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $label, $hash, $prefix]);
    return ['token' => $token, 'prefix' => $prefix, 'id' => (int) $pdo->lastInsertId()];
}