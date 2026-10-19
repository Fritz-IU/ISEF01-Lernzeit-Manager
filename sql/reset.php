<?php

declare(strict_types=1);

/**
 * Setzt die lokale Datenbank zurück: löschen, neu anlegen, schema.sql und seed.sql einspielen.
 *
 * Aufruf mit dem PHP von XAMPP aus dem Projektordner:
 *   macOS:   /Applications/XAMPP/xamppfiles/bin/php sql/reset.php
 *   Windows: C:\xampp\php\php.exe sql\reset.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile ausführbar.');
}

require_once __DIR__ . '/../config/database.php';

const SQL_FILES = [
    __DIR__ . '/schema.sql',
    __DIR__ . '/seed.sql',
];

/**
 * Zerlegt ein SQL-Skript in einzelne Anweisungen.
 * Erwartet je Anweisung ein Semikolon am Zeilenende; Zeilen mit -- sind Kommentare.
 *
 * @return list<string>
 */
function splitStatements(string $sql): array
{
    $lines = array_filter(
        preg_split('/\R/', $sql),
        static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
    );

    $statements = preg_split('/;\s*$/m', implode("\n", $lines));

    return array_values(array_filter(array_map('trim', $statements), static fn (string $s): bool => $s !== ''));
}

try {
    $env = loadEnv();

    if (($env['APP_ENV'] ?? 'local') === 'production') {
        throw new RuntimeException('APP_ENV=production – Reset auf dem Produktivsystem ist gesperrt.');
    }

    $dbName = $env['DB_NAME'] ?? 'lernzeit_manager';

    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        throw new RuntimeException("Ungültiger DB_NAME: $dbName");
    }

    $pdo = getDatabaseConnection(false);

    echo "Setze Datenbank `$dbName` zurück …\n";
    $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
    $pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    foreach (SQL_FILES as $file) {
        $statements = splitStatements((string) file_get_contents($file));

        foreach ($statements as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    sprintf("%s, Anweisung %d:\n%s\n\n%s", basename($file), $index + 1, $statement, $e->getMessage()),
                    0,
                    $e
                );
            }
        }

        printf("  %-12s %d Anweisungen\n", basename($file), count($statements));
    }

    echo "\nZeilen pro Tabelle:\n";

    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        printf("  %-16s %d\n", $table, $count);
    }

    echo "\nFertig.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nFehler beim Reset:\n" . $e->getMessage() . "\n");
    exit(1);
}
