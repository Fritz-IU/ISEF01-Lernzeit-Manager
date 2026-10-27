<?php
// tests/bootstrap.php

declare(strict_types=1);

/**
 * Wird von PHPUnit vor allen Tests geladen (siehe phpunit.xml).
 * Lädt Klassen aus src/ automatisch nach, damit Tests und Mocks sie ohne require_once nutzen können.
 * Keine Datenbank, keine .env, keine Session – Unit-Tests laufen vollständig ohne Umgebung.
 */

// Feste Zeitzone, damit Datums- und Monatsberechnungen auf jedem Rechner gleich ausfallen
date_default_timezone_set('Europe/Berlin');

// Namespace => Ordner unter src/ (analog zur Ordnerstruktur im README)
const SRC_NAMESPACES = [
    'Classes\\'     => __DIR__ . '/../src/Classes/',
    'Controllers\\' => __DIR__ . '/../src/Controllers/',
    'DAO\\'         => __DIR__ . '/../src/DAO/',
];

spl_autoload_register(static function (string $class): void {
    foreach (SRC_NAMESPACES as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $directory . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});
