<?php
// src/Controllers/TimeTrackingController.php

declare(strict_types=1);

namespace Controllers;

use Classes\ValidationException;
use DAO\TimeTrackingDAO;
use DateTimeImmutable;

require_once __DIR__ . '/../Classes/ValidationException.php';
require_once __DIR__ . '/../DAO/TimeTrackingDAO.php';

/**
 * Controller für die Stoppuhr und Zeiterfassung.
 * Validiert Benutzereingaben, überwacht Stoppuhr-Zustände und steuert die Interaktion mit dem TimeTrackingDAO.
 */
class TimeTrackingController
{
    public const MAX_NOTE_LENGTH = 255;
    public const MAX_DURATION_SECONDS = 86400; // Höchstens 24 Stunden pro Zeiteintrag

    private TimeTrackingDAO $timeTrackingDAO;

    /**
     * Dependency Injection des TimeTrackingDAO.
     */
    public function __construct(TimeTrackingDAO $timeTrackingDAO)
    {
        $this->timeTrackingDAO = $timeTrackingDAO;
    }

    /**
     * Startet eine neue Stoppuhr für ein ausgewähltes Lernziel.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten (goal_id, note)
     * @param string|null $now Optionaler Referenzzeitpunkt (für Tests)
     * @return int ID des erstellten Zeiteintrags
     * @throws ValidationException Bei ungültigen Eingaben oder wenn bereits eine Stoppuhr aktiv ist
     */
    public function startStopwatch(int $userId, array $input, ?string $now = null): int
    {
        $openStopwatch = $this->timeTrackingDAO->findOpenStopwatch($userId);
        if ($openStopwatch !== null) {
            throw new ValidationException('Es läuft bereits eine aktive Stoppuhr.', 'stopwatch');
        }

        $goalId = $this->validateGoalId($input, $userId);
        $note = $this->parseNote($input['note'] ?? null);

        $nowTimestamp = $now !== null ? $this->parseDateTime($now, 'started_at') : new DateTimeImmutable();
        $startedAt = $nowTimestamp->format('Y-m-d H:i:s');

        return $this->timeTrackingDAO->startStopwatch($userId, $goalId, $startedAt, $note);
    }

    /**
     * Pausiert eine laufende Stoppuhr.
     *
     * @param int $userId ID des Nutzers
     * @param string|null $now Optionaler Referenzzeitpunkt (für Tests)
     * @return array<string, mixed> Aktualisierter Zustand der Stoppuhr
     * @throws ValidationException Wenn keine offene Stoppuhr vorhanden ist
     */
    public function pauseStopwatch(int $userId, ?string $now = null): array
    {
        $open = $this->timeTrackingDAO->findOpenStopwatch($userId);
        if ($open === null) {
            throw new ValidationException('Keine aktive Stoppuhr gefunden.', 'stopwatch');
        }

        // Falls bereits pausiert, nichts tun
        if ($open['resumed_at'] === null) {
            return $open;
        }

        $nowTimestamp = $now !== null ? $this->parseDateTime($now, 'now') : new DateTimeImmutable();
        $resumedTimestamp = new DateTimeImmutable((string) $open['resumed_at']);

        $additionalSeconds = max(0, $nowTimestamp->getTimestamp() - $resumedTimestamp->getTimestamp());
        $this->timeTrackingDAO->pauseStopwatch((int) $open['id'], $userId, $additionalSeconds);

        $updated = $this->timeTrackingDAO->findByIdAndUserId((int) $open['id'], $userId);

        return $updated ?: $open;
    }

    /**
     * Setzt eine pausierte Stoppuhr fort.
     *
     * @param int $userId ID des Nutzers
     * @param string|null $now Optionaler Referenzzeitpunkt (für Tests)
     * @return array<string, mixed> Aktualisierter Zustand der Stoppuhr
     * @throws ValidationException Wenn keine offene Stoppuhr vorhanden ist
     */
    public function resumeStopwatch(int $userId, ?string $now = null): array
    {
        $open = $this->timeTrackingDAO->findOpenStopwatch($userId);
        if ($open === null) {
            throw new ValidationException('Keine aktive Stoppuhr gefunden.', 'stopwatch');
        }

        // Falls bereits läuft, nichts tun
        if ($open['resumed_at'] !== null) {
            return $open;
        }

        $nowTimestamp = $now !== null ? $this->parseDateTime($now, 'now') : new DateTimeImmutable();
        $resumedAt = $nowTimestamp->format('Y-m-d H:i:s');

        $this->timeTrackingDAO->resumeStopwatch((int) $open['id'], $userId, $resumedAt);

        $updated = $this->timeTrackingDAO->findByIdAndUserId((int) $open['id'], $userId);

        return $updated ?: $open;
    }

