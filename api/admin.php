<?php
declare(strict_types=1);

const ADMIN_MONTHS = [
    'working_days_jan', 'working_days_feb', 'working_days_mar', 'working_days_apr',
    'working_days_may', 'working_days_jun', 'working_days_jul', 'working_days_aug',
    'working_days_sep', 'working_days_oct', 'working_days_nov', 'working_days_dec',
];

const ADMIN_WD_FIELDS = [
    'working_days_year', 'oee_factor', 'shift_time_seconds', 'startup_shutdown_seconds',
    'working_weeks_per_year', 'shifts_per_day',
];

function admin_now(): string
{
    return date('Y-m-d H:i:s');
}

function admin_set(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        'INSERT INTO admin_settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    )->execute([$key, $value]);
}

function admin_flag(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function admin_site_root(): string
{
    return dirname(__DIR__);
}

function admin_resolve_storage(string $raw): string
{
    $raw = trim(str_replace('/', '\\', $raw));
    if ($raw === '' || str_contains($raw, "\0") || str_contains($raw, '..')) {
        throw new InvalidArgumentException('Nieprawidłowa ścieżka.');
    }
    $absolute = preg_match('/^[A-Za-z]:\\\\/', $raw) === 1 || str_starts_with($raw, '\\\\');
    if (!$absolute) {
        $raw = admin_site_root() . '\\' . ltrim($raw, '\\');
    }
    return $raw;
}

function admin_setting_value(string $absolute): string
{
    $root = rtrim(admin_site_root(), '\\') . '\\';
    if (str_starts_with(strtolower($absolute), strtolower($root))) {
        return str_replace('\\', '/', substr($absolute, strlen($root)));
    }
    return $absolute;
}

function attachment_directory(PDO $pdo): array
{
    $raw = trim((string) ($pdo->query("SELECT value FROM admin_settings WHERE key = 'project_attachments_output_dir'")->fetchColumn() ?: ''));
    if ($raw === '') {
        return ['configured' => false, 'raw' => '', 'absolute' => ''];
    }
    try {
        $absolute = admin_resolve_storage($raw);
    } catch (InvalidArgumentException) {
        return ['configured' => false, 'raw' => $raw, 'absolute' => ''];
    }
    return ['configured' => true, 'raw' => $raw, 'absolute' => $absolute];
}

function admin_user_payload(PDO $pdo, array $row): array
{
    $user = public_user(attach_roles($pdo, $row));
    $user['created_at'] = $row['created_at'] ?? null;
    $user['last_login_at'] = $row['last_login_at'] ?? null;
    return $user;
}

function admin_assignable_roles(PDO $pdo, array $roleIds): array
{
    $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), static fn(int $id): bool => $id > 0)));
    if ($roleIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($roleIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE id IN ($marks) AND login_required = 1");
    $stmt->execute($roleIds);
    $allowed = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter($roleIds, static fn(int $id): bool => in_array($id, $allowed, true)));
}

function admin_set_user_roles(PDO $pdo, int $userId, array $roleIds): void
{
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
    $insert = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
    foreach ($roleIds as $roleId) {
        $insert->execute([$userId, $roleId]);
    }
    $pdo->prepare('UPDATE users SET role_id = ?, updated_at = ? WHERE id = ?')
        ->execute([$roleIds[0], admin_now(), $userId]);
}

function admin_role_payload(PDO $pdo, array $row): array
{
    $stmt = $pdo->prepare('SELECT permission_key FROM role_permissions WHERE role_id = ? ORDER BY permission_key');
    $stmt->execute([(int) $row['id']]);
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'description' => $row['description'],
        'is_system' => (int) $row['is_system'],
        'login_required' => (int) $row['login_required'],
        'permissions' => $stmt->fetchAll(PDO::FETCH_COLUMN),
    ];
}

function admin_wd_defaults(PDO $pdo, string $prefix): array
{
    $map = setting_map($pdo);
    $fallbacks = [
        'working_days_year' => 252,
        'oee_factor' => 0.85,
        'shift_time_seconds' => 450,
        'startup_shutdown_seconds' => 720,
        'working_weeks_per_year' => 48,
        'shifts_per_day' => 3,
    ];
    $out = [];
    foreach ($fallbacks as $name => $fallback) {
        $out[$name] = setting_num($map, $prefix . '_default_' . $name, $fallback);
    }
    return $out;
}

function admin_optional_number(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float) $value : null;
}

function admin_wd_row(array $row, array $defaults): array
{
    $resolved = [];
    foreach (ADMIN_WD_FIELDS as $field) {
        $resolved[$field] = $row[$field] === null || $row[$field] === '' ? $defaults[$field] : (float) $row[$field];
        $row[$field] = $row[$field] === null ? null : (float) $row[$field];
    }
    $months = [];
    foreach (ADMIN_MONTHS as $column) {
        $months[] = (int) $row[$column];
    }
    $shifts = max(1.0, $resolved['shifts_per_day']);
    $seconds = ($resolved['working_days_year'] / 52.0) * $resolved['shift_time_seconds'] * 60.0 * $shifts * $resolved['oee_factor'];
    $row['id'] = (int) $row['id'];
    $row['year'] = (int) $row['year'];
    $row['months'] = $months;
    $row['capacity'] = (int) round($seconds - $resolved['startup_shutdown_seconds']);
    foreach (ADMIN_WD_FIELDS as $field) {
        $row['resolved_' . $field] = $resolved[$field];
    }
    return $row;
}

function admin_save_visual(PDO $pdo, array $body): array
{
    $current = visual_settings($pdo);
    foreach ($body as $key => $value) {
        if (!is_string($key) || !array_key_exists($key, $current) || is_array($current[$key])) {
            continue;
        }
        $stored = is_bool($current[$key])
            ? (admin_flag($value) ? '1' : '0')
            : (is_scalar($value) ? trim((string) $value) : '');
        admin_set($pdo, 'visual_' . $key, $stored);
    }
    return visual_settings($pdo);
}

function admin_csv(string $key, string $alt = ''): array
{
    $raw = $_GET[$key] ?? $_GET[$alt] ?? '';
    if (is_array($raw)) {
        $raw = implode(',', $raw);
    }
    $parts = preg_split('/\s*,\s*/', trim((string) $raw)) ?: [];
    return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
}

