<?php

declare(strict_types=1);

/**
 * Datenbankzugriff – liest die Zugangsdaten aus .env und baut die PDO-Verbindung auf.
 * Lokal (XAMPP) und auf Alwaysdata identisch, nur die .env unterscheidet sich.
 */

const ENV_FILE = __DIR__ . '/../.env';

/**
 * Liefert die Einträge aus .env als Array.
 *
 * @return array<string, string>
 */
function loadEnv(): array
{
    if (!is_file(ENV_FILE)) {
        throw new RuntimeException('Keine .env gefunden – bitte .env.example nach .env kopieren.');
    }

    $env = [];

    // Format: KEY=VALUE je Zeile, # leitet einen Kommentar ein, Werte optional in Anführungszeichen
    foreach (file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $env[$key] = preg_replace('/^(["\'])(.*)\1$/', '$2', $value);
    }

    return $env;
}

/**
 * Baut eine PDO-Verbindung zur MariaDB auf.
 *
 * @param bool $withDatabase false verbindet nur mit dem Server (für sql/reset.php)
 */
function getDatabaseConnection(bool $withDatabase = true): PDO
{
    $env = loadEnv();

    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=utf8mb4',
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_PORT'] ?? '3306'
    );

    if ($withDatabase) {
        $dsn .= ';dbname=' . ($env['DB_NAME'] ?? 'lernzeit_manager');
    }

    return new PDO($dsn, $env['DB_USER'] ?? 'root', $env['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
