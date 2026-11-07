<?php
// src/Controllers/DashboardController.php

declare(strict_types=1);

namespace Controllers;

use DAO\DashboardDAO;
use DateTimeImmutable;

require_once __DIR__ . '/../DAO/DashboardDAO.php';
require_once __DIR__ . '/GoalController.php';

/**
 * Bereitet die Daten für das Soll-Ist-Dashboard (Story #6) auf.
 *
 * Soll = geplante Stunden der Zwischenziele (monthly_goals.planned_hours) bzw. eingeplante Timeslots.
 * Ist  = erfasste Lernzeit (time_entries, nur manuelle oder beendete Stoppuhr-Einträge).
 *
 * Das Dashboard ist rein lesend, der Controller verändert keine Daten.
 */
class DashboardController
{
    /** Ab diesem Ist/Soll-Verhältnis gilt der Zeitplan als eingehalten */
    public const ON_TRACK_RATIO = 1.0;

    /** Ab diesem Ist/Soll-Verhältnis gilt man als leicht im Verzug, darunter als im Verzug */
    public const SLIGHTLY_BEHIND_RATIO = 0.8;

    private const WEEKDAY_LABELS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    private DashboardDAO $dashboardDAO;

    public function __construct(DashboardDAO $dashboardDAO)
    {
        $this->dashboardDAO = $dashboardDAO;
    }