    /**
     * Beendet eine Stoppuhr und speichert die erfasste Zeit ab.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Optionale Parameter wie note
     * @param string|null $now Optionaler Referenzzeitpunkt (für Tests)
     * @return array<string, mixed> Beendeter Zeiteintrag
     * @throws ValidationException Wenn keine offene Stoppuhr vorhanden ist
     */
    public function stopAndSaveStopwatch(int $userId, array $input = [], ?string $now = null): array
    {
        $open = $this->timeTrackingDAO->findOpenStopwatch($userId);
        if ($open === null) {
            throw new ValidationException('Keine aktive Stoppuhr gefunden.', 'stopwatch');
        }

        $note = isset($input['note']) ? $this->parseNote($input['note']) : null;

        $nowTimestamp = $now !== null ? $this->parseDateTime($now, 'ended_at') : new DateTimeImmutable();
        $endedAt = $nowTimestamp->format('Y-m-d H:i:s');

        $additionalSeconds = 0;
        if ($open['resumed_at'] !== null) {
            $resumedTimestamp = new DateTimeImmutable((string) $open['resumed_at']);
            $additionalSeconds = max(0, $nowTimestamp->getTimestamp() - $resumedTimestamp->getTimestamp());
        }

        $totalSeconds = (int) $open['tracked_seconds'] + $additionalSeconds;
        if ($totalSeconds > self::MAX_DURATION_SECONDS) {
            throw new ValidationException(
                'Ein Zeiteintrag darf höchstens 24 Stunden (86.400 Sekunden) umfassen.',
                'tracked_seconds'
            );
        }

        $this->timeTrackingDAO->stopStopwatch(
            (int) $open['id'],
            $userId,
            $endedAt,
            $additionalSeconds,
            $note
        );

        $saved = $this->timeTrackingDAO->findByIdAndUserId((int) $open['id'], $userId);

        return $saved ?: $open;
    }

    /**
     * Verwirft die aktuelle offene Stoppuhr ohne Speicherung.
     *
     * @param int $userId ID des Nutzers
     * @return bool True bei Erfolg
     * @throws ValidationException Wenn keine offene Stoppuhr vorhanden ist
     */
    public function discardStopwatch(int $userId): bool
    {
        $open = $this->timeTrackingDAO->findOpenStopwatch($userId);
        if ($open === null) {
            throw new ValidationException('Keine aktive Stoppuhr gefunden.', 'stopwatch');
        }

        return $this->timeTrackingDAO->discardStopwatch((int) $open['id'], $userId);
    }

    /**
     * Liefert die aktuelle Stoppuhr inklusive berechneter Live-Laufzeit.
     *
     * @param int $userId ID des Nutzers
     * @param string|null $now Optionaler Referenzzeitpunkt (für Tests)
     * @return array<string, mixed>|null
     */
    public function getCurrentStopwatch(int $userId, ?string $now = null): ?array
    {
        $open = $this->timeTrackingDAO->findOpenStopwatch($userId);
        if ($open === null) {
            return null;
        }

        $nowTimestamp = $now !== null ? $this->parseDateTime($now, 'now') : new DateTimeImmutable();

        $additionalSeconds = 0;
        $isRunning = $open['resumed_at'] !== null;
        if ($isRunning) {
            $resumedTimestamp = new DateTimeImmutable((string) $open['resumed_at']);
            $additionalSeconds = max(0, $nowTimestamp->getTimestamp() - $resumedTimestamp->getTimestamp());
        }

        $currentTracked = (int) $open['tracked_seconds'] + $additionalSeconds;
        $open['current_tracked_seconds'] = $currentTracked;
        $open['is_running'] = $isRunning;
        $open['is_paused'] = !$isRunning;
        $open['formatted_time'] = self::formatSecondsToTime($currentTracked);

        return $open;
    }

    /**
     * Löscht einen Zeiteintrag eines Nutzers.
     *
     * @param int $id ID des Eintrags
     * @param int $userId ID des Nutzers
     * @return bool True bei Erfolg
     * @throws ValidationException Wenn der Eintrag nicht existiert
     */
    public function deleteTimeEntry(int $id, int $userId): bool
    {
        $existing = $this->timeTrackingDAO->findByIdAndUserId($id, $userId);
        if ($existing === null) {
            throw new ValidationException('Der Zeiteintrag existiert nicht oder gehört einem anderen Benutzer.', 'id');
        }

        return $this->timeTrackingDAO->delete($id, $userId);
    }

