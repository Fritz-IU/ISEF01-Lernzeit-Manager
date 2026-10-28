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
        $this->dao = $this->createMock(GoalDAO::class);
        $this->controller = new GoalController($this->dao);
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
}