    /**
     * Baut alle Dashboard-Daten für einen Nutzer auf.
     *
     * @param int $userId ID des angemeldeten Nutzers
     * @param int|null $requestedGoalId Vom Nutzer gewähltes Semesterziel (GET-Parameter goal)
     * @param string|null $today Stichtag im Format YYYY-MM-DD (für Tests), Standard: heute
     * @return array<string, mixed>
     */
    public function buildDashboard(int $userId, ?int $requestedGoalId = null, ?string $today = null): array
    {
        $todayDt = self::parseDate($today) ?? new DateTimeImmutable('today');
        $todayStr = $todayDt->format('Y-m-d');

        $semesters = $this->dashboardDAO->findSemesterGoals($userId);
        $selected = self::selectSemester($semesters, $requestedGoalId, $todayStr);

        $semesterOptions = [];
        foreach ($semesters as $s) {
            $semesterOptions[] = [
                'id'          => (int) $s['id'],
                'title'       => (string) $s['title'],
                'is_selected' => $selected !== null && (int) $s['id'] === (int) $selected['id'],
            ];
        }

        $week = $this->buildWeek($userId, $todayDt);

        if ($selected === null) {
            return [
                'today'     => $todayStr,
                'semesters' => $semesterOptions,
                'semester'  => null,
                'week'      => $week,
            ];
        }

        $goalId = (int) $selected['id'];
        $startDt = self::parseDate((string) $selected['start_date']) ?? $todayDt;
        $endDt = self::parseDate((string) $selected['end_date']) ?? $todayDt;
        $targetHours = (float) $selected['target_hours'];

        $monthlyGoals = $this->dashboardDAO->findMonthlyGoalsWithProgress($userId, $goalId);
        $budgets = $this->dashboardDAO->findMonthlyBudgets($userId);
        $dailyTracked = $this->dashboardDAO->findDailyTrackedSeconds($userId, $goalId);

        $months = self::monthsBetween($startDt, $endDt);
        $modules = self::buildModules($monthlyGoals);

        // Soll und Ist je Monat (Ist = auf die Zwischenziele des Monats gebuchte Zeit)
        $plannedByMonth = array_fill_keys($months, 0.0);
        $trackedByMonth = array_fill_keys($months, 0.0);
        $completedCount = 0;
        foreach ($monthlyGoals as $mg) {
            $ym = (string) $mg['plan_month'];
            $plannedByMonth[$ym] = ($plannedByMonth[$ym] ?? 0.0) + (float) $mg['planned_hours'];
            $trackedByMonth[$ym] = ($trackedByMonth[$ym] ?? 0.0) + ((int) $mg['tracked_seconds']) / 3600;
            if (self::isMonthlyGoalCompleted($mg)) {
                $completedCount++;
            }
        }
        ksort($plannedByMonth);
        ksort($trackedByMonth);

        $plannedTotal = array_sum($plannedByMonth);
        $trackedTotal = array_sum($trackedByMonth);

        $monthlyChart = ['labels' => [], 'months' => [], 'soll' => [], 'ist' => [], 'budget' => []];
        foreach (array_keys($plannedByMonth) as $ym) {
            $monthlyChart['labels'][] = GoalController::formatYearMonth($ym, true);
            $monthlyChart['months'][] = $ym;
            $monthlyChart['soll'][] = round($plannedByMonth[$ym], 2);
            $monthlyChart['ist'][] = round($trackedByMonth[$ym], 2);
            $monthlyChart['budget'][] = isset($budgets[$ym]) ? round($budgets[$ym], 2) : null;
        }

        $cumulative = self::buildCumulative($startDt, $endDt, $todayDt, $plannedByMonth, $targetHours, $dailyTracked);
        $expectedToday = $cumulative['expected_today'];
        $trackedToday = $cumulative['tracked_today'];

        $status = self::scheduleStatus(
            $todayStr,
            (string) $selected['start_date'],
            !empty($selected['completed_at']),
            $expectedToday,
            $trackedToday
        );

        $totalDays = max(1, (int) $startDt->diff($endDt)->days + 1);
        $elapsedDays = $todayDt < $startDt ? 0 : min($totalDays, (int) $startDt->diff($todayDt)->days + 1);

        // Aktueller Monat (nur wenn er im Semester liegt, sonst der erste bzw. letzte Monat)
        $currentYm = $todayDt->format('Y-m');
        if (!isset($plannedByMonth[$currentYm])) {
            $currentYm = $todayDt < $startDt ? array_key_first($plannedByMonth) : array_key_last($plannedByMonth);
        }
        $currentPlanned = (float) ($plannedByMonth[$currentYm] ?? 0.0);
        $currentTracked = (float) ($trackedByMonth[$currentYm] ?? 0.0);

        return [
            'today'     => $todayStr,
            'semesters' => $semesterOptions,
            'week'      => $week,
            'semester'  => [
                'id'                  => $goalId,
                'title'               => (string) $selected['title'],
                'description'         => (string) ($selected['description'] ?? ''),
                'start_date'          => (string) $selected['start_date'],
                'end_date'            => (string) $selected['end_date'],
                'is_completed'        => !empty($selected['completed_at']),
                'target_hours'        => round($targetHours, 2),
                'planned_hours'       => round($plannedTotal, 2),
                'tracked_hours'       => round($trackedTotal, 2),
                'progress_percent'    => self::percent($trackedTotal, $targetHours),
                'planned_percent'     => self::percent($plannedTotal, $targetHours),
                'elapsed_percent'     => self::percent((float) $elapsedDays, (float) $totalDays),
                'days_left'           => max(0, $totalDays - $elapsedDays),
                'expected_hours'      => round($expectedToday, 2),
                'tracked_until_today' => round($trackedToday, 2),
                'delta_hours'         => round($trackedToday - $expectedToday, 2),
                'status'              => $status,
                'monthly_goal_count'  => count($monthlyGoals),
                'completed_count'     => $completedCount,
            ],
            'current_month' => [
                'year_month'    => $currentYm,
                'label'         => GoalController::formatYearMonth((string) $currentYm),
                'planned_hours' => round($currentPlanned, 2),
                'tracked_hours' => round($currentTracked, 2),
                'budget_hours'  => isset($budgets[$currentYm]) ? round($budgets[$currentYm], 2) : null,
                'percent'       => self::percent($currentTracked, $currentPlanned),
            ],
            'monthly_chart' => $monthlyChart,
            'cumulative'    => [
                'labels'      => $cumulative['labels'],
                'soll'        => $cumulative['soll'],
                'ist'         => $cumulative['ist'],
                'today_index' => $cumulative['today_index'],
                'soll_source' => $plannedTotal > 0 ? 'planning' : 'target',
            ],
            'modules'     => self::addModuleShares($modules, $trackedTotal),
            'tree_months' => self::treeMonths($modules),
        ];
    }

    /**
     * Ergänzt je Modul den Anteil an der gesamten erfassten Semesterzeit.
     * Bezug ist bewusst die Semestersumme aus den Kennzahlen, damit die Prozente zu dieser Zahl passen.
     *
     * @param list<array<string, mixed>> $modules
     * @return list<array<string, mixed>>
     */
    public static function addModuleShares(array $modules, float $trackedTotal): array
    {
        foreach ($modules as $i => $module) {
            $modules[$i]['share_percent'] = self::percent((float) $module['tracked_hours'], $trackedTotal);
        }

        return $modules;
    }