    /**
     * Liefert alle für den Nutzer zur Zeiterfassung verfügbaren Lernziele.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>>
     */
    public function getAvailableGoals(int $userId): array
    {
        return $this->timeTrackingDAO->getAvailableGoalsForUser($userId);
    }

    /**
     * Liefert abgeschlossene Zeiteinträge des Nutzers.
     *
     * @param int $userId ID des Nutzers
     * @param int|null $monthlyGoalId Optionaler Filter auf ein Modul
     * @param int $limit Anzahl der Einträge
     * @return list<array<string, mixed>>
     */
    public function getTimeEntries(int $userId, ?int $monthlyGoalId = null, int $limit = 50): array
    {
        return $this->timeTrackingDAO->findAllByUserId($userId, $monthlyGoalId, $limit);
    }

    /**
     * Berechnet die Gesamtlernzeit des Nutzers in Sekunden.
     */
    public function getTotalTrackedSeconds(int $userId, ?int $monthlyGoalId = null): int
    {
        return $this->timeTrackingDAO->getTotalTrackedSecondsByUserId($userId, $monthlyGoalId);
    }

    /**
     * Berechnet die Zeitdauer zwischen Start- und Endzeitpunkt in Sekunden.
     *
     * @param string $startedAt Startzeitpunkt
     * @param string $endedAt Endzeitpunkt
     * @return int Dauer in Sekunden
     * @throws ValidationException Bei ungültigen Formaten oder wenn end < start
     */
    public function calculateDuration(string $startedAt, string $endedAt): int
    {
        $startDt = $this->parseDateTime($startedAt, 'started_at');
        $endDt = $this->parseDateTime($endedAt, 'ended_at');

        if ($endDt < $startDt) {
            throw new ValidationException('Die Endzeit darf nicht vor der Startzeit liegen.', 'ended_at');
        }

        $duration = $endDt->getTimestamp() - $startDt->getTimestamp();

        if ($duration > self::MAX_DURATION_SECONDS) {
            throw new ValidationException(
                'Ein Zeiteintrag darf höchstens 24 Stunden (86.400 Sekunden) umfassen.',
                'tracked_seconds'
            );
        }

        return $duration;
    }

    /**
     * Validiert und bereinigt ein vollständiges Formular für einen Zeiteintrag.
     *
     * @param array<string, mixed> $input Rohdaten
     * @param int $userId ID des Nutzers
     * @return array{goal_id: int, entry_date: string, started_at: string, ended_at: string, tracked_seconds: int, note: ?string}
     * @throws ValidationException
     */
    public function validateAndParseInput(array $input, int $userId): array
    {
        $goalId = $this->validateGoalId($input, $userId);

        $startedAtRaw = $input['started_at'] ?? null;
        if (!is_string($startedAtRaw) || trim($startedAtRaw) === '') {
            throw new ValidationException('Bitte gib eine gültige Startzeit an.', 'started_at');
        }
        $startDt = $this->parseDateTime($startedAtRaw, 'started_at');

        $endedAtRaw = $input['ended_at'] ?? null;
        if (!is_string($endedAtRaw) || trim($endedAtRaw) === '') {
            throw new ValidationException('Bitte gib eine gültige Endzeit an.', 'ended_at');
        }
        $endDt = $this->parseDateTime($endedAtRaw, 'ended_at');

        if ($endDt < $startDt) {
            throw new ValidationException('Die Endzeit darf nicht vor der Startzeit liegen.', 'ended_at');
        }

        $calculatedSeconds = $endDt->getTimestamp() - $startDt->getTimestamp();

        $trackedSeconds = $calculatedSeconds;
        if (isset($input['tracked_seconds']) && $input['tracked_seconds'] !== '') {
            $rawSec = $input['tracked_seconds'];
            if (!is_numeric($rawSec)) {
                throw new ValidationException('Die erfasste Zeit muss eine Zahl sein.', 'tracked_seconds');
            }
            $trackedSeconds = (int) $rawSec;
        }

        if ($trackedSeconds < 0) {
            throw new ValidationException('Die erfasste Zeit darf nicht negativ sein.', 'tracked_seconds');
        }

        if ($trackedSeconds > self::MAX_DURATION_SECONDS) {
            throw new ValidationException(
                'Ein Zeiteintrag darf höchstens 24 Stunden (86.400 Sekunden) umfassen.',
                'tracked_seconds'
            );
        }

        $entryDate = $startDt->format('Y-m-d');
        if (isset($input['entry_date']) && is_string($input['entry_date']) && trim($input['entry_date']) !== '') {
            $parsedDate = trim($input['entry_date']);
            $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $parsedDate);
            if ($dateObj && $dateObj->format('Y-m-d') === $parsedDate) {
                $entryDate = $parsedDate;
            }
        }

