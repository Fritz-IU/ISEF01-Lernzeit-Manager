<?php
// tests/Unit/Controllers/DashboardControllerTest.php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Controllers\DashboardController;
use DAO\DashboardDAO;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für das Soll-Ist-Dashboard (Story #6, siehe docs/dashboard.md).
 * Der DashboardDAO wird durch einen Mock ersetzt, es wird keine Datenbank benötigt.
 */
final class DashboardControllerTest extends TestCase
{
    private const USER_ID = 42;

    private const SEMESTER = [
        'id'           => 7,
        'title'        => 'WiSe 2026/27',
        'description'  => '',
        'target_hours' => 100.0,
        'start_date'   => '2026-10-01',
        'end_date'     => '2026-11-30',
        'completed_at' => null,
    ];

    private DashboardDAO&MockObject $dao;
    private DashboardController $controller;

    protected function setUp(): void
    {
        $this->dao = $this->createMock(DashboardDAO::class);
        $this->dao->method('findScheduledSecondsByDay')->willReturn([]);
        $this->dao->method('findTrackedSecondsByDay')->willReturn([]);
        $this->controller = new DashboardController($this->dao);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function zwischenziel(array $overrides = []): array
    {
        return array_merge([
            'id'                => 1,
            'plan_month'        => '2026-10',
            'title'             => 'Modul Datenbanken',
            'description'       => '',
            'planned_hours'     => 31.0,
            'completed_at'      => null,
            'scheduled_seconds' => 0,
            'tracked_seconds'   => 0,
        ], $overrides);
    }

    #[Test]
    public function ohneSemesterzielWirdKeinSemesterAngezeigt(): void
    {
        // Vorbereiten
        $this->dao->method('findSemesterGoals')->willReturn([]);

        // Ausführen
        $result = $this->controller->buildDashboard(self::USER_ID, null, '2026-10-15');

        // Prüfen
        $this->assertNull($result['semester']);
        $this->assertCount(7, $result['week']['days']);
    }

    #[Test]
    public function stelltIstUndSollBisHeuteGegenueber(): void
    {
        // Vorbereiten: 31 h im Oktober geplant = 1 h pro Tag, bis 10.10. sind 10 h Soll fällig
        $this->dao->method('findSemesterGoals')->willReturn([self::SEMESTER]);
        $this->dao->method('findMonthlyGoalsWithProgress')->willReturn([
            self::zwischenziel(['tracked_seconds' => 8 * 3600]),
        ]);
        $this->dao->method('findMonthlyBudgets')->willReturn(['2026-10' => 40.0]);
        $this->dao->method('findDailyTrackedSeconds')->willReturn([
            '2026-10-02' => 5 * 3600,
            '2026-10-09' => 3 * 3600,
        ]);

        // Ausführen
        $result = $this->controller->buildDashboard(self::USER_ID, null, '2026-10-10');
        $semester = $result['semester'];

        // Prüfen
        $this->assertSame(8.0, $semester['tracked_hours']);
        $this->assertSame(10.0, $semester['expected_hours']);
        $this->assertSame(-2.0, $semester['delta_hours']);
        $this->assertSame('slightly_behind', $semester['status']['key']);
        $this->assertSame(8.0, $semester['progress_percent']);
        $this->assertSame([31.0, 0.0], $result['monthly_chart']['soll']);
        $this->assertSame([8.0, 0.0], $result['monthly_chart']['ist']);
        $this->assertSame([40.0, null], $result['monthly_chart']['budget']);
        $this->assertSame(9, $result['cumulative']['today_index']);
        $this->assertNull($result['cumulative']['ist'][10], 'Nach heute gibt es noch kein Ist');
    }

    #[Test]
    public function ohneMonatsplanungIstDerZielwertDasSoll(): void
    {
        // Vorbereiten: 100 h auf 61 Tage verteilt
        $this->dao->method('findSemesterGoals')->willReturn([self::SEMESTER]);
        $this->dao->method('findMonthlyGoalsWithProgress')->willReturn([]);
        $this->dao->method('findMonthlyBudgets')->willReturn([]);
        $this->dao->method('findDailyTrackedSeconds')->willReturn([]);

        // Ausführen
        $result = $this->controller->buildDashboard(self::USER_ID, null, '2026-11-30');

        // Prüfen
        $this->assertSame('target', $result['cumulative']['soll_source']);
        $this->assertSame(100.0, end($result['cumulative']['soll']));
        $this->assertSame('behind', $result['semester']['status']['key']);
    }

    #[Test]
    public function waehltDasAngefragteSemesterNurWennEsDemNutzerGehoert(): void
    {
        $semesters = [
            ['id' => 1, 'start_date' => '2026-03-01', 'end_date' => '2026-08-31'],
            ['id' => 2, 'start_date' => '2026-09-01', 'end_date' => '2027-02-28'],
            ['id' => 3, 'start_date' => '2027-03-01', 'end_date' => '2027-08-31'],
        ];

        $this->assertSame(3, DashboardController::selectSemester($semesters, 3, '2026-10-15')['id']);
        $this->assertSame(2, DashboardController::selectSemester($semesters, 99, '2026-10-15')['id'], 'Fremde ID fällt auf das laufende Semester zurück');
        $this->assertSame(2, DashboardController::selectSemester($semesters, null, '2026-10-15')['id']);
        $this->assertSame(1, DashboardController::selectSemester($semesters, null, '2026-01-15')['id'], 'Vor allen Semestern: das nächste anstehende');
        $this->assertSame(3, DashboardController::selectSemester($semesters, null, '2028-01-01')['id'], 'Nach allen Semestern: das letzte');
        $this->assertNull(DashboardController::selectSemester([], null, '2026-10-15'));
    }

    /**
     * @return array<string, array{float, float, string}>
     */
    public static function statusFaelle(): array
    {
        return [
            'genau im Plan'      => [10.0, 10.0, 'on_track'],
            'Vorsprung'          => [10.0, 12.0, 'on_track'],
            'Grenze leicht'      => [10.0, 8.0, 'slightly_behind'],
            'deutlich im Verzug' => [10.0, 7.9, 'behind'],
            'noch kein Soll'     => [0.0, 0.0, 'no_target'],
        ];
    }

    #[Test]
    #[DataProvider('statusFaelle')]
    public function bewertetDenZeitplan(float $expected, float $tracked, string $key): void
    {
        $status = DashboardController::scheduleStatus('2026-10-15', '2026-10-01', false, $expected, $tracked);

        $this->assertSame($key, $status['key']);
    }

    #[Test]
    public function abgeschlossenesUndKuenftigesSemesterHabenEigenenStatus(): void
    {
        $this->assertSame('completed', DashboardController::scheduleStatus('2026-10-15', '2026-10-01', true, 10.0, 0.0)['key']);
        $this->assertSame('upcoming', DashboardController::scheduleStatus('2026-09-15', '2026-10-01', false, 0.0, 0.0)['key']);
    }

    #[Test]
    public function gruppiertZwischenzieleNachModulMitFesterFarbe(): void
    {
        $modules = DashboardController::buildModules([
            self::zwischenziel(['id' => 1, 'title' => 'Modul A', 'planned_hours' => 10.0, 'tracked_seconds' => 3600 * 10, 'scheduled_seconds' => 3600]),
            self::zwischenziel(['id' => 2, 'title' => 'Modul B', 'planned_hours' => 5.0]),
            self::zwischenziel(['id' => 3, 'title' => 'Modul A', 'plan_month' => '2026-11', 'planned_hours' => 10.0, 'completed_at' => '2026-11-20']),
        ]);

        $this->assertCount(2, $modules);
        $this->assertSame('Modul A', $modules[0]['title']);
        $this->assertSame(0, $modules[0]['color_index']);
        $this->assertSame(1, $modules[1]['color_index']);
        $this->assertSame(20.0, $modules[0]['planned_hours']);
        $this->assertSame(50.0, $modules[0]['percent']);
        $this->assertCount(2, $modules[0]['children']);
        // Soll erreicht, aber Timeslots eingeplant und nicht abgehakt: noch offen
        $this->assertFalse($modules[0]['children'][0]['is_completed']);
        $this->assertTrue($modules[0]['children'][1]['is_completed']);
        $this->assertSame(1, $modules[0]['completed_count']);
        $this->assertFalse($modules[0]['is_completed']);
        // Sprungziel ist das erste noch offene Zwischenziel des Moduls
        $this->assertSame(['id' => 1, 'year_month' => '2026-10'], $modules[0]['target']);
    }

    #[Test]
    public function modulanteilBeziehtSichAufDieSemestersumme(): void
    {
        $modules = DashboardController::addModuleShares([
            ['title' => 'Modul A', 'tracked_hours' => 5.4],
            ['title' => 'Modul B', 'tracked_hours' => 0.0],
        ], 39.0);

        $this->assertSame(13.8, $modules[0]['share_percent']);
        $this->assertSame(0.0, $modules[1]['share_percent']);
        // Ohne erfasste Zeit kein Division-by-zero
        $this->assertSame(0.0, DashboardController::addModuleShares([['tracked_hours' => 0.0]], 0.0)[0]['share_percent']);
    }

    #[Test]
    public function zielstrukturZeilenSindAlleMonateChronologisch(): void
    {
        $months = DashboardController::treeMonths([
            ['children' => [['year_month' => '2026-11'], ['year_month' => '2026-12']]],
            ['children' => [['year_month' => '2026-10'], ['year_month' => '2026-11']]],
        ]);

        $this->assertSame(['2026-10', '2026-11', '2026-12'], $months);
    }

    #[Test]
    public function erledigtesModulSpringtZumLetztenZwischenziel(): void
    {
        $target = DashboardController::moduleTarget([
            ['id' => 4, 'year_month' => '2026-09', 'is_completed' => true],
            ['id' => 9, 'year_month' => '2026-10', 'is_completed' => true],
        ]);

        $this->assertSame(['id' => 9, 'year_month' => '2026-10'], $target);
    }

    #[Test]
    public function modulOhneZwischenzieleHatKeinSprungziel(): void
    {
        $this->assertNull(DashboardController::moduleTarget([]));
    }

    #[Test]
    public function linksFuehrenDirektZumZielInDerPlanung(): void
    {
        $this->assertSame('goals.php?tab=month&month=2026-10#monthly-goal-12', DashboardController::monthlyGoalUrl(12, '2026-10'));
        $this->assertSame('goals.php?tab=semester#goal-3', DashboardController::semesterGoalUrl(3));
    }

    #[Test]
    public function zwischenzielOhneTimeslotsIstMitErreichterSollzeitErledigt(): void
    {
        $this->assertTrue(DashboardController::isMonthlyGoalCompleted(self::zwischenziel([
            'planned_hours' => 2.0,
            'tracked_seconds' => 7200,
        ])));
        $this->assertFalse(DashboardController::isMonthlyGoalCompleted(self::zwischenziel([
            'planned_hours' => 2.0,
            'tracked_seconds' => 7199,
        ])));
    }

    #[Test]
    public function verteiltMonatsSollAufAngeschnitteneMonate(): void
    {
        // Semester beginnt am 22.10.: die 10 Oktober-Tage tragen das ganze Oktober-Soll
        $result = DashboardController::buildCumulative(
            new DateTimeImmutable('2026-10-22'),
            new DateTimeImmutable('2026-11-30'),
            new DateTimeImmutable('2026-10-31'),
            ['2026-10' => 20.0, '2026-11' => 30.0],
            50.0,
            []
        );

        $this->assertSame(20.0, $result['expected_today']);
        $this->assertSame(50.0, end($result['soll']));
    }

    // ------------------------------------------------------------------
    // US-7: Inaktivitäts-Hinweis auf dem Dashboard
    // ------------------------------------------------------------------

    /**
     * Verpasste Lerneinheit (1,5 h) am 30.10.2026 im offenen Modul Datenbanken.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function verpassteEinheit(array $overrides = []): array
    {
        return array_merge([
            'id'                        => 11,
            'slot_date'                 => '2026-10-30',
            'start_time'                => '14:00:00',
            'end_time'                  => '15:30:00',
            'notes'                     => 'Views wiederholen',
            'scheduled_seconds'         => 5400,
            'monthly_goal_id'           => 4,
            'monthly_goal_title'        => 'Modul Datenbanken',
            'plan_month'                => '2026-10',
            'monthly_goal_completed_at' => null,
            'goal_id'                   => 7,
            'goal_title'                => 'WiSe 2026/27',
        ], $overrides);
    }

    #[Test]
    public function zeigtVerpassteLernzeitenUndOffeneZieleSeitDemLetztenLogin(): void
    {
        // TC-7-01: Lerneinheiten nach dem letzten Login verpasst -> Hinweis mit Tagesleiste und offenen Modulen
        $this->dao->expects($this->once())
            ->method('findMissedSlots')
            ->with(self::USER_ID, '2026-10-28 21:05:00', '2026-11-07 09:00:00')
            ->willReturn([
                self::verpassteEinheit(),
                self::verpassteEinheit([
                    'id' => 12, 'slot_date' => '2026-11-03', 'scheduled_seconds' => 3600,
                    'monthly_goal_id' => 7, 'plan_month' => '2026-11',
                ]),
                self::verpassteEinheit([
                    'id' => 13, 'slot_date' => '2026-11-03', 'monthly_goal_id' => 5,
                    'monthly_goal_title' => 'Modul Betriebssysteme', 'plan_month' => '2026-11',
                ]),
            ]);

        $notice = $this->controller->buildInactivityNotice(self::USER_ID, '2026-10-28 21:05:00', '2026-11-07 09:00:00');

        $this->assertNotNull($notice);
        $this->assertSame(3, $notice['missed_count']);
        $this->assertSame(4.0, $notice['missed_hours']);

        // Tagesleiste vom Tag des letzten Logins (Mi 28.10.) bis heute (Sa 7.11.)
        $this->assertCount(11, $notice['days']);
        $this->assertSame(['2026-10-28', 'Mi', '28'], [$notice['days'][0]['date'], $notice['days'][0]['weekday'], $notice['days'][0]['day']]);
        $this->assertTrue($notice['days'][10]['is_today']);
        $this->assertFalse($notice['days_truncated']);
        $this->assertSame(1.5, $notice['days'][2]['hours']); // Fr 30.10.
        $this->assertSame(2.5, $notice['days'][6]['hours']); // Di 3.11., zwei Module übereinander
        $this->assertCount(2, $notice['days'][6]['segments']);

        // Tooltip: was genau an einem Tag verpasst wurde
        $this->assertSame([], $notice['days'][0]['slots']);
        $this->assertSame([
            'start'        => '14:00',
            'end'          => '15:30',
            'hours'        => 1.5,
            'notes'        => 'Views wiederholen',
            'module_title' => 'Modul Datenbanken',
            'color_index'  => 0,
        ], $notice['days'][2]['slots'][0]);
        $this->assertSame(
            ['Modul Datenbanken', 'Modul Betriebssysteme'],
            array_column($notice['days'][6]['slots'], 'module_title')
        );
        $this->assertSame(2.5, $notice['max_day_hours']);

        // Gleicher Titel in verschiedenen Monaten = ein Modul, sortiert nach verpasster Zeit
        $this->assertCount(2, $notice['open_modules']);
        $this->assertSame('Modul Datenbanken', $notice['open_modules'][0]['title']);
        $this->assertSame(2, $notice['open_modules'][0]['missed_count']);
        $this->assertSame(2.5, $notice['open_modules'][0]['missed_hours']);
        $this->assertSame('goals.php?tab=month&month=2026-10#monthly-goal-4', $notice['open_modules'][0]['url']);
    }

    #[Test]
    public function uebernimmtModulfarbenAusDerZielstruktur(): void
    {
        $this->dao->method('findMissedSlots')->willReturn([
            self::verpassteEinheit(),
            self::verpassteEinheit(['id' => 12, 'monthly_goal_id' => 9, 'monthly_goal_title' => 'Modul Statistik']),
        ]);

        $notice = $this->controller->buildInactivityNotice(
            self::USER_ID,
            '2026-10-28 21:05:00',
            '2026-11-07 09:00:00',
            ['modul software engineering' => 0, 'modul datenbanken' => 1]
        );

        $this->assertNotNull($notice);
        $colors = array_column($notice['open_modules'], 'color_index', 'title');
        $this->assertSame(1, $colors['Modul Datenbanken']);
        // Modul ohne Farbe aus der Zielstruktur bekommt die nächste freie Farbe
        $this->assertSame(2, $colors['Modul Statistik']);
    }

    #[Test]
    public function ohneVerpassteLernzeitErscheintKeinHinweis(): void
    {
        // TC-7-02: alles wahrgenommen oder gebucht -> kein Hinweis
        $this->dao->method('findMissedSlots')->willReturn([]);

        $this->assertNull($this->controller->buildInactivityNotice(self::USER_ID, '2026-10-28 21:05:00', '2026-11-07 09:00:00'));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function ungueltigerVorherigerLogin(): array
    {
        return [
            'erster Login'           => [null],
            'leer'                   => [''],
            'kein Datum'             => ['gestern'],
            'nach dem Stichzeitpunkt' => ['2026-11-08 10:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigerVorherigerLogin')]
    public function ohneGueltigenVorherigenLoginWirdNichtGesucht(?string $previousLoginAt): void
    {
        $this->dao->expects($this->never())->method('findMissedSlots');

        $this->assertNull($this->controller->buildInactivityNotice(self::USER_ID, $previousLoginAt, '2026-11-07 09:00:00'));
    }

    #[Test]
    public function erledigtesZwischenzielZaehltNichtAlsOffenesZiel(): void
    {
        $this->dao->method('findMissedSlots')->willReturn([
            self::verpassteEinheit(['monthly_goal_completed_at' => '2026-11-02']),
        ]);

        $notice = $this->controller->buildInactivityNotice(self::USER_ID, '2026-10-28 21:05:00', '2026-11-07 09:00:00');

        $this->assertNotNull($notice);
        $this->assertSame(1, $notice['missed_count']);
        $this->assertSame(1.5, $notice['days'][2]['hours']);
        $this->assertSame([], $notice['open_modules']);
    }

    #[Test]
    public function kuerztDieTagesleisteAufVierzehnTage(): void
    {
        $this->dao->method('findMissedSlots')->willReturn([
            self::verpassteEinheit(['slot_date' => '2026-10-01']),
            self::verpassteEinheit(['id' => 12, 'slot_date' => '2026-11-06']),
        ]);

        $notice = $this->controller->buildInactivityNotice(self::USER_ID, '2026-09-30 08:00:00', '2026-11-07 09:00:00');

        $this->assertNotNull($notice);
        $this->assertCount(DashboardController::INACTIVITY_MAX_DAYS, $notice['days']);
        $this->assertSame('2026-10-25', $notice['days'][0]['date']);
        $this->assertTrue($notice['days_truncated']);
        // Die Summe zählt auch Einheiten außerhalb der Grafik
        $this->assertSame(2, $notice['missed_count']);
        $this->assertSame(3.0, $notice['missed_hours']);
    }

    #[Test]
    public function zweiVerpassteModuleAmSelbenTagWerdenGestapeltUndEinzelnAufgelistet(): void
    {
        // Do 5.11.: Datenbanken 16:00–17:30 und Betriebssysteme 18:00–19:30, beides vergessen
        $this->dao->method('findMissedSlots')->willReturn([
            self::verpassteEinheit(['id' => 14, 'slot_date' => '2026-11-05', 'start_time' => '16:00:00', 'end_time' => '17:30:00']),
            self::verpassteEinheit([
                'id' => 15, 'slot_date' => '2026-11-05', 'start_time' => '18:00:00', 'end_time' => '19:30:00',
                'monthly_goal_id' => 8, 'monthly_goal_title' => 'Modul Betriebssysteme', 'notes' => 'Paging üben',
            ]),
        ]);

        $notice = $this->controller->buildInactivityNotice(
            self::USER_ID,
            '2026-11-04 20:00:00',
            '2026-11-07 09:00:00',
            ['modul datenbanken' => 1, 'modul betriebssysteme' => 2]
        );

        $this->assertNotNull($notice);
        $day = $notice['days'][1];
        $this->assertSame('2026-11-05', $day['date']);
        $this->assertSame(3.0, $day['hours']);
        $this->assertSame(3.0, $notice['max_day_hours']);

        // Eine Säule, zwei Abschnitte in den Modulfarben
        $this->assertSame([
            ['title' => 'Modul Datenbanken', 'color_index' => 1, 'hours' => 1.5],
            ['title' => 'Modul Betriebssysteme', 'color_index' => 2, 'hours' => 1.5],
        ], $day['segments']);

        // Tooltip: beide Einheiten in zeitlicher Reihenfolge
        $this->assertSame(['16:00', '18:00'], array_column($day['slots'], 'start'));
        $this->assertSame(['Modul Datenbanken', 'Modul Betriebssysteme'], array_column($day['slots'], 'module_title'));

        // Beide Module gelten als offene Ziele
        $this->assertCount(2, $notice['open_modules']);
    }

    #[Test]
    public function zweiEinheitenDesselbenModulsAmSelbenTagErgebenEinenAbschnitt(): void
    {
        $this->dao->method('findMissedSlots')->willReturn([
            self::verpassteEinheit(['id' => 14, 'slot_date' => '2026-11-05', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'scheduled_seconds' => 3600]),
            self::verpassteEinheit(['id' => 15, 'slot_date' => '2026-11-05', 'start_time' => '16:00:00', 'end_time' => '17:30:00']),
        ]);

        $notice = $this->controller->buildInactivityNotice(self::USER_ID, '2026-11-04 20:00:00', '2026-11-07 09:00:00');

        $this->assertNotNull($notice);
        $day = $notice['days'][1];
        $this->assertCount(1, $day['segments']);
        $this->assertSame(2.5, $day['segments'][0]['hours']);
        $this->assertCount(2, $day['slots']);
        $this->assertSame(1, count($notice['open_modules']));
        $this->assertSame(2, $notice['open_modules'][0]['missed_count']);
    }
}
