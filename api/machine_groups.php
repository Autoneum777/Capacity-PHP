<?php
declare(strict_types=1);

function machine_group_parse_machine_ids(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $item) {
        $id = (int) $item;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    return array_keys($ids);
}

function machine_group_attach_machines(PDO $pdo, array &$groups): void
{
    if ($groups === []) {
        return;
    }
    $byId = [];
    foreach ($groups as $i => $group) {
        $groups[$i]['machines'] = [];
        $byId[(int) $group['id']] = $i;
    }
    $stmt = $pdo->query(
        'SELECT mgm.group_id, m.id, m.internal_number, m.sap_number, m.type
         FROM machine_group_members mgm
         JOIN machines m ON m.id = mgm.machine_id
         ORDER BY m.internal_number COLLATE NOCASE'
    );
    foreach ($stmt as $row) {
        $gid = (int) $row['group_id'];
        if (!isset($byId[$gid])) {
            continue;
        }
        $groups[$byId[$gid]]['machines'][] = [
            'id' => (int) $row['id'],
            'internal_number' => $row['internal_number'],
            'sap_number' => $row['sap_number'],
            'type' => $row['type'],
        ];
    }
}

function machine_group_rows(PDO $pdo): array
{
    $groups = [];
    foreach ($pdo->query('SELECT id, name, created_at FROM machine_groups ORDER BY name COLLATE NOCASE') as $row) {
        $groups[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'created_at' => (string) $row['created_at'],
            'machines' => [],
        ];
    }
    machine_group_attach_machines($pdo, $groups);
    return $groups;
}

function machine_group_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, created_at FROM machine_groups WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $group = [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'created_at' => (string) $row['created_at'],
        'machines' => [],
    ];
    $groups = [$group];
    machine_group_attach_machines($pdo, $groups);
    return $groups[0];
}

function machine_group_filter_existing_machine_ids(PDO $pdo, array $machineIds): array
{
    if ($machineIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($machineIds), '?'));
    $stmt = $pdo->prepare('SELECT id FROM machines WHERE id IN (' . $placeholders . ')');
    $stmt->execute($machineIds);
    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $found[(int) $id] = true;
    }
    $out = [];
    foreach ($machineIds as $id) {
        if (isset($found[$id])) {
            $out[] = $id;
        }
    }
    return $out;
}

function machine_group_save(PDO $pdo, ?int $id): void
{
    require_permission($pdo, 'machines.edit');
    $creating = $id === null;
    $body = request_json();
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        json_response(400, ['error' => 'Nazwa grupy jest wymagana']);
    }
    if (function_exists('mb_strlen') ? mb_strlen($name) > 200 : strlen($name) > 200) {
        json_response(400, ['error' => 'Nazwa grupy jest za długa (maks. 200 znaków)']);
    }
    $machineIds = machine_group_filter_existing_machine_ids($pdo, machine_group_parse_machine_ids($body['machine_ids'] ?? []));

    $pdo->beginTransaction();
    try {
        if ($creating) {
            $pdo->prepare('INSERT INTO machine_groups (name, created_at) VALUES (?, ?)')->execute([$name, date('Y-m-d H:i:s')]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $check = $pdo->prepare('SELECT id FROM machine_groups WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) {
                $pdo->rollBack();
                json_response(404, ['error' => 'Nie znaleziono grupy']);
            }
            $pdo->prepare('UPDATE machine_groups SET name = ? WHERE id = ?')->execute([$name, $id]);
            $pdo->prepare('DELETE FROM machine_group_members WHERE group_id = ?')->execute([$id]);
        }
        if ($machineIds !== []) {
            $ins = $pdo->prepare('INSERT INTO machine_group_members (group_id, machine_id) VALUES (?, ?)');
            foreach ($machineIds as $machineId) {
                $ins->execute([$id, $machineId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Nie udało się zapisać grupy maszyn']);
    }

    $row = machine_group_row($pdo, (int) $id);
    json_response($creating ? 201 : 200, $row);
}

function machine_group_delete(PDO $pdo, int $id): void
{
    require_permission($pdo, 'machines.edit');
    $stmt = $pdo->prepare('SELECT id FROM machine_groups WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        json_response(404, ['error' => 'Nie znaleziono grupy']);
    }
    $pdo->prepare('DELETE FROM machine_groups WHERE id = ?')->execute([$id]);
    write_no_content();
}

function handle_machine_groups(string $method, string $path): bool
{
    if ($path === '/machine-groups') {
        if ($method === 'GET') {
            $pdo = open_database();
            bootstrap_admin($pdo);
            require_permission($pdo, 'machines.view');
            json_response(200, machine_group_rows($pdo));
            return true;
        }
        if ($method === 'POST') {
            $pdo = open_database();
            bootstrap_admin($pdo);
            machine_group_save($pdo, null);
            return true;
        }
        return false;
    }

    if (preg_match('#^/machine-groups/(\d+)$#', $path, $match) !== 1) {
        return false;
    }
    $id = (int) $match[1];
    if ($method === 'GET') {
        $pdo = open_database();
        bootstrap_admin($pdo);
        require_permission($pdo, 'machines.view');
        $row = machine_group_row($pdo, $id);
        if ($row === null) {
            json_response(404, ['error' => 'Nie znaleziono grupy']);
        }
        json_response(200, $row);
        return true;
    }
    if ($method === 'PUT') {
        $pdo = open_database();
        bootstrap_admin($pdo);
        machine_group_save($pdo, $id);
        return true;
    }
    if ($method === 'DELETE') {
        $pdo = open_database();
        bootstrap_admin($pdo);
        machine_group_delete($pdo, $id);
        return true;
    }

    return false;
}
