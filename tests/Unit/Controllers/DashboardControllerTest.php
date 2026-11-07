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
}
