<?php
// tests/Unit/Controllers/GoalControllerTest.php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Classes\ValidationException;
use Controllers\GoalController;
use DAO\GoalDAO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für die Validierung und Geschäftsregeln der Lernzielplanung (siehe docs/unit-tests.md).
 * Der GoalDAO wird durch einen Mock ersetzt, es wird keine Datenbank benötigt.
 */
final class GoalControllerTest extends TestCase
{
    private const USER_ID = 42;

    /** Lernziel, das der Mock für findByIdAndUserId(7, 42) liefert */
    private const PARENT_GOAL = [
        'id'           => 7,
        'user_id'      => self::USER_ID,
        'title'        => 'Software Engineering',
        'target_hours' => 150.0,
        'start_date'   => '2026-10-01',
        'end_date'     => '2027-03-31',
    ];

    private GoalDAO&MockObject $dao;
    private GoalController $controller;

    protected function setUp(): void
    {
        // Vorbereiten: Controller mit einem Mock statt der echten Datenbankzugriffsschicht
        $_SESSION = [];
        $this->dao = $this->createMock(GoalDAO::class);
        $this->controller = new GoalController($this->dao);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Gültige Eingabe für ein Lernziel, einzelne Felder lassen sich überschreiben.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function lernzielEingabe(array $overrides = []): array
    {
        return array_merge([
            'title'        => 'Software Engineering',
            'target_hours' => '150',
            'start_date'   => '2026-10-01',
            'end_date'     => '2027-03-31',
            'description'  => '',
        ], $overrides);
    }

    /**
     * Gültige Eingabe für ein Zwischenziel, einzelne Felder lassen sich überschreiben.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function zwischenzielEingabe(array $overrides = []): array
    {
        return array_merge([
            'goal_id'       => '7',
            'year_month'    => '2026-11',
            'title'         => 'Kapitel 1 bis 3',
            'planned_hours' => '20',
            'description'   => '',
        ], $overrides);
    }

    /**
     * Mock liefert das Lernziel 7 für Nutzer 42, für alles andere null (fremder oder fehlender Datensatz).
     */
    private function daoLiefertLernziel(): void
    {
        $this->dao->method('findByIdAndUserId')->willReturnCallback(
            static fn (int $goalId, int $userId): ?array => ($goalId === 7 && $userId === self::USER_ID) ? self::PARENT_GOAL : null
        );
    }

    /**
     * Führt $callable aus und prüft, dass eine ValidationException für das erwartete Feld geworfen wird.
     */
    private function erwartetValidierungsfehler(callable $callable, string $expectedField): ValidationException
    {
        try {
            $callable();
        } catch (ValidationException $e) {
            self::assertSame($expectedField, $e->getField(), 'Falsches Feld in der ValidationException');
            self::assertNotSame('', $e->getMessage());

            return $e;
        }

        self::fail('Es wurde keine ValidationException geworfen.');
    }

    // ------------------------------------------------------------------
    // formatYearMonth
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function monateMitErwartetemLabel(): array
    {
        return [
            'Langform'           => ['2026-10', false, 'Oktober 2026'],
            'Kurzform'           => ['2026-10', true, 'Okt 2026'],
            'Umlaut in Kurzform' => ['2027-03', true, 'Mär 2027'],
            'Jahreswechsel'      => ['2027-01', false, 'Januar 2027'],
        ];
    }

    #[Test]
    #[DataProvider('monateMitErwartetemLabel')]
    public function formatiertMonatAlsDeutschenMonatsnamen(string $yearMonth, bool $short, string $expected): void
    {
        self::assertSame($expected, GoalController::formatYearMonth($yearMonth, $short));
    }

    #[Test]
    public function gibtWertOhneBindestrichUnveraendertZurueck(): void
    {
        self::assertSame('Oktober', GoalController::formatYearMonth('Oktober'));
        self::assertSame('2026/10', GoalController::formatYearMonth('2026/10'));
    }

    // ------------------------------------------------------------------
    // validateYearMonth
    // ------------------------------------------------------------------

    #[Test]
    public function akzeptiertGueltigenPlanungsmonat(): void
    {
        self::assertSame('2026-10', $this->controller->validateYearMonth(' 2026-10 '));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function ungueltigePlanungsmonate(): array
    {
        return [
            'leer'            => [''],
            'nur Leerzeichen' => ['   '],
            'fehlt'           => [null],
            'Monat 13'        => ['2026-13'],
            'Monat 0'         => ['2026-00'],
            'ohne Null'       => ['2026-1'],
            'mit Tag'         => ['2026-10-01'],
            'Zahl statt Text' => [202610],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigePlanungsmonate')]
    public function lehntUngueltigenPlanungsmonatMitFeldhinweisAb(mixed $yearMonth): void
    {
        $this->erwartetValidierungsfehler(fn () => $this->controller->validateYearMonth($yearMonth), 'year_month');
    }

    // ------------------------------------------------------------------
    // validateAndParseInput (Lernziel)
    // ------------------------------------------------------------------

    #[Test]
    public function bereinigtGueltigeLernzielEingabe(): void
    {
        $result = $this->controller->validateAndParseInput(self::lernzielEingabe([
            'title'        => '  Software Engineering  ',
            'target_hours' => ' 12,5 ',
            'description'  => '   ',
        ]));

        self::assertSame([
            'title'        => 'Software Engineering',
            'description'  => null,
            'target_hours' => 12.5,
            'start_date'   => '2026-10-01',
            'end_date'     => '2027-03-31',
        ], $result);
    }

    #[Test]
    public function rundetZielstundenAufZweiStellenUndUebernimmtBeschreibung(): void
    {
        $result = $this->controller->validateAndParseInput(self::lernzielEingabe([
            'target_hours' => '10.456',
            'description'  => ' Klausur im März ',
        ]));

        self::assertSame(10.46, $result['target_hours']);
        self::assertSame('Klausur im März', $result['description']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function gueltigeLernzielRandwerte(): array
    {
        return [
            'Minimum 0,5 Stunden'   => [['target_hours' => '0,5']],
            'Maximum 1500 Stunden'  => [['target_hours' => 1500]],
            'Stunden als Ganzzahl'  => [['target_hours' => 40]],
            'Ende gleich Start'     => [['start_date' => '2026-10-01', 'end_date' => '2026-10-01']],
            'Titel mit 150 Zeichen' => [['title' => str_repeat('ä', 150)]],
        ];
    }

    #[Test]
    #[DataProvider('gueltigeLernzielRandwerte')]
    public function akzeptiertLernzielRandwerte(array $overrides): void
    {
        $result = $this->controller->validateAndParseInput(self::lernzielEingabe($overrides));

        self::assertArrayHasKey('title', $result);
        self::assertGreaterThanOrEqual(GoalController::MIN_TARGET_HOURS, $result['target_hours']);
        self::assertLessThanOrEqual(GoalController::MAX_TARGET_HOURS, $result['target_hours']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function ungueltigeLernzielEingaben(): array
    {
        return [
            // Titel
            'Titel fehlt'                 => [['title' => null], 'title'],
            'Titel leer'                  => [['title' => ''], 'title'],
            'Titel nur Leerzeichen'       => [['title' => '   '], 'title'],
            'Titel als Array'             => [['title' => ['x']], 'title'],
            'Titel 151 Zeichen'           => [['title' => str_repeat('ä', 151)], 'title'],
            // Zielstunden
            'Stunden fehlen'              => [['target_hours' => null], 'target_hours'],
            'Stunden leer'                => [['target_hours' => ' '], 'target_hours'],
            'Stunden keine Zahl'          => [['target_hours' => 'abc'], 'target_hours'],
            'Stunden als Array'           => [['target_hours' => [1]], 'target_hours'],
            'Stunden unter Minimum'       => [['target_hours' => '0,4'], 'target_hours'],
            'Stunden über Maximum'        => [['target_hours' => '1500.1'], 'target_hours'],
            'Stunden negativ'             => [['target_hours' => -5], 'target_hours'],
            // Startdatum
            'Startdatum fehlt'            => [['start_date' => null], 'start_date'],
            'Startdatum ungültiger Monat' => [['start_date' => '2026-13-01'], 'start_date'],
            'Startdatum deutsches Format' => [['start_date' => '01.10.2026'], 'start_date'],
            // Enddatum
            'Enddatum fehlt'              => [['end_date' => ''], 'end_date'],
            'Enddatum ungültig'           => [['end_date' => '2027-02-30'], 'end_date'],
            'Enddatum vor Startdatum'     => [['start_date' => '2026-10-02', 'end_date' => '2026-10-01'], 'end_date'],
            // Beschreibung
            'Beschreibung 2001 Zeichen'   => [['description' => str_repeat('a', 2001)], 'description'],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeLernzielEingaben')]
    public function lehntUngueltigeLernzielEingabeMitFeldhinweisAb(array $overrides, string $expectedField): void
    {
        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseInput(self::lernzielEingabe($overrides)),
            $expectedField
        );
    }

    // ------------------------------------------------------------------
    // validateAndParseMonthlyGoalInput (Zwischenziel)
    // ------------------------------------------------------------------

    #[Test]
    public function bereinigtGueltigeZwischenzielEingabe(): void
    {
        $this->daoLiefertLernziel();

        $result = $this->controller->validateAndParseMonthlyGoalInput(self::USER_ID, self::zwischenzielEingabe([
            'goal_id'       => ' 7 ',
            'year_month'    => ' 2027-03 ',
            'title'         => '  Kapitel 1 bis 3 ',
            'planned_hours' => '20,25',
            'description'   => ' Übungsblätter ',
        ]));

        self::assertSame([
            'goal_id'       => 7,
            'year_month'    => '2027-03',
            'title'         => 'Kapitel 1 bis 3',
            'description'   => 'Übungsblätter',
            'planned_hours' => 20.25,
        ], $result);
    }

    #[Test]
    public function akzeptiertZwischenzielImStartmonatDesLernziels(): void
    {
        $this->daoLiefertLernziel();

        $result = $this->controller->validateAndParseMonthlyGoalInput(
            self::USER_ID,
            self::zwischenzielEingabe(['goal_id' => 7, 'year_month' => '2026-10'])
        );

        self::assertSame('2026-10', $result['year_month']);
        self::assertNull($result['description']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function ungueltigeZwischenzielEingaben(): array
    {
        return [
            'Lernziel fehlt'                 => [['goal_id' => null], 'goal_id'],
            'Lernziel 0'                     => [['goal_id' => '0'], 'goal_id'],
            'Lernziel negativ'               => [['goal_id' => -7], 'goal_id'],
            'Lernziel keine Zahl'            => [['goal_id' => 'abc'], 'goal_id'],
            'Lernziel Kommazahl'             => [['goal_id' => '7.5'], 'goal_id'],
            'Lernziel gehört anderem Nutzer' => [['goal_id' => '8'], 'goal_id'],
            'Monat vor Lernziel'             => [['year_month' => '2026-09'], 'year_month'],
            'Monat nach Lernziel'            => [['year_month' => '2027-04'], 'year_month'],
            'Monat ungültig'                 => [['year_month' => '2026-13'], 'year_month'],
            'Titel leer'                     => [['title' => ' '], 'title'],
            'Titel 151 Zeichen'              => [['title' => str_repeat('x', 151)], 'title'],
            'Stunden fehlen'                 => [['planned_hours' => ''], 'planned_hours'],
            'Stunden keine Zahl'             => [['planned_hours' => 'viel'], 'planned_hours'],
            'Stunden unter Minimum'          => [['planned_hours' => '0.4'], 'planned_hours'],
            'Stunden über Monatsmaximum'     => [['planned_hours' => '744,1'], 'planned_hours'],
            'Beschreibung 2001 Zeichen'      => [['description' => str_repeat('a', 2001)], 'description'],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeZwischenzielEingaben')]
    public function lehntUngueltigeZwischenzielEingabeMitFeldhinweisAb(array $overrides, string $expectedField): void
    {
        $this->daoLiefertLernziel();

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseMonthlyGoalInput(self::USER_ID, self::zwischenzielEingabe($overrides)),
            $expectedField
        );
    }

    #[Test]
    public function nenntZeitraumDesLernzielsWennMonatAusserhalbLiegt(): void
    {
        $this->daoLiefertLernziel();

        $e = $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseMonthlyGoalInput(self::USER_ID, self::zwischenzielEingabe(['year_month' => '2027-04'])),
            'year_month'
        );

        self::assertStringContainsString('Oktober 2026 bis März 2027', $e->getMessage());
    }

    #[Test]
    public function fragtDatenbankNichtAbWennLernzielIdUngueltigIst(): void
    {
        $this->dao->expects($this->never())->method('findByIdAndUserId');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseMonthlyGoalInput(self::USER_ID, self::zwischenzielEingabe(['goal_id' => 'abc'])),
            'goal_id'
        );
    }

    // ------------------------------------------------------------------
    // saveMonthlyBudget
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function leereBudgetwerte(): array
    {
        return [
            'fehlt'          => [null],
            'leer'           => [''],
            'Leerzeichen'    => ['  '],
            'Null als Text'  => ['0'],
            'Null als Zahl'  => [0],
            'Null mit Komma' => ['0,0'],
        ];
    }

    #[Test]
    #[DataProvider('leereBudgetwerte')]
    public function loeschtMonatsbudgetBeiLeeremOderNullWert(mixed $budget): void
    {
        $this->dao->expects($this->once())
            ->method('deleteMonthlyBudget')
            ->with(self::USER_ID, '2026-10')
            ->willReturn(true);
        $this->dao->expects($this->never())->method('upsertMonthlyBudget');

        $result = $this->controller->saveMonthlyBudget(self::USER_ID, ['year_month' => '2026-10', 'budget_hours' => $budget]);

        self::assertSame('2026-10', $result);
    }

    #[Test]
    public function speichertMonatsbudgetMitKommaAlsDezimaltrennzeichen(): void
    {
        $this->dao->expects($this->once())
            ->method('upsertMonthlyBudget')
            ->with(self::USER_ID, '2026-10', 40.5)
            ->willReturn(true);
        $this->dao->expects($this->never())->method('deleteMonthlyBudget');

        $result = $this->controller->saveMonthlyBudget(self::USER_ID, ['year_month' => ' 2026-10', 'budget_hours' => ' 40,5 ']);

        self::assertSame('2026-10', $result);
    }

    #[Test]
    public function akzeptiertMonatsbudgetAmMaximum(): void
    {
        $this->dao->expects($this->once())
            ->method('upsertMonthlyBudget')
            ->with(self::USER_ID, '2026-10', 744.0)
            ->willReturn(true);

        self::assertSame('2026-10', $this->controller->saveMonthlyBudget(self::USER_ID, ['year_month' => '2026-10', 'budget_hours' => 744]));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function ungueltigeBudgetEingaben(): array
    {
        return [
            'Budget keine Zahl'  => [['year_month' => '2026-10', 'budget_hours' => 'abc'], 'budget_hours'],
            'Budget negativ'     => [['year_month' => '2026-10', 'budget_hours' => -1], 'budget_hours'],
            'Budget über 744'    => [['year_month' => '2026-10', 'budget_hours' => '744.01'], 'budget_hours'],
            'Budget als Array'   => [['year_month' => '2026-10', 'budget_hours' => [40]], 'budget_hours'],
            'Monat fehlt'        => [['budget_hours' => '40'], 'year_month'],
            'Monat ungültig'     => [['year_month' => '10/2026', 'budget_hours' => '40'], 'year_month'],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeBudgetEingaben')]
    public function lehntUngueltigesMonatsbudgetAbOhneDatenbankzugriff(array $input, string $expectedField): void
    {
        $this->dao->expects($this->never())->method('upsertMonthlyBudget');
        $this->dao->expects($this->never())->method('deleteMonthlyBudget');

        $this->erwartetValidierungsfehler(fn () => $this->controller->saveMonthlyBudget(self::USER_ID, $input), $expectedField);
    }

    // ------------------------------------------------------------------
    // buildMonthlyPlanningData
    // ------------------------------------------------------------------

    /**
     * Zwei Lernziele: Nr. 1 ist mit 50 von 30 Stunden überplant, Nr. 2 mit 20 von 100 Stunden nicht.
     *
     * @return list<array<string, mixed>>
     */
    private static function lernziele(): array
    {
        return [
            ['id' => 1, 'title' => 'Mathe', 'target_hours' => 30.0, 'start_date' => '2026-10-01', 'end_date' => '2027-03-31', 'allocated_hours' => 50.0],
            ['id' => 2, 'title' => 'Statistik', 'target_hours' => 100.0, 'start_date' => '2026-12-01', 'end_date' => '2027-03-31', 'allocated_hours' => 20.0],
        ];
    }

    /**
     * Mock-Daten: Budget nur im November (40 h), geplant 10 h im Oktober und 50 h im November.
     * Im November liegen zwei Zwischenziele (30 h zu Lernziel 1, 20 h zu Lernziel 2).
     */
    private function daoLiefertPlanungsdaten(): void
    {
        $this->dao->method('findAllMonthlyBudgetsByUserId')->willReturn(['2026-11' => 40.0]);
        $this->dao->method('getMonthlyPlannedHoursByUserId')->willReturn(['2026-10' => 10.0, '2026-11' => 50.0]);
        $this->dao->method('findMonthlyGoalsByUserId')->willReturnCallback(
            static fn (int $userId, ?string $yearMonth): array => $yearMonth === '2026-11' ? [
                ['id' => 11, 'goal_id' => 1, 'plan_month' => '2026-11', 'year_month' => '2026-11', 'title' => 'Analysis', 'planned_hours' => '30.00', 'parent_target_hours' => '30.00'],
                ['id' => 12, 'goal_id' => 2, 'plan_month' => '2026-11', 'year_month' => '2026-11', 'title' => 'Verteilungen', 'planned_hours' => '20.00', 'parent_target_hours' => '100.00'],
            ] : []
        );
    }

    #[Test]
    public function baut6MonatsFensterAbFruehestemLernzielStart(): void
    {
        $this->daoLiefertPlanungsdaten();

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), '2026-11');

        self::assertSame('2026-11', $data['selected_month']);
        self::assertSame('2026-10', $data['prev_month']);
        self::assertSame('2026-12', $data['next_month']);
        self::assertSame(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03'], array_keys($data['months_window']));
        self::assertTrue($data['months_window']['2026-11']['is_selected']);
        self::assertFalse($data['months_window']['2026-10']['is_selected']);
        self::assertSame(60.0, $data['total_planned_all_months']);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function monateAusserhalbDesStandardfensters(): array
    {
        return [
            'vor dem Fenster'  => ['2026-08', ['2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01']],
            'nach dem Fenster' => ['2027-06', ['2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06']],
        ];
    }

    #[Test]
    #[DataProvider('monateAusserhalbDesStandardfensters')]
    public function verschiebtFensterWennGewaehlterMonatAusserhalbLiegt(string $requestedMonth, array $expectedKeys): void
    {
        $this->daoLiefertPlanungsdaten();

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), $requestedMonth);

        self::assertSame($expectedKeys, array_keys($data['months_window']));
        self::assertSame($requestedMonth, $data['selected_month']);
        self::assertSame([], $data['monthly_goals']);
    }

    #[Test]
    public function faelltBeiUngueltigemMonatAufAktuellenKalendermonatZurueck(): void
    {
        $this->daoLiefertPlanungsdaten();

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), '2026-13');

        // Einziger erlaubter date()-Aufruf in den Tests: Der Fallback ist laut Fachlogik der aktuelle Kalendermonat.
        $currentMonth = date('Y-m');
        self::assertSame($currentMonth, $data['selected_month']);
        self::assertTrue($data['months_window'][$currentMonth]['is_current_calendar_month']);
    }

    #[Test]
    public function berechnetBudgetAuslastungUndWorkloadAnteilJeMonat(): void
    {
        $this->daoLiefertPlanungsdaten();

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), '2026-11');
        $november = $data['months_window']['2026-11'];
        $oktober = $data['months_window']['2026-10'];
        $dezember = $data['months_window']['2026-12'];

        // November: 50 h geplant bei 40 h Budget → überplant
        self::assertSame(40.0, $november['budget_hours']);
        self::assertSame(50.0, $november['planned_hours']);
        self::assertSame(-10.0, $november['remaining_budget_hours']);
        self::assertSame(125.0, $november['budget_utilization_percent']);
        self::assertTrue($november['is_over_budget']);
        self::assertSame(83.3, $november['workload_share_percent']);
        self::assertSame('November 2026', $november['label_long']);
        self::assertSame('Nov 2026', $november['label_short']);

        // Oktober: kein Budget hinterlegt
        self::assertNull($oktober['budget_hours']);
        self::assertNull($oktober['remaining_budget_hours']);
        self::assertNull($oktober['budget_utilization_percent']);
        self::assertFalse($oktober['is_over_budget']);
        self::assertSame(16.7, $oktober['workload_share_percent']);

        // Dezember: nichts geplant
        self::assertSame(0.0, $dezember['planned_hours']);
        self::assertSame(0.0, $dezember['workload_share_percent']);
    }

    #[Test]
    public function reichertZwischenzieleMitUeberplanungUndAnteilenAn(): void
    {
        $this->daoLiefertPlanungsdaten();

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), '2026-11');

        self::assertCount(2, $data['monthly_goals']);
        [$analysis, $verteilungen] = $data['monthly_goals'];

        // Lernziel 1: 50 h verteilt bei 30 h Ziel → überplant
        self::assertSame(50.0, $analysis['parent_allocated_hours']);
        self::assertTrue($analysis['is_parent_over_allocated']);
        self::assertSame(75.0, $analysis['share_of_month_budget_percent']);   // 30 von 40 h Budget
        self::assertSame(60.0, $analysis['share_of_month_planned_percent']);  // 30 von 50 h geplant

        // Lernziel 2: 20 h verteilt bei 100 h Ziel → nicht überplant
        self::assertSame(20.0, $verteilungen['parent_allocated_hours']);
        self::assertFalse($verteilungen['is_parent_over_allocated']);
        self::assertSame(50.0, $verteilungen['share_of_month_budget_percent']);
        self::assertSame(40.0, $verteilungen['share_of_month_planned_percent']);
    }

    #[Test]
    public function liefertOhneBudgetKeinenBudgetAnteilDerZwischenziele(): void
    {
        $this->dao->method('findAllMonthlyBudgetsByUserId')->willReturn([]);
        $this->dao->method('getMonthlyPlannedHoursByUserId')->willReturn(['2026-11' => 30.0]);
        $this->dao->method('findMonthlyGoalsByUserId')->willReturn([
            ['id' => 11, 'goal_id' => 1, 'plan_month' => '2026-11', 'year_month' => '2026-11', 'title' => 'Analysis', 'planned_hours' => '30.00', 'parent_target_hours' => '30.00'],
        ]);

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), '2026-11');

        self::assertNull($data['monthly_goals'][0]['share_of_month_budget_percent']);
        self::assertSame(100.0, $data['monthly_goals'][0]['share_of_month_planned_percent']);
        self::assertNull($data['selected_month_data']['budget_utilization_percent']);
    }

    #[Test]
    public function funktioniertOhneLernziele(): void
    {
        $this->dao->method('findAllMonthlyBudgetsByUserId')->willReturn([]);
        $this->dao->method('getMonthlyPlannedHoursByUserId')->willReturn([]);
        $this->dao->method('findMonthlyGoalsByUserId')->willReturn([]);

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, [], '2026-11');

        self::assertSame('2026-11', $data['selected_month']);
        self::assertCount(6, $data['months_window']);
        self::assertArrayHasKey('2026-11', $data['months_window']);
        self::assertSame([], $data['monthly_goals']);
        self::assertSame(0, $data['total_planned_all_months']);
    }

    // ------------------------------------------------------------------
    // buildMonthlyPlanningData: Ist-Zeit der Stoppuhr
    // ------------------------------------------------------------------

    /**
     * Mock-Daten: Ein Zwischenziel über 2 h im November 2026, zusätzliche Felder lassen sich setzen.
     *
     * @param array<string, mixed> $overrides
     */
    private function daoLiefertZwischenziel(array $overrides): void
    {
        $this->dao->method('findAllMonthlyBudgetsByUserId')->willReturn([]);
        $this->dao->method('getMonthlyPlannedHoursByUserId')->willReturn(['2026-11' => 2.0]);
        $this->dao->method('findMonthlyGoalsByUserId')->willReturn([
            array_merge(
                ['id' => 11, 'goal_id' => 1, 'plan_month' => '2026-11', 'year_month' => '2026-11', 'title' => 'Analysis', 'planned_hours' => '2.00', 'parent_target_hours' => '30.00'],
                $overrides
            ),
        ]);
    }

    #[Test]
    public function ergaenztZwischenzielUmGestoppteIstZeit(): void
    {
        $this->daoLiefertZwischenziel(['tracked_seconds' => '5400']);

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), '2026-11');
        $goal = $data['monthly_goals'][0];

        self::assertSame(5400, $goal['tracked_seconds']);
        self::assertSame(1.5, $goal['tracked_hours']);
        self::assertSame('1 Std. 30 Min.', $goal['tracked_formatted']);
        self::assertSame(75.0, $goal['tracked_percent']);
        self::assertSame(1.5, $data['selected_month_data']['tracked_hours']);
    }

