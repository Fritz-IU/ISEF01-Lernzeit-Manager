<?php
// src/Controllers/GoalController.php

declare(strict_types=1);

namespace Controllers;

use Classes\ValidationException;
use DAO\GoalDAO;
use DateTimeImmutable;

require_once __DIR__ . '/../Classes/ValidationException.php';
require_once __DIR__ . '/../DAO/GoalDAO.php';

/**
 * Controller für die 6-Monats-Lernzielplanung.
 * Validiert Benutzereingaben, setzt Geschäftsregeln durch und interagiert mit dem GoalDAO.
 */
class GoalController
{
    public const MAX_TITLE_LENGTH = 150;
    public const MAX_DESCRIPTION_LENGTH = 2000;
    public const MIN_TARGET_HOURS = 0.5;
    public const MAX_TARGET_HOURS = 1500.0;
    public const MAX_MONTHLY_HOURS = 744.0;
    public const MAX_SLOT_NOTES_LENGTH = 255;
    public const MIN_SLOT_MINUTES = 10;

    private const DAY_NAMES_LONG = [
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag',
        6 => 'Samstag',
        7 => 'Sonntag',
    ];

    private const DAY_NAMES_SHORT = [
        1 => 'Mo',
        2 => 'Di',
        3 => 'Mi',
        4 => 'Do',
        5 => 'Fr',
        6 => 'Sa',
        7 => 'So',
    ];

    private const MONTH_NAMES_LONG = [
        1  => 'Januar',
        2  => 'Februar',
        3  => 'März',
        4  => 'April',
        5  => 'Mai',
        6  => 'Juni',
        7  => 'Juli',
        8  => 'August',
        9  => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Dezember',
    ];

    private const MONTH_NAMES_SHORT = [
        1  => 'Jan',
        2  => 'Feb',
        3  => 'Mär',
        4  => 'Apr',
        5  => 'Mai',
        6  => 'Jun',
        7  => 'Jul',
        8  => 'Aug',
        9  => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Dez',
    ];

    private GoalDAO $goalDAO;

    /**
     * Dependency Injection des GoalDAO.
     */
    public function __construct(GoalDAO $goalDAO)
    {
        $this->goalDAO = $goalDAO;
    }

    /**
     * Liefert alle Lernziele eines Nutzers inklusive Erledigt-Status und formatiertem Abschlussdatum.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>>
     */
    public function listGoals(int $userId): array
    {
        $goals = $this->goalDAO->findAllByUserId($userId);

        foreach ($goals as &$goal) {
            $completedAt = !empty($goal['completed_at']) && is_string($goal['completed_at'])
                ? trim($goal['completed_at'])
                : null;
            $monthlyCount = (int) ($goal['monthly_goal_count'] ?? 0);
            $completedMonthlyCount = (int) ($goal['completed_monthly_goal_count'] ?? 0);
            $allMonthlyDone = $monthlyCount > 0 && $completedMonthlyCount === $monthlyCount;

            $goal['completed_at'] = $completedAt;
            $goal['is_completed'] = $completedAt !== null || $allMonthlyDone;
            $goal['completed_at_formatted'] = self::formatDateGerman($completedAt);
            $goal['completed_monthly_goal_count'] = $completedMonthlyCount;
        }
        unset($goal);

        return $goals;
    }

    /**
     * Lädt ein einzelnes Lernziel des Nutzers.
     *
     * @param int $goalId
     * @param int $userId
     * @return array<string, mixed>|null
     */
    public function getGoal(int $goalId, int $userId): ?array
    {
        return $this->goalDAO->findByIdAndUserId($goalId, $userId);
    }

    /**
     * Erstellt ein neues Lernziel nach erfolgreicher Validierung.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten (z. B. aus $_POST)
     * @return int ID des neu erstellten Lernziels
     * @throws ValidationException Bei ungültigen Eingaben
     */
    public function createGoal(int $userId, array $input): int
    {
        $validated = $this->validateAndParseInput($input);

        return $this->goalDAO->create(
            $userId,
            $validated['title'],
            $validated['description'],
            $validated['target_hours'],
            $validated['start_date'],
            $validated['end_date']
        );
    }

    /**
     * Aktualisiert ein bestehendes Lernziel nach erfolgreicher Validierung.
     *
     * @param int $goalId ID des Lernziels
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten
     * @return bool True bei Erfolg
     * @throws ValidationException Bei ungültigen Eingaben oder unberechtigtem Zugriff
     */
    public function updateGoal(int $goalId, int $userId, array $input): bool
    {
        $existing = $this->goalDAO->findByIdAndUserId($goalId, $userId);
        if ($existing === null) {
            throw new ValidationException("Das angegebene Lernziel existiert nicht oder gehört einem anderen Benutzer.");
        }

        $validated = $this->validateAndParseInput($input);

        return $this->goalDAO->update(
            $goalId,
            $userId,
            $validated['title'],
            $validated['description'],
            $validated['target_hours'],
            $validated['start_date'],
            $validated['end_date']
        );
    }

    /**
     * Löscht ein Lernziel eines Nutzers.
     *
     * @param int $goalId ID des Lernziels
     * @param int $userId ID des Nutzers
     * @return bool True bei erfolgreichem Löschen
     * @throws ValidationException Wenn Lernziel nicht existiert
     */
    public function deleteGoal(int $goalId, int $userId): bool
    {
        $existing = $this->goalDAO->findByIdAndUserId($goalId, $userId);
        if ($existing === null) {
            throw new ValidationException("Das angegebene Lernziel existiert nicht oder gehört einem anderen Benutzer.");
        }

        return $this->goalDAO->delete($goalId, $userId);
    }

    /**
     * Summe aller geplanten Lernstunden eines Nutzers.
     */
    public function getTotalHours(int $userId): float
    {
        return $this->goalDAO->getTotalTargetHours($userId);
    }

