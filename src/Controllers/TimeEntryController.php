<?php
// src/Controllers/TimeEntryController.php

declare(strict_types=1);

namespace Controllers;

use Classes\ValidationException;
use DAO\TimeEntryDAO;
use DateTimeImmutable;

require_once __DIR__ . '/../Classes/ValidationException.php';
require_once __DIR__ . '/../DAO/TimeEntryDAO.php';
require_once __DIR__ . '/GoalController.php';

/**
 * Controller für die Zeiterfassung und manuelle Zeitnachbuchung (Issue #9).
 * Validiert Benutzereingaben, prüft Berechtigungen (Mandantentrennung) und delegiert an TimeEntryDAO.
 */
class TimeEntryController
{
    public const MIN_DURATION_MINUTES = 1;
    public const MAX_DURATION_MINUTES = 1440;
    public const MAX_NOTE_LENGTH = 255;
    public const MIN_ENTRY_DATE = '2000-01-01';

    private TimeEntryDAO $timeEntryDAO;

    /**
     * Dependency Injection des TimeEntryDAO.
     */
    public function __construct(TimeEntryDAO $timeEntryDAO)
    {
        $this->timeEntryDAO = $timeEntryDAO;
    }

    /**
     * Erstellt eine neue manuelle Zeitnachbuchung nach erfolgreicher Validierung.
     * Wird dabei eine geplante Lerneinheit (learning_slot_id) zugeordnet, wird diese automatisch als erledigt abgehakt.
     *
     * @param int $userId ID des angemeldeten Nutzers
     * @param array<string, mixed> $input Rohdaten aus dem Formular / JSON-Body
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD) für deterministische Tests
     * @return array<string, mixed> Metadaten der angelegten Buchung
     * @throws ValidationException Bei ungültigen Eingaben oder fehlender Berechtigung
     */
    public function createManualEntry(int $userId, array $input, ?string $today = null): array
    {
        $validated = $this->validateAndParseManualEntryInput($userId, $input, $today);

        $entryId = $this->timeEntryDAO->createManualEntry(
            $userId,
            $validated['monthly_goal_id'],
            $validated['entry_date'],
            $validated['tracked_seconds'],
            $validated['note'],
            $validated['learning_slot_id']
        );

        $slotCompleted = false;
        if ($validated['learning_slot_id'] !== null) {
            $this->timeEntryDAO->markLearningSlotCompleted($validated['learning_slot_id'], $userId);
            $slotCompleted = true;
        }

        return [
            'entry_id'           => $entryId,
            'monthly_goal_id'    => $validated['monthly_goal_id'],
            'monthly_goal_title' => (string) ($validated['monthly_goal']['title'] ?? ''),
            'plan_month'         => (string) ($validated['monthly_goal']['plan_month'] ?? ''),
            'entry_date'         => $validated['entry_date'],
            'tracked_seconds'    => $validated['tracked_seconds'],
            'duration_minutes'   => $validated['duration_minutes'],
            'formatted_duration' => self::formatDurationSeconds($validated['tracked_seconds']),
            'note'               => $validated['note'],
            'learning_slot_id'   => $validated['learning_slot_id'],
            'slot_completed'     => $slotCompleted,
        ];
    }