        $note = $this->parseNote($input['note'] ?? null);

        return [
            'goal_id'         => $goalId,
            'entry_date'      => $entryDate,
            'started_at'      => $startDt->format('Y-m-d H:i:s'),
            'ended_at'        => $endDt->format('Y-m-d H:i:s'),
            'tracked_seconds' => $trackedSeconds,
            'note'            => $note,
        ];
    }

    /**
     * Formatiert Sekunden in einen String im Format HH:MM:SS.
     *
     * @param int $seconds
     * @return string
     */
    public static function formatSecondsToTime(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Formatiert Sekunden leserlich als "X Std. Y Min.".
     *
     * @param int $seconds
     * @return string
     */
    public static function formatSecondsHuman(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return sprintf('%d Sek.', $seconds);
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        if ($hours > 0 && $minutes > 0) {
            return sprintf('%d Std. %d Min.', $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%d Std.', $hours);
        }

        return sprintf('%d Min.', $minutes);
    }

    /**
     * Validiert das Pflichtfeld goal_id und prüft die Berechtigung des Nutzers.
     *
     * @param array<string, mixed> $input
     * @param int $userId
     * @return int Validierte Goal-ID
     * @throws ValidationException
     */
    private function validateGoalId(array $input, int $userId): int
    {
        $goalIdRaw = $input['goal_id'] ?? $input['monthly_goal_id'] ?? null;

        if ($goalIdRaw === null || (is_string($goalIdRaw) && trim($goalIdRaw) === '')) {
            throw new ValidationException('Bitte wähle ein Lernziel aus.', 'goal_id');
        }

        if (!is_numeric($goalIdRaw)) {
            throw new ValidationException('Bitte wähle ein Lernziel aus.', 'goal_id');
        }

        $goalId = (int) $goalIdRaw;
        if ($goalId <= 0) {
            throw new ValidationException('Bitte wähle ein Lernziel aus.', 'goal_id');
        }

        if (!$this->timeTrackingDAO->goalExistsForUser($goalId, $userId)) {
            throw new ValidationException(
                'Das ausgewählte Lernziel existiert nicht oder gehört einem anderen Benutzer.',
                'goal_id'
            );
        }

        return $goalId;
    }

    /**
     * Parst und validiert einen DateTime-String.
     *
     * @param string $dateTimeStr
     * @param string $fieldName Feldname für die Exception
     * @return DateTimeImmutable
     * @throws ValidationException
     */
    private function parseDateTime(string $dateTimeStr, string $fieldName): DateTimeImmutable
    {
        $trimmed = trim($dateTimeStr);
        if ($trimmed === '') {
            $label = $fieldName === 'started_at' ? 'Startzeit' : ($fieldName === 'ended_at' ? 'Endzeit' : 'Zeitangabe');
            throw new ValidationException("Bitte gib eine gültige {$label} an.", $fieldName);
        }

        // Unterstützte Datetime-Formate
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
        ];

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $trimmed);
            if ($dt && $dt->format($format) === $trimmed) {
                return $dt;
            }
        }

        // Fallback: strtotime
        $timestamp = strtotime($trimmed);
        if ($timestamp !== false) {
            $dt = (new DateTimeImmutable())->setTimestamp($timestamp);
            return $dt;
        }

        $label = $fieldName === 'started_at' ? 'Startzeit' : ($fieldName === 'ended_at' ? 'Endzeit' : 'Zeitangabe');
        throw new ValidationException("Bitte gib eine gültige {$label} an.", $fieldName);
    }

    /**
     * Bereinigt und validiert die optionale Notiz.
     *
     * @param mixed $noteRaw
     * @return string|null
     * @throws ValidationException
     */
    private function parseNote(mixed $noteRaw): ?string
    {
        if ($noteRaw === null) {
            return null;
        }

        if (!is_string($noteRaw)) {
            return null;
        }

        $trimmed = trim($noteRaw);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_NOTE_LENGTH) {
            throw new ValidationException(
                'Die Notiz darf höchstens ' . self::MAX_NOTE_LENGTH . ' Zeichen lang sein.',
                'note'
            );
        }

        return $trimmed;
    }
}