    /**
     * Validiert und bereinigt die Eingabedaten für Lernziele.
     *
     * @param array<string, mixed> $input
     * @return array{title: string, description: ?string, target_hours: float, start_date: string, end_date: string}
     * @throws ValidationException
     */
    public function validateAndParseInput(array $input): array
    {
        // 1. Modul / Titel (Pflichtfeld)
        $titleRaw = $input['title'] ?? null;
        if (!is_string($titleRaw) || trim($titleRaw) === '') {
            throw new ValidationException("Bitte gib einen Modul- bzw. Lernzielnamen an.", 'title');
        }
        $title = trim($titleRaw);
        if (mb_strlen($title, 'UTF-8') > self::MAX_TITLE_LENGTH) {
            throw new ValidationException("Der Name darf höchstens " . self::MAX_TITLE_LENGTH . " Zeichen lang sein.", 'title');
        }

        // 2. Zielstunden (Pflichtfeld, positiv)
        $hoursRaw = $input['target_hours'] ?? null;
        if ($hoursRaw === null || (is_string($hoursRaw) && trim($hoursRaw) === '')) {
            throw new ValidationException("Bitte gib die geplanten Zielstunden an.", 'target_hours');
        }

        if (!is_string($hoursRaw) && !is_int($hoursRaw) && !is_float($hoursRaw)) {
            throw new ValidationException("Die Zielstunden müssen eine Zahl sein.", 'target_hours');
        }

        // Kommas durch Punkte ersetzen (deutsches Eingabeformat unterstützen)
        if (is_string($hoursRaw)) {
            $hoursRaw = str_replace(',', '.', trim($hoursRaw));
        }

        if (!is_numeric($hoursRaw)) {
            throw new ValidationException("Die Zielstunden müssen eine Zahl sein.", 'target_hours');
        }

        $targetHours = (float) $hoursRaw;
        if (!is_finite($targetHours) || $targetHours < self::MIN_TARGET_HOURS || $targetHours > self::MAX_TARGET_HOURS) {
            throw new ValidationException(sprintf(
                "Die Zielstunden müssen zwischen %.1f und %.1f Stunden liegen.",
                self::MIN_TARGET_HOURS,
                self::MAX_TARGET_HOURS
            ), 'target_hours');
        }

        // 3. Startdatum (Pflichtfeld, Format Y-m-d)
        $startDateRaw = $input['start_date'] ?? null;
        if (!is_string($startDateRaw) || trim($startDateRaw) === '') {
            throw new ValidationException("Bitte gib ein Startdatum an.", 'start_date');
        }
        $startDate = trim($startDateRaw);
        $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if (!$startDt || $startDt->format('Y-m-d') !== $startDate) {
            throw new ValidationException("Das Startdatum muss im gültigen Format JJJJ-MM-TT vorliegen.", 'start_date');
        }

        // 4. Enddatum (Pflichtfeld, Format Y-m-d)
        $endDateRaw = $input['end_date'] ?? null;
        if (!is_string($endDateRaw) || trim($endDateRaw) === '') {
            throw new ValidationException("Bitte gib ein Enddatum an.", 'end_date');
        }
        $endDate = trim($endDateRaw);
        $endDt = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
        if (!$endDt || $endDt->format('Y-m-d') !== $endDate) {
            throw new ValidationException("Das Enddatum muss im gültigen Format JJJJ-MM-TT vorliegen.", 'end_date');
        }

        // Logische Prüfung: Enddatum darf nicht vor dem Startdatum liegen
        if ($endDt < $startDt) {
            throw new ValidationException("Das Enddatum darf zeitlich nicht vor dem Startdatum liegen.", 'end_date');
        }

        // 5. Beschreibung (optional)
        $description = null;
        if (isset($input['description']) && is_string($input['description'])) {
            $descTrimmed = trim($input['description']);
            if ($descTrimmed !== '') {
                if (mb_strlen($descTrimmed, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
                    throw new ValidationException("Die Beschreibung darf höchstens " . self::MAX_DESCRIPTION_LENGTH . " Zeichen lang sein.", 'description');
                }
                $description = $descTrimmed;
            }
        }

        return [
            'title'        => $title,
            'description'  => $description,
            'target_hours' => round($targetHours, 2),
            'start_date'   => $startDate,
            'end_date'     => $endDate,
        ];
    }

    /**
     * Speichert oder löscht das monatliche Soll-Zeitbudget eines Nutzers.
     * Wird 0 oder ein leerer Wert übergeben, wird das Budget für diesen Monat zurückgesetzt.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten (year_month, budget_hours)
     * @return string Validierter Monat im Format YYYY-MM
     * @throws ValidationException Bei ungültigen Eingaben
     */
    public function saveMonthlyBudget(int $userId, array $input): string
    {
        $yearMonth = $this->validateYearMonth($input['year_month'] ?? null);

        $budgetRaw = $input['budget_hours'] ?? null;
        if ($budgetRaw === null || (is_string($budgetRaw) && trim($budgetRaw) === '')) {
            $this->goalDAO->deleteMonthlyBudget($userId, $yearMonth);
            return $yearMonth;
        }

        if (!is_string($budgetRaw) && !is_int($budgetRaw) && !is_float($budgetRaw)) {
            throw new ValidationException("Das monatliche Zeitbudget muss eine Zahl sein.", 'budget_hours');
        }

        if (is_string($budgetRaw)) {
            $budgetRaw = str_replace(',', '.', trim($budgetRaw));
        }

        if (!is_numeric($budgetRaw)) {
            throw new ValidationException("Das monatliche Zeitbudget muss eine Zahl sein.", 'budget_hours');
        }

        $budgetFloat = (float) $budgetRaw;
        if (!is_finite($budgetFloat)) {
            throw new ValidationException("Das monatliche Zeitbudget muss eine Zahl sein.", 'budget_hours');
        }

        $budgetHours = round($budgetFloat, 2);
        if ($budgetHours < 0.0 || $budgetHours > self::MAX_MONTHLY_HOURS) {
            throw new ValidationException(sprintf(
                "Das monatliche Zeitbudget muss zwischen 0,0 und %.1f Stunden liegen.",
                self::MAX_MONTHLY_HOURS
            ), 'budget_hours');
        }

        if ($budgetHours === 0.0) {
            $this->goalDAO->deleteMonthlyBudget($userId, $yearMonth);
        } else {
            $this->goalDAO->upsertMonthlyBudget($userId, $yearMonth, $budgetHours);
        }

        return $yearMonth;
    }

    /**
     * Legt ein neues Zwischenziel (Monatsziel) für einen bestimmten Monat an.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten aus $_POST
     * @return string Monat des angelegten Zwischenziels (YYYY-MM)
     * @throws ValidationException Bei ungültigen Eingaben
     */
    public function createMonthlyGoal(int $userId, array $input): string
    {
        $validated = $this->validateAndParseMonthlyGoalInput($userId, $input);

        $this->goalDAO->createMonthlyGoal(
            $userId,
            $validated['goal_id'],
            $validated['year_month'],
            $validated['title'],
            $validated['description'],
            $validated['planned_hours']
        );

        // Ein neues (offenes) Monatsziel setzt das übergeordnete Semesterziel wieder auf offen
        $this->goalDAO->setGoalCompleted($validated['goal_id'], $userId, null);

        return $validated['year_month'];
    }

    /**
     * Aktualisiert ein bestehendes Zwischenziel (Monatsziel).
     *
     * @param int $monthlyGoalId ID des Zwischenziels
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten aus $_POST
     * @return string Monat des aktualisierten Zwischenziels (YYYY-MM)
     * @throws ValidationException Bei ungültigen Eingaben oder fremdem Datensatz
     */
    public function updateMonthlyGoal(int $monthlyGoalId, int $userId, array $input): string
    {
        $existing = $this->goalDAO->findMonthlyGoalByIdAndUserId($monthlyGoalId, $userId);
        if ($existing === null) {
            throw new ValidationException("Das angegebene Zwischenziel existiert nicht oder gehört einem anderen Benutzer.");
        }

        $validated = $this->validateAndParseMonthlyGoalInput($userId, $input);

        $this->goalDAO->updateMonthlyGoal(
            $monthlyGoalId,
            $userId,
            $validated['goal_id'],
            $validated['year_month'],
            $validated['title'],
            $validated['description'],
            $validated['planned_hours']
        );

        if (empty($existing['completed_at'])) {
            $this->goalDAO->setGoalCompleted($validated['goal_id'], $userId, null);
        }

        return $validated['year_month'];
    }

    /**
     * Löscht ein Zwischenziel eines Nutzers.
     *
     * @param int $monthlyGoalId ID des Zwischenziels
     * @param int $userId ID des Nutzers
     * @return string Monat des gelöschten Zwischenziels (YYYY-MM)
     * @throws ValidationException Wenn Zwischenziel nicht existiert
     */
    public function deleteMonthlyGoal(int $monthlyGoalId, int $userId): string
    {
        $existing = $this->goalDAO->findMonthlyGoalByIdAndUserId($monthlyGoalId, $userId);
        if ($existing === null) {
            throw new ValidationException("Das angegebene Zwischenziel existiert nicht oder gehört einem anderen Benutzer.");
        }

        $this->goalDAO->deleteMonthlyGoal($monthlyGoalId, $userId);

        return (string) $existing['year_month'];
    }

    /**
     * Validiert und bereinigt die Eingabedaten für ein monatliches Zwischenziel.
     * Eine Überschreitung der 6-Monats-Zielstunden oder des Monatsbudgets ist fachlich erlaubt
     * und wird in der Oberfläche visuell hervorgehoben.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten
     * @return array{goal_id: int, year_month: string, title: string, description: ?string, planned_hours: float}
     * @throws ValidationException
     */
    public function validateAndParseMonthlyGoalInput(int $userId, array $input): array
    {
        // 1. Zugehöriges Lernziel prüfen (nur echte Ganzzahlen zulassen)
        $goalIdRaw = $input['goal_id'] ?? null;
        if (is_int($goalIdRaw)) {
            $goalId = $goalIdRaw;
        } elseif (is_string($goalIdRaw)) {
            $filteredId = filter_var(trim($goalIdRaw), FILTER_VALIDATE_INT);
            $goalId = $filteredId !== false ? $filteredId : 0;
        } else {
            $goalId = 0;
        }
        if ($goalId <= 0) {
            throw new ValidationException("Bitte wähle ein zugehöriges Lernziel aus.", 'goal_id');
        }

        $parentGoal = $this->goalDAO->findByIdAndUserId($goalId, $userId);
        if ($parentGoal === null) {
            throw new ValidationException("Das ausgewählte Lernziel existiert nicht oder gehört einem anderen Benutzer.", 'goal_id');
        }

        // 2. Planungsmonat prüfen (YYYY-MM)
        $yearMonth = $this->validateYearMonth($input['year_month'] ?? null);

        // Der Monat muss im Zeitraum des Lernziels liegen (Vergleich auf Monatsebene)
        $parentStartMonth = substr((string) $parentGoal['start_date'], 0, 7);
        $parentEndMonth = substr((string) $parentGoal['end_date'], 0, 7);
        if ($yearMonth < $parentStartMonth || $yearMonth > $parentEndMonth) {
            throw new ValidationException(sprintf(
                "Der Monat muss im Zeitraum des Lernziels liegen (%s bis %s).",
                self::formatYearMonth($parentStartMonth),
                self::formatYearMonth($parentEndMonth)
            ), 'year_month');
        }

        // 3. Bezeichnung des Zwischenziels (Pflichtfeld)
        $titleRaw = $input['title'] ?? null;
        if (!is_string($titleRaw) || trim($titleRaw) === '') {
            throw new ValidationException("Bitte gib eine Bezeichnung für das Zwischenziel an.", 'title');
        }
        $title = trim($titleRaw);
        if (mb_strlen($title, 'UTF-8') > self::MAX_TITLE_LENGTH) {
            throw new ValidationException("Die Bezeichnung des Zwischenziels darf höchstens " . self::MAX_TITLE_LENGTH . " Zeichen lang sein.", 'title');
        }

        // 4. Geplante Lernzeit im Monat (Pflichtfeld, positiv)
        $hoursRaw = $input['planned_hours'] ?? null;
        if ($hoursRaw === null || (is_string($hoursRaw) && trim($hoursRaw) === '')) {
            throw new ValidationException("Bitte gib die geplanten Lernstunden für dieses Zwischenziel an.", 'planned_hours');
        }

        if (!is_string($hoursRaw) && !is_int($hoursRaw) && !is_float($hoursRaw)) {
            throw new ValidationException("Die geplanten Lernstunden müssen eine Zahl sein.", 'planned_hours');
        }

        if (is_string($hoursRaw)) {
            $hoursRaw = str_replace(',', '.', trim($hoursRaw));
        }

        if (!is_numeric($hoursRaw)) {
            throw new ValidationException("Die geplanten Lernstunden müssen eine Zahl sein.", 'planned_hours');
        }

        $plannedHours = (float) $hoursRaw;
        if (!is_finite($plannedHours) || $plannedHours < self::MIN_TARGET_HOURS || $plannedHours > self::MAX_MONTHLY_HOURS) {
            throw new ValidationException(sprintf(
                "Die geplanten Lernstunden im Monat müssen zwischen %.1f und %.1f Stunden liegen.",
                self::MIN_TARGET_HOURS,
                self::MAX_MONTHLY_HOURS
            ), 'planned_hours');
        }

        // 5. Optionale Beschreibung / Notiz
        $description = null;
        if (isset($input['description']) && is_string($input['description'])) {
            $descTrimmed = trim($input['description']);
            if ($descTrimmed !== '') {
                if (mb_strlen($descTrimmed, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
                    throw new ValidationException("Die Notiz darf höchstens " . self::MAX_DESCRIPTION_LENGTH . " Zeichen lang sein.", 'description');
                }
                $description = $descTrimmed;
            }
        }

        return [
            'goal_id'       => $goalId,
            'year_month'    => $yearMonth,
            'title'         => $title,
            'description'   => $description,
            'planned_hours' => round($plannedHours, 2),
        ];
    }

    /**
     * Prüft, ob ein Monatswert im gültigen Format JJJJ-MM vorliegt.
     *
     * @param mixed $yearMonthRaw
     * @return string Validierter Monat im Format YYYY-MM
     * @throws ValidationException
     */
    public function validateYearMonth(mixed $yearMonthRaw): string
    {
        if (!is_string($yearMonthRaw) || trim($yearMonthRaw) === '') {
            throw new ValidationException("Bitte wähle einen gültigen Planungsmonat aus.", 'year_month');
        }

        $yearMonth = trim($yearMonthRaw);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
            throw new ValidationException("Der Monat muss im gültigen Format JJJJ-MM vorliegen.", 'year_month');
        }

        return $yearMonth;
    }

    /**
     * Formatiert einen Monat (YYYY-MM) als deutschen Monatsnamen mit Jahr (z. B. "Oktober 2026" oder "Okt 2026").
     */
    public static function formatYearMonth(string $yearMonth, bool $short = false): string
    {
        $parts = explode('-', $yearMonth);
        if (count($parts) !== 2) {
            return $yearMonth;
        }

        $year = (int) $parts[0];
        $month = (int) $parts[1];
        $names = $short ? self::MONTH_NAMES_SHORT : self::MONTH_NAMES_LONG;
        $monthName = $names[$month] ?? $yearMonth;

        return $monthName . ' ' . $year;
    }

    /**
     * Bereitet alle Daten für die monatliche Detail- und Grobplanung (Tab-Ansicht, Budgets,
     * prozentuale Workload-Verteilung und Zwischenziele des gewählten Monats) auf.
     *
     * @param int $userId ID des Nutzers
     * @param list<array<string, mixed>> $goals Bereits geladene 6-Monats-Lernziele
     * @param string|null $requestedMonth Vom Nutzer ausgewählter Monat (GET-Parameter month)
     * @return array<string, mixed>
     */
    public function buildMonthlyPlanningData(int $userId, array $goals, ?string $requestedMonth = null): array
    {
        $currentCalendarMonth = date('Y-m');

        // 1. Ausgewählten Monat bestimmen (Fallback: aktueller Kalendermonat)
        $selectedMonth = $currentCalendarMonth;
        if ($requestedMonth !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', trim($requestedMonth))) {
            $selectedMonth = trim($requestedMonth);
        }

        // 2. Standard-Startmonat des 6-Monats-Fensters ermitteln (frühestes Startdatum oder aktueller Monat)
        $baseStartMonth = $currentCalendarMonth;
        if (!empty($goals)) {
            $earliestStart = (string) $goals[0]['start_date'];
            foreach ($goals as $g) {
                if ((string) $g['start_date'] < $earliestStart) {
                    $earliestStart = (string) $g['start_date'];
                }
            }
            $baseStartMonth = substr($earliestStart, 0, 7);
        }

        // Falls der gewählte Monat außerhalb des 6-Monats-Standardfensters liegt,
        // verschiebt sich die 6-Monats-Tab-Leiste passend zum gewählten Monat.
        $baseStartDt = DateTimeImmutable::createFromFormat('Y-m-d', $baseStartMonth . '-01') ?: new DateTimeImmutable('first day of this month');
        $baseEndDt = $baseStartDt->modify('+5 months');
        $selectedDt = DateTimeImmutable::createFromFormat('Y-m-d', $selectedMonth . '-01') ?: $baseStartDt;

        if ($selectedDt < $baseStartDt) {
            $windowStartDt = $selectedDt;
        } elseif ($selectedDt > $baseEndDt) {
            $windowStartDt = $selectedDt->modify('-5 months');
        } else {
            $windowStartDt = $baseStartDt;
        }

        // 3. Monatsbudgets und geplante Stunden je Monat aus der Datenbank abrufen
        $budgetsByMonth = $this->goalDAO->findAllMonthlyBudgetsByUserId($userId);
        $plannedByMonth = $this->goalDAO->getMonthlyPlannedHoursByUserId($userId);

        // Gesamtsumme aller geplanten Monatsstunden (für die prozentuale Workload-Verteilung über das Semester)
        $totalPlannedAllMonths = array_sum($plannedByMonth);

        // 4. 6-Monats-Tab-Leiste aufbauen
        $monthsWindow = [];
        for ($i = 0; $i < 6; $i++) {
            $mDt = $windowStartDt->modify("+{$i} months");
            $ym = $mDt->format('Y-m');

            $budgetHours = $budgetsByMonth[$ym] ?? null;
            $plannedHours = $plannedByMonth[$ym] ?? 0.0;
            $isOverBudget = $budgetHours !== null && $plannedHours > $budgetHours;
            $budgetUtilizationPercent = ($budgetHours !== null && $budgetHours > 0)
                ? round(($plannedHours / $budgetHours) * 100, 1)
                : null;
            $workloadSharePercent = $totalPlannedAllMonths > 0
                ? round(($plannedHours / $totalPlannedAllMonths) * 100, 1)
                : 0.0;

            $monthsWindow[$ym] = [
                'year_month'                 => $ym,
                'label_long'                 => self::formatYearMonth($ym, false),
                'label_short'                => self::formatYearMonth($ym, true),
                'is_current_calendar_month'  => ($ym === $currentCalendarMonth),
                'is_selected'                => ($ym === $selectedMonth),
                'budget_hours'               => $budgetHours,
                'planned_hours'              => $plannedHours,
                'remaining_budget_hours'     => $budgetHours !== null ? round($budgetHours - $plannedHours, 2) : null,
                'budget_utilization_percent' => $budgetUtilizationPercent,
                'is_over_budget'             => $isOverBudget,
                'workload_share_percent'     => $workloadSharePercent,
            ];
        }

        // Details des aktuell ausgewählten Monats (liegt immer in $monthsWindow)
        $selectedMonthData = $monthsWindow[$selectedMonth];

        // 5. Zwischenziele für den ausgewählten Monat laden und mit Überplanungs-Status des 6-Monats-Ziels anreichern
        $allocatedByGoalId = [];
        foreach ($goals as $g) {
            $allocatedByGoalId[(int) $g['id']] = (float) ($g['allocated_hours'] ?? 0.0);
        }

        $monthlyGoalsRaw = $this->goalDAO->findMonthlyGoalsByUserId($userId, $selectedMonth);
        $monthlyGoals = [];
        $selectedBudget = $selectedMonthData['budget_hours'];
        $selectedPlanned = (float) $selectedMonthData['planned_hours'];
        $totalScheduledInMonth = 0.0;
        $totalTrackedInMonth = 0.0;

        foreach ($monthlyGoalsRaw as $mg) {
            $gId = (int) $mg['goal_id'];
            $pHours = (float) $mg['planned_hours'];
            $parentTarget = (float) $mg['parent_target_hours'];
            $parentAllocated = $allocatedByGoalId[$gId] ?? $pHours;
            $scheduledHours = (float) ($mg['scheduled_hours'] ?? 0.0);
            $completedHours = (float) ($mg['completed_hours'] ?? 0.0);
            $slotCount = (int) ($mg['slot_count'] ?? 0);
            $completedSlotCount = (int) ($mg['completed_slot_count'] ?? 0);
            $allSlotsDone = $slotCount > 0 && $completedSlotCount === $slotCount;
            $trackedSeconds = (int) ($mg['tracked_seconds'] ?? 0);
            $trackedHours = round($trackedSeconds / 3600.0, 2);
            $plannedSeconds = (int) round($pHours * 3600);
            $completedAt = !empty($mg['completed_at']) && is_string($mg['completed_at'])
                ? trim($mg['completed_at'])
                : null;
            $isGoalCompleted = $completedAt !== null
                || $allSlotsDone
                || ($slotCount === 0 && $plannedSeconds > 0 && $trackedSeconds >= $plannedSeconds);

            $totalScheduledInMonth += $scheduledHours;
            $totalTrackedInMonth += $trackedHours;

            $mg['parent_allocated_hours'] = $parentAllocated;
            $mg['is_parent_over_allocated'] = $parentAllocated > $parentTarget;
            $mg['scheduled_hours'] = $scheduledHours;
            $mg['completed_hours'] = $completedHours;
            $mg['tracked_seconds'] = $trackedSeconds;
            $mg['tracked_hours'] = $trackedHours;
            $mg['tracked_formatted'] = self::formatTrackedDuration($trackedSeconds);
            $mg['tracked_percent'] = $pHours > 0 ? min(100.0, round(($trackedHours / $pHours) * 100, 1)) : 0.0;
            $mg['completed_at'] = $completedAt;
            $mg['completed_at_formatted'] = self::formatDateGerman($completedAt);
            $mg['is_completed'] = $isGoalCompleted;
            $mg['slot_count'] = $slotCount;
            $mg['completed_slot_count'] = $completedSlotCount;
            $mg['is_over_scheduled'] = $scheduledHours > $pHours;
            $mg['scheduled_percent'] = $pHours > 0 ? round(($scheduledHours / $pHours) * 100, 1) : 0.0;
            $mg['share_of_month_budget_percent'] = ($selectedBudget !== null && $selectedBudget > 0)
                ? round(($pHours / $selectedBudget) * 100, 1)
                : null;
            $mg['share_of_month_planned_percent'] = $selectedPlanned > 0
                ? round(($pHours / $selectedPlanned) * 100, 1)
                : 0.0;

            $monthlyGoals[] = $mg;
        }

        $selectedMonthData['scheduled_hours'] = round($totalScheduledInMonth, 2);
        $selectedMonthData['tracked_hours'] = round($totalTrackedInMonth, 2);

        return [
            'selected_month'           => $selectedMonth,
            'selected_month_data'      => $selectedMonthData,
            'prev_month'               => $selectedDt->modify('-1 month')->format('Y-m'),
            'next_month'               => $selectedDt->modify('+1 month')->format('Y-m'),
            'months_window'            => $monthsWindow,
            'monthly_goals'            => $monthlyGoals,
            'total_planned_all_months' => $totalPlannedAllMonths,
        ];
    }

    /**
     * Legt einen neuen Lern-Timeslot für ein monatliches Zwischenziel an.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten aus $_POST / JSON
     * @return array{slot_id: int, iso_week: string, year_month: string}
     * @throws ValidationException Bei ungültigen Eingaben
     */
    public function createLearningSlot(int $userId, array $input): array
    {
        $validated = $this->validateAndParseSlotInput($userId, $input);

        $slotId = $this->goalDAO->createLearningSlot(
            $userId,
            $validated['monthly_goal_id'],
            $validated['slot_date'],
            $validated['start_time'],
            $validated['end_time'],
            $validated['notes']
        );

        // Eine neu angelegte (offene) Lerneinheit setzt alle darüberliegenden Ebenen
        // (Woche, Monatsziel, Semesterziel) wieder auf offen
        if (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['completed_weeks'][$userId][$validated['iso_week']]);
        }
        $this->goalDAO->setMonthlyGoalCompleted($validated['monthly_goal_id'], $userId, null);
        if ($validated['goal_id'] > 0) {
            $this->goalDAO->setGoalCompleted($validated['goal_id'], $userId, null);
        }

        return [
            'slot_id'    => $slotId,
            'slot_date'  => $validated['slot_date'],
            'iso_week'   => $validated['iso_week'],
            'year_month' => $validated['year_month'],
        ];
    }