function admin_id_list(string $key, string $alt = ''): array
{
    $ids = [];
    foreach (admin_csv($key, $alt) as $part) {
        if (ctype_digit($part)) {
            $ids[] = (int) $part;
        }
    }
    return array_values(array_unique($ids));
}

function admin_in(string $column, array $values): array
{
    $marks = implode(',', array_fill(0, count($values), '?'));
    return ['clause' => "$column IN ($marks)", 'params' => array_values($values)];
}

function admin_detail_label(array $row): string
{
    $parts = array_filter([
        trim((string) ($row['detail_sap_number'] ?? '')),
        trim((string) ($row['detail_alias'] ?? '')),
        trim((string) ($row['detail_designation'] ?? '')),
    ], static fn(string $part): bool => $part !== '');
    return implode(' / ', array_unique($parts));
}

function admin_machine_label(array $row): string
{
    $internal = trim((string) ($row['machine_internal_number'] ?? ''));
    $sap = trim((string) ($row['machine_sap_number'] ?? ''));
    if ($internal !== '' && $sap !== '') {
        return $internal . ' / ' . $sap;
    }
    return $internal !== '' ? $internal : $sap;
}

function handle_admin(string $method, string $path): bool
{
    if (str_starts_with($path, '/admin/')) {
        return admin_handle_admin($method, $path);
    }
    if (str_starts_with($path, '/settings')) {
        return admin_handle_settings($method, $path);
    }
    if ($path === '/projects/history' || $path === '/projects/history/filters' || str_contains($path, '/notes') || str_contains($path, '/attachments')) {
        return admin_handle_projects($method, $path);
    }
    return false;
}

