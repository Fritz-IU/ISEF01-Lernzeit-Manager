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
     * Liefert alle Lernziele eines Benutzers inklusive bereits in Monatsziele aufgeteilter Stunden,
     * sortiert nach Startdatum aufsteigend.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>> Liste der Lernziel-Datensätze
     */
    public function findAllByUserId(int $userId): array
    {
        $sql = "SELECT lg.id,
                       lg.user_id,
                       lg.title,
                       lg.description,
                       lg.target_hours,
                       lg.start_date,
                       lg.end_date,
                       lg.created_at,
                       COALESCE(SUM(mg.planned_hours), 0) AS allocated_hours,
                       COUNT(mg.id) AS monthly_goal_count
                FROM learning_goals lg
                LEFT JOIN monthly_goals mg
                    ON mg.goal_id = lg.id AND mg.user_id = lg.user_id
                WHERE lg.user_id = :user_id
                GROUP BY lg.id, lg.user_id, lg.title, lg.description, lg.target_hours, lg.start_date, lg.end_date, lg.created_at
                ORDER BY lg.start_date ASC, lg.created_at DESC";

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
     * Berechnet die Gesamtzahl der geplanten Lernstunden eines Nutzers (6-Monats-Ziele).
     */
    public function getTotalTargetHours(int $userId): float
    {
        $sql = "SELECT COALESCE(SUM(target_hours), 0) FROM learning_goals WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return (float) $stmt->fetchColumn();
    }

    /**
     * Speichert oder aktualisiert das Soll-Zeitbudget eines Nutzers für einen bestimmten Monat.
     *
     * @param int $userId ID des Nutzers
     * @param string $yearMonth Monat im Format YYYY-MM
     * @param float $budgetHours Geplantes Zeitbudget in Stunden
     * @return bool True bei Erfolg
     */
    public function upsertMonthlyBudget(int $userId, string $yearMonth, float $budgetHours): bool
    {
        $sql = "INSERT INTO monthly_budgets (user_id, plan_month, budget_hours, created_at)
                VALUES (:user_id, :plan_month, :budget_hours, NOW())
                ON DUPLICATE KEY UPDATE budget_hours = VALUES(budget_hours)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':user_id'      => $userId,
            ':plan_month'   => $yearMonth,
            ':budget_hours' => $budgetHours,
        ]);
    }

    /**
     * Entfernt das Soll-Zeitbudget eines Nutzers für einen bestimmten Monat.
     *
     * @param int $userId ID des Nutzers
     * @param string $yearMonth Monat im Format YYYY-MM
     * @return bool True bei Erfolg
     */
    public function deleteMonthlyBudget(int $userId, string $yearMonth): bool
    {
        $sql = "DELETE FROM monthly_budgets
                WHERE user_id = :user_id AND plan_month = :plan_month";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':user_id'    => $userId,
            ':plan_month' => $yearMonth,
        ]);
    }

    /**
     * Liefert alle hinterlegten Monatsbudgets eines Nutzers als Map ['YYYY-MM' => float].
     *
     * @param int $userId ID des Nutzers
     * @return array<string, float>
     */
    public function findAllMonthlyBudgetsByUserId(int $userId): array
    {
        $sql = "SELECT plan_month, budget_hours
                FROM monthly_budgets
                WHERE user_id = :user_id
                ORDER BY plan_month ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $budgets = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $budgets[(string) $row['plan_month']] = (float) $row['budget_hours'];
        }

        return $budgets;
    }

    /**
     * Legt ein neues Zwischenziel (Monatsziel) für ein 6-Monats-Lernziel an.
     *
     * @param int $userId ID des Nutzers
     * @param int $goalId ID des übergeordneten 6-Monats-Ziels
     * @param string $yearMonth Monat im Format YYYY-MM
     * @param string $title Bezeichnung des Zwischenziels im Monat
     * @param string|null $description Optionale Notizen
     * @param float $plannedHours Geplante Lernstunden im Monat
     * @return int ID des neu angelegten Zwischenziels
     */
    public function createMonthlyGoal(
        int $userId,
        int $goalId,
        string $yearMonth,
        string $title,
        ?string $description,
        float $plannedHours
    ): int {
        $sql = "INSERT INTO monthly_goals (user_id, goal_id, plan_month, title, description, planned_hours, created_at)
                VALUES (:user_id, :goal_id, :plan_month, :title, :description, :planned_hours, NOW())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'       => $userId,
            ':goal_id'       => $goalId,
            ':plan_month'    => $yearMonth,
            ':title'         => $title,
            ':description'   => $description,
            ':planned_hours' => $plannedHours,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Liefert die Zwischenziele eines Nutzers (optional gefiltert auf einen Monat),
     * inklusive der Angaben des übergeordneten 6-Monats-Lernziels.
     *
     * @param int $userId ID des Nutzers
     * @param string|null $yearMonth Optionaler Filter auf einen Monat (YYYY-MM)
     * @return list<array<string, mixed>>
     */
    public function findMonthlyGoalsByUserId(int $userId, ?string $yearMonth = null): array
    {
        $sql = "SELECT mg.id,
                       mg.user_id,
                       mg.goal_id,
                       mg.plan_month,
                       mg.title,
                       mg.description,
                       mg.planned_hours,
                       mg.created_at,
                       lg.title AS parent_goal_title,
                       lg.target_hours AS parent_target_hours,
                       lg.start_date AS parent_start_date,
                       lg.end_date AS parent_end_date
                FROM monthly_goals mg
                INNER JOIN learning_goals lg
                    ON lg.id = mg.goal_id AND lg.user_id = mg.user_id
                WHERE mg.user_id = :user_id";

        $params = [':user_id' => $userId];
        if ($yearMonth !== null) {
            $sql .= " AND mg.plan_month = :plan_month";
            $params[':plan_month'] = $yearMonth;
        }

        $sql .= " ORDER BY mg.plan_month ASC, lg.title ASC, mg.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['year_month'] = $row['plan_month'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Lädt ein einzelnes Zwischenziel anhand seiner ID und der Benutzer-ID (Mandantenisolation).
     *
     * @param int $monthlyGoalId ID des Zwischenziels
     * @param int $userId ID des berechtigten Nutzers
     * @return array<string, mixed>|null
     */
    public function findMonthlyGoalByIdAndUserId(int $monthlyGoalId, int $userId): ?array
    {
        $sql = "SELECT id, user_id, goal_id, plan_month, title, description, planned_hours, created_at
                FROM monthly_goals
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $monthlyGoalId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['year_month'] = $row['plan_month'];
        return $row;
    }

    /**
     * Aktualisiert ein bestehendes Zwischenziel unter Berücksichtigung der Benutzer-ID.
     *
     * @param int $monthlyGoalId ID des Zwischenziels
     * @param int $userId ID des Nutzers
     * @param int $goalId ID des übergeordneten 6-Monats-Ziels
     * @param string $yearMonth Monat im Format YYYY-MM
     * @param string $title Bezeichnung des Zwischenziels
     * @param string|null $description Optionale Notizen
     * @param float $plannedHours Geplante Lernstunden im Monat
     * @return bool True bei Erfolg
     */
    public function updateMonthlyGoal(
        int $monthlyGoalId,
        int $userId,
        int $goalId,
        string $yearMonth,
        string $title,
        ?string $description,
        float $plannedHours
    ): bool {
        $sql = "UPDATE monthly_goals
                SET goal_id = :goal_id,
                    plan_month = :plan_month,
                    title = :title,
                    description = :description,
                    planned_hours = :planned_hours
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':goal_id'       => $goalId,
            ':plan_month'    => $yearMonth,
            ':title'         => $title,
            ':description'   => $description,
            ':planned_hours' => $plannedHours,
            ':id'            => $monthlyGoalId,
            ':user_id'       => $userId,
        ]);

        return $stmt->rowCount() >= 0;
    }

    /**
     * Löscht ein Zwischenziel unter Berücksichtigung der Benutzer-ID.
     *
     * @param int $monthlyGoalId ID des Zwischenziels
     * @param int $userId ID des Nutzers
     * @return bool True, wenn ein Datensatz gelöscht wurde
     */
    public function deleteMonthlyGoal(int $monthlyGoalId, int $userId): bool
    {
        $sql = "DELETE FROM monthly_goals
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $monthlyGoalId,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Liefert die Summe der geplanten Zwischenziel-Stunden je Monat als Map ['YYYY-MM' => float].
     *
     * @param int $userId ID des Nutzers
     * @return array<string, float>
     */
    public function getMonthlyPlannedHoursByUserId(int $userId): array
    {
        $sql = "SELECT plan_month, COALESCE(SUM(planned_hours), 0) AS total_planned
                FROM monthly_goals
                WHERE user_id = :user_id
                GROUP BY plan_month
                ORDER BY plan_month ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $summary = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary[(string) $row['plan_month']] = (float) $row['total_planned'];
        }

        return $summary;
    }
}