    /**
     * Aktualisiert einen bestehenden Lern-Timeslot eines Nutzers.
     *
     * @param int $slotId ID des Timeslots
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten
     * @return array{slot_id: int, slot_date: string, iso_week: string, year_month: string}
     * @throws ValidationException Bei ungültigen Eingaben oder fremdem Datensatz
     */
    public function updateLearningSlot(int $slotId, int $userId, array $input): array
    {
        $existing = $this->goalDAO->findLearningSlotByIdAndUserId($slotId, $userId);
        if ($existing === null) {
            throw new ValidationException("Der angegebene Timeslot existiert nicht oder gehört einem anderen Benutzer.");
        }

        $validated = $this->validateAndParseSlotInput($userId, $input);

        $this->goalDAO->updateLearningSlot(
            $slotId,
            $userId,
            $validated['monthly_goal_id'],
            $validated['slot_date'],
            $validated['start_time'],
            $validated['end_time'],
            $validated['notes']
        );

        if ((int) ($existing['is_completed'] ?? 0) === 0) {
            if (isset($_SESSION) && is_array($_SESSION)) {
                unset($_SESSION['completed_weeks'][$userId][$validated['iso_week']]);
            }
            $this->goalDAO->setMonthlyGoalCompleted($validated['monthly_goal_id'], $userId, null);
            if ($validated['goal_id'] > 0) {
                $this->goalDAO->setGoalCompleted($validated['goal_id'], $userId, null);
            }
        }

        return [
            'slot_id'    => $slotId,
            'slot_date'  => $validated['slot_date'],
            'iso_week'   => $validated['iso_week'],
            'year_month' => $validated['year_month'],
        ];
    }