function admin_handle_admin(string $method, string $path): bool
{
    $pdo = open_database();
    bootstrap_admin($pdo);

    if ($method === 'GET' && $path === '/admin/users') {
        require_permission($pdo, 'user_management.view');
        $rows = [];
        foreach ($pdo->query(
            'SELECT u.id, u.username, u.email, u.display_name, u.role_id, u.is_active, u.must_change_password,
                    u.created_at, u.last_login_at, r.name AS role_name
             FROM users u LEFT JOIN roles r ON r.id = u.role_id
             ORDER BY COALESCE(u.display_name, u.username, u.email)'
        ) as $row) {
            $rows[] = admin_user_payload($pdo, $row);
        }
        json_response(200, $rows);
    }

    if ($method === 'POST' && $path === '/admin/users') {
        $auth = require_permission($pdo, 'user_management.edit');
        $body = request_json();
        $username = trim((string) ($body['username'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $display = trim((string) ($body['display_name'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $problem = password_problem($password);
        if (($username === '' && $email === '') || $problem !== null) {
            json_response(400, ['error' => $problem ?? 'Podaj login lub e-mail.']);
        }
        $roles = admin_assignable_roles($pdo, is_array($body['role_ids'] ?? null) ? $body['role_ids'] : [(int) ($body['role_id'] ?? 0)]);
        if ($roles === []) {
            json_response(400, ['error' => 'Wybierz rolę, która wymaga logowania.']);
        }
        try {
            $pdo->prepare(
                'INSERT INTO users (username, email, password_hash, display_name, role_id, is_active, must_change_password, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?)'
            )->execute([
                $username !== '' ? $username : null,
                $email !== '' ? $email : null,
                password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                $display !== '' ? $display : null,
                $roles[0],
                admin_now(),
                admin_now(),
            ]);
        } catch (PDOException $e) {
            json_response(409, ['error' => str_contains($e->getMessage(), 'UNIQUE') ? 'Login lub e-mail jest już używany.' : $e->getMessage()]);
        }
        $id = (int) $pdo->lastInsertId();
        admin_set_user_roles($pdo, $id, $roles);
        $fresh = get_user_by_id($pdo, $id);
        json_response(201, admin_user_payload($pdo, $fresh ?? ['id' => $id]));
    }

    if (preg_match('#^/admin/users/(\d+)$#', $path, $match) === 1) {
        $id = (int) $match[1];
        if ($method === 'PUT') {
            $auth = require_permission($pdo, 'user_management.edit');
            $body = request_json();
            $existing = get_user_by_id($pdo, $id);
            if (!$existing) {
                json_response(404, ['error' => 'Not found']);
            }
            $username = array_key_exists('username', $body) ? trim((string) $body['username']) : (string) $existing['username'];
            $email = array_key_exists('email', $body) ? trim((string) $body['email']) : (string) ($existing['email'] ?? '');
            $display = array_key_exists('display_name', $body) ? trim((string) $body['display_name']) : (string) ($existing['display_name'] ?? '');
            $active = array_key_exists('is_active', $body) ? (admin_flag($body['is_active']) ? 1 : 0) : (int) $existing['is_active'];
            if ($active === 0 && $id === (int) $auth['user']['id']) {
                json_response(400, ['error' => 'Nie można wyłączyć własnego konta.']);
            }
            $roles = array_key_exists('role_ids', $body) || array_key_exists('role_id', $body)
                ? admin_assignable_roles($pdo, is_array($body['role_ids'] ?? null) ? $body['role_ids'] : [(int) ($body['role_id'] ?? 0)])
                : array_map('intval', $existing['role_ids'] ?? [(int) $existing['role_id']]);
            if ($roles === []) {
                json_response(400, ['error' => 'Wybierz rolę, która wymaga logowania.']);
            }
            try {
                $pdo->prepare('UPDATE users SET username = ?, email = ?, display_name = ?, is_active = ?, updated_at = ? WHERE id = ?')
                    ->execute([
                        $username !== '' ? $username : null,
                        $email !== '' ? $email : null,
                        $display !== '' ? $display : null,
                        $active,
                        admin_now(),
                        $id,
                    ]);
            } catch (PDOException $e) {
                json_response(409, ['error' => 'Login lub e-mail jest już używany.']);
            }
            admin_set_user_roles($pdo, $id, $roles);
            $fresh = get_user_by_id($pdo, $id);
            json_response(200, admin_user_payload($pdo, $fresh ?? $existing));
        }
        if ($method === 'DELETE') {
            $auth = require_permission($pdo, 'user_management.delete');
            if ($id === (int) $auth['user']['id']) {
                json_response(400, ['error' => 'Nie można usunąć własnego konta.']);
            }
            $gone = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $gone->execute([$id]);
            if ($gone->rowCount() === 0) {
                json_response(404, ['error' => 'Not found']);
            }
            write_no_content();
        }
    }

    if ($method === 'POST' && preg_match('#^/admin/users/(\d+)/reset-password$#', $path, $match) === 1) {
        $auth = require_permission($pdo, 'user_management.edit');
        $user = get_user_by_id($pdo, (int) $match[1]);
        if (!$user) {
            json_response(404, ['error' => 'Not found']);
        }
        json_response(200, issue_password_reset($pdo, (int) $user['id'], (int) $auth['user']['id']));
    }

    if ($method === 'GET' && $path === '/admin/users/password-reset-requests') {
        require_permission($pdo, 'user_management.view');
        $rows = [];
        foreach ($pdo->query(
            "SELECT q.id, q.user_id, q.status, q.requested_at, u.username, u.email, u.display_name
             FROM password_reset_requests q
             INNER JOIN users u ON u.id = q.user_id
             WHERE q.status = 'pending'
             ORDER BY q.requested_at DESC"
        ) as $row) {
            $row['id'] = (int) $row['id'];
            $row['user_id'] = (int) $row['user_id'];
            $rows[] = $row;
        }
        json_response(200, $rows);
    }

    if ($method === 'PATCH' && preg_match('#^/admin/users/password-reset-requests/(\d+)$#', $path, $match) === 1) {
        $auth = require_permission($pdo, 'user_management.edit');
        $body = request_json();
        $action = (string) ($body['action'] ?? '');
        $stmt = $pdo->prepare("SELECT id, user_id, status FROM password_reset_requests WHERE id = ?");
        $stmt->execute([(int) $match[1]]);
        $request = $stmt->fetch();
        if (!$request || $request['status'] !== 'pending') {
            json_response(404, ['error' => 'Not found']);
        }
        if ($action !== 'approve' && $action !== 'reject') {
            json_response(400, ['error' => 'Wybierz zatwierdzenie albo odrzucenie.']);
        }
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $pdo->prepare('UPDATE password_reset_requests SET status = ?, resolved_at = ?, resolved_by_user_id = ?, note = ? WHERE id = ?')
            ->execute([$status, admin_now(), (int) $auth['user']['id'], trim((string) ($body['note'] ?? '')), (int) $request['id']]);
        $payload = ['ok' => true, 'status' => $status, 'email_sent' => false];
        if ($action === 'approve') {
            $payload = array_merge($payload, issue_password_reset($pdo, (int) $request['user_id'], (int) $auth['user']['id']));
        }
        json_response(200, $payload);
    }

    if ($method === 'GET' && $path === '/admin/roles/permissions-catalog') {
        require_permission($pdo, 'role_management.view');
        json_response(200, ['permissions' => ADMIN_PERMISSIONS]);
    }

    if ($method === 'GET' && $path === '/admin/roles') {
        require_permission($pdo, 'role_management.view');
        $rows = [];
        foreach ($pdo->query('SELECT id, name, description, is_system, login_required FROM roles ORDER BY name') as $row) {
            $rows[] = admin_role_payload($pdo, $row);
        }
        json_response(200, $rows);
    }

    if ($method === 'POST' && $path === '/admin/roles') {
        require_permission($pdo, 'role_management.edit');
        $body = request_json();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(400, ['error' => 'Podaj nazwę roli.']);
        }
        try {
            $pdo->prepare('INSERT INTO roles (name, description, is_system, login_required, created_at, updated_at) VALUES (?, ?, 0, 1, ?, ?)')
                ->execute([$name, trim((string) ($body['description'] ?? '')), admin_now(), admin_now()]);
        } catch (PDOException) {
            json_response(400, ['error' => 'Rola o takiej nazwie już istnieje.']);
        }
        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT id, name, description, is_system, login_required FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        json_response(201, admin_role_payload($pdo, $stmt->fetch()));
    }

    if (preg_match('#^/admin/roles/(\d+)$#', $path, $match) === 1) {
        $id = (int) $match[1];
        $stmt = $pdo->prepare('SELECT id, name, description, is_system, login_required FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        $role = $stmt->fetch();
        if ($method === 'PUT') {
            require_permission($pdo, 'role_management.edit');
            if (!$role) {
                json_response(404, ['error' => 'Not found']);
            }
            $body = request_json();
            $name = array_key_exists('name', $body) ? trim((string) $body['name']) : (string) $role['name'];
            if ((int) $role['is_system'] === 1) {
                $name = (string) $role['name'];
            }
            try {
                $pdo->prepare('UPDATE roles SET name = ?, description = ?, updated_at = ? WHERE id = ?')
                    ->execute([$name, trim((string) ($body['description'] ?? $role['description'] ?? '')), admin_now(), $id]);
            } catch (PDOException) {
                json_response(400, ['error' => 'Rola o takiej nazwie już istnieje.']);
            }
            $stmt->execute([$id]);
            json_response(200, admin_role_payload($pdo, $stmt->fetch()));
        }
        if ($method === 'DELETE') {
            require_permission($pdo, 'role_management.delete');
            if (!$role) {
                json_response(404, ['error' => 'Not found']);
            }
            if ((int) $role['is_system'] === 1) {
                json_response(400, ['error' => 'Nie można usunąć roli systemowej.']);
            }
            $used = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?');
            $used->execute([$id]);
            if ((int) $used->fetchColumn() > 0) {
                json_response(400, ['error' => 'Nie można usunąć roli przypisanej do użytkownika.']);
            }
            $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
            write_no_content();
        }
    }

    if ($method === 'PUT' && preg_match('#^/admin/roles/(\d+)/permissions$#', $path, $match) === 1) {
        require_permission($pdo, 'role_management.edit');
        $id = (int) $match[1];
        $exists = $pdo->prepare('SELECT 1 FROM roles WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            json_response(404, ['error' => 'Not found']);
        }
        $body = request_json();
        $keys = is_array($body['permissions'] ?? null) ? $body['permissions'] : [];
        $clean = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (!in_array($key, ADMIN_PERMISSIONS, true)) {
                json_response(400, ['error' => 'Nieznane uprawnienie: ' . $key]);
            }
            $clean[$key] = true;
        }
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$id]);
        $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key) VALUES (?, ?)');
        foreach (array_keys($clean) as $key) {
            $insert->execute([$id, $key]);
        }
        $stmt = $pdo->prepare('SELECT id, name, description, is_system, login_required FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        json_response(200, admin_role_payload($pdo, $stmt->fetch()));
    }

    if ($method === 'GET' && $path === '/admin/backup-settings') {
        require_permission($pdo, 'admin_settings.view');
        json_response(200, admin_backup_settings($pdo));
    }

    if ($method === 'PUT' && $path === '/admin/backup-settings') {
        require_permission($pdo, 'admin_settings.edit');
        $body = request_json();
        foreach ([
            'backup_enabled' => 'backup_enabled',
            'volumes_autosave_enabled' => 'volumes_autosave_enabled',
            'ocu_enabled' => 'ocu_enabled',
        ] as $field => $key) {
            if (array_key_exists($field, $body)) {
                admin_set($pdo, $key, admin_flag($body[$field]) ? '1' : '0');
            }
        }
        if (array_key_exists('backup_frequency_days', $body)) {
            admin_set($pdo, 'backup_frequency_days', (string) max(0, (int) $body['backup_frequency_days']));
        }
        foreach (['backup_output_dir', 'project_attachments_output_dir'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = trim((string) $body[$field]);
            if ($value !== '') {
                try {
                    $absolute = admin_resolve_storage($value);
                } catch (InvalidArgumentException $e) {
                    json_response(400, ['error' => $e->getMessage()]);
                }
                if (!is_dir($absolute) && !@mkdir($absolute, 0777, true) && !is_dir($absolute)) {
                    json_response(400, ['error' => 'Nie udało się utworzyć folderu: ' . $absolute]);
                }
            }
            admin_set($pdo, $field, $value);
        }
        json_response(200, admin_backup_settings($pdo));
    }

    if ($method === 'POST' && $path === '/admin/preview-storage-path') {
        require_permission($pdo, 'admin_settings.view');
        $body = request_json();
        $raw = trim((string) ($body['path'] ?? ''));
        try {
            $absolute = admin_resolve_storage($raw);
        } catch (InvalidArgumentException $e) {
            json_response(400, ['error' => $e->getMessage()]);
        }
        $exists = is_dir($absolute);
        $writable = $exists ? is_writable($absolute) : is_writable(dirname($absolute));
        json_response(200, [
            'absolute_path' => $absolute,
            'exists' => $exists,
            'writable' => $writable,
            'setting_value' => admin_setting_value($absolute),
        ]);
    }

    if ($method === 'POST' && $path === '/admin/storage/browse') {
        require_permission($pdo, 'admin_settings.view');
        $body = request_json();
        $raw = trim((string) ($body['path'] ?? ''));
        try {
            $absolute = $raw === '' ? admin_site_root() : admin_resolve_storage($raw);
        } catch (InvalidArgumentException $e) {
            json_response(400, ['error' => $e->getMessage()]);
        }
        if (!is_dir($absolute)) {
            json_response(400, ['error' => 'Folder nie istnieje.']);
        }
        $entries = [];
        foreach (scandir($absolute) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($absolute . '\\' . $name)) {
                continue;
            }
            $child = $absolute . '\\' . $name;
            $entries[] = ['name' => $name, 'path' => $child, 'setting_value' => admin_setting_value($child)];
        }
        usort($entries, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $parent = dirname($absolute);
        $hasParent = strcasecmp($parent, $absolute) !== 0;
        json_response(200, [
            'current_path' => $absolute,
            'setting_value' => admin_setting_value($absolute),
            'parent_path' => $hasParent ? $parent : null,
            'parent_setting_value' => $hasParent ? admin_setting_value($parent) : null,
            'entries' => $entries,
        ]);
    }

    if ($method === 'GET' && $path === '/admin/backup-files') {
        require_permission($pdo, 'admin_settings.view');
        json_response(200, []);
    }

    if ($method === 'GET' && $path === '/admin/attachments') {
        require_permission($pdo, 'admin_attachments.view');
        $storage = attachment_directory($pdo);
        $rows = [];
        foreach ($pdo->query(
            'SELECT a.*, p.client AS project_client, p.name AS project_name
             FROM project_attachments a
             INNER JOIN projects p ON p.id = a.project_id
             ORDER BY a.uploaded_at DESC, a.id DESC'
        ) as $row) {
            $row['id'] = (int) $row['id'];
            $row['project_id'] = (int) $row['project_id'];
            $row['size_bytes'] = (int) $row['size_bytes'];
            $row['is_shared'] = (int) $row['is_shared'];
            $folder = (int) $row['is_shared'] === 1 ? 'shared' : (string) $row['project_id'];
            $row['relative_dir'] = $folder;
            $row['absolute_path'] = $storage['absolute'] !== '' ? $storage['absolute'] . '\\' . $folder . '\\' . $row['stored_filename'] : '';
            $rows[] = $row;
        }
        json_response(200, [
            'storage_configured' => $storage['configured'],
            'storage_root' => $storage['absolute'],
            'attachments' => $rows,
        ]);
    }

    if ($method === 'POST' && ($path === '/admin/capacity-bundle-import' || $path === '/admin/capacity-bundle-import-zip')) {
        require_once __DIR__ . '/bundle_import.php';
        bundle_import_request($pdo);
    }

    if (in_array($path, ['/admin/backup-now', '/admin/restore-from-backup', '/admin/clear-database', '/admin/pick-backup-file'], true)) {
        require_permission($pdo, 'admin_database.edit');
        json_response(400, ['error' => 'Kopia zapasowa, przywracanie i czyszczenie bazy zostaną włączone po zmianach IIS.']);
    }

    return false;
}

function admin_backup_settings(PDO $pdo): array
{
    $map = setting_map($pdo);
    $attachments = attachment_directory($pdo);
    $backupRaw = trim((string) ($map['backup_output_dir'] ?? ''));
    $backupAbsolute = '';
    if ($backupRaw !== '') {
        try {
            $backupAbsolute = admin_resolve_storage($backupRaw);
        } catch (InvalidArgumentException) {
            $backupAbsolute = '';
        }
    }
    return [
        'backup_enabled' => setting_bool($map, 'backup_enabled', false),
        'backup_frequency_days' => (int) setting_num($map, 'backup_frequency_days', 0),
        'backup_output_dir' => $backupRaw,
        'absolute_output_dir' => $backupAbsolute,
        'project_attachments_output_dir' => $attachments['raw'],
        'absolute_attachments_output_dir' => $attachments['absolute'],
        'volumes_autosave_enabled' => setting_bool($map, 'volumes_autosave_enabled', true),
        'ocu_enabled' => setting_bool($map, 'ocu_enabled', false),
        'pick_location_available' => true,
        'storage_base_dir' => admin_site_root(),
        'last_backup_at' => (string) ($map['last_backup_at'] ?? ''),
        'last_backup_file' => (string) ($map['last_backup_file'] ?? ''),
    ];
}

function admin_handle_settings(string $method, string $path): bool
{
    $profile = str_starts_with($path, '/settings/ocu') ? 'ocu' : 'capacity';
    $table = $profile === 'ocu' ? 'working_days_ocu' : 'working_days';
    $local = $profile === 'ocu' ? substr($path, strlen('/settings/ocu')) : substr($path, strlen('/settings'));
    if ($local === '') {
        $local = '/';
    }
    $pdo = open_database();
    bootstrap_admin($pdo);

    if ($method === 'PUT' && $local === '/visual') {
        require_permission($pdo, 'admin_settings.edit');
        json_response(200, admin_save_visual($pdo, request_json()));
    }
    if ($method === 'PUT' && $local === '/behavior') {
        require_permission($pdo, 'admin_settings.edit');
        $body = request_json();
        if (array_key_exists('volumes_autosave_enabled', $body)) {
            admin_set($pdo, 'volumes_autosave_enabled', admin_flag($body['volumes_autosave_enabled']) ? '1' : '0');
        }
        if (array_key_exists('ocu_enabled', $body)) {
            admin_set($pdo, 'ocu_enabled', admin_flag($body['ocu_enabled']) ? '1' : '0');
        }
        $map = setting_map($pdo);
        json_response(200, [
            'volumes_autosave_enabled' => setting_bool($map, 'volumes_autosave_enabled', true),
            'ocu_enabled' => setting_bool($map, 'ocu_enabled', false),
        ]);
    }
    if ($local === '/defaults') {
        require_permission($pdo, $method === 'GET' ? 'admin_settings.view' : 'admin_settings.edit');
        if ($method === 'PUT') {
            $body = request_json();
            foreach (ADMIN_WD_FIELDS as $field) {
                if (array_key_exists($field, $body) && is_numeric($body[$field])) {
                    admin_set($pdo, $profile . '_default_' . $field, (string) $body[$field]);
                }
            }
        }
        if ($method === 'GET' || $method === 'PUT') {
            json_response(200, admin_wd_defaults($pdo, $profile));
        }
    }
    if ($method === 'POST' && $local === '/from-months') {
        require_permission($pdo, 'admin_settings.view');
        $months = request_json()['months'] ?? null;
        if (!is_array($months) || count($months) !== 12) {
            json_response(400, ['error' => 'Podaj 12 miesięcy.']);
        }
        json_response(200, ['working_days_year' => array_sum(array_map('intval', $months))]);
    }
    if ($method === 'GET' && $local === '/') {
        require_permission($pdo, 'admin_settings.view');
        $defaults = admin_wd_defaults($pdo, $profile);
        $rows = [];
        foreach ($pdo->query("SELECT * FROM $table ORDER BY year") as $row) {
            $rows[] = admin_wd_row($row, $defaults);
        }
        json_response(200, $rows);
    }
    if ($method === 'POST' && $local === '/') {
        require_permission($pdo, 'admin_settings.edit');
        json_response(201, admin_wd_save($pdo, $table, $profile, request_json(), null));
    }
    if (preg_match('#^/(\d+)$#', $local, $match) === 1 && in_array($method, ['GET', 'PUT', 'DELETE'], true)) {
        require_any_permission($pdo, $method === 'DELETE' ? ['admin_settings.delete', 'admin_settings.edit'] : [$method === 'GET' ? 'admin_settings.view' : 'admin_settings.edit']);
        $id = (int) $match[1];
        if ($method === 'DELETE') {
            $gone = $pdo->prepare("DELETE FROM $table WHERE id = ?");
            $gone->execute([$id]);
            if ($gone->rowCount() === 0) {
                json_response(404, ['error' => 'Not found']);
            }
            write_no_content();
        }
        if ($method === 'PUT') {
            json_response(200, admin_wd_save($pdo, $table, $profile, request_json(), $id));
        }
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(404, ['error' => 'Not found']);
        }
        json_response(200, admin_wd_row($row, admin_wd_defaults($pdo, $profile)));
    }

    if ($profile === 'ocu') {
        return false;
    }
    if ($method === 'GET' && $path === '/settings/machine-types') {
        require_permission($pdo, 'admin_database.view');
        json_response(200, admin_machine_types($pdo));
    }
    if ($method === 'POST' && $path === '/settings/machine-types/sync-from-machines') {
        require_permission($pdo, 'admin_database.edit');
        $pdo->exec(
            "INSERT OR IGNORE INTO machine_types (name)
             SELECT DISTINCT TRIM(type) FROM machines
             WHERE type IS NOT NULL AND TRIM(type) != ''"
        );
        json_response(200, admin_machine_types($pdo));
    }
    if ($method === 'POST' && $path === '/settings/machine-types') {
        require_permission($pdo, 'admin_database.edit');
        json_response(201, admin_machine_type_save($pdo, request_json(), null));
    }
    if (preg_match('#^/settings/machine-types/(\d+)$#', $path, $match) === 1) {
        $id = (int) $match[1];
        if ($method === 'PUT') {
            require_permission($pdo, 'admin_database.edit');
            json_response(200, admin_machine_type_save($pdo, request_json(), $id));
        }
        if ($method === 'DELETE') {
            require_any_permission($pdo, ['admin_database.delete', 'admin_database.edit']);
            $stmt = $pdo->prepare('SELECT name FROM machine_types WHERE id = ?');
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn();
            if ($name === false) {
                json_response(404, ['error' => 'Not found']);
            }
            $used = $pdo->prepare('SELECT COUNT(*) FROM machines WHERE type = ?');
            $used->execute([$name]);
            if ((int) $used->fetchColumn() > 0) {
                json_response(400, ['error' => 'Nie można usunąć: istnieją maszyny z tym typem. Najpierw zmień typ tych maszyn.']);
            }
            $pdo->prepare('DELETE FROM machine_types WHERE id = ?')->execute([$id]);
            write_no_content();
        }
    }
    if ($method === 'POST' && $path === '/settings/phases') {
        require_permission($pdo, 'admin_database.edit');
        json_response(201, admin_phase_save($pdo, request_json(), null));
    }
    if (preg_match('#^/settings/phases/(\d+)$#', $path, $match) === 1) {
        $id = (int) $match[1];
        if ($method === 'PUT') {
            require_permission($pdo, 'admin_database.edit');
            json_response(200, admin_phase_save($pdo, request_json(), $id));
        }
        if ($method === 'DELETE') {
            require_any_permission($pdo, ['admin_database.delete', 'admin_database.edit']);
            try {
                $gone = $pdo->prepare('DELETE FROM process_phases WHERE id = ?');
                $gone->execute([$id]);
            } catch (PDOException) {
                json_response(400, ['error' => 'Nie można usunąć fazy używanej przez operacje.']);
            }
            if ($gone->rowCount() === 0) {
                json_response(404, ['error' => 'Not found']);
            }
            write_no_content();
        }
    }
    if ($method === 'DELETE' && preg_match('#^/settings/designations/(\d+)$#', $path, $match) === 1) {
        require_permission($pdo, 'designations.delete');
        $id = (int) $match[1];
        $used = $pdo->prepare('SELECT COUNT(*) FROM parts WHERE designation_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            json_response(400, ['error' => 'Nie można usunąć oznaczenia używanego przez detale.']);
        }
        $gone = $pdo->prepare('DELETE FROM part_designations WHERE id = ?');
        $gone->execute([$id]);
        if ($gone->rowCount() === 0) {
            json_response(404, ['error' => 'Not found']);
        }
        write_no_content();
    }
    return false;
}

function admin_wd_save(PDO $pdo, string $table, string $profile, array $body, ?int $id): array
{
    $existing = null;
    if ($id !== null) {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(404, ['error' => 'Not found']);
        }
    }
    $year = $id === null ? (int) ($body['year'] ?? 0) : (int) $existing['year'];
    if ($year < 2000 || $year > 2100) {
        json_response(400, ['error' => 'Podaj rok.']);
    }
    if ($id === null) {
        $dup = $pdo->prepare("SELECT 1 FROM $table WHERE year = ?");
        $dup->execute([$year]);
        if ($dup->fetchColumn()) {
            json_response(400, ['error' => 'Ten rok już istnieje.']);
        }
    }
    $values = [];
    foreach (ADMIN_WD_FIELDS as $field) {
        $raw = array_key_exists($field, $body) ? $body[$field] : ($existing[$field] ?? null);
        $values[$field] = admin_optional_number($raw);
    }
    $months = is_array($body['months'] ?? null) ? $body['months'] : null;
    $status = (($body['status'] ?? ($existing['status'] ?? 'active')) === 'inactive') ? 'inactive' : 'active';
    $monthValues = [];
    foreach (ADMIN_MONTHS as $index => $column) {
        $monthValues[] = $months !== null ? (int) ($months[$index] ?? 0) : (int) ($existing[$column] ?? 0);
    }
    $params = [
        $values['working_days_year'], $values['oee_factor'], $values['shift_time_seconds'],
        $values['startup_shutdown_seconds'], $values['working_weeks_per_year'], $values['shifts_per_day'],
        $status, ...$monthValues,
    ];
    if ($id === null) {
        $pdo->prepare(
            "INSERT INTO $table (year, working_days_year, oee_factor, shift_time_seconds, startup_shutdown_seconds,
             working_weeks_per_year, shifts_per_day, status, " . implode(', ', ADMIN_MONTHS) . ")
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$year, ...$params]);
        $id = (int) $pdo->lastInsertId();
    } else {
        $pdo->prepare(
            "UPDATE $table SET working_days_year = ?, oee_factor = ?, shift_time_seconds = ?, startup_shutdown_seconds = ?,
             working_weeks_per_year = ?, shifts_per_day = ?, status = ?,
             working_days_jan = ?, working_days_feb = ?, working_days_mar = ?, working_days_apr = ?,
             working_days_may = ?, working_days_jun = ?, working_days_jul = ?, working_days_aug = ?,
             working_days_sep = ?, working_days_oct = ?, working_days_nov = ?, working_days_dec = ?
             WHERE id = ?"
        )->execute([...$params, $id]);
    }
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return admin_wd_row($stmt->fetch(), admin_wd_defaults($pdo, $profile));
}

