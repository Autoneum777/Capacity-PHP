<?php
declare(strict_types=1);

function database_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'capacity.db';
}

function migrations_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'migrations';
}

function open_database(): PDO
{
    $path = database_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('data directory is not writable');
    }

    apply_migrations();

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function apply_migrations(): int
{
    $dir = dirname(database_path());
    $lock = fopen($dir . DIRECTORY_SEPARATOR . 'migrate.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('migration lock failed');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('migration lock failed');
        }

        $db = new SQLite3(database_path());
        $db->busyTimeout(5000);
        $db->enableExceptions(true);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec("
            CREATE TABLE IF NOT EXISTS _migrations (
                name TEXT PRIMARY KEY,
                applied_at TEXT DEFAULT (datetime('now'))
            )
        ");

        $files = glob(migrations_dir() . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        usort($files, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));

        foreach ($files as $file) {
            $name = basename($file, '.sql');
            $exists = $db->querySingle('SELECT 1 FROM _migrations WHERE name = \'' . SQLite3::escapeString($name) . '\'');
            if ($exists) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException($name . ': unreadable');
            }

            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'duplicate column name')) {
                    throw new RuntimeException($name . ': ' . $e->getMessage(), 0, $e);
                }
            }

            $insert = $db->prepare('INSERT INTO _migrations (name) VALUES (:name)');
            $insert->bindValue(':name', $name, SQLITE3_TEXT);
            $insert->execute();
            $insert->close();
        }

        $count = (int) $db->querySingle('SELECT COUNT(*) FROM _migrations');
        $db->close();

        return $count;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
