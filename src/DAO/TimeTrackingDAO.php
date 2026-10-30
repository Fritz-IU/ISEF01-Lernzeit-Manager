<?php
// src/DAO/TimeTrackingDAO.php

declare(strict_types=1);

namespace DAO;

use PDO;
use PDOException;

/**
 * Data Access Object (DAO) für Zeiteinträge (time_entries).
 * Kapselt alle SQL-Abfragen und Prepared Statements für die Stoppuhr und Zeiterfassung.
 * Gewährleistet strikte Mandantentrennung über die user_id.
 */
class TimeTrackingDAO
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
     * Erstellt einen neuen Zeiteintrag in der Datenbank.
     *
     * @param int $userId ID des Besitzers
     * @param int $monthlyGoalId ID des zugeordneten Moduls/Zwischenziels
     * @param string $source Herkunft ('stopwatch' oder 'manual')
     * @param string $entryDate Datum des Eintrags (YYYY-MM-DD)
     * @param string|null $startedAt Startzeitpunkt (YYYY-MM-DD HH:MM:SS)
     * @param string|null $endedAt Endzeitpunkt (YYYY-MM-DD HH:MM:SS)
     * @param string|null $resumedAt Zeitpunkt der letzten Fortsetzung
     * @param int $trackedSeconds Netto-Lernzeit in Sekunden
     * @param string|null $note Optionale Notiz (max. 255 Zeichen)
     * @param int|null $learningSlotId Optionaler Bezug zu einem Lernslot
     * @return int ID des neu angelegten Datensatzes
     * @throws PDOException Bei Datenbankfehlern
     */
    public function create(
        int $userId,
        int $monthlyGoalId,
        string $source,
        string $entryDate,
        ?string $startedAt,
        ?string $endedAt,
        ?string $resumedAt,
        int $trackedSeconds,
        ?string $note = null,
        ?int $learningSlotId = null
    ): int {
        $sql = "INSERT INTO time_entries (
                    user_id, monthly_goal_id, learning_slot_id, source, entry_date,
                    started_at, ended_at, resumed_at, tracked_seconds, note, created_at
                ) VALUES (
                    :user_id, :monthly_goal_id, :learning_slot_id, :source, :entry_date,
                    :started_at, :ended_at, :resumed_at, :tracked_seconds, :note, NOW()
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'          => $userId,
            ':monthly_goal_id'  => $monthlyGoalId,
            ':learning_slot_id' => $learningSlotId,
            ':source'           => $source,
            ':entry_date'       => $entryDate,
            ':started_at'       => $startedAt,
            ':ended_at'         => $endedAt,
            ':resumed_at'       => $resumedAt,
            ':tracked_seconds'  => $trackedSeconds,
            ':note'             => $note,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Startet eine neue Stoppuhr für den Benutzer.
     * Setzt started_at und resumed_at auf den Startzeitpunkt.
     *
     * @param int $userId ID des Nutzers
     * @param int $monthlyGoalId ID des Moduls
     * @param string $startedAt Startzeitpunkt (YYYY-MM-DD HH:MM:SS)
     * @param string|null $note Optionale Notiz
     * @param int|null $learningSlotId Optionaler Lernslot
     * @return int ID des erstellten Zeiteintrags
     */
    public function startStopwatch(
        int $userId,
        int $monthlyGoalId,
        string $startedAt,
        ?string $note = null,
        ?int $learningSlotId = null
    ): int {
        $entryDate = substr($startedAt, 0, 10);

        return $this->create(
            $userId,
            $monthlyGoalId,
            'stopwatch',
            $entryDate,
            $startedAt,
            null,
            $startedAt,
            0,
            $note,
            $learningSlotId
        );
    }

    /**
     * Sucht die aktuell offene Stoppuhr eines Nutzers (läuft oder pausiert).
     *
     * @param int $userId ID des Nutzers
     * @return array<string, mixed>|null Datensatz oder null
     */
    public function findOpenStopwatch(int $userId): ?array
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
                       mg.title AS goal_title,
                       mg.plan_month,
                       lg.title AS parent_goal_title
                FROM time_entries te
                INNER JOIN monthly_goals mg ON mg.id = te.monthly_goal_id
                INNER JOIN learning_goals lg ON lg.id = mg.goal_id
                WHERE te.user_id = :user_id
                  AND te.source = 'stopwatch'
                  AND te.ended_at IS NULL
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Pausiert eine laufende Stoppuhr und addiert die verstrichenen Sekunden auf tracked_seconds.
     * Setzt resumed_at auf NULL.
     *
     * @param int $id ID des Eintrags
     * @param int $userId ID des Nutzers
     * @param int $additionalSeconds Neu verstrichene Sekunden seit resumed_at
     * @return bool True bei Erfolg
     */
    public function pauseStopwatch(int $id, int $userId, int $additionalSeconds): bool
    {
        $sql = "UPDATE time_entries
                SET tracked_seconds = tracked_seconds + :additional_seconds,
                    resumed_at = NULL
                WHERE id = :id
                  AND user_id = :user_id
                  AND source = 'stopwatch'
                  AND ended_at IS NULL";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':additional_seconds' => $additionalSeconds,
            ':id'                 => $id,
            ':user_id'            => $userId,
        ]);
    }

    /**
     * Setzt eine pausierte Stoppuhr fort.
     *
     * @param int $id ID des Eintrags
     * @param int $userId ID des Nutzers
     * @param string $resumedAt Neuer Fortsetzungszeitpunkt (YYYY-MM-DD HH:MM:SS)
     * @return bool True bei Erfolg
     */
    public function resumeStopwatch(int $id, int $userId, string $resumedAt): bool
    {
        $sql = "UPDATE time_entries
                SET resumed_at = :resumed_at
                WHERE id = :id
                  AND user_id = :user_id
                  AND source = 'stopwatch'
                  AND ended_at IS NULL";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':resumed_at' => $resumedAt,
            ':id'         => $id,
            ':user_id'    => $userId,
        ]);
    }

    /**
     * Beendet eine Stoppuhr endgültig.
     * Addiert etwaige Restsekunden, setzt ended_at und leert resumed_at.
     *
     * @param int $id ID des Eintrags
     * @param int $userId ID des Nutzers
     * @param string $endedAt Endzeitpunkt (YYYY-MM-DD HH:MM:SS)
     * @param int $additionalSeconds Zusätzliche Sekunden seit letztem resumed_at
     * @param string|null $note Optionale Notiz
     * @return bool True bei Erfolg
     */
    public function stopStopwatch(
        int $id,
        int $userId,
        string $endedAt,
        int $additionalSeconds,
        ?string $note = null
    ): bool {
        $hasNote = $note !== null;

        $sql = "UPDATE time_entries
                SET ended_at = :ended_at,
                    tracked_seconds = tracked_seconds + :additional_seconds,
                    resumed_at = NULL,
                    note = CASE WHEN :has_note = 1 THEN :note ELSE note END
                WHERE id = :id
                  AND user_id = :user_id
                  AND source = 'stopwatch'
                  AND ended_at IS NULL";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':ended_at'           => $endedAt,
            ':additional_seconds' => $additionalSeconds,
            ':has_note'           => $hasNote ? 1 : 0,
            ':note'               => $note,
            ':id'                 => $id,
            ':user_id'            => $userId,
        ]);
    }

    /**
     * Verwirft/löscht eine offene Stoppuhr, ohne die Zeit zu speichern.
     *
     * @param int $id ID des Eintrags
     * @param int $userId ID des Nutzers
     * @return bool True bei Erfolg
     */
    public function discardStopwatch(int $id, int $userId): bool
    {
        $sql = "DELETE FROM time_entries
                WHERE id = :id
                  AND user_id = :user_id
                  AND source = 'stopwatch'
                  AND ended_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $id,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Lädt einen einzelnen Zeiteintrag anhand seiner ID und der Benutzer-ID.
     *
     * @param int $id ID des Zeiteintrags
     * @param int $userId ID des Nutzers
     * @return array<string, mixed>|null
     */
    public function findByIdAndUserId(int $id, int $userId): ?array
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
                       mg.title AS goal_title,
                       mg.plan_month,
                       lg.title AS parent_goal_title
                FROM time_entries te
                INNER JOIN monthly_goals mg ON mg.id = te.monthly_goal_id
                INNER JOIN learning_goals lg ON lg.id = mg.goal_id
                WHERE te.id = :id AND te.user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $id,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Liefert abgeschlossene Zeiteinträge eines Nutzers, sortiert nach Datum absteigend.
     *
     * @param int $userId ID des Nutzers
     * @param int|null $monthlyGoalId Optionaler Filter auf ein bestimmtes Modul
     * @param int $limit Maximale Anzahl der Einträge
     * @return list<array<string, mixed>>
     */
    public function findAllByUserId(int $userId, ?int $monthlyGoalId = null, int $limit = 50): array
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
                       mg.title AS goal_title,
                       mg.plan_month,
                       lg.title AS parent_goal_title
                FROM time_entries te
                INNER JOIN monthly_goals mg ON mg.id = te.monthly_goal_id
                INNER JOIN learning_goals lg ON lg.id = mg.goal_id
                WHERE te.user_id = :user_id
                  AND (te.source = 'manual' OR te.ended_at IS NOT NULL)";

        $params = [':user_id' => $userId];

        if ($monthlyGoalId !== null) {
            $sql .= " AND te.monthly_goal_id = :monthly_goal_id";
            $params[':monthly_goal_id'] = $monthlyGoalId;
        }

        $sql .= " ORDER BY te.entry_date DESC, te.id DESC LIMIT " . (int) $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Aktualisiert einen bestehenden Zeiteintrag.
     *
     * @param int $id ID des Eintrags
     * @param int $userId ID des Nutzers
     * @param int $monthlyGoalId Neues Modul
     * @param string $entryDate Datum (YYYY-MM-DD)
     * @param string|null $startedAt Startzeit
     * @param string|null $endedAt Endzeit
     * @param int $trackedSeconds Lernzeit in Sekunden
     * @param string|null $note Notiz
     * @return bool True bei Erfolg
     */
    public function update(
        int $id,
        int $userId,
        int $monthlyGoalId,
        string $entryDate,
        ?string $startedAt,
        ?string $endedAt,
        int $trackedSeconds,
        ?string $note = null
    ): bool {
        $sql = "UPDATE time_entries
                SET monthly_goal_id = :monthly_goal_id,
                    entry_date = :entry_date,
                    started_at = :started_at,
                    ended_at = :ended_at,
                    tracked_seconds = :tracked_seconds,
                    note = :note
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':monthly_goal_id' => $monthlyGoalId,
            ':entry_date'      => $entryDate,
            ':started_at'      => $startedAt,
            ':ended_at'        => $endedAt,
            ':tracked_seconds' => $trackedSeconds,
            ':note'            => $note,
            ':id'              => $id,
            ':user_id'         => $userId,
        ]);
    }

    /**
     * Löscht einen Zeiteintrag des Nutzers.
     *
     * @param int $id ID des Eintrags
     * @param int $userId ID des Nutzers
     * @return bool True, wenn gelöscht
     */
    public function delete(int $id, int $userId): bool
    {
        $sql = "DELETE FROM time_entries
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $id,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Prüft, ob ein bestimmtes Modul existiert und dem Nutzer gehört.
     *
     * @param int $goalId ID des Moduls (monthly_goals.id)
     * @param int $userId ID des Nutzers
     * @return bool True, wenn vorhanden und berechtigt
     */
    public function goalExistsForUser(int $goalId, int $userId): bool
    {
        $sql = "SELECT COUNT(*) FROM monthly_goals WHERE id = :id AND user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $goalId,
            ':user_id' => $userId,
        ]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Liefert alle für den Nutzer zur Zeiterfassung verfügbaren Lernziele / Module.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>>
     */
    public function getAvailableGoalsForUser(int $userId): array
    {
        $sql = "SELECT mg.id,
                       mg.title,
                       mg.plan_month,
                       mg.planned_hours,
                       lg.title AS parent_goal_title
                FROM monthly_goals mg
                INNER JOIN learning_goals lg ON lg.id = mg.goal_id AND lg.user_id = mg.user_id
                WHERE mg.user_id = :user_id
                ORDER BY mg.plan_month DESC, lg.title ASC, mg.title ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Berechnet die gesamte erfasste Netto-Lernzeit eines Nutzers in Sekunden.
     *
     * @param int $userId ID des Nutzers
     * @param int|null $monthlyGoalId Optionaler Filter auf ein Modul
     * @return int Summe in Sekunden
     */
    public function getTotalTrackedSecondsByUserId(int $userId, ?int $monthlyGoalId = null): int
    {
        $sql = "SELECT COALESCE(SUM(tracked_seconds), 0)
                FROM time_entries
                WHERE user_id = :user_id
                  AND (source = 'manual' OR ended_at IS NOT NULL)";

        $params = [':user_id' => $userId];

        if ($monthlyGoalId !== null) {
            $sql .= " AND monthly_goal_id = :monthly_goal_id";
            $params[':monthly_goal_id'] = $monthlyGoalId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Berechnet die aufsummierte gestoppte/erfasste Lernzeit in Sekunden für ein bestimmtes
     * Lernziel (monthly_goal_id) an einem bestimmten Datum (entry_date).
     *
     * @param int $userId ID des Nutzers
     * @param int $monthlyGoalId ID des Moduls / Zwischenziels
     * @param string $entryDate Datum im Format YYYY-MM-DD
     * @return int Aufsummierte Sekunden
     */
    public function getTrackedSecondsForGoalAndDate(int $userId, int $monthlyGoalId, string $entryDate): int
    {
        $sql = "SELECT COALESCE(SUM(tracked_seconds), 0)
                FROM time_entries
                WHERE user_id = :user_id
                  AND monthly_goal_id = :monthly_goal_id
                  AND entry_date = :entry_date
                  AND (source = 'manual' OR ended_at IS NOT NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'         => $userId,
            ':monthly_goal_id' => $monthlyGoalId,
            ':entry_date'      => $entryDate,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Liefert alle geplanten Tagesziele / Lerneinheiten (learning_slots) eines Nutzers
     * für ein bestimmtes Modul an einem bestimmten Tag, chronologisch sortiert.
     *
     * @param int $userId ID des Nutzers
     * @param int $monthlyGoalId ID des Moduls / Zwischenziels
     * @param string $slotDate Datum im Format YYYY-MM-DD
     * @return list<array<string, mixed>>
     */
    public function findLearningSlotsByGoalAndDate(int $userId, int $monthlyGoalId, string $slotDate): array
    {
        $sql = "SELECT id,
                       user_id,
                       monthly_goal_id,
                       slot_date,
                       start_time,
                       end_time,
                       is_completed,
                       TIME_TO_SEC(TIMEDIFF(end_time, start_time)) AS planned_seconds,
                       ROUND(TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 3600.0, 2) AS duration_hours
                FROM learning_slots
                WHERE user_id = :user_id
                  AND monthly_goal_id = :monthly_goal_id
                  AND slot_date = :slot_date
                ORDER BY start_time ASC, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'         => $userId,
            ':monthly_goal_id' => $monthlyGoalId,
            ':slot_date'       => $slotDate,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Setzt den Erledigt-Status (is_completed) eines Tagesziels / Lern-Timeslots.
     *
     * @param int $slotId ID des Timeslots (learning_slots.id)
     * @param int $userId ID des Nutzers
     * @param bool $isCompleted Neuer Status (Standard: true)
     * @return bool True bei Erfolg
     */
    public function markLearningSlotCompleted(int $slotId, int $userId, bool $isCompleted = true): bool
    {
        $sql = "UPDATE learning_slots
                SET is_completed = :is_completed
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':is_completed' => $isCompleted ? 1 : 0,
            ':id'           => $slotId,
            ':user_id'      => $userId,
        ]);
    }

    /**
     * Lädt ein einzelnes Lernziel / Zwischenziel (monthly_goals) anhand seiner ID und Benutzer-ID.
     *
     * @param int $monthlyGoalId ID des Moduls / Zwischenziels
     * @param int $userId ID des Nutzers
     * @return array<string, mixed>|null
     */
    public function findGoalByIdAndUserId(int $monthlyGoalId, int $userId): ?array
    {
        $sql = "SELECT id,
                       user_id,
                       goal_id,
                       plan_month,
                       title,
                       planned_hours,
                       completed_at
                FROM monthly_goals
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'      => $monthlyGoalId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Markiert ein monatliches Lernziel (monthly_goals) durch Setzen von completed_at als erledigt.
     *
     * @param int $monthlyGoalId ID des Moduls / Zwischenziels
     * @param int $userId ID des Nutzers
     * @param string|null $completedAt Abschlussdatum (YYYY-MM-DD) oder null
     * @return bool True bei Erfolg
     */
    public function markMonthlyGoalCompleted(int $monthlyGoalId, int $userId, ?string $completedAt = null): bool
    {
        $sql = "UPDATE monthly_goals
                SET completed_at = :completed_at
                WHERE id = :id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':completed_at' => $completedAt ?? date('Y-m-d'),
            ':id'           => $monthlyGoalId,
            ':user_id'      => $userId,
        ]);
    }
}