function admin_machine_types(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT id, name, default_machine_usage FROM machine_types ORDER BY name') as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'default_machine_usage' => (float) $row['default_machine_usage'],
        ];
    }
    return $rows;
}

function admin_machine_type_save(PDO $pdo, array $body, ?int $id): array
{
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        json_response(400, ['error' => 'Podaj nazwę typu.']);
    }
    $usage = array_key_exists('default_machine_usage', $body) ? write_usage($body['default_machine_usage']) : 1.0;
    try {
        if ($id === null) {
            $pdo->prepare('INSERT INTO machine_types (name, default_machine_usage) VALUES (?, ?)')->execute([$name, $usage]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare('SELECT name, default_machine_usage FROM machine_types WHERE id = ?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                json_response(404, ['error' => 'Not found']);
            }
            if (!array_key_exists('default_machine_usage', $body)) {
                $usage = (float) $existing['default_machine_usage'];
            }
            $pdo->prepare('UPDATE machine_types SET name = ?, default_machine_usage = ? WHERE id = ?')->execute([$name, $usage, $id]);
            if ((string) $existing['name'] !== $name) {
                $pdo->prepare('UPDATE machines SET type = ? WHERE type = ?')->execute([$name, $existing['name']]);
            }
        }
    } catch (PDOException $e) {
        json_response(400, ['error' => str_contains($e->getMessage(), 'UNIQUE') ? 'Typ o takiej nazwie już istnieje' : $e->getMessage()]);
    }
    $stmt = $pdo->prepare('SELECT id, name, default_machine_usage FROM machine_types WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'default_machine_usage' => (float) $row['default_machine_usage']];
}

function admin_phase_save(PDO $pdo, array $body, ?int $id): array
{
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        json_response(400, ['error' => 'Podaj nazwę fazy.']);
    }
    try {
        if ($id === null) {
            $pdo->prepare('INSERT INTO process_phases (name) VALUES (?)')->execute([$name]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $updated = $pdo->prepare('UPDATE process_phases SET name = ? WHERE id = ?');
            $updated->execute([$name, $id]);
            if ($updated->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM process_phases WHERE id = ?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    json_response(404, ['error' => 'Not found']);
                }
            }
        }
    } catch (PDOException $e) {
        json_response(400, ['error' => str_contains($e->getMessage(), 'UNIQUE') ? 'Faza o takiej nazwie już istnieje' : $e->getMessage()]);
    }
    return ['id' => $id, 'name' => $name];
}

