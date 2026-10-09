<?php
declare(strict_types=1);

const SESSION_COOKIE = 'capacity_session';
const SESSION_TTL_SECONDS = 14 * 24 * 60 * 60;

const ADMIN_PERMISSIONS = [
    'calculator.view', 'calculator.download',
    'machines.view', 'machines.details', 'machines.change_status', 'machines.edit', 'machines.delete', 'machines.download',
    'projects.view', 'projects.details', 'projects.change_status', 'projects.edit', 'projects.delete', 'projects.create_rfq',
    'designations.view', 'designations.edit', 'designations.delete', 'designations.download',
    'scenarios.view', 'scenarios.edit', 'scenarios.delete', 'scenarios.download', 'scenarios.view_confidential',
    'call_offs.view', 'call_offs.edit', 'call_offs.delete', 'call_offs.download',
    'admin_database.view', 'admin_database.edit', 'admin_database.download',
    'admin_settings.view', 'admin_settings.edit', 'admin_settings.download',
    'admin_data_viz.view', 'admin_data_viz.edit', 'admin_data_viz.download',
    'admin_ocu.view', 'admin_ocu.edit',
    'admin_attachments.view', 'admin_attachments.download',
    'change_history.view', 'change_history.download',
    'user_management.view', 'user_management.edit', 'user_management.delete',
    'role_management.view', 'role_management.edit', 'role_management.delete',
];

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function user_login_label(array $user): string
{
    $label = $user['display_name'] ?? $user['username'] ?? $user['email'] ?? ('user#' . $user['id']);
    return trim((string) $label);
}

function public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'display_name' => $user['display_name'],
        'role_id' => (int) $user['role_id'],
        'role_name' => (string) $user['role_name'],
        'role_ids' => array_map('intval', $user['role_ids']),
        'role_names' => array_values($user['role_names']),
        'is_active' => (int) $user['is_active'],
        'must_change_password' => (int) $user['must_change_password'],
        'is_guest' => (int) ($user['is_guest'] ?? 0),
        'login' => user_login_label($user),
    ];
}

function user_roles(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.id, r.name
         FROM user_roles ur
         INNER JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = ?
         ORDER BY r.name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function attach_roles(PDO $pdo, array $row): array
{
    $roles = user_roles($pdo, (int) $row['id']);
    $roleIds = array_map(static fn(array $role): int => (int) $role['id'], $roles);
    $roleNames = array_map(static fn(array $role): string => (string) $role['name'], $roles);
    $row['role_id'] = $roleIds[0] ?? (int) ($row['role_id'] ?? 0);
    $row['role_name'] = $roleNames !== [] ? implode(', ', $roleNames) : (string) ($row['role_name'] ?? '');
    $row['role_ids'] = $roleIds;
    $row['role_names'] = $roleNames;
    $row['is_guest'] = 0;
    return $row;
}

function find_user_by_login(PDO $pdo, string $login): ?array
{
    $q = strtolower(trim($login));
    if ($q === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.email, u.password_hash, u.display_name, u.role_id, u.is_active, u.must_change_password,
                r.name AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE LOWER(u.username) = ? OR LOWER(u.email) = ?
         LIMIT 1'
    );
    $stmt->execute([$q, $q]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return attach_roles($pdo, $row);
}

function get_user_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.email, u.display_name, u.role_id, u.is_active, u.must_change_password,
                r.name AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return attach_roles($pdo, $row);
}

function permissions_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT rp.permission_key
         FROM user_roles ur
         INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
         WHERE ur.user_id = ?
         ORDER BY rp.permission_key'
    );
    $stmt->execute([$userId]);
    $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($keys) {
        return $keys;
    }
    $role = $pdo->prepare('SELECT role_id FROM users WHERE id = ?');
    $role->execute([$userId]);
    $roleId = $role->fetchColumn();
    if (!$roleId) {
        return [];
    }
    $perm = $pdo->prepare('SELECT permission_key FROM role_permissions WHERE role_id = ? ORDER BY permission_key');
    $perm->execute([(int) $roleId]);
    return $perm->fetchAll(PDO::FETCH_COLUMN);
}

function guest_permissions(PDO $pdo): array
{
    return $pdo->query(
        'SELECT DISTINCT rp.permission_key
         FROM roles r
         INNER JOIN role_permissions rp ON rp.role_id = r.id
         WHERE r.login_required = 0
         ORDER BY rp.permission_key'
    )->fetchAll(PDO::FETCH_COLUMN);
}

function guest_available(PDO $pdo): bool
{
    $roles = (int) $pdo->query('SELECT COUNT(*) FROM roles WHERE login_required = 0')->fetchColumn();
    return $roles > 0 && guest_permissions($pdo) !== [];
}

