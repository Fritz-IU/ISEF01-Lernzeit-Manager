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
     * Maximale Länge des Studiengangs in Zeichen (Spalte users.study_program VARCHAR(150)).
     */
    public const MAX_STUDY_PROGRAM_LENGTH = 150;

    /**
     * Minimales und maximales Fachsemester für die Validierung.
     */
    public const MIN_SEMESTER = 1;
    public const MAX_SEMESTER = 30;

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
        // Nur trimmen wie in login(), ungültige Adressen ablehnen statt sie still umzuschreiben.
        $email = trim($email);
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
        $stmt = $this->db->prepare("SELECT id, email, password_hash, display_name, study_program, semester FROM users WHERE email = :email");
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
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_display_name'] = $user['display_name'];
        $_SESSION['user_study_program'] = $user['study_program'];
        $_SESSION['user_semester'] = $user['semester'] !== null ? (int) $user['semester'] : null;

        return true;
    }

    /**
     * Lädt die Profildaten eines Benutzers anhand seiner ID.
     *
     * @param int $userId Die Benutzer-ID
     * @return array<string, mixed>|null Assoziatives Array mit den Benutzerdaten oder null
     */
    public function findById(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, email, display_name, study_program, semester, created_at
             FROM users
             WHERE id = :id"
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Aktualisiert die Profildaten (Anzeigename, Studiengang, Semester) eines Benutzers.
     *
     * @param int $userId ID des Nutzers
     * @param string $displayName Optionaler Anzeigename
     * @param string|null $studyProgram Optionaler Studiengang
     * @param int|null $semester Optionales Fachsemester (1..30)
     * @return bool True bei Erfolg
     * @throws ValidationException Wenn Validierungsregeln verletzt werden
     */
    public function updateProfile(
        int $userId,
        string $displayName,
        ?string $studyProgram = null,
        ?int $semester = null
    ): bool {
        // 1. Validierung: Anzeigename
        $displayName = trim($displayName);
        if (mb_strlen($displayName, 'UTF-8') > self::MAX_DISPLAY_NAME_LENGTH) {
            throw new ValidationException("Der Anzeigename darf höchstens " . self::MAX_DISPLAY_NAME_LENGTH . " Zeichen lang sein.");
        }

        // 2. Validierung: Studiengang
        if ($studyProgram !== null) {
            $studyProgram = trim($studyProgram);
            if ($studyProgram === '') {
                $studyProgram = null;
            } elseif (mb_strlen($studyProgram, 'UTF-8') > self::MAX_STUDY_PROGRAM_LENGTH) {
                throw new ValidationException("Der Studiengang darf höchstens " . self::MAX_STUDY_PROGRAM_LENGTH . " Zeichen lang sein.");
            }
        }

        // 3. Validierung: Semester
        if ($semester !== null) {
            if ($semester < self::MIN_SEMESTER || $semester > self::MAX_SEMESTER) {
                throw new ValidationException("Das Semester muss zwischen " . self::MIN_SEMESTER . " und " . self::MAX_SEMESTER . " liegen.");
            }
        }

        // 4. Datenbank-Update mit Prepared Statement
        $sql = "UPDATE users
                SET display_name = :display_name,
                    study_program = :study_program,
                    semester = :semester
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([
            ':display_name'  => $displayName,
            ':study_program' => $studyProgram,
            ':semester'      => $semester,
            ':id'            => $userId,
        ]);

        if ($success) {
            // Aktive Session aktualisieren, falls der angemeldete Nutzer sein eigenes Profil ändert
            if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === $userId) {
                $_SESSION['user_display_name'] = $displayName;
                $_SESSION['user_study_program'] = $studyProgram;
                $_SESSION['user_semester'] = $semester;
            }
        }

        return $success;
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