function admin_handle_projects(string $method, string $path): bool
{
    $pdo = open_database();
    bootstrap_admin($pdo);

    if ($method === 'GET' && $path === '/projects/history/filters') {
        require_any_permission($pdo, ['change_history.view', 'projects.view']);
        json_response(200, admin_history_filters($pdo));
    }
    if ($method === 'GET' && $path === '/projects/history') {
        require_any_permission($pdo, ['change_history.view', 'projects.view']);
        json_response(200, admin_history($pdo));
    }
    if ($method === 'POST' && preg_match('#^/projects/(\d+)/notes$#', $path, $match) === 1) {
        $auth = require_permission($pdo, 'projects.edit');
        $projectId = (int) $match[1];
        $body = request_json();
        $note = trim((string) ($body['note'] ?? ''));
        if ($note === '') {
            json_response(400, ['error' => 'Treść notatki nie może być pusta.']);
        }
        $exists = $pdo->prepare('SELECT 1 FROM projects WHERE id = ?');
        $exists->execute([$projectId]);
        if (!$exists->fetchColumn()) {
            json_response(404, ['error' => 'Not found']);
        }
        $date = trim((string) ($body['note_date'] ?? date('Y-m-d')));
        $pdo->prepare(
            'INSERT INTO project_notes (project_id, note_date, author, note, note_type) VALUES (?, ?, ?, ?, ?)'
        )->execute([$projectId, $date !== '' ? $date : date('Y-m-d'), user_login_label($auth['user']), $note, 'manual']);
        json_response(201, admin_note($pdo, (int) $pdo->lastInsertId()));
    }
    if (preg_match('#^/projects/(\d+)/notes/(\d+)$#', $path, $match) === 1 && in_array($method, ['PUT', 'DELETE'], true)) {
        $auth = require_permission($pdo, 'projects.edit');
        $stmt = $pdo->prepare('SELECT * FROM project_notes WHERE id = ? AND project_id = ?');
        $stmt->execute([(int) $match[2], (int) $match[1]]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(404, ['error' => 'Not found']);
        }
        if (($existing['note_type'] ?? 'manual') !== 'manual' || strcasecmp(trim((string) $existing['author']), user_login_label($auth['user'])) !== 0) {
            json_response(403, ['error' => 'Można zmieniać tylko własne notatki ręczne.']);
        }
        if ($method === 'DELETE') {
            $pdo->prepare('DELETE FROM project_notes WHERE id = ?')->execute([(int) $existing['id']]);
            write_no_content();
        }
        $note = trim((string) (request_json()['note'] ?? ''));
        if ($note === '') {
            json_response(400, ['error' => 'Treść notatki nie może być pusta.']);
        }
        $pdo->prepare('UPDATE project_notes SET note = ? WHERE id = ?')->execute([$note, (int) $existing['id']]);
        json_response(200, admin_note($pdo, (int) $existing['id']));
    }
    if ($method === 'POST' && preg_match('#^/projects/(\d+)/attachments$#', $path, $match) === 1) {
        $auth = require_permission($pdo, 'projects.edit');
        json_response(201, admin_upload($pdo, (int) $match[1], $auth));
    }
    if ($method === 'GET' && preg_match('#^/projects/(\d+)/attachments/(\d+)/download$#', $path, $match) === 1) {
        require_any_permission($pdo, ['projects.view', 'admin_attachments.download', 'projects.details']);
        admin_download($pdo, (int) $match[1], (int) $match[2]);
    }
    if ($method === 'DELETE' && preg_match('#^/projects/(\d+)/attachments/(\d+)$#', $path, $match) === 1) {
        $auth = require_permission($pdo, 'projects.edit');
        admin_delete_attachment($pdo, (int) $match[1], (int) $match[2], user_login_label($auth['user']));
        write_no_content();
    }
    return false;
}

