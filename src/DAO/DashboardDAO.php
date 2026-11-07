<?php
// src/DAO/DashboardDAO.php

declare(strict_types=1);

namespace DAO;

use PDO;

/**
 * Data Access Object (DAO) für das Soll-Ist-Dashboard (Story #6).
 * Reiner Lesezugriff: liefert Soll-Werte (Lernziele, Zwischenziele, Budgets, Timeslots)
 * und Ist-Werte (erfasste Lernzeiten) eines Nutzers.
 * Laufende bzw. pausierte Stoppuhren zählen noch nicht als Ist (nur manuell oder beendet).
 * Gewährleistet strikte Mandantentrennung über die user_id.
 */
class DashboardDAO
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
     * Liefert alle Semesterziele (learning_goals) eines Nutzers, chronologisch sortiert.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>>
     */
    public function findSemesterGoals(int $userId): array
    {
        $sql = "SELECT id, title, description, target_hours, start_date, end_date, completed_at
                FROM learning_goals
                WHERE user_id = :user_id
                ORDER BY start_date ASC, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liefert alle Zwischenziele eines Semesterziels mit eingeplanten und erfassten Stunden.
     *
     * @param int $userId ID des Nutzers
     * @param int $goalId ID des Semesterziels
     * @return list<array<string, mixed>>
     */
    public function findMonthlyGoalsWithProgress(int $userId, int $goalId): array
    {
        $sql = "SELECT mg.id,
                       mg.plan_month,
                       mg.title,
                       mg.description,
                       mg.planned_hours,
                       mg.completed_at,
                       COALESCE((
                           SELECT SUM(TIME_TO_SEC(TIMEDIFF(ls.end_time, ls.start_time)))
                           FROM learning_slots ls
                           WHERE ls.monthly_goal_id = mg.id AND ls.user_id = mg.user_id
                       ), 0) AS scheduled_seconds,
                       COALESCE((
                           SELECT SUM(te.tracked_seconds)
                           FROM time_entries te
                           WHERE te.monthly_goal_id = mg.id
                             AND te.user_id = mg.user_id
                             AND (te.source = 'manual' OR te.ended_at IS NOT NULL)
                       ), 0) AS tracked_seconds
                FROM monthly_goals mg
                WHERE mg.user_id = :user_id
                  AND mg.goal_id = :goal_id
                ORDER BY mg.plan_month ASC, mg.title ASC, mg.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':goal_id' => $goalId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liefert die Monatsbudgets eines Nutzers als Zuordnung Monat => Stunden.
     *
     * @param int $userId ID des Nutzers
     * @return array<string, float>
     */
    public function findMonthlyBudgets(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT plan_month, budget_hours FROM monthly_budgets WHERE user_id = :user_id"
        );
        $stmt->execute([':user_id' => $userId]);

        $budgets = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $budgets[(string) $row['plan_month']] = (float) $row['budget_hours'];
        }

        return $budgets;
    }

    /**
     * Liefert die erfassten Lernsekunden je Tag für alle Zwischenziele eines Semesterziels.
     *
     * @param int $userId ID des Nutzers
     * @param int $goalId ID des Semesterziels
     * @return array<string, int> Datum (YYYY-MM-DD) => Sekunden
     */
    public function findDailyTrackedSeconds(int $userId, int $goalId): array
    {
        $sql = "SELECT te.entry_date, SUM(te.tracked_seconds) AS tracked_seconds
                FROM time_entries te
                INNER JOIN monthly_goals mg
                    ON mg.id = te.monthly_goal_id AND mg.user_id = te.user_id
                WHERE te.user_id = :user_id
                  AND mg.goal_id = :goal_id
                  AND (te.source = 'manual' OR te.ended_at IS NOT NULL)
                GROUP BY te.entry_date
                ORDER BY te.entry_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':goal_id' => $goalId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['entry_date']] = (int) $row['tracked_seconds'];
        }

        return $result;
    }

    /**
     * Liefert die eingeplanten Lernsekunden (Timeslots) je Tag in einem Zeitraum, über alle Ziele.
     *
     * @param int $userId ID des Nutzers
     * @param string $startDate Startdatum (inklusiv, YYYY-MM-DD)
     * @param string $endDate Enddatum (inklusiv, YYYY-MM-DD)
     * @return array<string, int> Datum (YYYY-MM-DD) => Sekunden
     */
    public function findScheduledSecondsByDay(int $userId, string $startDate, string $endDate): array
    {
        $sql = "SELECT slot_date, SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time))) AS scheduled_seconds
                FROM learning_slots
                WHERE user_id = :user_id
                  AND slot_date BETWEEN :start_date AND :end_date
                GROUP BY slot_date";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':start_date' => $startDate, ':end_date' => $endDate]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['slot_date']] = (int) $row['scheduled_seconds'];
        }

        return $result;
    }

    /**
     * Liefert die erfassten Lernsekunden je Tag in einem Zeitraum, über alle Ziele.
     *
     * @param int $userId ID des Nutzers
     * @param string $startDate Startdatum (inklusiv, YYYY-MM-DD)
     * @param string $endDate Enddatum (inklusiv, YYYY-MM-DD)
     * @return array<string, int> Datum (YYYY-MM-DD) => Sekunden
     */
    public function findTrackedSecondsByDay(int $userId, string $startDate, string $endDate): array
    {
        $sql = "SELECT entry_date, SUM(tracked_seconds) AS tracked_seconds
                FROM time_entries
                WHERE user_id = :user_id
                  AND entry_date BETWEEN :start_date AND :end_date
                  AND (source = 'manual' OR ended_at IS NOT NULL)
                GROUP BY entry_date";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':start_date' => $startDate, ':end_date' => $endDate]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['entry_date']] = (int) $row['tracked_seconds'];
        }

        return $result;
    }

    /**
     * Liefert die verpassten Lerneinheiten eines Zeitraums (Inaktivitäts-Hinweis, Story #7).
     *
     * Verpasst ist eine Lerneinheit, deren Ende im Zeitraum liegt, die nicht abgehakt ist
     * und auf die keine Lernzeit gebucht wurde (nur manuelle oder beendete Stoppuhr-Einträge).
     *
     * @param int $userId ID des Nutzers
     * @param string $since Beginn, exklusiv (YYYY-MM-DD HH:MM:SS), in der Regel der vorherige Login
     * @param string $until Ende, inklusiv (YYYY-MM-DD HH:MM:SS), in der Regel jetzt
     * @return list<array<string, mixed>>
     */
    public function findMissedSlots(int $userId, string $since, string $until): array
    {
        $sql = "SELECT ls.id,
                       ls.slot_date,
                       ls.start_time,
                       ls.end_time,
                       ls.notes,
                       TIME_TO_SEC(TIMEDIFF(ls.end_time, ls.start_time)) AS scheduled_seconds,
                       mg.id AS monthly_goal_id,
                       mg.title AS monthly_goal_title,
                       mg.plan_month,
                       mg.completed_at AS monthly_goal_completed_at,
                       lg.id AS goal_id,
                       lg.title AS goal_title
                FROM learning_slots ls
                INNER JOIN monthly_goals mg
                    ON mg.id = ls.monthly_goal_id AND mg.user_id = ls.user_id
                INNER JOIN learning_goals lg
                    ON lg.id = mg.goal_id AND lg.user_id = ls.user_id
                WHERE ls.user_id = :user_id
                  AND ls.is_completed = 0
                  AND TIMESTAMP(ls.slot_date, ls.end_time) > :since
                  AND TIMESTAMP(ls.slot_date, ls.end_time) <= :until
                  AND NOT EXISTS (
                      SELECT 1
                      FROM time_entries te
                      WHERE te.learning_slot_id = ls.id
                        AND te.user_id = ls.user_id
                        AND (te.source = 'manual' OR te.ended_at IS NOT NULL)
                  )
                ORDER BY ls.slot_date ASC, ls.start_time ASC, ls.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':since' => $since, ':until' => $until]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