    /**
     * Löscht einen Lern-Timeslot eines Nutzers.
     *
     * @param int $slotId ID des Timeslots
     * @param int $userId ID des Nutzers
     * @return array{slot_date: string, iso_week: string, year_month: string}
     * @throws ValidationException Wenn Timeslot nicht existiert
     */
    public function deleteLearningSlot(int $slotId, int $userId): array
    {
        $existing = $this->goalDAO->findLearningSlotByIdAndUserId($slotId, $userId);
        if ($existing === null) {
            throw new ValidationException("Der angegebene Timeslot existiert nicht oder gehört einem anderen Benutzer.");
        }

        $slotDate = (string) $existing['slot_date'];
        $slotDt = DateTimeImmutable::createFromFormat('Y-m-d', $slotDate) ?: new DateTimeImmutable('today');
        $isoWeek = $slotDt->format('o-\WW');
        $weekMonth = self::resolveMonthForWeek((string) $existing['plan_month'], $isoWeek) ?? (string) $existing['plan_month'];

        $this->goalDAO->deleteLearningSlot($slotId, $userId);

        return [
            'slot_date'  => $slotDate,
            'iso_week'   => $isoWeek,
            'year_month' => $weekMonth,
        ];
    }

    /**
     * Zentrale Umschalt- und Kaskadenlogik (US-5) zum manuellen und automatischen Abhaken
     * von Lerneinheiten (slot), Kalenderwochen (week), Monatszielen (monthly_goal) und Semesterzielen (goal).
     * Prüft bei vorhandener Session das CSRF-Token und gewährleistet strikte Mandantenisolation.
     *
     * @param int $userId ID des angemeldeten Nutzers
     * @param array<string, mixed> $input Eingabedaten (type/action, ID bzw. iso_week, ggf. csrf_token)
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD) für deterministische Unit-Tests
     * @return array<string, mixed>
     * @throws ValidationException Bei ungültigen Eingaben, fehlerhaftem CSRF-Token oder fremdem Datensatz
     */
    public function toggleCompletion(int $userId, array $input, ?string $today = null): array
    {
        // CSRF-Prüfung, sobald ein Session-Token vorliegt oder ein Token im Request übergeben wurde
        $sessionCsrf = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])
            ? $_SESSION['csrf_token']
            : null;
        if ($sessionCsrf !== null && $sessionCsrf !== '') {
            $submittedCsrf = isset($input['csrf_token']) && is_string($input['csrf_token'])
                ? $input['csrf_token']
                : '';
            if (!hash_equals($sessionCsrf, $submittedCsrf)) {
                throw new ValidationException(
                    'Ungültige Sitzungsanfrage. Bitte lade die Seite neu und versuche es erneut.',
                    'csrf_token'
                );
            }
        } elseif (array_key_exists('csrf_token', $input)) {
            $submittedCsrf = $input['csrf_token'];
            if (!is_string($submittedCsrf) || trim($submittedCsrf) === '') {
                throw new ValidationException(
                    'Ungültige Sitzungsanfrage. Bitte lade die Seite neu und versuche es erneut.',
                    'csrf_token'
                );
            }
        }

        $rawType = $input['target_type'] ?? $input['type'] ?? $input['action'] ?? '';
        $type = is_string($rawType) ? strtolower(trim($rawType)) : '';

        if ($type === '') {
            if (isset($input['slot_id'])) {
                $type = 'slot';
            } elseif (isset($input['iso_week']) || isset($input['week'])) {
                $type = 'week';
            } elseif (isset($input['monthly_goal_id'])) {
                $type = 'monthly_goal';
            } elseif (isset($input['goal_id'])) {
                $type = 'goal';
            }
        }

        if ($type === 'slot' || $type === 'learning_slot' || $type === 'toggle_learning_slot') {
            $slotId = $this->parsePositiveId($input['slot_id'] ?? $input['id'] ?? null, 'slot_id', 'Bitte wähle eine gültige Lerneinheit aus.');
            return $this->toggleLearningSlotCompleted($slotId, $userId, $today);
        }

        if ($type === 'week' || $type === 'toggle_week') {
            $isoWeekRaw = $input['iso_week'] ?? $input['week'] ?? null;
            $isoWeek = is_string($isoWeekRaw) ? trim($isoWeekRaw) : '';
            $reqMonth = isset($input['year_month']) && is_string($input['year_month'])
                ? trim($input['year_month'])
                : (isset($input['redirect_month']) && is_string($input['redirect_month']) ? trim($input['redirect_month']) : null);
            return $this->toggleWeekCompleted($userId, $isoWeek, $reqMonth, $today);
        }

        if ($type === 'monthly_goal' || $type === 'toggle_monthly_goal') {
            $monthlyGoalId = $this->parsePositiveId($input['monthly_goal_id'] ?? $input['id'] ?? null, 'monthly_goal_id', 'Bitte wähle ein gültiges Monatsziel aus.');
            return $this->toggleMonthlyGoalCompleted($monthlyGoalId, $userId, $today);
        }

        if ($type === 'goal' || $type === 'semester_goal' || $type === 'learning_goal' || $type === 'toggle_goal') {
            $goalId = $this->parsePositiveId($input['goal_id'] ?? $input['id'] ?? null, 'goal_id', 'Bitte wähle ein gültiges Lernziel aus.');
            return $this->toggleGoalCompleted($goalId, $userId, $today);
        }

        throw new ValidationException('Bitte gib eine gültige Zielebene zum Abhaken an.', 'target_type');
    }

    /**
     * Schaltet den Erledigt-Status (completed_at) eines 6-Monats-Semesterziels (learning_goals) um.
     *
     * @param int $goalId ID des Lernziels
     * @param int $userId ID des Nutzers
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD)
     * @return array{target_type: string, goal_id: int, is_completed: bool, completed_at: ?string, completed_at_formatted: ?string}
     * @throws ValidationException Wenn das Lernziel nicht existiert oder fremd ist
     */
    public function toggleGoalCompleted(int $goalId, int $userId, ?string $today = null): array
    {
        if ($goalId <= 0) {
            throw new ValidationException('Bitte wähle ein gültiges Lernziel aus.', 'goal_id');
        }

        $existing = $this->goalDAO->findByIdAndUserId($goalId, $userId);
        if ($existing === null) {
            throw new ValidationException('Das angegebene Lernziel existiert nicht oder gehört einem anderen Benutzer.', 'goal_id');
        }

        $effectiveDate = self::resolveCompletionDate($today);
        $isCurrentlyCompleted = !empty($existing['completed_at']);
        $newStatus = !$isCurrentlyCompleted;
        $completedAt = $newStatus ? $effectiveDate : null;

        $this->goalDAO->setGoalCompleted($goalId, $userId, $completedAt);

        return [
            'target_type'            => 'goal',
            'goal_id'                => $goalId,
            'is_completed'           => $newStatus,
            'completed_at'           => $completedAt,
            'completed_at_formatted' => self::formatDateGerman($completedAt),
        ];
    }

    /**
     * Schaltet den Erledigt-Status (completed_at) eines monatlichen Zwischenziels (monthly_goals) um
     * und kaskadiert den Status automatisch auf das übergeordnete Semesterziel.
     *
     * @param int $monthlyGoalId ID des Zwischenziels
     * @param int $userId ID des Nutzers
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD)
     * @return array<string, mixed>
     * @throws ValidationException Wenn das Zwischenziel nicht existiert oder fremd ist
     */
    public function toggleMonthlyGoalCompleted(int $monthlyGoalId, int $userId, ?string $today = null): array
    {
        if ($monthlyGoalId <= 0) {
            throw new ValidationException('Bitte wähle ein gültiges Monatsziel aus.', 'monthly_goal_id');
        }

        $existing = $this->goalDAO->findMonthlyGoalByIdAndUserId($monthlyGoalId, $userId);
        if ($existing === null) {
            throw new ValidationException('Das angegebene Zwischenziel existiert nicht oder gehört einem anderen Benutzer.', 'monthly_goal_id');
        }

        $effectiveDate = self::resolveCompletionDate($today);
        $isCurrentlyCompleted = !empty($existing['completed_at']);
        $newStatus = !$isCurrentlyCompleted;
        $completedAt = $newStatus ? $effectiveDate : null;

        $this->goalDAO->setMonthlyGoalCompleted($monthlyGoalId, $userId, $completedAt);

        $goalId = (int) ($existing['goal_id'] ?? 0);
        $semesterCascade = $this->syncSemesterGoalCompletion(
            $goalId,
            $userId,
            $effectiveDate,
            [$monthlyGoalId => $completedAt]
        );

        $yearMonth = (string) ($existing['year_month'] ?? $existing['plan_month'] ?? '');

        return [
            'target_type'             => 'monthly_goal',
            'monthly_goal_id'         => $monthlyGoalId,
            'year_month'              => $yearMonth,
            'is_completed'            => $newStatus,
            'completed_at'            => $completedAt,
            'completed_at_formatted'  => self::formatDateGerman($completedAt),
            'goal_id'                 => $goalId,
            'goal_completed'          => $semesterCascade['is_completed'],
            'semester_goal_completed' => $semesterCascade['is_completed'],
            'goal_completed_at'       => $semesterCascade['completed_at'],
        ];
    }

    /**
     * Schaltet den Erledigt-Status einer gesamten Kalenderwoche (alle Lerneinheiten der Woche) um
     * und kaskadiert den Status automatisch auf die betroffenen Monatsziele und Semesterziele.
     *
     * @param int $userId ID des Nutzers
     * @param string $isoWeek Kalenderwoche im Format YYYY-Www
     * @param string|null $requestedMonth Optionaler Planungsmonat (YYYY-MM)
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD)
     * @return array<string, mixed>
     * @throws ValidationException Bei ungültiger Kalenderwoche oder wenn keine Lerneinheiten in der Woche liegen
     */
    public function toggleWeekCompleted(
        int $userId,
        string $isoWeek,
        ?string $requestedMonth = null,
        ?string $today = null
    ): array {
        $weekMonday = self::parseIsoWeek($isoWeek);
        if ($weekMonday === null) {
            throw new ValidationException('Bitte gib eine gültige Kalenderwoche im Format JJJJ-Www an.', 'iso_week');
        }

        $normalizedWeek = $weekMonday->format('o-\WW');
        $weekSunday = $weekMonday->modify('+6 days');
        $startDate = $weekMonday->format('Y-m-d');
        $endDate = $weekSunday->format('Y-m-d');

        $weekSlots = $this->goalDAO->findLearningSlotsByDateRange($userId, $startDate, $endDate);
        if (empty($weekSlots)) {
            throw new ValidationException('Für diese Kalenderwoche sind noch keine Lerneinheiten eingeplant.', 'iso_week');
        }

        $effectiveDate = self::resolveCompletionDate($today);
        $currentlyAllCompleted = self::areAllSlotsCompleted($weekSlots);
        $newWeekStatus = !$currentlyAllCompleted;
        $completedAt = $newWeekStatus ? $effectiveDate : null;

        $this->goalDAO->setLearningSlotsCompletedByDateRange($userId, $startDate, $endDate, $newWeekStatus);

        if (isset($_SESSION) && is_array($_SESSION)) {
            if ($newWeekStatus) {
                $_SESSION['completed_weeks'][$userId][$normalizedWeek] = $effectiveDate;
            } else {
                unset($_SESSION['completed_weeks'][$userId][$normalizedWeek]);
            }
        }

        $slotOverrides = [];
        $affectedMonthlyGoals = [];
        foreach ($weekSlots as $slot) {
            $sId = (int) ($slot['id'] ?? 0);
            $mgId = (int) ($slot['monthly_goal_id'] ?? 0);
            $gId = (int) ($slot['goal_id'] ?? 0);
            if ($sId > 0) {
                $slotOverrides[$sId] = $newWeekStatus;
            }
            if ($mgId > 0) {
                $affectedMonthlyGoals[$mgId] = $gId;
            }
        }

        $monthlyGoalOverrides = [];
        $affectedSemesterGoals = [];
        foreach ($affectedMonthlyGoals as $mgId => $fallbackGoalId) {
            $mgCascade = $this->syncMonthlyGoalCompletion($mgId, $userId, $effectiveDate, $slotOverrides, $fallbackGoalId);
            $monthlyGoalOverrides[$mgId] = $mgCascade['completed_at'];
            $resolvedGoalId = $mgCascade['goal_id'] > 0 ? $mgCascade['goal_id'] : $fallbackGoalId;
            if ($resolvedGoalId > 0) {
                $affectedSemesterGoals[$resolvedGoalId] = true;
            }
        }

        $completedGoalIds = [];
        foreach (array_keys($affectedSemesterGoals) as $gId) {
            $semCascade = $this->syncSemesterGoalCompletion($gId, $userId, $effectiveDate, $monthlyGoalOverrides);
            if ($semCascade['is_completed']) {
                $completedGoalIds[] = $gId;
            }
        }

        $weekMonth = self::resolveMonthForWeek($requestedMonth, $normalizedWeek)
            ?? $weekMonday->modify('+3 days')->format('Y-m');

        return [
            'target_type'            => 'week',
            'iso_week'               => $normalizedWeek,
            'year_month'             => $weekMonth,
            'is_completed'           => $newWeekStatus,
            'week_completed'         => $newWeekStatus,
            'completed_at'           => $completedAt,
            'completed_at_formatted' => self::formatDateGerman($completedAt),
            'updated_slot_count'     => count($weekSlots),
            'completed_goal_ids'     => $completedGoalIds,
        ];
    }

    /**
     * Schaltet den Erledigt-Status (is_completed) eines Lern-Timeslots um
     * und kaskadiert den Status automatisch auf die Woche, das Monatsziel und das Semesterziel.
     *
     * @param int $slotId ID des Timeslots
     * @param int $userId ID des Nutzers
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD)
     * @return array<string, mixed>
     * @throws ValidationException Wenn Timeslot nicht existiert
     */
    public function toggleLearningSlotCompleted(int $slotId, int $userId, ?string $today = null): array
    {
        if ($slotId <= 0) {
            throw new ValidationException("Der angegebene Timeslot existiert nicht oder gehört einem anderen Benutzer.", 'slot_id');
        }

        $existing = $this->goalDAO->findLearningSlotByIdAndUserId($slotId, $userId);
        if ($existing === null) {
            throw new ValidationException("Der angegebene Timeslot existiert nicht oder gehört einem anderen Benutzer.", 'slot_id');
        }

        $effectiveDate = self::resolveCompletionDate($today);
        $newStatus = !((int) $existing['is_completed'] === 1);
        $this->goalDAO->setLearningSlotCompleted($slotId, $userId, $newStatus);

        $slotDate = (string) $existing['slot_date'];
        $slotDt = DateTimeImmutable::createFromFormat('Y-m-d', $slotDate) ?: new DateTimeImmutable('today');
        $isoWeek = $slotDt->format('o-\WW');
        $weekMonth = self::resolveMonthForWeek((string) ($existing['plan_month'] ?? ''), $isoWeek)
            ?? (string) ($existing['plan_month'] ?? $slotDt->format('Y-m'));

        // Prüfen, ob damit alle Lerneinheiten der Kalenderwoche erledigt sind
        $weekMonday = $slotDt->modify('monday this week');
        $weekSunday = $weekMonday->modify('+6 days');
        $weekSlots = $this->goalDAO->findLearningSlotsByDateRange(
            $userId,
            $weekMonday->format('Y-m-d'),
            $weekSunday->format('Y-m-d')
        );
        foreach ($weekSlots as &$ws) {
            if ((int) ($ws['id'] ?? 0) === $slotId) {
                $ws['is_completed'] = $newStatus ? 1 : 0;
            }
        }
        unset($ws);

        $weekCompleted = !empty($weekSlots) ? self::areAllSlotsCompleted($weekSlots) : $newStatus;
        $weekCompletedAt = $weekCompleted ? $effectiveDate : null;

        if (isset($_SESSION) && is_array($_SESSION)) {
            if ($weekCompleted) {
                $_SESSION['completed_weeks'][$userId][$isoWeek] = $effectiveDate;
            } else {
                unset($_SESSION['completed_weeks'][$userId][$isoWeek]);
            }
        }

        // Kaskadierung nach oben: Monatsziel -> Semesterziel
        $monthlyGoalId = (int) ($existing['monthly_goal_id'] ?? 0);
        $fallbackGoalId = (int) ($existing['goal_id'] ?? 0);
        $monthlyCascade = $this->syncMonthlyGoalCompletion(
            $monthlyGoalId,
            $userId,
            $effectiveDate,
            [$slotId => $newStatus],
            $fallbackGoalId
        );

        $goalId = $monthlyCascade['goal_id'] > 0 ? $monthlyCascade['goal_id'] : $fallbackGoalId;
        $semesterCascade = $this->syncSemesterGoalCompletion(
            $goalId,
            $userId,
            $effectiveDate,
            [$monthlyGoalId => $monthlyCascade['completed_at']]
        );

        $slotCompletedAt = $newStatus ? $effectiveDate : null;

        return [
            'target_type'               => 'slot',
            'slot_id'                   => $slotId,
            'slot_date'                 => $slotDate,
            'iso_week'                  => $isoWeek,
            'year_month'                => $weekMonth,
            'is_completed'              => $newStatus,
            'completed_at'              => $slotCompletedAt,
            'completed_at_formatted'    => self::formatDateGerman($slotCompletedAt),
            'week_completed'            => $weekCompleted,
            'week_completed_at'         => $weekCompletedAt,
            'monthly_goal_id'           => $monthlyGoalId,
            'monthly_goal_completed'    => $monthlyCascade['is_completed'],
            'monthly_goal_completed_at' => $monthlyCascade['completed_at'],
            'goal_id'                   => $goalId,
            'goal_completed'            => $semesterCascade['is_completed'],
            'semester_goal_completed'   => $semesterCascade['is_completed'],
            'goal_completed_at'         => $semesterCascade['completed_at'],
        ];
    }

    /**
     * Prüft anhand aller zugeordneten Lerneinheiten (Wocheneinheiten), ob ein Monatsziel
     * vollständig erfüllt ist, und setzt bzw. entfernt automatisch dessen completed_at-Datum.
     *
     * @param int $monthlyGoalId ID des Monatsziels
     * @param int $userId ID des Nutzers
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD)
     * @param array<int, bool> $slotOverrides In-Memory-Überschreibungen soeben geänderter Slot-Status
     * @param int $fallbackGoalId Optionale ID des übergeordneten Semesterziels
     * @return array{is_completed: bool, completed_at: ?string, goal_id: int}
     */
    public function syncMonthlyGoalCompletion(
        int $monthlyGoalId,
        int $userId,
        ?string $today = null,
        array $slotOverrides = [],
        int $fallbackGoalId = 0
    ): array {
        if ($monthlyGoalId <= 0) {
            return [
                'is_completed' => false,
                'completed_at' => null,
                'goal_id'      => $fallbackGoalId,
            ];
        }

        $effectiveDate = self::resolveCompletionDate($today);
        $monthlyGoal = $this->goalDAO->findMonthlyGoalByIdAndUserId($monthlyGoalId, $userId);
        $goalId = (int) ($monthlyGoal['goal_id'] ?? $fallbackGoalId);
        $existingCompletedAt = !empty($monthlyGoal['completed_at']) && is_string($monthlyGoal['completed_at'])
            ? trim($monthlyGoal['completed_at'])
            : null;

        $slots = $this->goalDAO->findLearningSlotsByMonthlyGoalId($monthlyGoalId, $userId);
        foreach ($slots as &$slot) {
            $sId = (int) ($slot['id'] ?? 0);
            if ($sId > 0 && array_key_exists($sId, $slotOverrides)) {
                $slot['is_completed'] = $slotOverrides[$sId] ? 1 : 0;
            }
        }
        unset($slot);

        if (empty($slots)) {
            return [
                'is_completed' => $existingCompletedAt !== null,
                'completed_at' => $existingCompletedAt,
                'goal_id'      => $goalId,
            ];
        }

        if (self::areAllSlotsCompleted($slots)) {
            $completedAt = $existingCompletedAt ?? $effectiveDate;
            if ($existingCompletedAt === null) {
                $this->goalDAO->setMonthlyGoalCompleted($monthlyGoalId, $userId, $completedAt);
            }

            return [
                'is_completed' => true,
                'completed_at' => $completedAt,
                'goal_id'      => $goalId,
            ];
        }

        if ($existingCompletedAt !== null) {
            $this->goalDAO->setMonthlyGoalCompleted($monthlyGoalId, $userId, null);
        }

        return [
            'is_completed' => false,
            'completed_at' => null,
            'goal_id'      => $goalId,
        ];
    }

    /**
     * Prüft anhand aller zugeordneten Monatsziele, ob ein übergeordnetes Semesterziel (learning_goals)
     * vollständig erfüllt ist, und setzt bzw. entfernt automatisch dessen completed_at-Datum.
     *
     * @param int $goalId ID des Semesterziels
     * @param int $userId ID des Nutzers
     * @param string|null $today Optionales Referenzdatum (YYYY-MM-DD)
     * @param array<int, ?string> $monthlyGoalOverrides In-Memory-Überschreibungen soeben geänderter Monatsziele
     * @return array{is_completed: bool, completed_at: ?string}
     */
    public function syncSemesterGoalCompletion(
        int $goalId,
        int $userId,
        ?string $today = null,
        array $monthlyGoalOverrides = []
    ): array {
        if ($goalId <= 0) {
            return [
                'is_completed' => false,
                'completed_at' => null,
            ];
        }

        $effectiveDate = self::resolveCompletionDate($today);
        $parentGoal = $this->goalDAO->findByIdAndUserId($goalId, $userId);
        $existingCompletedAt = !empty($parentGoal['completed_at']) && is_string($parentGoal['completed_at'])
            ? trim($parentGoal['completed_at'])
            : null;

        $monthlyGoals = $this->goalDAO->findMonthlyGoalsByGoalId($goalId, $userId);
        foreach ($monthlyGoals as &$mg) {
            $mgId = (int) ($mg['id'] ?? 0);
            if ($mgId > 0 && array_key_exists($mgId, $monthlyGoalOverrides)) {
                $mg['completed_at'] = $monthlyGoalOverrides[$mgId];
                $mg['is_completed'] = $monthlyGoalOverrides[$mgId] !== null;
            }
        }
        unset($mg);

        if (empty($monthlyGoals)) {
            return [
                'is_completed' => $existingCompletedAt !== null,
                'completed_at' => $existingCompletedAt,
            ];
        }

        if (self::areAllMonthlyGoalsCompleted($monthlyGoals)) {
            $completedAt = $existingCompletedAt ?? $effectiveDate;
            if ($existingCompletedAt === null) {
                $this->goalDAO->setGoalCompleted($goalId, $userId, $completedAt);
            }

            return [
                'is_completed' => true,
                'completed_at' => $completedAt,
            ];
        }

        if ($existingCompletedAt !== null) {
            $this->goalDAO->setGoalCompleted($goalId, $userId, null);
        }

        return [
            'is_completed' => false,
            'completed_at' => null,
        ];
    }

    /**
     * Prüft, ob in einer nicht-leeren Liste von Lern-Timeslots alle Einheiten als erledigt markiert sind.
     *
     * @param list<array<string, mixed>> $slots
     * @return bool
     */
    public static function areAllSlotsCompleted(array $slots): bool
    {
        if (empty($slots)) {
            return false;
        }

        foreach ($slots as $slot) {
            $done = ($slot['is_completed'] ?? 0) === true || (int) ($slot['is_completed'] ?? 0) === 1;
            if (!$done) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prüft, ob in einer nicht-leeren Liste von Monatszielen alle Ziele als erledigt markiert sind.
     *
     * @param list<array<string, mixed>> $monthlyGoals
     * @return bool
     */
    public static function areAllMonthlyGoalsCompleted(array $monthlyGoals): bool
    {
        if (empty($monthlyGoals)) {
            return false;
        }

        foreach ($monthlyGoals as $mg) {
            $done = !empty($mg['completed_at']) || !empty($mg['is_completed']);
            if (!$done) {
                return false;
            }
        }

        return true;
    }

    /**
     * Formatiert ein ISO-Datum (YYYY-MM-DD) in das deutsche Datumsformat (TT.MM.JJJJ).
     */
    public static function formatDateGerman(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        if (!$dt || $dt->format('Y-m-d') !== trim($date)) {
            return null;
        }

        return $dt->format('d.m.Y');
    }

    /**
     * Ermittelt das gültige Abschlussdatum im Format YYYY-MM-DD (Fallback: aktuelles Tagesdatum).
     */
    private static function resolveCompletionDate(?string $today = null): string
    {
        if ($today !== null && trim($today) !== '') {
            $trimmed = trim($today);
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed);
            if ($dt !== false && $dt->format('Y-m-d') === $trimmed) {
                return $trimmed;
            }
        }

        return date('Y-m-d');
    }

    /**
     * Liest und prüft eine positive Ganzzahl-ID aus Benutzereingaben.
     *
     * @throws ValidationException
     */
    private function parsePositiveId(mixed $rawId, string $field, string $errorMessage): int
    {
        if (is_int($rawId)) {
            $id = $rawId;
        } elseif (is_string($rawId) && preg_match('/^[1-9]\d*$/', trim($rawId))) {
            $id = (int) trim($rawId);
        } else {
            $id = 0;
        }

        if ($id <= 0) {
            throw new ValidationException($errorMessage, $field);
        }

        return $id;
    }

    /**
     * Validiert und bereinigt die Eingabedaten für einen Lern-Timeslot.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten
     * @return array{monthly_goal_id: int, year_month: string, slot_date: string, iso_week: string, start_time: string, end_time: string, notes: ?string}
     * @throws ValidationException
     */
    public function validateAndParseSlotInput(int $userId, array $input): array
    {
        // 1. Zugehöriges Zwischenziel prüfen
        $mgIdRaw = $input['monthly_goal_id'] ?? null;
        if (is_int($mgIdRaw)) {
            $monthlyGoalId = $mgIdRaw;
        } elseif (is_string($mgIdRaw)) {
            $filteredId = filter_var(trim($mgIdRaw), FILTER_VALIDATE_INT);
            $monthlyGoalId = $filteredId !== false ? $filteredId : 0;
        } else {
            $monthlyGoalId = 0;
        }

        if ($monthlyGoalId <= 0) {
            throw new ValidationException("Bitte wähle ein monatliches Zwischenziel für diesen Timeslot aus.", 'monthly_goal_id');
        }

        $monthlyGoal = $this->goalDAO->findMonthlyGoalByIdAndUserId($monthlyGoalId, $userId);
        if ($monthlyGoal === null) {
            throw new ValidationException("Das ausgewählte Zwischenziel existiert nicht oder gehört einem anderen Benutzer.", 'monthly_goal_id');
        }

        $planMonth = (string) $monthlyGoal['plan_month'];
        $parentGoal = $this->goalDAO->findByIdAndUserId((int) $monthlyGoal['goal_id'], $userId);

        // 2. Datum des Timeslots prüfen (YYYY-MM-DD)
        $slotDateRaw = $input['slot_date'] ?? null;
        if (!is_string($slotDateRaw) || trim($slotDateRaw) === '') {
            throw new ValidationException("Bitte gib ein Datum für den Timeslot an.", 'slot_date');
        }
        $slotDate = trim($slotDateRaw);
        $slotDt = DateTimeImmutable::createFromFormat('Y-m-d', $slotDate);
        if (!$slotDt || $slotDt->format('Y-m-d') !== $slotDate) {
            throw new ValidationException("Das Datum muss im gültigen Format JJJJ-MM-TT vorliegen.", 'slot_date');
        }

        // Prüfen, ob das Datum im Zeitraum des übergeordneten Lernziels liegt
        if ($parentGoal !== null) {
            $parentStart = (string) $parentGoal['start_date'];
            $parentEnd = (string) $parentGoal['end_date'];
            if ($slotDate < $parentStart || $slotDate > $parentEnd) {
                throw new ValidationException(sprintf(
                    "Das Datum muss im Zeitraum des Lernziels liegen (%s bis %s).",
                    date('d.m.Y', strtotime($parentStart)),
                    date('d.m.Y', strtotime($parentEnd))
                ), 'slot_date');
            }
        }

        // Prüfen, ob das Datum zum Monat des Zwischenziels (inkl. überlappender Kalenderwochen an den Monatsrändern) passt
        $monthStartDt = DateTimeImmutable::createFromFormat('Y-m-d', $planMonth . '-01');
        if ($monthStartDt !== false) {
            $monthEndDt = $monthStartDt->modify('last day of this month');
            $allowedMinDate = $monthStartDt->modify('monday this week')->format('Y-m-d');
            $allowedMaxDate = $monthEndDt->modify('sunday this week')->format('Y-m-d');

            if ($slotDate < $allowedMinDate || $slotDate > $allowedMaxDate) {
                throw new ValidationException(sprintf(
                    "Das Datum muss im Monat des Zwischenziels (%s) oder einer angrenzenden Kalenderwoche liegen.",
                    self::formatYearMonth($planMonth)
                ), 'slot_date');
            }
        }

        // 3. Startzeit prüfen (HH:MM)
        $startTimeRaw = $input['start_time'] ?? null;
        if (!is_string($startTimeRaw) || trim($startTimeRaw) === '') {
            throw new ValidationException("Bitte gib eine Startzeit an.", 'start_time');
        }
        $startTimeTrimmed = trim($startTimeRaw);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $startTimeTrimmed, $startMatches)) {
            throw new ValidationException("Die Startzeit muss im gültigen Format HH:MM vorliegen.", 'start_time');
        }
        $startMinutes = ((int) $startMatches[1]) * 60 + ((int) $startMatches[2]);
        if (((int) $startMatches[2]) % 10 !== 0) {
            throw new ValidationException("Die Startzeit muss in 10-Minuten-Schritten angegeben werden (z. B. 18:00, 18:10, 18:20).", 'start_time');
        }
        $startTimeNormalized = sprintf('%02d:%02d:00', (int) $startMatches[1], (int) $startMatches[2]);

        // 4. Endzeit prüfen (HH:MM)
        $endTimeRaw = $input['end_time'] ?? null;
        if (!is_string($endTimeRaw) || trim($endTimeRaw) === '') {
            throw new ValidationException("Bitte gib eine Endzeit an.", 'end_time');
        }
        $endTimeTrimmed = trim($endTimeRaw);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $endTimeTrimmed, $endMatches)) {
            throw new ValidationException("Die Endzeit muss im gültigen Format HH:MM vorliegen.", 'end_time');
        }
        $endMinutes = ((int) $endMatches[1]) * 60 + ((int) $endMatches[2]);
        if (((int) $endMatches[2]) % 10 !== 0) {
            throw new ValidationException("Die Endzeit muss in 10-Minuten-Schritten angegeben werden (z. B. 19:30, 19:40).", 'end_time');
        }
        $endTimeNormalized = sprintf('%02d:%02d:00', (int) $endMatches[1], (int) $endMatches[2]);

        // Logische Zeitprüfung: Endzeit nach Startzeit und Mindestdauer
        if ($endMinutes <= $startMinutes) {
            throw new ValidationException("Die Endzeit muss zeitlich nach der Startzeit liegen.", 'end_time');
        }
        if (($endMinutes - $startMinutes) < self::MIN_SLOT_MINUTES) {
            throw new ValidationException(sprintf(
                "Ein Lern-Timeslot muss mindestens %d Minuten dauern.",
                self::MIN_SLOT_MINUTES
            ), 'end_time');
        }

        // 5. Optionale Notiz prüfen
        $notes = null;
        if (isset($input['notes']) && is_string($input['notes'])) {
            $notesTrimmed = trim($input['notes']);
            if ($notesTrimmed !== '') {
                if (mb_strlen($notesTrimmed, 'UTF-8') > self::MAX_SLOT_NOTES_LENGTH) {
                    throw new ValidationException("Die Notiz darf höchstens " . self::MAX_SLOT_NOTES_LENGTH . " Zeichen lang sein.", 'notes');
                }
                $notes = $notesTrimmed;
            }
        }

        $isoWeek = $slotDt->format('o-\WW');
        $weekMonth = self::resolveMonthForWeek($planMonth, $isoWeek) ?? $planMonth;

        return [
            'monthly_goal_id' => $monthlyGoalId,
            'goal_id'         => (int) ($monthlyGoal['goal_id'] ?? 0),
            'year_month'      => $weekMonth,
            'slot_date'       => $slotDate,
            'iso_week'        => $isoWeek,
            'start_time'      => $startTimeNormalized,
            'end_time'        => $endTimeNormalized,
            'notes'           => $notes,
        ];
    }

    /**
     * Parst einen ISO-Wochenstring (z. B. "2026-W44") und liefert den Montag dieser Woche zurück.
     */
    public static function parseIsoWeek(?string $isoWeek): ?DateTimeImmutable
    {
        if (!is_string($isoWeek)) {
            return null;
        }
        $trimmed = trim($isoWeek);
        if (!preg_match('/^(\d{4})-W(0[1-9]|[1-4]\d|5[0-3])$/', $trimmed, $m)) {
            return null;
        }

        $year = (int) $m[1];
        $week = (int) $m[2];
        $monday = (new DateTimeImmutable('today'))->setISODate($year, $week, 1);

        if ($monday->format('o-\WW') !== $trimmed) {
            return null;
        }

        return $monday;
    }

    /**
     * Prüft, ob eine Kalenderwoche (beginnend am übergebenen Montag) zu einem bestimmten Monat (YYYY-MM) gehört.
     * Eine Woche gehört genau dann zu einem Monat, wenn mindestens 4 ihrer 7 Tage (Montag bis Sonntag)
     * in diesem Monat liegen (entspricht nach ISO-8601 der Woche, deren Donnerstag in diesem Monat liegt).
     */
    public static function doesWeekBelongToMonth(DateTimeImmutable $weekMonday, string $yearMonth): bool
    {
        $daysInMonth = 0;
        for ($d = 0; $d < 7; $d++) {
            if ($weekMonday->modify("+{$d} days")->format('Y-m') === $yearMonth) {
                $daysInMonth++;
            }
        }

        return $daysInMonth >= 4;
    }

    /**
     * Bestimmt den passenden Planungsmonat (YYYY-MM) für eine angeforderte Kalenderwoche.
     * Eine Woche gehört eindeutig zu dem Monat, in dem mindestens 4 ihrer Tage (bzw. ihr Donnerstag) liegen.
     */
    public static function resolveMonthForWeek(?string $requestedMonth, ?string $requestedWeek): ?string
    {
        $validMonth = ($requestedMonth !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', trim($requestedMonth)))
            ? trim($requestedMonth)
            : null;

        $weekMonday = self::parseIsoWeek($requestedWeek);
        if ($weekMonday === null) {
            return $validMonth;
        }

        // Eine Woche (Montag bis Sonntag) hat genau in dem Monat mindestens 4 Tage,
        // in dem ihr Donnerstag (+3 Tage ab Montag) liegt.
        return $weekMonday->modify('+3 days')->format('Y-m');
    }

    /**
     * Bereitet alle Daten für die wöchentliche Detailplanung und Timeslot-Einteilung auf.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $monthlyPlanning Ergebnis aus buildMonthlyPlanningData()
     * @param string|null $requestedWeek Vom Nutzer gewählte Kalenderwoche (Format YYYY-Www)
     * @param string|null $requestedDay Optional ausgewählter Tag im Wochenband (Format YYYY-MM-DD)
     * @return array<string, mixed>
     */
    public function buildWeeklyPlanningData(
        int $userId,
        array $monthlyPlanning,
        ?string $requestedWeek = null,
        ?string $requestedDay = null
    ): array {
        $selectedMonth = (string) $monthlyPlanning['selected_month'];
        $selectedMonthData = $monthlyPlanning['selected_month_data'];
        $monthlyGoals = $monthlyPlanning['monthly_goals'];

        $todayDt = new DateTimeImmutable('today');
        $todayStr = $todayDt->format('Y-m-d');
        $currentIsoWeek = $todayDt->format('o-\WW');

        // 1. Alle Kalenderwochen ermitteln, die zum ausgewählten Monat gehören (mindestens 4 Tage im Monat)
        $monthFirstDay = DateTimeImmutable::createFromFormat('Y-m-d', $selectedMonth . '-01') ?: $todayDt->modify('first day of this month');
        $monthLastDay = $monthFirstDay->modify('last day of this month');

        $firstCandidateMonday = $monthFirstDay->modify('monday this week');
        $lastCandidateMonday = $monthLastDay->modify('monday this week');

        $monthWeekMondays = [];
        $cursor = $firstCandidateMonday;
        while ($cursor <= $lastCandidateMonday) {
            if (self::doesWeekBelongToMonth($cursor, $selectedMonth)) {
                $monthWeekMondays[$cursor->format('o-\WW')] = $cursor;
            }
            $cursor = $cursor->modify('+7 days');
        }

        $firstWeekMonday = reset($monthWeekMondays) ?: $firstCandidateMonday;
        $lastWeekMonday = end($monthWeekMondays) ?: $lastCandidateMonday;

        // 2. Ausgewählte Kalenderwoche bestimmen
        $requestedMonday = self::parseIsoWeek($requestedWeek);
        if ($requestedMonday !== null && isset($monthWeekMondays[$requestedMonday->format('o-\WW')])) {
            $selectedWeekMonday = $requestedMonday;
        } elseif (isset($monthWeekMondays[$currentIsoWeek])) {
            $selectedWeekMonday = $monthWeekMondays[$currentIsoWeek];
        } else {
            $selectedWeekMonday = $firstWeekMonday;
        }

        $selectedIsoWeek = $selectedWeekMonday->format('o-\WW');
        $selectedWeekSunday = $selectedWeekMonday->modify('+6 days');

        // 3. Alle Lern-Timeslots und gestoppten Lernzeiten für das gesamte Wochenfenster des Monats laden
        $windowStartDate = $firstWeekMonday->format('Y-m-d');
        $windowEndDate = $lastWeekMonday->modify('+6 days')->format('Y-m-d');
        $allWindowSlots = $this->goalDAO->findLearningSlotsByDateRange($userId, $windowStartDate, $windowEndDate);
        $trackedRows = $this->goalDAO->findTrackedSecondsByDateRange($userId, $windowStartDate, $windowEndDate);

        $unassignedByDateAndGoal = [];
        $trackedByDateTotal = [];
        foreach ($trackedRows as $tRow) {
            $dKey = (string) $tRow['entry_date'];
            $mgKey = (int) $tRow['monthly_goal_id'];
            $sec = max(0, (int) $tRow['tracked_seconds']);
            $unassignedSec = max(0, (int) $tRow['unassigned_seconds']);
            $unassignedByDateAndGoal[$dKey][$mgKey] = ($unassignedByDateAndGoal[$dKey][$mgKey] ?? 0) + $unassignedSec;
            $trackedByDateTotal[$dKey] = ($trackedByDateTotal[$dKey] ?? 0) + $sec;
        }

        // Slots nach Datum gruppieren. Verknüpfte Buchungen bleiben bei ihrem Slot, nicht
        // verknüpfte Ist-Zeiten (Stoppuhr) werden chronologisch auf die Slots verteilt.
        $rawSlotsByDate = [];
        foreach ($allWindowSlots as $slot) {
            $d = (string) $slot['slot_date'];
            $rawSlotsByDate[$d][] = $slot;
        }

        $slotsByDate = [];
        foreach ($rawSlotsByDate as $d => $dateSlots) {
            $goalCountsOnDay = [];
            foreach ($dateSlots as $ds) {
                $mgId = (int) $ds['monthly_goal_id'];
                $goalCountsOnDay[$mgId] = ($goalCountsOnDay[$mgId] ?? 0) + 1;
            }

            $remainingByGoal = $unassignedByDateAndGoal[$d] ?? [];
            $seenByGoal = [];

            foreach ($dateSlots as $ds) {
                $mgId = (int) $ds['monthly_goal_id'];
                $seenByGoal[$mgId] = ($seenByGoal[$mgId] ?? 0) + 1;
                $isLastForGoal = ($seenByGoal[$mgId] === $goalCountsOnDay[$mgId]);

                $plannedSec = isset($ds['planned_seconds']) && (int) $ds['planned_seconds'] > 0
                    ? (int) $ds['planned_seconds']
                    : (int) round(((float) $ds['duration_hours']) * 3600);
                $linkedSec = max(0, (int) ($ds['tracked_seconds'] ?? 0));
                $availableSec = $remainingByGoal[$mgId] ?? 0;
                $allocatedSec = $isLastForGoal ? $availableSec : min(max(0, $plannedSec - $linkedSec), $availableSec);
                $remainingByGoal[$mgId] = max(0, $availableSec - $allocatedSec);
                $slotTrackedSec = $linkedSec + $allocatedSec;

                $targetReached = ($plannedSec > 0 && $slotTrackedSec >= $plannedSec);

                $isDone = (int) $ds['is_completed'] === 1;
                $progressPct = $plannedSec > 0
                    ? min(100.0, round(($slotTrackedSec / $plannedSec) * 100, 1))
                    : ($isDone ? 100.0 : 0.0);

                $ds['planned_seconds'] = $plannedSec;
                $ds['tracked_seconds'] = $slotTrackedSec;
                $ds['tracked_hours'] = round($slotTrackedSec / 3600.0, 2);
                $ds['tracked_formatted'] = self::formatTrackedDuration($slotTrackedSec);
                $ds['progress_percent'] = $progressPct;
                $ds['is_target_reached'] = $targetReached;

                $slotsByDate[$d][] = $ds;
            }
        }

        // Empfohlener Wochen-Richtwert aus dem Monatsbudget (proportional auf die Wochen des Monats)
        $weekCountInMonth = max(1, count($monthWeekMondays));
        $monthBudget = $selectedMonthData['budget_hours'] !== null ? (float) $selectedMonthData['budget_hours'] : null;
        $monthPlanned = (float) $selectedMonthData['planned_hours'];
        $weeklyReferenceHours = $monthBudget !== null
            ? round($monthBudget / $weekCountInMonth, 1)
            : ($monthPlanned > 0 ? round($monthPlanned / $weekCountInMonth, 1) : null);

        // 4. Wochen-Tab-Leiste aufbauen
        $weeksWindow = [];
        foreach ($monthWeekMondays as $isoW => $mondayDt) {
            $sundayDt = $mondayDt->modify('+6 days');
            $weekHours = 0.0;
            $weekCompletedHours = 0.0;
            $weekTrackedSeconds = 0;
            $weekSlotCount = 0;
            $weekCompletedSlotCount = 0;

            for ($dIdx = 0; $dIdx < 7; $dIdx++) {
                $dStr = $mondayDt->modify("+{$dIdx} days")->format('Y-m-d');
                $weekTrackedSeconds += ($trackedByDateTotal[$dStr] ?? 0);
                foreach ($slotsByDate[$dStr] ?? [] as $s) {
                    $dur = (float) $s['duration_hours'];
                    $weekHours += $dur;
                    if ((int) $s['is_completed'] === 1) {
                        $weekCompletedHours += $dur;
                        $weekCompletedSlotCount++;
                    }
                    $weekSlotCount++;
                }
            }

            $isWeekCompleted = $weekSlotCount > 0 && $weekCompletedSlotCount === $weekSlotCount;
            $weekCompletedAt = null;
            if ($isWeekCompleted) {
                $sessionWeekDate = isset($_SESSION['completed_weeks'][$userId][$isoW]) && is_string($_SESSION['completed_weeks'][$userId][$isoW])
                    ? $_SESSION['completed_weeks'][$userId][$isoW]
                    : null;
                $weekCompletedAt = $sessionWeekDate ?? $todayStr;
            }

            $weekNum = (int) $mondayDt->format('W');
            $weeksWindow[$isoW] = [
                'iso_week'               => $isoW,
                'week_number'            => $weekNum,
                'label_short'            => 'KW ' . $weekNum,
                'label_long'             => sprintf('KW %d (%s – %s)', $weekNum, $mondayDt->format('d.m.'), $sundayDt->format('d.m.Y')),
                'date_range_short'       => sprintf('%s – %s', $mondayDt->format('d.m.'), $sundayDt->format('d.m.')),
                'start_date'             => $mondayDt->format('Y-m-d'),
                'end_date'               => $sundayDt->format('Y-m-d'),
                'is_current_week'        => ($isoW === $currentIsoWeek),
                'is_selected'            => ($isoW === $selectedIsoWeek),
                'scheduled_hours'        => round($weekHours, 2),
                'completed_hours'        => round($weekCompletedHours, 2),
                'tracked_seconds'        => $weekTrackedSeconds,
                'tracked_hours'          => round($weekTrackedSeconds / 3600.0, 2),
                'slot_count'             => $weekSlotCount,
                'completed_slot_count'   => $weekCompletedSlotCount,
                'is_completed'           => $isWeekCompleted,
                'completed_at'           => $weekCompletedAt,
                'completed_at_formatted' => self::formatDateGerman($weekCompletedAt),
            ];
        }

        // 5. 7 Wochentage (Montag bis Sonntag) der ausgewählten Kalenderwoche inkl. Kollisionsprüfung aufbereiten
        $days = [];
        $selectedWeekScheduled = 0.0;
        $selectedWeekCompleted = 0.0;
        $selectedWeekTrackedSeconds = 0;
        $selectedWeekSlotCount = 0;
        $selectedWeekCompletedSlotCount = 0;
        $weekHasOverlaps = false;

        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $dayDt = $selectedWeekMonday->modify("+{$dayOffset} days");
            $dayDateStr = $dayDt->format('Y-m-d');
            $isoDayNum = (int) $dayDt->format('N'); // 1 (Mo) bis 7 (So)
            $daySlots = $slotsByDate[$dayDateStr] ?? [];
            $dayTrackedSeconds = $trackedByDateTotal[$dayDateStr] ?? 0;
            $selectedWeekTrackedSeconds += $dayTrackedSeconds;

            // Zeitüberschneidungen innerhalb des Tages prüfen
            $dayHasOverlap = false;
            $slotCountDay = count($daySlots);
            for ($i = 0; $i < $slotCountDay; $i++) {
                $daySlots[$i]['has_overlap'] = false;
            }
            for ($i = 0; $i < $slotCountDay; $i++) {
                for ($j = $i + 1; $j < $slotCountDay; $j++) {
                    if (
                        (string) $daySlots[$i]['start_time'] < (string) $daySlots[$j]['end_time']
                        && (string) $daySlots[$j]['start_time'] < (string) $daySlots[$i]['end_time']
                    ) {
                        $daySlots[$i]['has_overlap'] = true;
                        $daySlots[$j]['has_overlap'] = true;
                        $dayHasOverlap = true;
                        $weekHasOverlaps = true;
                    }
                }
            }

            $dayHours = 0.0;
            foreach ($daySlots as &$sItem) {
                $dur = (float) $sItem['duration_hours'];
                $dayHours += $dur;
                $selectedWeekScheduled += $dur;
                $selectedWeekSlotCount++;
                $isCompleted = (int) $sItem['is_completed'] === 1;
                if ($isCompleted) {
                    $selectedWeekCompleted += $dur;
                    $selectedWeekCompletedSlotCount++;
                }

                $plannedSeconds = isset($sItem['planned_seconds'])
                    ? (int) $sItem['planned_seconds']
                    : (int) round($dur * 3600);
                $trackedSeconds = (int) ($sItem['tracked_seconds'] ?? 0);
                $isUnderTarget = $isCompleted && $plannedSeconds > 0 && $trackedSeconds < $plannedSeconds;
                $missingSeconds = $isUnderTarget ? max(0, $plannedSeconds - $trackedSeconds) : 0;

                $sItem['planned_seconds'] = $plannedSeconds;
                $sItem['tracked_seconds'] = $trackedSeconds;
                $sItem['tracked_hours'] = round($trackedSeconds / 3600.0, 2);
                $sItem['is_under_target'] = $isUnderTarget;
                $sItem['missing_seconds'] = $missingSeconds;
                $sItem['missing_hours'] = $isUnderTarget ? round($missingSeconds / 3600.0, 2) : 0.0;
                $sItem['start_time_formatted'] = substr((string) $sItem['start_time'], 0, 5);
                $sItem['end_time_formatted'] = substr((string) $sItem['end_time'], 0, 5);
            }
            unset($sItem);

            $days[] = [
                'date'                => $dayDateStr,
                'day_number'          => $isoDayNum,
                'day_name_long'       => self::DAY_NAMES_LONG[$isoDayNum] ?? '',
                'day_name_short'      => self::DAY_NAMES_SHORT[$isoDayNum] ?? '',
                'date_formatted'      => $dayDt->format('d.m.'),
                'date_full_formatted' => $dayDt->format('d.m.Y'),
                'is_today'            => ($dayDateStr === $todayStr),
                'is_in_selected_month'=> (substr($dayDateStr, 0, 7) === $selectedMonth),
                'scheduled_hours'     => round($dayHours, 2),
                'tracked_seconds'     => $dayTrackedSeconds,
                'tracked_hours'       => round($dayTrackedSeconds / 3600.0, 2),
                'tracked_formatted'   => self::formatTrackedDuration($dayTrackedSeconds),
                'slot_count'          => $slotCountDay,
                'has_overlap'         => $dayHasOverlap,
                'slots'               => $daySlots,
            ];
        }

        // Aktiv fokussierten Tag für das Wochenband und das "Timeslot planen"-Modal ermitteln
        $weekMinDate = $selectedWeekMonday->format('Y-m-d');
        $weekMaxDate = $selectedWeekSunday->format('Y-m-d');
        $trimmedReqDay = is_string($requestedDay) ? trim($requestedDay) : '';

        if ($trimmedReqDay !== '' && $trimmedReqDay >= $weekMinDate && $trimmedReqDay <= $weekMaxDate) {
            $defaultSlotDate = $trimmedReqDay;
        } elseif ($todayStr >= $weekMinDate && $todayStr <= $weekMaxDate) {
            $defaultSlotDate = $todayStr;
        } else {
            $firstDayWithSlots = null;
            foreach ($days as $dCheck) {
                if ((int) $dCheck['slot_count'] > 0) {
                    $firstDayWithSlots = (string) $dCheck['date'];
                    break;
                }
            }
            $defaultSlotDate = $firstDayWithSlots ?? $weekMinDate;
        }

        foreach ($days as &$dEntry) {
            $dEntry['is_selected'] = ((string) $dEntry['date'] === $defaultSlotDate);
        }
        unset($dEntry);

        // Vorherige und nächste Kalenderwoche samt passendem Zielmonat berechnen
        $prevMonday = $selectedWeekMonday->modify('-7 days');
        $nextMonday = $selectedWeekMonday->modify('+7 days');
        $prevIsoWeek = $prevMonday->format('o-\WW');
        $nextIsoWeek = $nextMonday->format('o-\WW');
        $prevWeekMonth = self::resolveMonthForWeek($selectedMonth, $prevIsoWeek) ?? $selectedMonth;
        $nextWeekMonth = self::resolveMonthForWeek($selectedMonth, $nextIsoWeek) ?? $selectedMonth;

        $selectedWeekScheduled = round($selectedWeekScheduled, 2);
        $selectedWeekCompleted = round($selectedWeekCompleted, 2);

        $shareOfMonthPlannedPercent = $monthPlanned > 0
            ? round(($selectedWeekScheduled / $monthPlanned) * 100, 1)
            : 0.0;
        $shareOfMonthBudgetPercent = ($monthBudget !== null && $monthBudget > 0)
            ? round(($selectedWeekScheduled / $monthBudget) * 100, 1)
            : null;

        // Zwischenziele für das Auswahlfeld im Timeslot-Modal bereitstellen
        // (aktueller Monat + ggf. angrenzender Monat bei monatsübergreifender Kalenderwoche)
        $selectableMonthlyGoals = $monthlyGoals;
        $adjacentMonths = array_unique([
            $selectedWeekMonday->format('Y-m'),
            $selectedWeekSunday->format('Y-m'),
        ]);
        foreach ($adjacentMonths as $adjMonth) {
            if ($adjMonth !== $selectedMonth) {
                $adjGoals = $this->goalDAO->findMonthlyGoalsByUserId($userId, $adjMonth);
                foreach ($adjGoals as $adjGoal) {
                    $selectableMonthlyGoals[] = $adjGoal;
                }
            }
        }

        return [
            'selected_week'            => $selectedIsoWeek,
            'selected_week_data'       => $weeksWindow[$selectedIsoWeek],
            'prev_week'                => $prevIsoWeek,
            'prev_week_month'          => $prevWeekMonth,
            'next_week'                => $nextIsoWeek,
            'next_week_month'          => $nextWeekMonth,
            'prev_month'               => (string) $monthlyPlanning['prev_month'],
            'next_month'               => (string) $monthlyPlanning['next_month'],
            'selected_month'           => $selectedMonth,
            'selected_month_data'      => $selectedMonthData,
            'weeks_window'             => $weeksWindow,
            'days'                     => $days,
            'default_slot_date'        => $defaultSlotDate,
            'weekly_reference_hours'   => $weeklyReferenceHours,
            'week_summary'             => [
                'scheduled_hours'                => $selectedWeekScheduled,
                'completed_hours'                => $selectedWeekCompleted,
                'tracked_seconds'                => $selectedWeekTrackedSeconds,
                'tracked_hours'                  => round($selectedWeekTrackedSeconds / 3600.0, 2),
                'tracked_formatted'              => self::formatTrackedDuration($selectedWeekTrackedSeconds),
                'slot_count'                     => $selectedWeekSlotCount,
                'completed_slot_count'           => $selectedWeekCompletedSlotCount,
                'is_completed'                   => (bool) ($weeksWindow[$selectedIsoWeek]['is_completed'] ?? false),
                'completed_at'                   => $weeksWindow[$selectedIsoWeek]['completed_at'] ?? null,
                'completed_at_formatted'         => $weeksWindow[$selectedIsoWeek]['completed_at_formatted'] ?? null,
                'has_overlaps'                   => $weekHasOverlaps,
                'share_of_month_planned_percent' => $shareOfMonthPlannedPercent,
                'share_of_month_budget_percent'  => $shareOfMonthBudgetPercent,
            ],
            'monthly_goals'            => $monthlyGoals,
            'selectable_monthly_goals' => $selectableMonthlyGoals,
        ];
    }

    /**
     * Formatiert eine gestoppte Ist-Zeit (in Sekunden) in eine kompakte lesbare Form (z. B. "1 Std. 30 Min.", "45 Min.").
     *
     * @param int $seconds
     * @return string
     */
    public static function formatTrackedDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds === 0) {
            return '0 Min.';
        }
        if ($seconds < 60) {
            return sprintf('%d Sek.', $seconds);
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) round(($seconds % 3600) / 60);
        if ($minutes === 60) {
            $hours++;
            $minutes = 0;
        }

        if ($hours > 0 && $minutes > 0) {
            return sprintf('%d Std. %d Min.', $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%d Std.', $hours);
        }

        return sprintf('%d Min.', $minutes);
    }
}