function admin_note(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM project_notes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    foreach (['id', 'project_id', 'machine_id', 'part_id', 'operation_id'] as $field) {
        if (array_key_exists($field, $row) && $row[$field] !== null) {
            $row[$field] = (int) $row[$field];
        }
    }
    return $row;
}

function admin_history_filters(PDO $pdo): array
{
    $projects = [];
    foreach ($pdo->query('SELECT id, client, name FROM projects ORDER BY client, name') as $row) {
        $projects[] = ['id' => (int) $row['id'], 'client' => (string) $row['client'], 'name' => (string) $row['name']];
    }
    $clients = [];
    foreach ($pdo->query("SELECT DISTINCT client FROM projects WHERE TRIM(client) <> '' ORDER BY client") as $row) {
        $clients[] = (string) $row['client'];
    }
    $machines = [];
    foreach ($pdo->query('SELECT id, sap_number, internal_number, type FROM machines ORDER BY sap_number, internal_number') as $row) {
        $machines[] = [
            'id' => (int) $row['id'],
            'sap_number' => $row['sap_number'],
            'internal_number' => $row['internal_number'],
            'type' => $row['type'],
        ];
    }
    $details = [];
    foreach ($pdo->query(
        'SELECT pt.id, pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pt.designation AS detail_designation
         FROM parts pt LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         ORDER BY pt.id'
    ) as $row) {
        $details[] = ['id' => (int) $row['id'], 'label' => admin_detail_label($row)];
    }
    $authors = $pdo->query("SELECT DISTINCT author FROM project_notes WHERE TRIM(COALESCE(author, '')) <> '' ORDER BY author")->fetchAll(PDO::FETCH_COLUMN);
    return compact('projects', 'clients', 'machines', 'details', 'authors');
}