    /**
     * Aktualisiert eine bestehende manuelle Zeitnachbuchung eines Nutzers.
     *
     * @param int $entryId ID des Zeiteintrags
     * @param int $userId ID des angemeldeten Nutzers
     * @param array<string, mixed> $input Rohdaten aus dem Formular / JSON-Body
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD) für deterministische Tests
     * @return array<string, mixed> Aktualisierte Metadaten
     * @throws ValidationException Bei ungültigen Eingaben oder fehlender Berechtigung
     */
    public function updateManualEntry(int $entryId, int $userId, array $input, ?string $today = null): array
    {
        if ($entryId <= 0) {
            throw new ValidationException('Ungültige Zeiteintrags-ID.');
        }

        $existing = $this->timeEntryDAO->findByIdAndUserId($entryId, $userId);
        if ($existing === null) {
            throw new ValidationException('Der Zeiteintrag wurde nicht gefunden oder du hast keine Berechtigung.');
        }

        if (($existing['source'] ?? '') !== 'manual') {
            throw new ValidationException('Nur manuell nachgebuchte Zeiteinträge können über dieses Formular bearbeitet werden.');
        }

        $validated = $this->validateAndParseManualEntryInput($userId, $input, $today);

        $this->timeEntryDAO->updateManualEntry(
            $entryId,
            $userId,
            $validated['monthly_goal_id'],
            $validated['entry_date'],
            $validated['tracked_seconds'],
            $validated['note'],
            $validated['learning_slot_id']
        );

        $slotCompleted = false;
        if ($validated['learning_slot_id'] !== null) {
            $this->timeEntryDAO->markLearningSlotCompleted($validated['learning_slot_id'], $userId);
            $slotCompleted = true;
        }

        return [
            'entry_id'           => $entryId,
            'monthly_goal_id'    => $validated['monthly_goal_id'],
            'monthly_goal_title' => (string) ($validated['monthly_goal']['title'] ?? ''),
            'plan_month'         => (string) ($validated['monthly_goal']['plan_month'] ?? ''),
            'entry_date'         => $validated['entry_date'],
            'tracked_seconds'    => $validated['tracked_seconds'],
            'duration_minutes'   => $validated['duration_minutes'],
            'formatted_duration' => self::formatDurationSeconds($validated['tracked_seconds']),
            'note'               => $validated['note'],
            'learning_slot_id'   => $validated['learning_slot_id'],
            'slot_completed'     => $slotCompleted,
        ];
    }

    /**
     * Löscht einen Zeiteintrag eines Nutzers.
     *
     * @param int $entryId ID des Zeiteintrags
     * @param int $userId ID des angemeldeten Nutzers
     * @return void
     * @throws ValidationException Falls der Eintrag nicht existiert oder fremd ist
     */
    public function deleteEntry(int $entryId, int $userId): void
    {
        if ($entryId <= 0) {
            throw new ValidationException('Ungültige Zeiteintrags-ID.');
        }

        $existing = $this->timeEntryDAO->findByIdAndUserId($entryId, $userId);
        if ($existing === null) {
            throw new ValidationException('Der zu löschende Zeiteintrag wurde nicht gefunden oder du hast keine Berechtigung.');
        }

        $this->timeEntryDAO->deleteByIdAndUserId($entryId, $userId);
    }