    /**
     * Alle Monate, in denen mindestens ein Modul ein Zwischenziel hat, chronologisch.
     * Sie bilden die Zeilen der Zielstruktur, damit parallel laufende Module auf einer Höhe stehen.
     *
     * @param list<array<string, mixed>> $modules
     * @return list<string> Monate als YYYY-MM
     */
    public static function treeMonths(array $modules): array
    {
        $months = [];
        foreach ($modules as $module) {
            foreach ($module['children'] as $child) {
                $months[(string) $child['year_month']] = true;
            }
        }
        ksort($months);

        return array_keys($months);
    }

    /**
     * Wählt das anzuzeigende Semesterziel: das angefragte, sonst das laufende,
     * sonst das nächste anstehende, sonst das zuletzt beendete.
     *
     * @param list<array<string, mixed>> $semesters
     * @return array<string, mixed>|null
     */
    public static function selectSemester(array $semesters, ?int $requestedGoalId, string $today): ?array
    {
        if ($semesters === []) {
            return null;
        }

        if ($requestedGoalId !== null) {
            foreach ($semesters as $s) {
                if ((int) $s['id'] === $requestedGoalId) {
                    return $s;
                }
            }
        }

        foreach ($semesters as $s) {
            if ((string) $s['start_date'] <= $today && (string) $s['end_date'] >= $today) {
                return $s;
            }
        }

        foreach ($semesters as $s) {
            if ((string) $s['start_date'] > $today) {
                return $s;
            }
        }

        return $semesters[count($semesters) - 1];
    }

    /**
     * Bewertet, ob die erfasste Lernzeit bis heute mit dem Soll bis heute mithält.
     *
     * @return array{key: string, label: string, variant: string}
     */
    public static function scheduleStatus(
        string $today,
        string $startDate,
        bool $isCompleted,
        float $expectedHours,
        float $trackedHours
    ): array {
        if ($isCompleted) {
            return ['key' => 'completed', 'label' => 'Abgeschlossen', 'variant' => 'success'];
        }
        if ($today < $startDate) {
            return ['key' => 'upcoming', 'label' => 'Noch nicht gestartet', 'variant' => 'secondary'];
        }
        if ($expectedHours <= 0.0) {
            return ['key' => 'no_target', 'label' => 'Noch kein Soll fällig', 'variant' => 'secondary'];
        }

        $ratio = $trackedHours / $expectedHours;
        if ($ratio >= self::ON_TRACK_RATIO) {
            return ['key' => 'on_track', 'label' => 'Im Zeitplan', 'variant' => 'success'];
        }
        if ($ratio >= self::SLIGHTLY_BEHIND_RATIO) {
            return ['key' => 'slightly_behind', 'label' => 'Leicht im Verzug', 'variant' => 'warning'];
        }

        return ['key' => 'behind', 'label' => 'Im Verzug', 'variant' => 'danger'];
    }

    /**
     * Ein Zwischenziel gilt als erledigt, wenn ein Abschlussdatum gesetzt ist oder es ohne
     * eingeplante Timeslots die Soll-Zeit erreicht hat (gleiche Regel wie in der Planung).
     *
     * @param array<string, mixed> $monthlyGoal
     */
    public static function isMonthlyGoalCompleted(array $monthlyGoal): bool
    {
        if (!empty($monthlyGoal['completed_at'])) {
            return true;
        }

        $plannedSeconds = (int) round(((float) $monthlyGoal['planned_hours']) * 3600);

        return (int) ($monthlyGoal['scheduled_seconds'] ?? 0) === 0
            && $plannedSeconds > 0
            && (int) $monthlyGoal['tracked_seconds'] >= $plannedSeconds;
    }

