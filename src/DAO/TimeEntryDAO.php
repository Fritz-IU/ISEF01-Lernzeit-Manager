<?php
// src/DAO/TimeEntryDAO.php

declare(strict_types=1);

namespace DAO;

use PDO;
use PDOException;

/**
 * Data Access Object (DAO) für Zeiteinträge (time_entries).
 * Kapselt alle SQL-Abfragen und Prepared Statements für die Zeiterfassung und manuelle Zeitnachbuchung.
 * Gewährleistet strikte Mandantentrennung über die user_id.
 */
class TimeEntryDAO
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
     * Legt eine neue manuelle Zeitnachbuchung in der Datenbank an.
     * Gemäß DB-Constraint chk_time_entries_manual bleiben started_at, ended_at und resumed_at NULL
     * und tracked_seconds muss > 0 sein.
     *
     * @param int $userId ID des angemeldeten Nutzers
     * @param int $monthlyGoalId ID des zugeordneten Moduls / Zwischenziels (monthly_goals)
     * @param string $entryDate Datum des Lerntags im Format YYYY-MM-DD
     * @param int $trackedSeconds Netto-Lernzeit in Sekunden (> 0)
     * @param string|null $note Optionale Notiz zur Lerneinheit (max. 255 Zeichen)
     * @param int|null $learningSlotId Optionale Verknüpfung zu einem geplanten Wochen-Timeslot
     * @return int ID des neu angelegten Zeiteintrags
     * @throws PDOException Bei Datenbankfehlern
     */
    public function createManualEntry(
        int $userId,
        int $monthlyGoalId,
        string $entryDate,
        int $trackedSeconds,
        ?string $note = null,
        ?int $learningSlotId = null
    ): int {
        $sql = "INSERT INTO time_entries (
                    user_id,
                    monthly_goal_id,
                    learning_slot_id,
                    source,
                    entry_date,
                    started_at,
                    ended_at,
                    resumed_at,
                    tracked_seconds,
                    note,
                    created_at
                ) VALUES (
                    :user_id,
                    :monthly_goal_id,
                    :learning_slot_id,
                    'manual',
                    :entry_date,
                    NULL,
                    NULL,
                    NULL,
                    :tracked_seconds,
                    :note,
                    NOW()
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'          => $userId,
            ':monthly_goal_id'  => $monthlyGoalId,
            ':learning_slot_id' => $learningSlotId,
            ':entry_date'       => $entryDate,
            ':tracked_seconds'  => $trackedSeconds,
            ':note'             => $note,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Aktualisiert eine bestehende manuelle Zeitnachbuchung unter Berücksichtigung der Benutzer-ID.
     *
     * @param int $entryId ID des Zeiteintrags
     * @param int $userId ID des berechtigten Nutzers
     * @param int $monthlyGoalId ID des zugeordneten Moduls / Zwischenziels
     * @param string $entryDate Datum des Lerntags im Format YYYY-MM-DD
     * @param int $trackedSeconds Netto-Lernzeit in Sekunden (> 0)
     * @param string|null $note Optionale Notiz
     * @param int|null $learningSlotId Optionale Verknüpfung zu einem geplanten Timeslot
     * @return bool True bei Erfolg
     */
    public function updateManualEntry(
        int $entryId,
        int $userId,
        int $monthlyGoalId,
        string $entryDate,
        int $trackedSeconds,
        ?string $note = null,
        ?int $learningSlotId = null
    ): bool {
        $sql = "UPDATE time_entries
                SET monthly_goal_id = :monthly_goal_id,
                    learning_slot_id = :learning_slot_id,
                    entry_date = :entry_date,
                    tracked_seconds = :tracked_seconds,
                    note = :note
                WHERE id = :id
                  AND user_id = :user_id
                  AND source = 'manual'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':monthly_goal_id'  => $monthlyGoalId,
            ':learning_slot_id' => $learningSlotId,
            ':entry_date'       => $entryDate,
            ':tracked_seconds'  => $trackedSeconds,
            ':note'             => $note,
            ':id'               => $entryId,
            ':user_id'          => $userId,
        ]);

        return $stmt->rowCount() >= 0;
    }

    /**
     * Löscht einen Zeiteintrag unter Berücksichtigung der Benutzer-ID (Mandantenisolation).
     *
     * @param int $entryId ID des Zeiteintrags
     * @param int $userId ID des berechtigten Nutzers
     * @return bool True, wenn ein Datensatz gelöscht wurde
     */
    public function deleteByIdAndUserId(int $entryId, int $userId): bool
    {
        $sql = "DELETE FROM time_entries
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $entryId,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Lädt einen einzelnen Zeiteintrag anhand seiner ID und der Benutzer-ID (Mandantenisolation / Schutz vor IDOR).
     *
     * @param int $entryId ID des Zeiteintrags
     * @param int $userId ID des berechtigten Nutzers
     * @return array<string, mixed>|null Datensatz oder null, falls nicht gefunden / fremd
     */
    public function findByIdAndUserId(int $entryId, int $userId): ?array
    {
        $sql = "SELECT te.id,
                       te.user_id,
                       te.monthly_goal_id,
                       te.learning_slot_id,
                       te.source,
                       te.entry_date,
                       te.started_at,
                       te.ended_at,
                       te.resumed_at,
                       te.tracked_seconds,
                       te.note,
                       te.created_at,
                       mg.title AS monthly_goal_title,
                       mg.plan_month,
                       mg.goal_id,
                       lg.title AS parent_goal_title
                FROM time_entries te
                INNER JOIN monthly_goals mg
                    ON mg.id = te.monthly_goal_id AND mg.user_id = te.user_id
                INNER JOIN learning_goals lg
                    ON lg.id = mg.goal_id AND lg.user_id = te.user_id
                WHERE te.id = :id AND te.user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $entryId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Liefert die zuletzt erfassten Zeiteinträge eines Nutzers inklusive Modul- und Semesterangaben.
     *
     * @param int $userId ID des Nutzers
     * @param int $limit Maximale Anzahl der zurückgegebenen Einträge
     * @return list<array<string, mixed>>
     */
    public function findRecentByUserId(int $userId, int $limit = 10): array
    {
        $safeLimit = max(1, min(100, $limit));

        $sql = "SELECT te.id,
                       te.user_id,
                       te.monthly_goal_id,
                       te.learning_slot_id,
                       te.source,
                       te.entry_date,
                       te.started_at,
                       te.ended_at,
                       te.resumed_at,
                       te.tracked_seconds,
                       te.note,
                       te.created_at,
                       mg.title AS monthly_goal_title,
                       mg.plan_month,
                       mg.goal_id,
                       lg.title AS parent_goal_title
                FROM time_entries te
                INNER JOIN monthly_goals mg
                    ON mg.id = te.monthly_goal_id AND mg.user_id = te.user_id
                INNER JOIN learning_goals lg
                    ON lg.id = mg.goal_id AND lg.user_id = te.user_id
                WHERE te.user_id = :user_id
                  AND (te.source = 'manual' OR te.ended_at IS NOT NULL)
                ORDER BY te.entry_date DESC, te.created_at DESC, te.id DESC
                LIMIT " . $safeLimit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt ein einzelnes Modul / Zwischenziel anhand seiner ID und der Benutzer-ID (Mandantenisolation).
     *
     * @param int $monthlyGoalId ID des Zwischenziels
     * @param int $userId ID des berechtigten Nutzers
     * @return array<string, mixed>|null
     */
    public function findMonthlyGoalByIdAndUserId(int $monthlyGoalId, int $userId): ?array
    {
        $sql = "SELECT mg.id,
                       mg.user_id,
                       mg.goal_id,
                       mg.plan_month,
                       mg.title,
                       mg.description,
                       mg.planned_hours,
                       mg.completed_at,
                       lg.title AS parent_goal_title,
                       lg.start_date AS parent_start_date,
                       lg.end_date AS parent_end_date
                FROM monthly_goals mg
                INNER JOIN learning_goals lg
                    ON lg.id = mg.goal_id AND lg.user_id = mg.user_id
                WHERE mg.id = :id AND mg.user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $monthlyGoalId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Lädt einen einzelnen geplanten Wochen-Timeslot anhand seiner ID und der Benutzer-ID (Mandantenisolation).
     *
     * @param int $slotId ID des Timeslots
     * @param int $userId ID des berechtigten Nutzers
     * @return array<string, mixed>|null
     */
    public function findLearningSlotByIdAndUserId(int $slotId, int $userId): ?array
    {
        $sql = "SELECT ls.id,
                       ls.user_id,
                       ls.monthly_goal_id,
                       ls.slot_date,
                       ls.start_time,
                       ls.end_time,
                       ls.notes,
                       ls.is_completed
                FROM learning_slots ls
                WHERE ls.id = :id AND ls.user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $slotId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Liefert alle auswählbaren Module / Zwischenziele eines Nutzers für das Nachbuchungs-Formular,
     * inklusive übergeordnetem Semester und bisher gebuchter Lernzeit.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>>
     */
    public function findSelectableMonthlyGoalsByUserId(int $userId): array
    {
        $sql = "SELECT mg.id,
                       mg.user_id,
                       mg.goal_id,
                       mg.plan_month,
                       mg.title,
                       mg.description,
                       mg.planned_hours,
                       mg.completed_at,
                       lg.title AS parent_goal_title,
                       lg.start_date AS parent_start_date,
                       lg.end_date AS parent_end_date,
                       COALESCE(SUM(te.tracked_seconds), 0) AS tracked_seconds
                FROM monthly_goals mg
                INNER JOIN learning_goals lg
                    ON lg.id = mg.goal_id AND lg.user_id = mg.user_id
                LEFT JOIN time_entries te
                    ON te.monthly_goal_id = mg.id
                   AND te.user_id = mg.user_id
                   AND (te.source = 'manual' OR te.ended_at IS NOT NULL)
                WHERE mg.user_id = :user_id
                GROUP BY mg.id, mg.user_id, mg.goal_id, mg.plan_month, mg.title, mg.description,
                         mg.planned_hours, mg.completed_at, lg.title, lg.start_date, lg.end_date
                ORDER BY lg.start_date DESC, lg.id DESC, mg.plan_month DESC, mg.title ASC, mg.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