    #[Test]
    public function begrenztIstZeitAnteilDesZwischenzielsAuf100Prozent(): void
    {
        $this->daoLiefertZwischenziel(['tracked_seconds' => 10800]);

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), '2026-11');

        self::assertSame(100.0, $data['monthly_goals'][0]['tracked_percent']);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function erledigtStatusZwischenzielProvider(): array
    {
        return [
            'keine Ist-Zeit'            => [[], false],
            '1 Sekunde unter Soll'      => [['tracked_seconds' => 7199], false],
            'Soll exakt erreicht'       => [['tracked_seconds' => 7200], true],
            'Soll überschritten'        => [['tracked_seconds' => 9000], true],
            'in der Datenbank erledigt' => [['tracked_seconds' => 0, 'completed_at' => '2026-11-15'], true],
            'keine Soll-Zeit'           => [['tracked_seconds' => 3600, 'planned_hours' => '0.00'], false],
        ];
    }

    #[Test]
    #[DataProvider('erledigtStatusZwischenzielProvider')]
    public function bestimmtErledigtStatusDesZwischenziels(array $overrides, bool $expected): void
    {
        $this->daoLiefertZwischenziel($overrides);

        $data = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), '2026-11');

        self::assertSame($expected, $data['monthly_goals'][0]['is_completed']);
    }

    // ------------------------------------------------------------------
    // buildWeeklyPlanningData: Ist-Zeit der Stoppuhr
    // ------------------------------------------------------------------

    /** Kalenderwoche 45 (02.11. bis 08.11.2026) ist die erste Woche des Testmonats */
    private const TEST_WEEK = '2026-W45';
    private const TEST_MONDAY = '2026-11-02';

    /**
     * Timeslot über 1 h am Montag der Testwoche, einzelne Felder lassen sich überschreiben.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function timeslot(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'              => $id,
            'monthly_goal_id' => 11,
            'slot_date'       => self::TEST_MONDAY,
            'start_time'      => sprintf('%02d:00:00', 8 + $id),
            'end_time'        => sprintf('%02d:00:00', 9 + $id),
            'planned_seconds' => 3600,
            'duration_hours'  => '1.00',
            'is_completed'    => 0,
        ], $overrides);
    }

    /**
     * Ruft die Wochenplanung für die Testwoche mit den übergebenen Slots und Zeiteinträgen auf.
     *
     * @param list<array<string, mixed>> $slots
     * @param list<array<string, mixed>> $trackedRows
     * @return array<string, mixed>
     */
    private function baueTestwoche(array $slots, array $trackedRows): array
    {
        $this->dao->method('findLearningSlotsByDateRange')->willReturn($slots);
        $this->dao->method('findTrackedSecondsByDateRange')->willReturn($trackedRows);

        return $this->controller->buildWeeklyPlanningData(self::USER_ID, [
            'selected_month'      => '2026-11',
            'selected_month_data' => ['budget_hours' => null, 'planned_hours' => 10.0],
            'monthly_goals'       => [],
            'prev_month'          => '2026-10',
            'next_month'          => '2026-12',
        ], self::TEST_WEEK);
    }

    /**
     * @return array<string, mixed>
     */
    private static function zeiteintrag(
        int $seconds,
        int $monthlyGoalId = 11,
        string $date = self::TEST_MONDAY,
        ?int $unassignedSeconds = null
    ): array {
        return [
            'entry_date'         => $date,
            'monthly_goal_id'    => $monthlyGoalId,
            'tracked_seconds'    => (string) $seconds,
            'unassigned_seconds' => (string) ($unassignedSeconds ?? $seconds),
        ];
    }

    #[Test]
    public function verteiltIstZeitChronologischAufTimeslotsDesselbenZwischenziels(): void
    {
        $data = $this->baueTestwoche([self::timeslot(1), self::timeslot(2)], [self::zeiteintrag(5400)]);
        [$erster, $zweiter] = $data['days'][0]['slots'];

        self::assertSame(3600, $erster['tracked_seconds']);
        self::assertTrue($erster['is_target_reached']);
        self::assertSame(100.0, $erster['progress_percent']);

        self::assertSame(1800, $zweiter['tracked_seconds']);
        self::assertFalse($zweiter['is_target_reached']);
        self::assertSame(50.0, $zweiter['progress_percent']);
    }

    #[Test]
    public function schreibtUeberschussDemLetztenTimeslotZu(): void
    {
        $data = $this->baueTestwoche([self::timeslot(1), self::timeslot(2)], [self::zeiteintrag(9000)]);
        [$erster, $zweiter] = $data['days'][0]['slots'];

        self::assertSame(3600, $erster['tracked_seconds']);
        self::assertSame(5400, $zweiter['tracked_seconds']);
        self::assertSame(100.0, $zweiter['progress_percent']);
    }

    #[Test]
    public function rechnetIstZeitNurDemPassendenZwischenzielZu(): void
    {
        $data = $this->baueTestwoche(
            [self::timeslot(1), self::timeslot(2, ['monthly_goal_id' => 12])],
            [self::zeiteintrag(1800, 12)]
        );
        [$fremd, $passend] = $data['days'][0]['slots'];

        self::assertSame(0, $fremd['tracked_seconds']);
        self::assertSame(1800, $passend['tracked_seconds']);
    }

    #[Test]
    public function laesstErledigtStatusBeimAnzeigenUnveraendert(): void
    {
        // Die Einheit hat ihre Soll-Zeit erreicht, wurde aber bewusst wieder geöffnet
        $this->dao->expects(self::never())->method('setLearningSlotCompleted');

        $data = $this->baueTestwoche([self::timeslot(1)], [self::zeiteintrag(3600)]);
        $slot = $data['days'][0]['slots'][0];

        self::assertSame(0, $slot['is_completed']);
        self::assertTrue($slot['is_target_reached']);
    }

    #[Test]
    public function verteiltVerknuepfteZeitNichtAufAndereTimeslots(): void
    {
        $data = $this->baueTestwoche(
            [self::timeslot(1), self::timeslot(2, ['tracked_seconds' => 1800])],
            [self::zeiteintrag(1800, 11, self::TEST_MONDAY, 0)]
        );
        [$erster, $zweiter] = $data['days'][0]['slots'];

        self::assertSame(0, $erster['tracked_seconds']);
        self::assertSame(1800, $zweiter['tracked_seconds']);
    }

    #[Test]
    public function fuelltVerknuepfteZeitMitStoppuhrZeitBisZurSollzeitAuf(): void
    {
        // 30 Min. sind auf Slot 1 nachgebucht, 1 Std. Stoppuhr-Zeit hat keine Verknüpfung
        $data = $this->baueTestwoche(
            [self::timeslot(1, ['tracked_seconds' => 1800]), self::timeslot(2)],
            [self::zeiteintrag(5400, 11, self::TEST_MONDAY, 3600)]
        );
        [$erster, $zweiter] = $data['days'][0]['slots'];

        self::assertSame(3600, $erster['tracked_seconds']);
        self::assertSame(1800, $zweiter['tracked_seconds']);
        self::assertSame(5400, $data['days'][0]['tracked_seconds']);
    }

    #[Test]
    public function zeigtUnterschreitungAuchBeiStoppuhrZeit(): void
    {
        $data = $this->baueTestwoche([self::timeslot(1, ['is_completed' => 1])], [self::zeiteintrag(1800)]);
        $slot = $data['days'][0]['slots'][0];

        self::assertTrue($slot['is_under_target']);
        self::assertSame(1800, $slot['missing_seconds']);
    }

    #[Test]
    public function berechnetSollzeitDesTimeslotsAusDauerWennSekundenFehlen(): void
    {
        $data = $this->baueTestwoche(
            [self::timeslot(1, ['planned_seconds' => null, 'duration_hours' => '0.50'])],
            [self::zeiteintrag(900)]
        );
        $slot = $data['days'][0]['slots'][0];

        self::assertSame(1800, $slot['planned_seconds']);
        self::assertSame(50.0, $slot['progress_percent']);
    }

    #[Test]
    public function summiertIstZeitJeTagUndWocheAuchOhneTimeslots(): void
    {
        $data = $this->baueTestwoche([], [
            self::zeiteintrag(3600),
            self::zeiteintrag(600, 12),
            self::zeiteintrag(1200, 11, '2026-11-04'),
            self::zeiteintrag(900, 11, '2026-11-09'),
        ]);

        self::assertSame(4200, $data['days'][0]['tracked_seconds']);
        self::assertSame('1 Std. 10 Min.', $data['days'][0]['tracked_formatted']);
        self::assertSame(1200, $data['days'][2]['tracked_seconds']);
        self::assertSame(0, $data['days'][1]['tracked_seconds']);

        self::assertSame(5400, $data['week_summary']['tracked_seconds']);
        self::assertSame(1.5, $data['week_summary']['tracked_hours']);
        self::assertSame('1 Std. 30 Min.', $data['week_summary']['tracked_formatted']);

        self::assertSame(5400, $data['weeks_window'][self::TEST_WEEK]['tracked_seconds']);
        self::assertSame(900, $data['weeks_window']['2026-W46']['tracked_seconds']);
    }

    #[Test]
    public function fragtIstZeitFuerAlleWochenDesMonatsAb(): void
    {
        $this->dao->expects(self::once())
            ->method('findTrackedSecondsByDateRange')
            ->with(self::USER_ID, self::TEST_MONDAY, '2026-11-29')
            ->willReturn([]);

        $this->baueTestwoche([], []);
    }

    // ------------------------------------------------------------------
    // formatTrackedDuration
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{int, string}>
     */
    public static function istZeitFormate(): array
    {
        return [
            'negativ'                 => [-5, '0 Min.'],
            'null'                    => [0, '0 Min.'],
            'unter einer Minute'      => [45, '45 Sek.'],
            'genau eine Minute'       => [60, '1 Min.'],
            'rundet Minuten ab'       => [89, '1 Min.'],
            'rundet Minuten auf'      => [90, '2 Min.'],
            'volle Stunde'            => [3600, '1 Std.'],
            'Stunde und Minuten'      => [5400, '1 Std. 30 Min.'],
            'rundet zur vollen Stunde' => [3599, '1 Std.'],
            'Übertrag auf Stunden'    => [7170, '2 Std.'],
        ];
    }

    #[Test]
    #[DataProvider('istZeitFormate')]
    public function formatiertIstZeitLesbar(int $seconds, string $expected): void
    {
        self::assertSame($expected, GoalController::formatTrackedDuration($seconds));
    }

    // ------------------------------------------------------------------
    // buildWeeklyPlanningData – Unterschreitung geplanter Lerneinheiten
    // ------------------------------------------------------------------

    /**
     * Erledigte Lerneinheit von 2,0 h am 28.10.2026 (KW 44), einzelne Felder lassen sich überschreiben.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function lerneinheit(array $overrides = []): array
    {
        return array_merge([
            'id'                         => 101,
            'user_id'                    => self::USER_ID,
            'monthly_goal_id'            => 3,
            'slot_date'                  => '2026-10-28',
            'start_time'                 => '10:00:00',
            'end_time'                   => '12:00:00',
            'notes'                      => null,
            'is_completed'               => 1,
            'planned_seconds'            => 7200,
            'duration_hours'             => 2.0,
            'tracked_seconds'            => 7200,
            'monthly_goal_title'         => 'Modul Software Engineering',
            'plan_month'                 => '2026-10',
            'monthly_goal_planned_hours' => 18.0,
            'goal_id'                    => 1,
            'parent_goal_title'          => '3. Semester',
        ], $overrides);
    }

    /**
     * Baut die Wochenplanung der KW 44 mit den übergebenen Lerneinheiten auf.
     *
     * @param list<array<string, mixed>> $slots
     * @return array<string, mixed>
     */
    private function wochenplanungMit(array $slots): array
    {
        $this->dao->method('findAllMonthlyBudgetsByUserId')->willReturn([]);
        $this->dao->method('getMonthlyPlannedHoursByUserId')->willReturn(['2026-10' => 18.0]);
        $this->dao->method('findMonthlyGoalsByUserId')->willReturn([
            [
                'id'                  => 3,
                'goal_id'             => 1,
                'plan_month'          => '2026-10',
                'year_month'          => '2026-10',
                'title'               => 'Modul Software Engineering',
                'planned_hours'       => '18.00',
                'parent_target_hours' => '150.00',
            ],
        ]);
        $this->dao->method('findLearningSlotsByDateRange')->willReturn($slots);

        $monthlyPlanning = $this->controller->buildMonthlyPlanningData(self::USER_ID, self::lernziele(), '2026-10');

        return $this->controller->buildWeeklyPlanningData(self::USER_ID, $monthlyPlanning, '2026-W44', '2026-10-28');
    }

    #[Test]
    public function markiertNachgebuchteLerneinheitMitWenigerZeitAlsUnterschritten(): void
    {
        // Drei Slots am 28.10.2026 (KW 44):
        // 1) Nachgebucht mit 1,5 h statt geplanten 2,0 h -> unterschritten (0,5 h fehlen)
        // 2) Nachgebucht mit vollen 2,0 h -> nicht unterschritten
        // 3) Direkt ohne erfasste Zeit abgehakt (tracked_seconds = 0) -> unterschritten (volle 1,0 h fehlen)
        $weeklyPlanning = $this->wochenplanungMit([
            self::lerneinheit(['id' => 101, 'tracked_seconds' => 5400]),
            self::lerneinheit(['id' => 102, 'start_time' => '14:00:00', 'end_time' => '16:00:00']),
            self::lerneinheit([
                'id'              => 103,
                'start_time'      => '17:00:00',
                'end_time'        => '18:00:00',
                'planned_seconds' => 3600,
                'duration_hours'  => 1.0,
                'tracked_seconds' => 0,
            ]),
        ]);

        // Mittwoch (Index 2) ist der 28.10.2026
        $wednesdaySlots = $weeklyPlanning['days'][2]['slots'];
        self::assertCount(3, $wednesdaySlots);

        self::assertTrue($wednesdaySlots[0]['is_under_target']);
        self::assertSame(1800, $wednesdaySlots[0]['missing_seconds']);
        self::assertSame(0.5, $wednesdaySlots[0]['missing_hours']);

        self::assertFalse($wednesdaySlots[1]['is_under_target']);
        self::assertSame(0, $wednesdaySlots[1]['missing_seconds']);
        self::assertSame(0.0, $wednesdaySlots[1]['missing_hours']);

        self::assertTrue($wednesdaySlots[2]['is_under_target']);
        self::assertSame(3600, $wednesdaySlots[2]['missing_seconds']);
        self::assertSame(1.0, $wednesdaySlots[2]['missing_hours']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: int}>
     */
    public static function lerneinheitenFuerUnterschreitung(): array
    {
        return [
            'Offen mit zu wenig gebuchter Zeit' => [
                ['is_completed' => 0, 'tracked_seconds' => 3600],
                false,
                0,
            ],
            'Mehr als geplant gebucht' => [
                ['tracked_seconds' => 9000],
                false,
                0,
            ],
            'Eine Sekunde zu wenig' => [
                ['tracked_seconds' => 7199],
                true,
                1,
            ],
            'Geplante Zeit aus duration_hours abgeleitet' => [
                ['planned_seconds' => null, 'duration_hours' => 1.5, 'tracked_seconds' => 3600],
                true,
                1800,
            ],
            'Manuell abgehakt ohne erfasste Zeit (tracked_seconds = 0)' => [
                ['tracked_seconds' => 0],
                true,
                7200,
            ],
            'Manuell abgehakt ohne gebuchte Zeit (tracked_seconds = null)' => [
                ['tracked_seconds' => null],
                true,
                7200,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $slotOverrides
     */
    #[Test]
    #[DataProvider('lerneinheitenFuerUnterschreitung')]
    public function erkenntUnterschreitungJeNachLerneinheit(
        array $slotOverrides,
        bool $expectedUnderTarget,
        int $expectedMissingSeconds
    ): void {
        $weeklyPlanning = $this->wochenplanungMit([self::lerneinheit($slotOverrides)]);

        $slot = $weeklyPlanning['days'][2]['slots'][0];
        self::assertSame($expectedUnderTarget, $slot['is_under_target']);
        self::assertSame($expectedMissingSeconds, $slot['missing_seconds']);
    }

    #[Test]
    public function rundetGebuchteStundenDerLerneinheitAufZweiStellen(): void
    {
        $weeklyPlanning = $this->wochenplanungMit([self::lerneinheit(['tracked_seconds' => 4000])]);

        self::assertSame(1.11, $weeklyPlanning['days'][2]['slots'][0]['tracked_hours']);
    }

    #[Test]
    public function zaehltUnterschritteneLerneinheitWeiterAlsErledigt(): void
    {
        $weeklyPlanning = $this->wochenplanungMit([self::lerneinheit(['tracked_seconds' => 3600])]);

        self::assertSame(2.0, $weeklyPlanning['week_summary']['completed_hours']);
        self::assertSame(1, $weeklyPlanning['week_summary']['completed_slot_count']);
    }

    // ------------------------------------------------------------------
    // US-5: Lernziele als erledigt markieren & hierarchische Kaskadierung
    // ------------------------------------------------------------------

    #[Test]
    public function markiertMonatszielAlsErledigtWennAlleWochenzieleErledigtSind(): void
    {
        $completionDate = '2026-11-05';

        // Ausgangslage: Lerneinheit 101 ist noch offen (is_completed = 0)
        $this->dao->method('findLearningSlotByIdAndUserId')
            ->with(101, self::USER_ID)
            ->willReturn([
                'id'              => 101,
                'user_id'         => self::USER_ID,
                'monthly_goal_id' => 3,
                'slot_date'       => '2026-11-04',
                'is_completed'    => 0,
            ]);

        $this->dao->expects($this->once())
            ->method('setLearningSlotCompleted')
            ->with(101, self::USER_ID, true)
            ->willReturn(true);

        // Alle Einheiten der Woche KW 45 sind nach dem Abhaken erledigt
        $this->dao->method('findLearningSlotsByDateRange')
            ->with(self::USER_ID, '2026-11-02', '2026-11-08')
            ->willReturn([
                ['id' => 100, 'monthly_goal_id' => 3, 'is_completed' => 1],
                ['id' => 101, 'monthly_goal_id' => 3, 'is_completed' => 1],
            ]);

        // Monatsziel 3 ist bisher noch offen (completed_at = null)
        $this->dao->method('findMonthlyGoalByIdAndUserId')
            ->with(3, self::USER_ID)
            ->willReturn([
                'id'           => 3,
                'goal_id'      => 7,
                'user_id'      => self::USER_ID,
                'plan_month'   => '2026-11',
                'year_month'   => '2026-11',
                'title'        => 'Kapitel 1 bis 3',
                'completed_at' => null,
            ]);

        // Alle Wocheneinheiten des Monatsziels 3 sind erledigt -> Monatsziel muss automatisch erledigt werden
        $this->dao->method('findLearningSlotsByMonthlyGoalId')
            ->with(3, self::USER_ID)
            ->willReturn([
                ['id' => 100, 'is_completed' => 1],
                ['id' => 101, 'is_completed' => 1],
            ]);

        $this->dao->expects($this->once())
            ->method('setMonthlyGoalCompleted')
            ->with(3, self::USER_ID, $completionDate)
            ->willReturn(true);

        // Semesterziel 7 hat noch ein weiteres offenes Monatsziel -> bleibt offen
        $this->dao->method('findByIdAndUserId')
            ->with(7, self::USER_ID)
            ->willReturn(array_merge(self::PARENT_GOAL, ['completed_at' => null]));

        $this->dao->method('findMonthlyGoalsByGoalId')
            ->with(7, self::USER_ID)
            ->willReturn([
                ['id' => 3, 'completed_at' => $completionDate],
                ['id' => 4, 'completed_at' => null],
            ]);

        $this->dao->expects($this->never())->method('setGoalCompleted');

        $result = $this->controller->toggleLearningSlotCompleted(101, self::USER_ID, $completionDate);

        self::assertTrue($result['is_completed']);
        self::assertTrue($result['week_completed']);
        self::assertTrue($result['monthly_goal_completed']);
        self::assertFalse($result['semester_goal_completed']);
        self::assertSame($completionDate, $result['completed_at']);
    }

    #[Test]
    public function markiertSemesterzielAlsErledigtWennAlleMonatszieleErledigtSind(): void
    {
        $completionDate = '2026-11-05';

        // Monatsziel 4 wird abgehakt (war bisher offen)
        $this->dao->method('findMonthlyGoalByIdAndUserId')
            ->with(4, self::USER_ID)
            ->willReturn([
                'id'           => 4,
                'goal_id'      => 7,
                'user_id'      => self::USER_ID,
                'plan_month'   => '2026-12',
                'year_month'   => '2026-12',
                'title'        => 'Kapitel 4 bis 6',
                'completed_at' => null,
            ]);

        $this->dao->expects($this->once())
            ->method('setMonthlyGoalCompleted')
            ->with(4, self::USER_ID, $completionDate)
            ->willReturn(true);

        // Übergeordnetes Semesterziel 7 ist noch offen
        $this->dao->method('findByIdAndUserId')
            ->with(7, self::USER_ID)
            ->willReturn(array_merge(self::PARENT_GOAL, ['completed_at' => null]));

        // Alle Monatsziele zu Semesterziel 7 sind nun erledigt -> Semesterziel kaskadiert auf erledigt
        $this->dao->method('findMonthlyGoalsByGoalId')
            ->with(7, self::USER_ID)
            ->willReturn([
                ['id' => 3, 'completed_at' => '2026-10-28'],
                ['id' => 4, 'completed_at' => $completionDate],
            ]);

        $this->dao->expects($this->once())
            ->method('setGoalCompleted')
            ->with(7, self::USER_ID, $completionDate)
            ->willReturn(true);

        $result = $this->controller->toggleMonthlyGoalCompleted(4, self::USER_ID, $completionDate);

        self::assertTrue($result['is_completed']);
        self::assertTrue($result['semester_goal_completed']);
        self::assertSame($completionDate, $result['completed_at']);
        self::assertSame('05.11.2026', $result['completed_at_formatted']);
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}>
     */
    public static function offeneUntergeordneteEinheitenSzenarien(): array
    {
        return [
            'Eine Wocheneinheit des Monatsziels ist noch offen' => [
                [
                    ['id' => 101, 'is_completed' => 1],
                    ['id' => 102, 'is_completed' => 0],
                ],
                [
                    ['id' => 3, 'completed_at' => null],
                ],
            ],
            'Monatsziel erledigt, aber zweites Monatsziel des Semesters noch offen' => [
                [
                    ['id' => 101, 'is_completed' => 1],
                ],
                [
                    ['id' => 3, 'completed_at' => '2026-11-05'],
                    ['id' => 4, 'completed_at' => null],
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $monthlySlots
     * @param list<array<string, mixed>> $semesterMonthlyGoals
     */
    #[Test]
    #[DataProvider('offeneUntergeordneteEinheitenSzenarien')]
    public function belaesstZielAlsOffenWennMindestensEineUntergeordneteEinheitOffenIst(
        array $monthlySlots,
        array $semesterMonthlyGoals
    ): void {
        $completionDate = '2026-11-05';

        $this->dao->method('findLearningSlotByIdAndUserId')
            ->with(101, self::USER_ID)
            ->willReturn([
                'id'              => 101,
                'user_id'         => self::USER_ID,
                'monthly_goal_id' => 3,
                'slot_date'       => '2026-11-04',
                'is_completed'    => 0,
            ]);

        $this->dao->expects($this->once())
            ->method('setLearningSlotCompleted')
            ->with(101, self::USER_ID, true)
            ->willReturn(true);

        $this->dao->method('findLearningSlotsByDateRange')
            ->willReturn($monthlySlots);

        $this->dao->method('findMonthlyGoalByIdAndUserId')
            ->with(3, self::USER_ID)
            ->willReturn([
                'id'           => 3,
                'goal_id'      => 7,
                'user_id'      => self::USER_ID,
                'plan_month'   => '2026-11',
                'year_month'   => '2026-11',
                'title'        => 'Kapitel 1 bis 3',
                'completed_at' => null,
            ]);

        $this->dao->method('findLearningSlotsByMonthlyGoalId')
            ->with(3, self::USER_ID)
            ->willReturn($monthlySlots);

        $this->dao->method('findByIdAndUserId')
            ->with(7, self::USER_ID)
            ->willReturn(array_merge(self::PARENT_GOAL, ['completed_at' => null]));

        $this->dao->method('findMonthlyGoalsByGoalId')
            ->with(7, self::USER_ID)
            ->willReturn($semesterMonthlyGoals);

        // Das Semesterziel darf in keinem der Fälle als erledigt markiert werden
        $this->dao->expects($this->never())->method('setGoalCompleted');

        $result = $this->controller->toggleLearningSlotCompleted(101, self::USER_ID, $completionDate);

        self::assertFalse($result['semester_goal_completed']);

        // Wenn noch eine Wocheneinheit offen ist, müssen auch Woche und Monatsziel offen bleiben
        if (count($monthlySlots) > 1) {
            self::assertFalse($result['week_completed']);
            self::assertFalse($result['monthly_goal_completed']);
        }
    }

    #[Test]
    public function speichertAktuellesDatumBeimAbhaken(): void
    {
        $today = date('Y-m-d');
        $todayFormatted = date('d.m.Y');

        $this->dao->method('findByIdAndUserId')
            ->with(7, self::USER_ID)
            ->willReturn(array_merge(self::PARENT_GOAL, ['completed_at' => null]));

        $this->dao->expects($this->once())
            ->method('setGoalCompleted')
            ->with(7, self::USER_ID, $today)
            ->willReturn(true);

        // Ohne explizite Datumsübergabe wird automatisch das aktuelle Tagesdatum (completed_at) gespeichert
        $result = $this->controller->toggleGoalCompleted(7, self::USER_ID);

        self::assertTrue($result['is_completed']);
        self::assertSame($today, $result['completed_at']);
        self::assertSame($todayFormatted, $result['completed_at_formatted']);
    }

    #[Test]
    public function setztUebergeordnetesZielWiederAufOffenWennUntergeordneteEinheitGeoeffnetWird(): void
    {
        // Ausgangslage: Lerneinheit 101, Monatsziel 3 und Semesterziel 7 waren bereits erledigt
        $this->dao->method('findLearningSlotByIdAndUserId')
            ->with(101, self::USER_ID)
            ->willReturn([
                'id'              => 101,
                'user_id'         => self::USER_ID,
                'monthly_goal_id' => 3,
                'slot_date'       => '2026-11-04',
                'is_completed'    => 1,
            ]);

        $this->dao->expects($this->once())
            ->method('setLearningSlotCompleted')
            ->with(101, self::USER_ID, false)
            ->willReturn(true);

        $this->dao->method('findLearningSlotsByDateRange')
            ->willReturn([['id' => 101, 'monthly_goal_id' => 3, 'is_completed' => 0]]);

        $this->dao->method('findMonthlyGoalByIdAndUserId')
            ->with(3, self::USER_ID)
            ->willReturn([
                'id'           => 3,
                'goal_id'      => 7,
                'user_id'      => self::USER_ID,
                'plan_month'   => '2026-11',
                'year_month'   => '2026-11',
                'title'        => 'Kapitel 1 bis 3',
                'completed_at' => '2026-11-04',
            ]);

        $this->dao->method('findLearningSlotsByMonthlyGoalId')
            ->with(3, self::USER_ID)
            ->willReturn([['id' => 101, 'is_completed' => 0]]);

        // Monatsziel muss wieder auf NULL zurückgesetzt werden
        $this->dao->expects($this->once())
            ->method('setMonthlyGoalCompleted')
            ->with(3, self::USER_ID, null)
            ->willReturn(true);

        $this->dao->method('findByIdAndUserId')
            ->with(7, self::USER_ID)
            ->willReturn(array_merge(self::PARENT_GOAL, ['completed_at' => '2026-11-04']));

        $this->dao->method('findMonthlyGoalsByGoalId')
            ->with(7, self::USER_ID)
            ->willReturn([['id' => 3, 'completed_at' => null]]);

        // Semesterziel muss ebenfalls wieder auf NULL zurückgesetzt werden
        $this->dao->expects($this->once())
            ->method('setGoalCompleted')
            ->with(7, self::USER_ID, null)
            ->willReturn(true);

        $result = $this->controller->toggleLearningSlotCompleted(101, self::USER_ID, '2026-11-05');

        self::assertFalse($result['is_completed']);
        self::assertFalse($result['week_completed']);
        self::assertFalse($result['monthly_goal_completed']);
        self::assertFalse($result['semester_goal_completed']);
        self::assertNull($result['completed_at']);
    }

    #[Test]
    public function haktAlleLerneinheitenEinerWocheAbUndKaskadiertNachOben(): void
    {
        $_SESSION['csrf_token'] = 'gueltiges-csrf-token';
        $completionDate = '2026-11-05';

        // KW 45 (02.11.2026 – 08.11.2026) hat zwei Lerneinheiten, davon eine noch offen
        $this->dao->method('findLearningSlotsByDateRange')
            ->with(self::USER_ID, '2026-11-02', '2026-11-08')
            ->willReturn([
                ['id' => 101, 'monthly_goal_id' => 3, 'is_completed' => 1],
                ['id' => 102, 'monthly_goal_id' => 3, 'is_completed' => 0],
            ]);

        $this->dao->expects($this->once())
            ->method('setLearningSlotsCompletedByDateRange')
            ->with(self::USER_ID, '2026-11-02', '2026-11-08', true)
            ->willReturn(true);

        $this->dao->method('findMonthlyGoalByIdAndUserId')
            ->with(3, self::USER_ID)
            ->willReturn([
                'id'           => 3,
                'goal_id'      => 7,
                'user_id'      => self::USER_ID,
                'plan_month'   => '2026-11',
                'year_month'   => '2026-11',
                'title'        => 'Kapitel 1 bis 3',
                'completed_at' => null,
            ]);

        $this->dao->method('findLearningSlotsByMonthlyGoalId')
            ->with(3, self::USER_ID)
            ->willReturn([
                ['id' => 101, 'is_completed' => 1],
                ['id' => 102, 'is_completed' => 1],
            ]);

        $this->dao->expects($this->once())
            ->method('setMonthlyGoalCompleted')
            ->with(3, self::USER_ID, $completionDate)
            ->willReturn(true);

        $this->dao->method('findByIdAndUserId')
            ->with(7, self::USER_ID)
            ->willReturn(array_merge(self::PARENT_GOAL, ['completed_at' => null]));

        $this->dao->method('findMonthlyGoalsByGoalId')
            ->with(7, self::USER_ID)
            ->willReturn([
                ['id' => 3, 'completed_at' => $completionDate],
            ]);

        $this->dao->expects($this->once())
            ->method('setGoalCompleted')
            ->with(7, self::USER_ID, $completionDate)
            ->willReturn(true);

        $result = $this->controller->toggleCompletion(self::USER_ID, [
            'csrf_token' => 'gueltiges-csrf-token',
            'action'     => 'toggle_week',
            'iso_week'   => '2026-W45',
            'year_month' => '2026-11',
        ], $completionDate);

        self::assertTrue($result['is_completed']);
        self::assertTrue($result['week_completed']);
        self::assertSame(2, $result['updated_slot_count']);
        self::assertSame([7], $result['completed_goal_ids']);
    }

    #[Test]
    public function lehntToggleCompletionMitUngueltigemCsrfTokenAb(): void
    {
        $_SESSION['csrf_token'] = 'korrektes-token';

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->toggleCompletion(self::USER_ID, [
                'csrf_token' => 'falsches-token',
                'action'     => 'toggle_goal',
                'goal_id'    => 7,
            ]),
            'csrf_token'
        );
    }

    #[Test]
    public function setztSemesterzielWiederAufOffenWennNeuesMonatszielAngelegtWird(): void
    {
        $this->daoLiefertLernziel();

        $this->dao->expects($this->once())
            ->method('createMonthlyGoal')
            ->with(self::USER_ID, 7, '2026-11', 'Kapitel 4', null, 15.0)
            ->willReturn(12);

        // Das übergeordnete Semesterziel (ID 7) muss beim Anlegen eines neuen Monatsziels wieder auf offen (null) gesetzt werden
        $this->dao->expects($this->once())
            ->method('setGoalCompleted')
            ->with(7, self::USER_ID, null)
            ->willReturn(true);

        $yearMonth = $this->controller->createMonthlyGoal(self::USER_ID, [
            'goal_id'       => '7',
            'year_month'    => '2026-11',
            'title'         => 'Kapitel 4',
            'planned_hours' => '15',
            'description'   => '',
        ]);

        self::assertSame('2026-11', $yearMonth);
    }

    #[Test]
    public function setztAlleUebergeordnetenEbenenWiederAufOffenWennNeueLerneinheitAngelegtWird(): void
    {
        $_SESSION['completed_weeks'][self::USER_ID]['2026-W45'] = '2026-11-04';

        $this->dao->method('findMonthlyGoalByIdAndUserId')
            ->with(3, self::USER_ID)
            ->willReturn([
                'id'           => 3,
                'goal_id'      => 7,
                'user_id'      => self::USER_ID,
                'plan_month'   => '2026-11',
                'year_month'   => '2026-11',
                'title'        => 'Kapitel 1 bis 3',
                'completed_at' => '2026-11-04',
            ]);

        $this->daoLiefertLernziel();

        $this->dao->expects($this->once())
            ->method('createLearningSlot')
            ->with(self::USER_ID, 3, '2026-11-05', '10:00:00', '12:00:00', null)
            ->willReturn(205);

        // Sowohl das Monatsziel (ID 3) als auch das Semesterziel (ID 7) müssen wieder auf offen (null) gesetzt werden
        $this->dao->expects($this->once())
            ->method('setMonthlyGoalCompleted')
            ->with(3, self::USER_ID, null)
            ->willReturn(true);

        $this->dao->expects($this->once())
            ->method('setGoalCompleted')
            ->with(7, self::USER_ID, null)
            ->willReturn(true);

        $result = $this->controller->createLearningSlot(self::USER_ID, [
            'monthly_goal_id' => '3',
            'slot_date'       => '2026-11-05',
            'start_time'      => '10:00',
            'end_time'        => '12:00',
            'notes'           => '',
        ]);

        self::assertSame(205, $result['slot_id']);
        self::assertSame('2026-W45', $result['iso_week']);
        self::assertArrayNotHasKey('2026-W45', $_SESSION['completed_weeks'][self::USER_ID] ?? []);
    }
}