function admin_history(PDO $pdo): array
{
    $sql = 'SELECT n.id, n.project_id, n.note_date, n.author, n.note, COALESCE(n.note_type, \'manual\') AS note_type,
            COALESCE(n.machine_id, op.machine_id) AS machine_id,
            COALESCE(n.part_id, op.part_id) AS part_id,
            COALESCE(n.operation_id, op.id) AS operation_id,
            p.client, p.name AS project_name,
            m.sap_number AS machine_sap_number, m.internal_number AS machine_internal_number, m.type AS machine_type,
            pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text,
            pt.designation AS detail_designation
            FROM project_notes n
            INNER JOIN projects p ON p.id = n.project_id
            LEFT JOIN operations op ON op.id = n.operation_id
            LEFT JOIN machines m ON m.id = COALESCE(n.machine_id, op.machine_id)
            LEFT JOIN parts pt ON pt.id = COALESCE(n.part_id, op.part_id)
            LEFT JOIN part_designations pd ON pd.id = pt.designation_id
            WHERE 1 = 1';
    $params = [];
    foreach ([
        ['n.project_id', admin_id_list('projectIds', 'projectId')],
        ['COALESCE(n.machine_id, op.machine_id)', admin_id_list('machineIds', 'machineId')],
        ['COALESCE(n.part_id, op.part_id)', admin_id_list('partIds', 'partId')],
        ['p.client', admin_csv('clients', 'client')],
        ['n.author', admin_csv('authors', 'author')],
    ] as [$column, $values]) {
        if ($values !== []) {
            $in = admin_in($column, $values);
            $sql .= ' AND ' . $in['clause'];
            array_push($params, ...$in['params']);
        }
    }
    $text = trim((string) ($_GET['text'] ?? ''));
    if ($text !== '') {
        $sql .= ' AND (n.note LIKE ? OR COALESCE(n.author, \'\') LIKE ? OR p.name LIKE ? OR p.client LIKE ?)';
        $like = '%' . $text . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY n.id DESC LIMIT 2000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt as $row) {
        foreach (['id', 'project_id', 'machine_id', 'part_id', 'operation_id'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['machine_label'] = admin_machine_label($row);
        $row['detail_label'] = admin_detail_label($row);
        $rows[] = $row;
    }
    return $rows;
}

function admin_upload(PDO $pdo, int $projectId, array $auth): array
{
    $exists = $pdo->prepare('SELECT 1 FROM projects WHERE id = ?');
    $exists->execute([$projectId]);
    if (!$exists->fetchColumn()) {
        json_response(404, ['error' => 'Not found']);
    }
    $storage = attachment_directory($pdo);
    if (!$storage['configured']) {
        json_response(400, ['error' => 'Najpierw ustaw folder załączników w ustawieniach administracyjnych.']);
    }
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $message = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            ? 'Plik przekracza limit przesyłania PHP. Limit zostanie podniesiony po zmianach IIS.'
            : 'Brak pliku do zapisania.';
        json_response(400, ['error' => $message]);
    }
    $original = (string) ($file['name'] ?? 'file');
    $safe = preg_replace('/[^\w.\- ()+ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', '_', basename($original)) ?: 'file';
    $safe = substr($safe, 0, 180);
    $shared = admin_flag($_POST['shared'] ?? $_POST['is_shared'] ?? false);
    $folder = $shared ? 'shared' : (string) $projectId;
    $dir = $storage['absolute'] . '\\' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        json_response(500, ['error' => 'Nie udało się utworzyć folderu załączników.']);
    }
    $stored = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
    $target = $dir . '\\' . $stored;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        json_response(500, ['error' => 'Nie udało się zapisać pliku.']);
    }
    $description = trim((string) ($_POST['description'] ?? ''));
    $author = user_login_label($auth['user']);
    $pdo->prepare(
        'INSERT INTO project_attachments (project_id, description, original_filename, stored_filename, mime_type, size_bytes, uploaded_at, uploaded_by, is_shared)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $projectId,
        $description,
        $original,
        $stored,
        (string) ($file['type'] ?? ''),
        (int) ($file['size'] ?? 0),
        admin_now(),
        $author,
        $shared ? 1 : 0,
    ]);
    $id = (int) $pdo->lastInsertId();
    $note = 'Dodano załącznik "' . $original . '"' . ($description !== '' ? ' (' . $description . ')' : '') . '.';
    if ($shared) {
        foreach ($pdo->query('SELECT id FROM projects') as $project) {
            write_note($pdo, (int) $project['id'], $note, $author);
        }
    } else {
        write_note($pdo, $projectId, $note, $author);
    }
    $stmt = $pdo->prepare('SELECT * FROM project_attachments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $row['id'] = (int) $row['id'];
    $row['project_id'] = (int) $row['project_id'];
    $row['size_bytes'] = (int) $row['size_bytes'];
    $row['is_shared'] = (int) $row['is_shared'];
    return $row;
}