    /**
     * Gruppiert die Zwischenziele nach Modul (gleicher Titel) für die Zielstruktur.
     * Die Reihenfolge folgt dem ersten Auftreten, damit jedes Modul eine feste Farbe behält.
     *
     * @param list<array<string, mixed>> $monthlyGoals
     * @return list<array<string, mixed>>
     */
    public static function buildModules(array $monthlyGoals): array
    {
        $modules = [];
        foreach ($monthlyGoals as $mg) {
            $title = trim((string) $mg['title']);
            $key = mb_strtolower($title);
            $planned = (float) $mg['planned_hours'];
            $tracked = ((int) $mg['tracked_seconds']) / 3600;
            $completed = self::isMonthlyGoalCompleted($mg);

            if (!isset($modules[$key])) {
                $modules[$key] = [
                    'title'         => $title,
                    'color_index'   => count($modules),
                    'planned_hours' => 0.0,
                    'tracked_hours' => 0.0,
                    'children'      => [],
                ];
            }

            $modules[$key]['planned_hours'] += $planned;
            $modules[$key]['tracked_hours'] += $tracked;
            $modules[$key]['children'][] = [
                'id'            => (int) $mg['id'],
                'year_month'    => (string) $mg['plan_month'],
                'month_label'   => GoalController::formatYearMonth((string) $mg['plan_month'], true),
                'description'   => (string) ($mg['description'] ?? ''),
                'planned_hours' => round($planned, 2),
                'tracked_hours' => round($tracked, 2),
                'percent'       => self::percent($tracked, $planned),
                'is_completed'  => $completed,
            ];
        }

        $result = [];
        foreach ($modules as $module) {
            $doneCount = count(array_filter($module['children'], static fn (array $c): bool => $c['is_completed']));
            $module['planned_hours'] = round($module['planned_hours'], 2);
            $module['tracked_hours'] = round($module['tracked_hours'], 2);
            $module['percent'] = self::percent($module['tracked_hours'], $module['planned_hours']);
            $module['completed_count'] = $doneCount;
            $module['is_completed'] = $doneCount === count($module['children']);
            $module['target'] = self::moduleTarget($module['children']);
            $result[] = $module;
        }

        return $result;
    }

    /**
     * Sprungziel eines Moduls in der Planung: das erste noch offene Zwischenziel,
     * bei komplett erledigten Modulen das letzte.
     *
     * @param list<array<string, mixed>> $children Zwischenziele des Moduls, chronologisch
     * @return array{id: int, year_month: string}|null Null, wenn das Modul keine Zwischenziele hat
     */
    public static function moduleTarget(array $children): ?array
    {
        if ($children === []) {
            return null;
        }

        $target = $children[count($children) - 1];
        foreach ($children as $child) {
            if (!$child['is_completed']) {
                $target = $child;
                break;
            }
        }

        return ['id' => (int) $target['id'], 'year_month' => (string) $target['year_month']];
    }

    /**
     * Liefert den Link in die Monatsplanung, der direkt zu einem Zwischenziel springt.
     */
    public static function monthlyGoalUrl(int $monthlyGoalId, string $yearMonth): string
    {
        return 'goals.php?tab=month&month=' . rawurlencode($yearMonth) . '#monthly-goal-' . $monthlyGoalId;
    }

    /**
     * Liefert den Link in die Semesterplanung, der direkt zu einem Semesterziel springt.
     */
    public static function semesterGoalUrl(int $goalId): string
    {
        return 'goals.php?tab=semester#goal-' . $goalId;
    }