    /**
     * Validiert und bereinigt alle Eingabefelder für eine manuelle Zeitnachbuchung.
     * Reihenfolge passend zum Formular: 1. Lerntag (entry_date), 2. Ziel/Modul im Monat (monthly_goal_id),
     * 3. Geplante Lerneinheit am Tag (learning_slot_id, optional), 4. Dauer, 5. Notiz.
     *
     * @param int $userId ID des angemeldeten Nutzers
     * @param array<string, mixed> $input Rohdaten
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD), Standard: date('Y-m-d')
     * @return array{
     *     monthly_goal_id: int,
     *     learning_slot_id: int|null,
     *     entry_date: string,
     *     tracked_seconds: int,
     *     duration_minutes: int,
     *     note: string|null,
     *     monthly_goal: array<string, mixed>
     * }
     * @throws ValidationException Bei Validierungsfehlern mit Angabe des betroffenen Felds
     */
    public function validateAndParseManualEntryInput(int $userId, array $input, ?string $today = null): array
    {
        // 1. Lerntag / Datum (entry_date) prüfen
        $entryDate = isset($input['entry_date']) && is_string($input['entry_date'])
            ? trim($input['entry_date'])
            : '';

        if ($entryDate === '') {
            throw new ValidationException(
                'Bitte gib das Datum der Lerneinheit an.',
                'entry_date'
            );
        }

        $dateObj = $this->parseStrictDate($entryDate);
        if ($dateObj === null) {
            throw new ValidationException(
                'Das Datum muss im gültigen Format JJJJ-MM-TT vorliegen.',
                'entry_date'
            );
        }

        if ($entryDate < self::MIN_ENTRY_DATE) {
            throw new ValidationException(
                'Das Datum darf nicht vor dem Jahr 2000 liegen.',
                'entry_date'
            );
        }

        $effectiveToday = $today !== null && $this->parseStrictDate($today) !== null
            ? $today
            : date('Y-m-d');

        if ($entryDate > $effectiveToday) {
            throw new ValidationException(
                'Nachbuchungen sind nur für heute oder vergangene Tage möglich.',
                'entry_date'
            );
        }

        $entryMonth = substr($entryDate, 0, 7);

        // 2. Ziel / Modul (monthly_goal_id) prüfen (inkl. Zugehörigkeit zum Monat des Lerntags)
        $rawGoalId = isset($input['monthly_goal_id']) && is_scalar($input['monthly_goal_id'])
            ? trim((string) $input['monthly_goal_id'])
            : '';

        if ($rawGoalId === '' || !preg_match('/^[1-9]\d*$/', $rawGoalId)) {
            throw new ValidationException(
                'Bitte wähle ein Modul für die Nachbuchung aus.',
                'monthly_goal_id'
            );
        }

        $monthlyGoalId = (int) $rawGoalId;
        $monthlyGoal = $this->timeEntryDAO->findMonthlyGoalByIdAndUserId($monthlyGoalId, $userId);
        if ($monthlyGoal === null) {
            throw new ValidationException(
                'Das ausgewählte Modul wurde nicht gefunden oder du hast keine Berechtigung.',
                'monthly_goal_id'
            );
        }

        $goalPlanMonth = isset($monthlyGoal['plan_month']) && is_string($monthlyGoal['plan_month'])
            ? trim($monthlyGoal['plan_month'])
            : '';

        if ($goalPlanMonth !== '' && $goalPlanMonth !== $entryMonth) {
            throw new ValidationException(
                'Das ausgewählte Modul gehört zum ' . GoalController::formatYearMonth($goalPlanMonth) . ' und passt nicht zum gewählten Datum.',
                'monthly_goal_id'
            );
        }

        // 3. Optionale Verknüpfung zu einer geplanten Lerneinheit (learning_slot_id) prüfen
        $rawSlotId = isset($input['learning_slot_id']) && is_scalar($input['learning_slot_id'])
            ? trim((string) $input['learning_slot_id'])
            : '';
        $learningSlotId = null;

        if ($rawSlotId !== '' && $rawSlotId !== '0') {
            if (!preg_match('/^[1-9]\d*$/', $rawSlotId)) {
                throw new ValidationException(
                    'Ungültige geplante Lerneinheit.',
                    'learning_slot_id'
                );
            }

            $slotId = (int) $rawSlotId;
            $slot = $this->timeEntryDAO->findLearningSlotByIdAndUserId($slotId, $userId);
            if ($slot === null) {
                throw new ValidationException(
                    'Die ausgewählte geplante Lerneinheit wurde nicht gefunden.',
                    'learning_slot_id'
                );
            }

            if ((int) ($slot['monthly_goal_id'] ?? 0) !== $monthlyGoalId) {
                throw new ValidationException(
                    'Die geplante Lerneinheit gehört zu einem anderen Modul.',
                    'learning_slot_id'
                );
            }

            $slotDate = isset($slot['slot_date']) && is_string($slot['slot_date'])
                ? trim($slot['slot_date'])
                : '';
            if ($slotDate !== '' && $slotDate !== $entryDate) {
                throw new ValidationException(
                    'Die ausgewählte geplante Lerneinheit liegt an einem anderen Tag.',
                    'learning_slot_id'
                );
            }

            $learningSlotId = $slotId;
        }

        // 4. Dauer (duration_hours / duration_minutes) prüfen und in Sekunden umrechnen
        $hasHoursField = array_key_exists('duration_hours', $input);
        $rawHours = isset($input['duration_hours']) && is_scalar($input['duration_hours'])
            ? trim((string) $input['duration_hours'])
            : '';
        $rawMinutes = isset($input['duration_minutes']) && is_scalar($input['duration_minutes'])
            ? trim((string) $input['duration_minutes'])
            : '';

        $hours = 0;
        if ($rawHours !== '') {
            if (!preg_match('/^\d+$/', $rawHours)) {
                throw new ValidationException(
                    'Die Stunden müssen eine ganze Zahl ab 0 sein.',
                    'duration_hours'
                );
            }
            $hours = (int) $rawHours;
            if ($hours > 24) {
                throw new ValidationException(
                    'Die nachgebuchte Lernzeit darf maximal 24 Stunden pro Eintrag betragen.',
                    'duration_hours'
                );
            }
        }

        $minutes = 0;
        if ($rawMinutes !== '') {
            if (!preg_match('/^\d+$/', $rawMinutes)) {
                throw new ValidationException(
                    'Die Minuten müssen eine ganze Zahl ab 0 sein.',
                    'duration_minutes'
                );
            }
            $minutes = (int) $rawMinutes;
            if ($hasHoursField && $minutes >= 60) {
                throw new ValidationException(
                    'Die Minuten müssen zwischen 0 und 59 liegen.',
                    'duration_minutes'
                );
            }
        }

        $totalMinutes = ($hours * 60) + $minutes;

        if ($totalMinutes < self::MIN_DURATION_MINUTES) {
            throw new ValidationException(
                'Bitte gib eine Lerndauer von mindestens einer Minute an.',
                'duration_minutes'
            );
        }

        if ($totalMinutes > self::MAX_DURATION_MINUTES) {
            throw new ValidationException(
                'Die nachgebuchte Lernzeit darf maximal 24 Stunden pro Eintrag betragen.',
                $hasHoursField ? 'duration_hours' : 'duration_minutes'
            );
        }

        $trackedSeconds = $totalMinutes * 60;

        // 5. Optionale Notiz (note) prüfen
        $note = isset($input['note']) && is_string($input['note'])
            ? trim($input['note'])
            : '';

        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new ValidationException(
                'Die Notiz darf maximal ' . self::MAX_NOTE_LENGTH . ' Zeichen lang sein.',
                'note'
            );
        }