function guest_user(): array
{
    return [
        'id' => 0,
        'username' => null,
        'email' => null,
        'display_name' => 'Gość',
        'role_id' => 0,
        'role_name' => 'Gość',
        'role_ids' => [],
        'role_names' => ['Gość'],
        'is_active' => 1,
        'must_change_password' => 0,
        'is_guest' => 1,
    ];
}

function grant_admin_permissions(PDO $pdo, int $roleId): void
{
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
    $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key) VALUES (?, ?)');
    foreach (ADMIN_PERMISSIONS as $key) {
        $insert->execute([$roleId, $key]);
    }
}

function bootstrap_admin(PDO $pdo): void
{
    $dir = dirname(database_path());
    $lock = fopen($dir . DIRECTORY_SEPARATOR . 'migrate.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('auth lock failed');
    }
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $roleId = $pdo->query("SELECT id FROM roles WHERE name = 'Administrator'")->fetchColumn();
        if (!$roleId) {
            $pdo->prepare(
                "INSERT INTO roles (name, description, is_system, login_required) VALUES ('Administrator', 'Pełny dostęp', 1, 1)"
            )->execute();
            $roleId = (int) $pdo->lastInsertId();
        } else {
            $roleId = (int) $roleId;
        }
        grant_admin_permissions($pdo, $roleId);
        $hash = password_hash('Admin12345', PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, display_name, role_id, is_active, must_change_password)
             VALUES (?, NULL, ?, ?, ?, 1, 1)'
        )->execute(['admin', $hash, 'Administrator', $roleId]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function session_cookie(string $token, int $maxAge): void
{
    setcookie(SESSION_COOKIE, $token, [
        'expires' => $maxAge === 0 ? time() - 3600 : time() + $maxAge,
        'path' => '/capacity',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false,
    ]);
}

function read_session_token(): string
{
    return (string) ($_COOKIE[SESSION_COOKIE] ?? '');
}

function create_session(PDO $pdo, ?int $userId, bool $guest): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expires = gmdate('Y-m-d\TH:i:s\Z', time() + SESSION_TTL_SECONDS);
    $stmt = $pdo->prepare(
        'INSERT INTO sessions (user_id, is_guest, token_hash, expires_at, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $guest ? null : $userId,
        $guest ? 1 : 0,
        hash('sha256', $token),
        $expires,
        client_ip(),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
    return $token;
}

function find_session(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT user_id, is_guest FROM sessions
         WHERE token_hash = ? AND revoked_at IS NULL AND datetime(expires_at) > datetime(\'now\')'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function current_auth(PDO $pdo): ?array
{
    $session = find_session($pdo, read_session_token());
    if (!$session) {
        return null;
    }
    if ((int) $session['is_guest'] === 1) {
        $permissions = guest_permissions($pdo);
        if ($permissions === []) {
            return null;
        }
        $user = guest_user();
        return ['user' => $user, 'permissions' => $permissions];
    }
    $user = get_user_by_id($pdo, (int) $session['user_id']);
    if (!$user || !(int) $user['is_active']) {
        return null;
    }
    return ['user' => $user, 'permissions' => permissions_for_user($pdo, (int) $user['id'])];
}

function require_auth(PDO $pdo): array
{
    $auth = current_auth($pdo);
    if (!$auth) {
        $token = read_session_token();
        // 403, not 401: IIS Windows Authentication turns 401 into a Negotiate challenge.
        json_response(403, ['error' => $token === '' ? 'Wymagane logowanie' : 'Sesja wygasła lub jest nieprawidłowa']);
    }
    return $auth;
}

function require_permission(PDO $pdo, string $key): array
{
    return require_any_permission($pdo, [$key]);
}

function require_any_permission(PDO $pdo, array $keys): array
{
    $auth = require_auth($pdo);
    foreach ($keys as $key) {
        if (in_array($key, $auth['permissions'], true)) {
            return $auth;
        }
    }
    json_response(403, ['error' => 'Brak uprawnień']);
}

function password_problem(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Hasło musi mieć co najmniej 10 znaków';
    }
    if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Hasło musi zawierać litery i cyfry';
    }
    return null;
}

function handle_auth(string $method, string $path): void
{
    $pdo = open_database();
    bootstrap_admin($pdo);

    if ($method === 'GET' && $path === '/auth/guest-available') {
        json_response(200, ['available' => guest_available($pdo)]);
    }

    if ($method === 'POST' && $path === '/auth/guest') {
        if (!guest_available($pdo)) {
            json_response(403, ['error' => 'Wejście jako gość jest wyłączone']);
        }
        $token = create_session($pdo, null, true);
        session_cookie($token, SESSION_TTL_SECONDS);
        $user = guest_user();
        json_response(200, ['user' => public_user($user), 'permissions' => guest_permissions($pdo)]);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        $body = request_json();
        $login = trim((string) ($body['login'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $user = find_user_by_login($pdo, $login);
        if (!$user || !(int) $user['is_active'] || !password_verify($password, (string) $user['password_hash'])) {
            json_response(403, ['error' => 'Nieprawidłowy login lub hasło']);
        }
        $token = create_session($pdo, (int) $user['id'], false);
        $pdo->prepare("UPDATE users SET last_login_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")
            ->execute([(int) $user['id']]);
        session_cookie($token, SESSION_TTL_SECONDS);
        $fresh = get_user_by_id($pdo, (int) $user['id']);
        json_response(200, [
            'user' => public_user($fresh ?? $user),
            'permissions' => permissions_for_user($pdo, (int) $user['id']),
        ]);
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        $token = read_session_token();
        if ($token !== '') {
            $pdo->prepare("UPDATE sessions SET revoked_at = datetime('now') WHERE token_hash = ? AND revoked_at IS NULL")
                ->execute([hash('sha256', $token)]);
        }
        session_cookie('', 0);
        http_response_code(204);
        exit;
    }

    if ($method === 'GET' && $path === '/auth/me') {
        $auth = require_auth($pdo);
        json_response(200, [
            'user' => public_user($auth['user']),
            'permissions' => $auth['permissions'],
        ]);
    }

    if ($method === 'POST' && $path === '/auth/change-password') {
        $auth = require_auth($pdo);
        if ((int) $auth['user']['is_guest'] === 1) {
            json_response(403, ['error' => 'Konto gościa nie może zmieniać hasła']);
        }
        $body = request_json();
        $current = (string) ($body['current_password'] ?? '');
        $next = (string) ($body['new_password'] ?? '');
        $problem = password_problem($next);
        if ($problem !== null) {
            json_response(400, ['error' => $problem]);
        }
        $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $row->execute([(int) $auth['user']['id']]);
        $hash = (string) $row->fetchColumn();
        if (!password_verify($current, $hash)) {
            json_response(400, ['error' => 'Aktualne hasło jest nieprawidłowe']);
        }
        $pdo->prepare(
            "UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = datetime('now') WHERE id = ?"
        )->execute([password_hash($next, PASSWORD_BCRYPT, ['cost' => 12]), (int) $auth['user']['id']]);
        $token = read_session_token();
        $pdo->prepare(
            "UPDATE sessions SET revoked_at = datetime('now')
             WHERE user_id = ? AND revoked_at IS NULL AND token_hash != ?"
        )->execute([(int) $auth['user']['id'], hash('sha256', $token)]);
        json_response(200, ['ok' => true]);
    }

    if ($method === 'POST' && $path === '/auth/forgot-password') {
        $body = request_json();
        $login = trim((string) ($body['login'] ?? ''));
        $user = find_user_by_login($pdo, $login);
        if ($user && (int) $user['is_active'] === 1) {
            $pdo->prepare('INSERT INTO password_reset_requests (user_id, status) VALUES (?, ?)')
                ->execute([(int) $user['id'], 'pending']);
        }
        json_response(200, ['ok' => true, 'message' => 'Jeśli konto istnieje, prośba o reset została zapisana.']);
    }

    if ($method === 'POST' && $path === '/auth/reset-password') {
        $body = request_json();
        $token = trim((string) ($body['token'] ?? ''));
        $next = (string) ($body['password'] ?? '');
        $problem = password_problem($next);
        if ($token === '' || $problem !== null) {
            json_response(400, ['error' => $problem ?? 'Link resetu jest nieprawidłowy.']);
        }
        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM password_reset_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(400, ['error' => 'Link resetu jest nieprawidłowy lub wygasł.']);
        }
        $pdo->prepare(
            "UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE id = ?"
        )->execute([
            password_hash($next, PASSWORD_BCRYPT, ['cost' => 12]),
            date('Y-m-d H:i:s'),
            (int) $row['user_id'],
        ]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), (int) $row['id']]);
        $pdo->prepare('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL')
            ->execute([date('Y-m-d H:i:s'), (int) $row['user_id']]);
        json_response(200, ['ok' => true]);
    }

    json_response(404, ['ok' => false, 'error' => 'not_found', 'path' => $path]);
}

function issue_password_reset(PDO $pdo, int $userId, ?int $createdBy): array
{
    $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_by_user_id, via)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $userId,
        hash('sha256', $raw),
        date('Y-m-d H:i:s', time() + 86400),
        $createdBy,
        'admin_link',
    ]);
    return [
        'reset_url' => '/capacity/reset-hasla?token=' . rawurlencode($raw),
        'email_sent' => false,
    ];
}
