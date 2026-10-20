<?php
// src/Classes/User.php

namespace Classes;

use PDO;
use Exception;

/**
 * Klasse User
 * Verantwortlich für die Benutzerverwaltung (Registrierung, Authentifizierung und Session-Verwaltung).
 */
class User
{
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
     * @return bool True bei Erfolg, sonst Exception
     * @throws Exception Wenn E-Mail ungültig, bereits vergeben oder Passwort zu kurz ist.
     */
    public function register(string $email, string $password): bool
    {
        // 1. Eingabevalidierung: E-Mail-Format prüfen
        $email = trim(filter_var($email, FILTER_SANITIZE_EMAIL));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Bitte eine gültige E-Mail-Adresse eingeben.");
        }

        // 2. Eingabevalidierung: Mindestlänge für Passwörter durchsetzen
        if (strlen($password) < 8) {
            throw new Exception("Das Passwort muss mindestens 8 Zeichen lang sein.");
        }

        // 3. Prüfen, ob die E-Mail bereits existiert
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            throw new Exception("Diese E-Mail-Adresse ist bereits registriert.");
        }

        // 4. Passwort sicher hashen
        // Ein automatischer Salt schützt gegen Rainbow-Table-Angriffe.
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // 5. In die MariaDB-Datenbank einfügen
        $sql = "INSERT INTO users (email, password_hash, created_at) VALUES (:email, :password_hash, NOW())";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':email' => $email,
            ':password_hash' => $passwordHash
        ]);
    }

    /**
     * Authentifiziert einen Nutzer und startet eine sichere Session.
     *
     * @param string $email
     * @param string $password
     * @return bool True bei erfolgreichem Login
     * @throws Exception Wenn Anmeldedaten ungültig sind.
     */
    public function login(string $email, string $password): bool
    {
        $email = trim($email);

        // 1. Benutzer anhand der E-Mail abfragen
        $stmt = $this->db->prepare("SELECT id, email, password_hash FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Existenz und Passwort-Hash prüfen
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new Exception("E-Mail-Adresse oder Passwort ist falsch.");
        }

        // 3. Session-Fixation-Angriffe verhindern
        // Generiert bei jedem erfolgreichen Login eine neue Session-ID.
        session_regenerate_id(true);

        // 4. Benutzerdaten in der Session speichern
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];

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