        return [
            'monthly_goal_id'  => $monthlyGoalId,
            'learning_slot_id' => $learningSlotId,
            'entry_date'       => $entryDate,
            'tracked_seconds'  => $trackedSeconds,
            'duration_minutes' => $totalMinutes,
            'note'             => $note !== '' ? $note : null,
            'monthly_goal'     => $monthlyGoal,
        ];
    }

    /**
     * Liefert die auswählbaren Module / Zwischenziele eines Nutzers gruppiert nach Semester (Lernziel).
     *
     * @param int $userId ID des Nutzers
     * @return list<array{
     *     goal_id: int,
     *     goal_title: string,
     *     items: list<array<string, mixed>>
     * }>
     */
    public function getSelectableGoalsGroupedBySemester(int $userId): array
    {
        $monthlyGoals = $this->timeEntryDAO->findSelectableMonthlyGoalsByUserId($userId);

        return self::groupMonthlyGoalsBySemester($monthlyGoals);
    }

    /**
     * Liefert alle geplanten Lerneinheiten eines Nutzers aufbereitet für die tagesbezogene Auswahl im Modal.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>>
     */
    public function getSelectableLearningSlots(int $userId): array
    {
        $slots = $this->timeEntryDAO->findLearningSlotsByUserId($userId);

        foreach ($slots as &$slot) {
            $slot['option_label'] = self::formatLearningSlotOptionLabel($slot);
        }
        unset($slot);

        return $slots;
    }

    /**
     * Formatiert eine geplante Lerneinheit als lesbaren Option-Text für das Auswahlfeld im Modal.
     *
     * @param array<string, mixed> $slot
     * @return string
     */
    public static function formatLearningSlotOptionLabel(array $slot): string
    {
        $startTime = substr((string) ($slot['start_time'] ?? '00:00'), 0, 5);
        $endTime = substr((string) ($slot['end_time'] ?? '00:00'), 0, 5);
        $durationMinutes = max(1, (int) ($slot['duration_minutes'] ?? 60));
        $durationLabel = self::formatDurationSeconds($durationMinutes * 60);
        $notes = trim((string) ($slot['notes'] ?? ''));
        $isCompleted = ((int) ($slot['is_completed'] ?? 0)) === 1;

        $label = $startTime . '–' . $endTime . ' Uhr · ' . $durationLabel;
        if ($notes !== '') {
            $label .= ' · ' . $notes;
        }
        if ($isCompleted) {
            $label .= ' · erledigt';
        }

        return $label;
    }

    /**
     * Liefert die zuletzt erfassten Zeiteinträge eines Nutzers mit formatierter Dauer.
     *
     * @param int $userId ID des Nutzers
     * @param int $limit Maximale Anzahl
     * @return list<array<string, mixed>>
     */
    public function listRecentEntries(int $userId, int $limit = 10): array
    {
        $entries = $this->timeEntryDAO->findRecentByUserId($userId, $limit);

        foreach ($entries as &$entry) {
            $seconds = (int) ($entry['tracked_seconds'] ?? 0);
            $entry['formatted_duration'] = self::formatDurationSeconds($seconds);
            $entry['formatted_month'] = isset($entry['plan_month']) && is_string($entry['plan_month'])
                ? GoalController::formatYearMonth($entry['plan_month'], true)
                : '';
        }
        unset($entry);

        return $entries;
    }

    /**
     * Gruppiert eine flache Liste von Modulen / Zwischenzielen nach ihrem übergeordneten Semester
     * und ergänzt Anzeige-Labels für das Auswahlfeld im Modal.
     *
     * @param list<array<string, mixed>> $monthlyGoals
     * @return list<array{
     *     goal_id: int,
     *     goal_title: string,
     *     items: list<array<string, mixed>>
     * }>
     */
    public static function groupMonthlyGoalsBySemester(array $monthlyGoals): array
    {
        $groups = [];

        foreach ($monthlyGoals as $mg) {
            $goalId = (int) ($mg['goal_id'] ?? 0);
            $goalTitle = (string) ($mg['parent_goal_title'] ?? 'Sonstige Ziele');
            $planMonth = (string) ($mg['plan_month'] ?? '');
            $monthLabel = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $planMonth)
                ? GoalController::formatYearMonth($planMonth, true)
                : $planMonth;

            $trackedHours = round(((int) ($mg['tracked_seconds'] ?? 0)) / 3600.0, 1);
            $plannedHours = round((float) ($mg['planned_hours'] ?? 0.0), 1);

            $mg['month_label_short'] = $monthLabel;
            $mg['tracked_hours'] = $trackedHours;
            $mg['option_label'] = (string) ($mg['title'] ?? '')
                . ' · ' . number_format($trackedHours, 1, ',', '.') . ' / ' . number_format($plannedHours, 1, ',', '.') . ' h';

            if (!isset($groups[$goalId])) {
                $groups[$goalId] = [
                    'goal_id'    => $goalId,
                    'goal_title' => $goalTitle,
                    'items'      => [],
                ];
            }

            $groups[$goalId]['items'][] = $mg;
        }

        return array_values($groups);
    }

    /**
     * Formatiert eine Sekundenzahl in eine gut lesbare deutsche Dauer (z. B. "1 Std. 30 Min." oder "45 Min.").
     *
     * @param int $seconds Dauer in Sekunden
     * @return string
     */
    public static function formatDurationSeconds(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 Min.';
        }

        $totalMinutes = (int) round($seconds / 60);
        if ($totalMinutes <= 0) {
            $totalMinutes = 1;
        }

        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        if ($hours > 0 && $minutes > 0) {
            return $hours . ' Std. ' . $minutes . ' Min.';
        }

        if ($hours > 0) {
            return $hours . ' Std.';
        }

        return $minutes . ' Min.';
    }

    /**
     * Prüft ein Datum streng auf das Format YYYY-MM-DD und kalendarische Gültigkeit.
     */
    private function parseStrictDate(string $dateString): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateString);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d') === $dateString ? $date : null;
    }
}