    /**
     * Baut die kumulierten Soll- und Ist-Kurven über die Semestertage auf.
     * Das Soll eines Monats verteilt sich gleichmäßig auf seine Tage innerhalb des Semesters.
     * Ohne Monatsplanung dient der Zielwert des Semesters, linear verteilt, als Soll.
     *
     * @param array<string, float> $plannedByMonth
     * @param array<string, int> $dailyTracked
     * @return array{labels: list<string>, soll: list<float>, ist: list<float|null>, today_index: int|null, expected_today: float, tracked_today: float}
     */
    public static function buildCumulative(
        DateTimeImmutable $startDt,
        DateTimeImmutable $endDt,
        DateTimeImmutable $todayDt,
        array $plannedByMonth,
        float $targetHours,
        array $dailyTracked
    ): array {
        $usePlanning = array_sum($plannedByMonth) > 0;
        $totalDays = max(1, (int) $startDt->diff($endDt)->days + 1);

        // Anzahl Semestertage je Monat (Rand-Monate können angeschnitten sein)
        $daysInMonth = [];
        for ($d = $startDt; $d <= $endDt; $d = $d->modify('+1 day')) {
            $ym = $d->format('Y-m');
            $daysInMonth[$ym] = ($daysInMonth[$ym] ?? 0) + 1;
        }

        // Ist-Zeit vor Semesterbeginn wird dem ersten Tag zugerechnet
        $sumIst = 0.0;
        foreach ($dailyTracked as $date => $seconds) {
            if ($date < $startDt->format('Y-m-d')) {
                $sumIst += $seconds / 3600;
            }
        }

        $labels = [];
        $soll = [];
        $ist = [];
        $sumSoll = 0.0;
        $todayIndex = null;
        $expectedToday = 0.0;
        $trackedToday = 0.0;
        $todayStr = $todayDt->format('Y-m-d');
        $i = 0;

        for ($d = $startDt; $d <= $endDt; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $ym = $d->format('Y-m');

            $sumSoll += $usePlanning
                ? ($plannedByMonth[$ym] ?? 0.0) / max(1, $daysInMonth[$ym])
                : $targetHours / $totalDays;
            $sumIst += ($dailyTracked[$date] ?? 0) / 3600;

            $labels[] = $date;
            $soll[] = round($sumSoll, 2);
            $ist[] = $date <= $todayStr ? round($sumIst, 2) : null;

            if ($date === $todayStr) {
                $todayIndex = $i;
            }
            if ($date <= $todayStr) {
                $expectedToday = $sumSoll;
                $trackedToday = $sumIst;
            }
            $i++;
        }

        // Nach Semesterende zählt auch später gebuchte Zeit noch zum Ist
        if ($todayStr > $endDt->format('Y-m-d')) {
            foreach ($dailyTracked as $date => $seconds) {
                if ($date > $endDt->format('Y-m-d') && $date <= $todayStr) {
                    $trackedToday += $seconds / 3600;
                }
            }
        }

        return [
            'labels'         => $labels,
            'soll'           => $soll,
            'ist'            => $ist,
            'today_index'    => $todayIndex,
            'expected_today' => $expectedToday,
            'tracked_today'  => $trackedToday,
        ];
    }

    /**
     * Soll (eingeplante Timeslots) und Ist (erfasste Zeit) der laufenden Kalenderwoche, über alle Ziele.
     *
     * @return array<string, mixed>
     */
    private function buildWeek(int $userId, DateTimeImmutable $todayDt): array
    {
        $monday = $todayDt->modify('monday this week');
        $sunday = $monday->modify('+6 days');
        $scheduled = $this->dashboardDAO->findScheduledSecondsByDay($userId, $monday->format('Y-m-d'), $sunday->format('Y-m-d'));
        $tracked = $this->dashboardDAO->findTrackedSecondsByDay($userId, $monday->format('Y-m-d'), $sunday->format('Y-m-d'));

        $days = [];
        $sumScheduled = 0.0;
        $sumTracked = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->modify("+{$i} days");
            $date = $day->format('Y-m-d');
            $s = ($scheduled[$date] ?? 0) / 3600;
            $t = ($tracked[$date] ?? 0) / 3600;
            $sumScheduled += $s;
            $sumTracked += $t;
            $days[] = [
                'date'            => $date,
                'label'           => self::WEEKDAY_LABELS[$i] . ' ' . $day->format('d.m.'),
                'scheduled_hours' => round($s, 2),
                'tracked_hours'   => round($t, 2),
                'is_today'        => $day == $todayDt,
            ];
        }

        return [
            'label'           => 'KW ' . (int) $monday->format('W') . ' · ' . $monday->format('d.m.') . '–' . $sunday->format('d.m.Y'),
            'days'            => $days,
            'scheduled_hours' => round($sumScheduled, 2),
            'tracked_hours'   => round($sumTracked, 2),
            'percent'         => self::percent($sumTracked, $sumScheduled),
        ];
    }

    /**
     * Liefert alle Monate (YYYY-MM) von Start bis Ende einschließlich.
     *
     * @return list<string>
     */
    public static function monthsBetween(DateTimeImmutable $startDt, DateTimeImmutable $endDt): array
    {
        $months = [];
        $cursor = $startDt->modify('first day of this month');
        $last = $endDt->format('Y-m');
        while ($cursor->format('Y-m') <= $last) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * Prozentwert mit einer Nachkommastelle, 0 bei fehlender Basis.
     */
    public static function percent(float $value, float $base): float
    {
        return $base > 0 ? round(($value / $base) * 100, 1) : 0.0;
    }

    private static function parseDate(?string $date): ?DateTimeImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));

        return ($dt && $dt->format('Y-m-d') === trim($date)) ? $dt : null;
    }
}
