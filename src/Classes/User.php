<?php
// src/Classes/User.php

declare(strict_types=1);

namespace Classes;

use PDO;
use PDOException;

require_once __DIR__ . '/ValidationException.php';

/**
 * Klasse User
 * Verantwortlich für die Benutzerverwaltung (Registrierung, Authentifizierung und Session-Verwaltung).
 */
class User
{
    /**
     * Mindestlänge für Passwörter in Zeichen (NIST SP 800-63B-4, Single-Factor).
     */
    public const MIN_PASSWORD_LENGTH = 15;

    /**
     * Maximale Länge des Anzeigenamens in Zeichen (Spalte users.display_name VARCHAR(100)).
     */
    public const MAX_DISPLAY_NAME_LENGTH = 100;

    /**
     * MariaDB-Fehlercode für einen verletzten Unique-Key (Duplicate entry).
     */
    private const ERROR_DUPLICATE_ENTRY = 1062;

    private PDO $db;

    /**
     * Konstruktor injiziert die bestehende Datenbankverbindung (Dependency Injection).
     * @param PDO $db Die aktive PDO-Instanz.
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Registriert einen neuen Studierenden in der Datenbank.
     *
     * @param string $email E-Mail-Adresse des Nutzers
     * @param string $password Klartext-Passwort
     * @param string $displayName Optionaler Anzeigename ('' = nicht gesetzt)
     * @return bool True bei Erfolg, sonst Exception
     * @throws ValidationException Wenn E-Mail ungültig oder bereits vergeben, Passwort zu kurz oder Anzeigename zu lang ist.
     */
    public function register(string $email, #[\SensitiveParameter] string $password, string $displayName = ''): bool
    {
        // 1. Eingabevalidierung: E-Mail-Format prüfen
        $email = trim(filter_var($email, FILTER_SANITIZE_EMAIL));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException("Bitte eine gültige E-Mail-Adresse eingeben.");
        }

        // 2. Eingabevalidierung: Mindestlänge für Passwörter durchsetzen
        // mb_strlen zählt Zeichen statt Bytes (Umlaute belegen in UTF-8 mehrere Bytes).
        if (mb_strlen($password, 'UTF-8') < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationException("Das Passwort muss mindestens " . self::MIN_PASSWORD_LENGTH . " Zeichen lang sein.");
        }

        // 3. Eingabevalidierung: Anzeigename ist optional, darf aber nicht zu lang sein
        $displayName = trim($displayName);
        if (mb_strlen($displayName, 'UTF-8') > self::MAX_DISPLAY_NAME_LENGTH) {
            throw new ValidationException("Der Anzeigename darf höchstens " . self::MAX_DISPLAY_NAME_LENGTH . " Zeichen lang sein.");
        }

        // 4. Passwort sicher hashen
        // Ein automatischer Salt schützt gegen Rainbow-Table-Angriffe.
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // 5. In die MariaDB-Datenbank einfügen
        $sql = "INSERT INTO users (email, password_hash, display_name, created_at)
                VALUES (:email, :password_hash, :display_name, NOW())";
        $stmt = $this->db->prepare($sql);

        // Doppelte E-Mail-Adressen erkennt der Unique-Key uq_users_email – auch bei gleichzeitigen Anfragen.
        try {
            return $stmt->execute([
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':display_name' => $displayName
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === self::ERROR_DUPLICATE_ENTRY) {
                throw new ValidationException("Diese E-Mail-Adresse ist bereits registriert.");
            }
            throw $e;
        }
    }

    /**
     * Authentifiziert einen Nutzer und startet eine sichere Session.
     *
     * @param string $email
     * @param string $password
     * @return bool True bei erfolgreichem Login
     * @throws ValidationException Wenn Anmeldedaten ungültig sind.
     */
    public function login(string $email, #[\SensitiveParameter] string $password): bool
    {
        $email = trim($email);

        // 1. Benutzer anhand der E-Mail abfragen
        $stmt = $this->db->prepare("SELECT id, email, password_hash, display_name FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Existenz und Passwort-Hash prüfen
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new ValidationException("E-Mail-Adresse oder Passwort ist falsch.");
        }

        // 3. Session-Fixation-Angriffe verhindern
        // Generiert bei jedem erfolgreichen Login eine neue Session-ID.
        session_regenerate_id(true);

        // 4. Benutzerdaten in der Session speichern
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_display_name'] = $user['display_name'];

        return true;
    }

    /**
     * Prüft, ob aktuell ein Nutzer eingeloggt ist.
     */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * Loggt den aktuellen Nutzer aus und zerstört die Session.
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
    }
}