function admin_attachment_file(PDO $pdo, int $projectId, int $attachmentId): array
{
    $stmt = $pdo->prepare('SELECT * FROM project_attachments WHERE id = ? AND (project_id = ? OR is_shared = 1)');
    $stmt->execute([$attachmentId, $projectId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(404, ['error' => 'Not found']);
    }
    $storage = attachment_directory($pdo);
    if (!$storage['configured']) {
        json_response(400, ['error' => 'Folder załączników nie jest ustawiony.']);
    }
    $folder = (int) $row['is_shared'] === 1 ? 'shared' : (string) $row['project_id'];
    $path = $storage['absolute'] . '\\' . $folder . '\\' . basename((string) $row['stored_filename']);
    if (!is_file($path)) {
        json_response(404, ['error' => 'Plik nie został znaleziony na dysku.']);
    }
    return ['row' => $row, 'path' => $path];
}

function admin_download(PDO $pdo, int $projectId, int $attachmentId): void
{
    $file = admin_attachment_file($pdo, $projectId, $attachmentId);
    $name = str_replace(['"', "\r", "\n"], '', (string) $file['row']['original_filename']);
    $mime = trim((string) ($file['row']['mime_type'] ?? '')) ?: 'application/octet-stream';
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string) filesize($file['path']));
    readfile($file['path']);
    exit;
}

function admin_delete_attachment(PDO $pdo, int $projectId, int $attachmentId, string $author): void
{
    $file = admin_attachment_file($pdo, $projectId, $attachmentId);
    $pdo->prepare('DELETE FROM project_attachments WHERE id = ?')->execute([$attachmentId]);
    if (is_file($file['path'])) {
        unlink($file['path']);
    }
    write_note($pdo, $projectId, 'Usunięto załącznik "' . $file['row']['original_filename'] . '".', $author);
}
