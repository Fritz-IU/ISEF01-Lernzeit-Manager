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
    public const MIN_SLOT_MINUTES = 15;

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
     * Liefert alle Lernziele eines Nutzers.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>>
     */
    public function listGoals(int $userId): array
    {
        return $this->goalDAO->findAllByUserId($userId);
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

        foreach ($monthlyGoalsRaw as $mg) {
            $gId = (int) $mg['goal_id'];
            $pHours = (float) $mg['planned_hours'];
            $parentTarget = (float) $mg['parent_target_hours'];
            $parentAllocated = $allocatedByGoalId[$gId] ?? $pHours;
            $scheduledHours = (float) ($mg['scheduled_hours'] ?? 0.0);
            $completedHours = (float) ($mg['completed_hours'] ?? 0.0);
            $slotCount = (int) ($mg['slot_count'] ?? 0);

            $totalScheduledInMonth += $scheduledHours;

            $mg['parent_allocated_hours'] = $parentAllocated;
            $mg['is_parent_over_allocated'] = $parentAllocated > $parentTarget;
            $mg['scheduled_hours'] = $scheduledHours;
            $mg['completed_hours'] = $completedHours;
            $mg['slot_count'] = $slotCount;
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

        $this->goalDAO->deleteLearningSlot($slotId, $userId);

        return [
            'slot_date'  => $slotDate,
            'iso_week'   => $slotDt->format('o-\WW'),
            'year_month' => (string) $existing['plan_month'],
        ];
    }

    /**
     * Schaltet den Erledigt-Status (is_completed) eines Lern-Timeslots um.
     *
     * @param int $slotId ID des Timeslots
     * @param int $userId ID des Nutzers
     * @return array{slot_date: string, iso_week: string, year_month: string, is_completed: bool}
     * @throws ValidationException Wenn Timeslot nicht existiert
     */
    public function toggleLearningSlotCompleted(int $slotId, int $userId): array
    {
        $existing = $this->goalDAO->findLearningSlotByIdAndUserId($slotId, $userId);
        if ($existing === null) {
            throw new ValidationException("Der angegebene Timeslot existiert nicht oder gehört einem anderen Benutzer.");
        }

        $newStatus = !((int) $existing['is_completed'] === 1);
        $this->goalDAO->setLearningSlotCompleted($slotId, $userId, $newStatus);

        $slotDate = (string) $existing['slot_date'];
        $slotDt = DateTimeImmutable::createFromFormat('Y-m-d', $slotDate) ?: new DateTimeImmutable('today');

        return [
            'slot_date'    => $slotDate,
            'iso_week'     => $slotDt->format('o-\WW'),
            'year_month'   => (string) $existing['plan_month'],
            'is_completed' => $newStatus,
        ];
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

        return [
            'monthly_goal_id' => $monthlyGoalId,
            'year_month'      => $planMonth,
            'slot_date'       => $slotDate,
            'iso_week'        => $slotDt->format('o-\WW'),
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
     * Bestimmt den passenden Planungsmonat (YYYY-MM) für eine angeforderte Kalenderwoche,
     * falls kein expliziter Monat übergeben wurde oder die Woche außerhalb des Monats liegt.
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

        $weekSunday = $weekMonday->modify('+6 days');
        $mondayMonth = $weekMonday->format('Y-m');
        $sundayMonth = $weekSunday->format('Y-m');

        // Wenn ein gültiger Monat übergeben wurde und die Woche diesen Monat berührt, Monat beibehalten
        if ($validMonth !== null && ($validMonth === $mondayMonth || $validMonth === $sundayMonth)) {
            return $validMonth;
        }

        // Sonst den Monat des Donnerstags (ISO-8601-Mehrheitsmonat der Woche) verwenden
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

        // 1. Alle Kalenderwochen ermitteln, die den ausgewählten Monat berühren
        $monthFirstDay = DateTimeImmutable::createFromFormat('Y-m-d', $selectedMonth . '-01') ?: $todayDt->modify('first day of this month');
        $monthLastDay = $monthFirstDay->modify('last day of this month');

        $firstWeekMonday = $monthFirstDay->modify('monday this week');
        $lastWeekMonday = $monthLastDay->modify('monday this week');

        $monthWeekMondays = [];
        $cursor = $firstWeekMonday;
        while ($cursor <= $lastWeekMonday) {
            $monthWeekMondays[$cursor->format('o-\WW')] = $cursor;
            $cursor = $cursor->modify('+7 days');
        }

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

        // 3. Alle Lern-Timeslots für das gesamte Wochenfenster des Monats in einer Abfrage laden
        $windowStartDate = $firstWeekMonday->format('Y-m-d');
        $windowEndDate = $lastWeekMonday->modify('+6 days')->format('Y-m-d');
        $allWindowSlots = $this->goalDAO->findLearningSlotsByDateRange($userId, $windowStartDate, $windowEndDate);

        // Slots nach Datum gruppieren
        $slotsByDate = [];
        foreach ($allWindowSlots as $slot) {
            $d = (string) $slot['slot_date'];
            $slotsByDate[$d][] = $slot;
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
            $weekSlotCount = 0;

            for ($dIdx = 0; $dIdx < 7; $dIdx++) {
                $dStr = $mondayDt->modify("+{$dIdx} days")->format('Y-m-d');
                foreach ($slotsByDate[$dStr] ?? [] as $s) {
                    $dur = (float) $s['duration_hours'];
                    $weekHours += $dur;
                    if ((int) $s['is_completed'] === 1) {
                        $weekCompletedHours += $dur;
                    }
                    $weekSlotCount++;
                }
            }

            $weekNum = (int) $mondayDt->format('W');
            $weeksWindow[$isoW] = [
                'iso_week'          => $isoW,
                'week_number'       => $weekNum,
                'label_short'       => 'KW ' . $weekNum,
                'label_long'        => sprintf('KW %d (%s – %s)', $weekNum, $mondayDt->format('d.m.'), $sundayDt->format('d.m.Y')),
                'date_range_short'  => sprintf('%s – %s', $mondayDt->format('d.m.'), $sundayDt->format('d.m.')),
                'start_date'        => $mondayDt->format('Y-m-d'),
                'end_date'          => $sundayDt->format('Y-m-d'),
                'is_current_week'   => ($isoW === $currentIsoWeek),
                'is_selected'       => ($isoW === $selectedIsoWeek),
                'scheduled_hours'   => round($weekHours, 2),
                'completed_hours'   => round($weekCompletedHours, 2),
                'slot_count'        => $weekSlotCount,
            ];
        }

        // 5. 7 Wochentage (Montag bis Sonntag) der ausgewählten Kalenderwoche inkl. Kollisionsprüfung aufbereiten
        $days = [];
        $selectedWeekScheduled = 0.0;
        $selectedWeekCompleted = 0.0;
        $selectedWeekSlotCount = 0;
        $selectedWeekCompletedSlotCount = 0;
        $weekHasOverlaps = false;

        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $dayDt = $selectedWeekMonday->modify("+{$dayOffset} days");
            $dayDateStr = $dayDt->format('Y-m-d');
            $isoDayNum = (int) $dayDt->format('N'); // 1 (Mo) bis 7 (So)
            $daySlots = $slotsByDate[$dayDateStr] ?? [];

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
                if ((int) $sItem['is_completed'] === 1) {
                    $selectedWeekCompleted += $dur;
                    $selectedWeekCompletedSlotCount++;
                }
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
            'weeks_window'             => $weeksWindow,
            'days'                     => $days,
            'default_slot_date'        => $defaultSlotDate,
            'weekly_reference_hours'   => $weeklyReferenceHours,
            'week_summary'             => [
                'scheduled_hours'                => $selectedWeekScheduled,
                'completed_hours'                => $selectedWeekCompleted,
                'slot_count'                     => $selectedWeekSlotCount,
                'completed_slot_count'           => $selectedWeekCompletedSlotCount,
                'has_overlaps'                   => $weekHasOverlaps,
                'share_of_month_planned_percent' => $shareOfMonthPlannedPercent,
                'share_of_month_budget_percent'  => $shareOfMonthBudgetPercent,
            ],
            'monthly_goals'            => $monthlyGoals,
            'selectable_monthly_goals' => $selectableMonthlyGoals,
        ];
    }
}

