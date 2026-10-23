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
            throw new ValidationException("Bitte gib einen Modul- bzw. Lernzielnamen an.");
        }
        $title = trim($titleRaw);
        if (mb_strlen($title, 'UTF-8') > self::MAX_TITLE_LENGTH) {
            throw new ValidationException("Der Name darf höchstens " . self::MAX_TITLE_LENGTH . " Zeichen lang sein.");
        }

        // 2. Zielstunden (Pflichtfeld, positiv)
        $hoursRaw = $input['target_hours'] ?? null;
        if ($hoursRaw === null || (is_string($hoursRaw) && trim($hoursRaw) === '')) {
            throw new ValidationException("Bitte gib die geplanten Zielstunden an.");
        }

        // Kommas durch Punkte ersetzen (deutsches Eingabeformat unterstützen)
        if (is_string($hoursRaw)) {
            $hoursRaw = str_replace(',', '.', trim($hoursRaw));
        }

        if (!is_numeric($hoursRaw)) {
            throw new ValidationException("Die Zielstunden müssen eine Zahl sein.");
        }

        $targetHours = (float) $hoursRaw;
        if ($targetHours < self::MIN_TARGET_HOURS || $targetHours > self::MAX_TARGET_HOURS) {
            throw new ValidationException(sprintf(
                "Die Zielstunden müssen zwischen %.1f und %.1f Stunden liegen.",
                self::MIN_TARGET_HOURS,
                self::MAX_TARGET_HOURS
            ));
        }

        // 3. Startdatum (Pflichtfeld, Format Y-m-d)
        $startDateRaw = $input['start_date'] ?? null;
        if (!is_string($startDateRaw) || trim($startDateRaw) === '') {
            throw new ValidationException("Bitte gib ein Startdatum an.");
        }
        $startDate = trim($startDateRaw);
        $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if (!$startDt || $startDt->format('Y-m-d') !== $startDate) {
            throw new ValidationException("Das Startdatum muss im gültigen Format JJJJ-MM-TT vorliegen.");
        }

        // 4. Enddatum (Pflichtfeld, Format Y-m-d)
        $endDateRaw = $input['end_date'] ?? null;
        if (!is_string($endDateRaw) || trim($endDateRaw) === '') {
            throw new ValidationException("Bitte gib ein Enddatum an.");
        }
        $endDate = trim($endDateRaw);
        $endDt = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
        if (!$endDt || $endDt->format('Y-m-d') !== $endDate) {
            throw new ValidationException("Das Enddatum muss im gültigen Format JJJJ-MM-TT vorliegen.");
        }

        // Logische Prüfung: Enddatum darf nicht vor dem Startdatum liegen
        if ($endDt < $startDt) {
            throw new ValidationException("Das Enddatum darf zeitlich nicht vor dem Startdatum liegen.");
        }

        // 5. Beschreibung (optional)
        $description = null;
        if (isset($input['description']) && is_string($input['description'])) {
            $descTrimmed = trim($input['description']);
            if ($descTrimmed !== '') {
                if (mb_strlen($descTrimmed, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
                    throw new ValidationException("Die Beschreibung darf höchstens " . self::MAX_DESCRIPTION_LENGTH . " Zeichen lang sein.");
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

        if (is_string($budgetRaw)) {
            $budgetRaw = str_replace(',', '.', trim($budgetRaw));
        }

        if (!is_numeric($budgetRaw)) {
            throw new ValidationException("Das monatliche Soll-Zeitbudget muss eine Zahl sein.");
        }

        $budgetHours = round((float) $budgetRaw, 2);
        if ($budgetHours < 0.0 || $budgetHours > self::MAX_MONTHLY_HOURS) {
            throw new ValidationException(sprintf(
                "Das monatliche Soll-Zeitbudget muss zwischen 0,0 und %.1f Stunden liegen.",
                self::MAX_MONTHLY_HOURS
            ));
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
        // 1. Zugeordnetes 6-Monats-Lernziel prüfen (inkl. Mandantenisolation)
        $goalIdRaw = $input['goal_id'] ?? null;
        $goalId = is_numeric($goalIdRaw) ? (int) $goalIdRaw : 0;
        if ($goalId <= 0) {
            throw new ValidationException("Bitte wähle ein übergeordnetes 6-Monats-Lernziel aus.");
        }

        $parentGoal = $this->goalDAO->findByIdAndUserId($goalId, $userId);
        if ($parentGoal === null) {
            throw new ValidationException("Das ausgewählte 6-Monats-Lernziel existiert nicht oder gehört einem anderen Benutzer.");
        }

        // 2. Planungsmonat prüfen (YYYY-MM)
        $yearMonth = $this->validateYearMonth($input['year_month'] ?? null);

        // 3. Bezeichnung des Zwischenziels (Pflichtfeld)
        $titleRaw = $input['title'] ?? null;
        if (!is_string($titleRaw) || trim($titleRaw) === '') {
            throw new ValidationException("Bitte gib eine Bezeichnung für das monatliche Zwischenziel an.");
        }
        $title = trim($titleRaw);
        if (mb_strlen($title, 'UTF-8') > self::MAX_TITLE_LENGTH) {
            throw new ValidationException("Die Bezeichnung des Zwischenziels darf höchstens " . self::MAX_TITLE_LENGTH . " Zeichen lang sein.");
        }

        // 4. Geplante Lernzeit im Monat (Pflichtfeld, positiv)
        $hoursRaw = $input['planned_hours'] ?? null;
        if ($hoursRaw === null || (is_string($hoursRaw) && trim($hoursRaw) === '')) {
            throw new ValidationException("Bitte gib die geplanten Lernstunden für dieses Zwischenziel an.");
        }

        if (is_string($hoursRaw)) {
            $hoursRaw = str_replace(',', '.', trim($hoursRaw));
        }

        if (!is_numeric($hoursRaw)) {
            throw new ValidationException("Die geplanten Lernstunden müssen eine Zahl sein.");
        }

        $plannedHours = (float) $hoursRaw;
        if ($plannedHours < self::MIN_TARGET_HOURS || $plannedHours > self::MAX_MONTHLY_HOURS) {
            throw new ValidationException(sprintf(
                "Die geplanten Lernstunden im Monat müssen zwischen %.1f und %.1f Stunden liegen.",
                self::MIN_TARGET_HOURS,
                self::MAX_MONTHLY_HOURS
            ));
        }

        // 5. Optionale Beschreibung / Notiz
        $description = null;
        if (isset($input['description']) && is_string($input['description'])) {
            $descTrimmed = trim($input['description']);
            if ($descTrimmed !== '') {
                if (mb_strlen($descTrimmed, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
                    throw new ValidationException("Die Notiz darf höchstens " . self::MAX_DESCRIPTION_LENGTH . " Zeichen lang sein.");
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
            throw new ValidationException("Bitte wähle einen gültigen Planungsmonat aus.");
        }

        $yearMonth = trim($yearMonthRaw);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
            throw new ValidationException("Der Monat muss im gültigen Format JJJJ-MM vorliegen.");
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

        foreach ($monthlyGoalsRaw as $mg) {
            $gId = (int) $mg['goal_id'];
            $pHours = (float) $mg['planned_hours'];
            $parentTarget = (float) $mg['parent_target_hours'];
            $parentAllocated = $allocatedByGoalId[$gId] ?? $pHours;

            $mg['parent_allocated_hours'] = $parentAllocated;
            $mg['is_parent_over_allocated'] = $parentAllocated > $parentTarget;
            $mg['share_of_month_budget_percent'] = ($selectedBudget !== null && $selectedBudget > 0)
                ? round(($pHours / $selectedBudget) * 100, 1)
                : null;
            $mg['share_of_month_planned_percent'] = $selectedPlanned > 0
                ? round(($pHours / $selectedPlanned) * 100, 1)
                : 0.0;

            $monthlyGoals[] = $mg;
        }

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
}

