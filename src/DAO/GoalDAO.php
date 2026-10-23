<?php
// src/DAO/GoalDAO.php

declare(strict_types=1);

namespace DAO;

use PDO;
use PDOException;

/**
 * Data Access Object (DAO) für Lernziele (learning_goals).
 * Kapselt alle SQL-Abfragen und Prepared Statements für die 6-Monats-Lernzielplanung.
 * Gewährleistet strikte Mandantentrennung über die user_id.
 */
class GoalDAO
{
    private PDO $db;

    /**
     * Dependency Injection der PDO-Datenbankverbindung.
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Legt ein neues Lernziel in der Datenbank an.
     *
     * @param int $userId ID des Besitzers
     * @param string $title Modulname bzw. Zielbezeichnung
     * @param string|null $description Optionale Notizen oder Beschreibung
     * @param float $targetHours Zielstunden (z. B. 150.0)
     * @param string $startDate Startdatum im Format YYYY-MM-DD
     * @param string $endDate Enddatum / Deadline im Format YYYY-MM-DD
     * @return int ID des neu angelegten Datensatzes
     * @throws PDOException Bei Datenbankfehlern
     */
    public function create(
        int $userId,
        string $title,
        ?string $description,
        float $targetHours,
        string $startDate,
        string $endDate
    ): int {
        $sql = "INSERT INTO learning_goals (user_id, title, description, target_hours, start_date, end_date, created_at)
                VALUES (:user_id, :title, :description, :target_hours, :start_date, :end_date, NOW())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'      => $userId,
            ':title'        => $title,
            ':description'  => $description,
            ':target_hours' => $targetHours,
            ':start_date'   => $startDate,
            ':end_date'     => $endDate,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Liefert alle Lernziele eines Benutzers, sortiert nach Startdatum aufsteigend.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>> Liste der Lernziel-Datensätze
     */
    public function findAllByUserId(int $userId): array
    {
        $sql = "SELECT id, user_id, title, description, target_hours, start_date, end_date, created_at
                FROM learning_goals
                WHERE user_id = :user_id
                ORDER BY start_date ASC, created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt ein einzelnes Lernziel anhand seiner ID und der Benutzer-ID (Mandantenisolation).
     * Verhindert unberechtigte Zugriffe (IDOR).
     *
     * @param int $goalId ID des Lernziels
     * @param int $userId ID des berechtigten Nutzers
     * @return array<string, mixed>|null Datensatz oder null, falls nicht gefunden / fremd
     */
    public function findByIdAndUserId(int $goalId, int $userId): ?array
    {
        $sql = "SELECT id, user_id, title, description, target_hours, start_date, end_date, created_at
                FROM learning_goals
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $goalId,
            ':user_id' => $userId,
        ]);

        $goal = $stmt->fetch(PDO::FETCH_ASSOC);

        return $goal ?: null;
    }

    /**
     * Aktualisiert ein bestehendes Lernziel unter Berücksichtigung der Benutzer-ID.
     *
     * @param int $goalId ID des Lernziels
     * @param int $userId ID des Nutzers
     * @param string $title Modulname
     * @param string|null $description Beschreibung
     * @param float $targetHours Zielstunden
     * @param string $startDate Startdatum
     * @param string $endDate Enddatum
     * @return bool True bei erfolgreicher Aktualisierung
     */
    public function update(
        int $goalId,
        int $userId,
        string $title,
        ?string $description,
        float $targetHours,
        string $startDate,
        string $endDate
    ): bool {
        $sql = "UPDATE learning_goals
                SET title = :title,
                    description = :description,
                    target_hours = :target_hours,
                    start_date = :start_date,
                    end_date = :end_date
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':title'        => $title,
            ':description'  => $description,
            ':target_hours' => $targetHours,
            ':start_date'   => $startDate,
            ':end_date'     => $endDate,
            ':id'           => $goalId,
            ':user_id'      => $userId,
        ]);

        // rowCount() kann 0 sein, wenn unveränderte Werte übergeben wurden
        return $stmt->rowCount() >= 0;
    }

    /**
     * Löscht ein Lernziel unter Berücksichtigung der Benutzer-ID.
     *
     * @param int $goalId ID des Lernziels
     * @param int $userId ID des berechtigten Nutzers
     * @return bool True, wenn ein Datensatz gelöscht wurde
     */
    public function delete(int $goalId, int $userId): bool
    {
        $sql = "DELETE FROM learning_goals
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $goalId,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Berechnet die Gesamtzahl der geplanten Lernstunden eines Nutzers.
     */
    public function getTotalTargetHours(int $userId): float
    {
        $sql = "SELECT COALESCE(SUM(target_hours), 0) FROM learning_goals WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return (float) $stmt->fetchColumn();
    }
}

