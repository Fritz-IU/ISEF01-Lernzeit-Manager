<?php
// tests/Unit/Controllers/TimeEntryControllerTest.php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Classes\ValidationException;
use Controllers\TimeEntryController;
use DAO\TimeEntryDAO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für die Validierung und Geschäftsregeln der manuellen Zeitnachbuchung (Issue #9).
 * Der TimeEntryDAO wird durch einen Mock ersetzt, es wird keine Datenbank benötigt.
 */
final class TimeEntryControllerTest extends TestCase
{
    private const USER_ID = 42;
    private const FIXED_TODAY = '2026-10-30';

    /** Modul / Zwischenziel, das der Mock für findMonthlyGoalByIdAndUserId(5, 42) liefert */
    private const MONTHLY_GOAL = [
        'id'                => 5,
        'user_id'           => self::USER_ID,
        'goal_id'           => 1,
        'plan_month'        => '2026-10',
        'title'             => 'Modul Betriebssysteme',
        'description'       => 'Prozesse, Threads & Scheduling',
        'planned_hours'     => 10.0,
        'completed_at'      => null,
        'parent_goal_title' => '3. Semester – Informatik (WiSe 2026/27)',
        'parent_start_date' => '2026-09-01',
        'parent_end_date'   => '2027-02-28',
    ];

    /** Geplante Lerneinheit zu Modul 5 am 28.10.2026 */
    private const LEARNING_SLOT = [
        'id'              => 6,
        'user_id'         => self::USER_ID,
        'monthly_goal_id' => 5,
        'slot_date'       => '2026-10-28',
        'start_time'      => '16:00:00',
        'end_time'        => '18:00:00',
        'notes'           => 'Übungsaufgaben zu Round Robin',
        'is_completed'    => 0,
    ];

    private TimeEntryDAO&MockObject $dao;
    private TimeEntryController $controller;

    protected function setUp(): void
    {
        $this->dao = $this->createMock(TimeEntryDAO::class);
        $this->controller = new TimeEntryController($this->dao);
    }

    /**
     * Gültige Standardeingabe für eine manuelle Nachbuchung, einzelne Felder lassen sich überschreiben.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function nachbuchungEingabe(array $overrides = []): array
    {
        return array_merge([
            'entry_date'       => '2026-10-28',
            'monthly_goal_id'  => '5',
            'duration_hours'   => '1',
            'duration_minutes' => '30',
            'note'             => 'Scheduling-Aufgaben wiederholt',
        ], $overrides);
    }

    /**
     * Mock liefert das Modul 5 für Nutzer 42, für alle anderen IDs null.
     */
    private function daoLiefertModul(): void
    {
        $this->dao->method('findMonthlyGoalByIdAndUserId')->willReturnCallback(
            static fn (int $monthlyGoalId, int $userId): ?array => ($monthlyGoalId === 5 && $userId === self::USER_ID)
                ? self::MONTHLY_GOAL
                : null
        );
    }

    /**
     * Mock liefert das Modul 5 mit abweichendem Planungsmonat, z. B. für Grenzwerte beim Datum.
     */
    private function daoLiefertModulImMonat(string $planMonth): void
    {
        $this->dao->method('findMonthlyGoalByIdAndUserId')->willReturn(
            array_merge(self::MONTHLY_GOAL, ['plan_month' => $planMonth])
        );
    }

    /**
     * Mock liefert einen bestehenden manuellen Zeiteintrag 9 für Nutzer 42.
     */
    private function daoLiefertManuellenEintrag(): void
    {
        $this->dao->method('findByIdAndUserId')->willReturn([
            'id'              => 9,
            'user_id'         => self::USER_ID,
            'monthly_goal_id' => 5,
            'source'          => 'manual',
            'entry_date'      => '2026-10-28',
            'tracked_seconds' => 2700,
        ]);
    }

    /**
     * Offene Lerneinheit für die Auswahlliste im Modal, einzelne Felder lassen sich überschreiben.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function lerneinheitOption(array $overrides = []): array
    {
        return array_merge([
            'start_time'       => '14:00:00',
            'end_time'         => '15:30:00',
            'duration_minutes' => 90,
            'notes'            => '',
            'is_completed'     => 0,
        ], $overrides);
    }

    /**
     * Führt $callable aus und prüft, dass eine ValidationException für das erwartete Feld geworfen wird.
     */
    private function erwartetValidierungsfehler(callable $callable, ?string $expectedField = null): ValidationException
    {
        try {
            $callable();
        } catch (ValidationException $e) {
            if ($expectedField !== null) {
                self::assertSame($expectedField, $e->getField(), 'Falsches Feld in der ValidationException');
            }
            self::assertNotSame('', $e->getMessage());

            return $e;
        }

        self::fail('Es wurde keine ValidationException geworfen.');
    }

    // ------------------------------------------------------------------
    // formatDurationSeconds & formatLearningSlotOptionLabel
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function sekundenMitErwarteterAnzeige(): array
    {
        return [
            'Null Sekunden'            => [0, '0 Min.'],
            'Negative Sekunden'        => [-60, '0 Min.'],
            'Genau 45 Minuten'         => [2700, '45 Min.'],
            'Genau eine Stunde'        => [3600, '1 Std.'],
            'Eineinhalb Stunden'       => [5400, '1 Std. 30 Min.'],
            'Zwei Stunden 15 Minuten'  => [8100, '2 Std. 15 Min.'],
            'Kleiner als eine Minute'  => [20, '1 Min.'],
            'Eine Sekunde'             => [1, '1 Min.'],
            '29 Sekunden'              => [29, '1 Min.'],
            '30 Sekunden'              => [30, '1 Min.'],
            'Knapp eine Stunde'        => [3599, '1 Std.'],
            'Genau 24 Stunden'         => [86400, '24 Std.'],
        ];
    }

    #[Test]
    #[DataProvider('sekundenMitErwarteterAnzeige')]
    public function formatiertSekundenAlsLesbareDauer(int $seconds, string $expected): void
    {
        self::assertSame($expected, TimeEntryController::formatDurationSeconds($seconds));
    }

    #[Test]
    public function formatiertLerneinheitAlsAuswahltext(): void
    {
        $offenerSlot = [
            'start_time'       => '14:00:00',
            'end_time'         => '15:30:00',
            'duration_minutes' => 90,
            'notes'            => 'Kapitel 3 lesen',
            'is_completed'     => 0,
        ];
        self::assertSame(
            '14:00–15:30 Uhr · 1 Std. 30 Min. · Kapitel 3 lesen',
            TimeEntryController::formatLearningSlotOptionLabel($offenerSlot)
        );

        $erledigterSlot = $offenerSlot;
        $erledigterSlot['is_completed'] = 1;
        self::assertSame(
            '14:00–15:30 Uhr · 1 Std. 30 Min. · Kapitel 3 lesen · erledigt',
            TimeEntryController::formatLearningSlotOptionLabel($erledigterSlot)
        );

        $unterschrittenerSlot = $erledigterSlot;
        $unterschrittenerSlot['planned_seconds'] = 5400;
        $unterschrittenerSlot['tracked_seconds'] = 3600;
        self::assertSame(
            '14:00–15:30 Uhr · 1 Std. 30 Min. · Kapitel 3 lesen · 0,5 h fehlen',
            TimeEntryController::formatLearningSlotOptionLabel($unterschrittenerSlot)
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function lerneinheitenMitErwartetemText(): array
    {
        $basis = '14:00–15:30 Uhr · 1 Std. 30 Min.';
        $erledigt = ['is_completed' => 1, 'planned_seconds' => 5400];

        return [
            'Ohne Notiz' => [self::lerneinheitOption(), $basis],
            'Keine Daten (Standardwerte)' => [[], '00:00–00:00 Uhr · 1 Std.'],
            'Dauer 0 wird zu einer Minute' => [
                self::lerneinheitOption(['duration_minutes' => 0]),
                '14:00–15:30 Uhr · 1 Min.',
            ],
            'Erledigt ohne gebuchte Zeit' => [
                self::lerneinheitOption($erledigt + ['tracked_seconds' => 0]),
                $basis . ' · erledigt',
            ],
            'Erledigt mit genau der geplanten Zeit' => [
                self::lerneinheitOption($erledigt + ['tracked_seconds' => 5400]),
                $basis . ' · erledigt',
            ],
            'Erledigt mit mehr als der geplanten Zeit' => [
                self::lerneinheitOption($erledigt + ['tracked_seconds' => 7200]),
                $basis . ' · erledigt',
            ],
            'Offen trotz zu wenig gebuchter Zeit' => [
                self::lerneinheitOption(['planned_seconds' => 5400, 'tracked_seconds' => 3600]),
                $basis,
            ],
            'Eine Minute fehlt, Anzeige mindestens 0,1 h' => [
                self::lerneinheitOption($erledigt + ['tracked_seconds' => 5340]),
                $basis . ' · 0,1 h fehlen',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $slot
     */
    #[Test]
    #[DataProvider('lerneinheitenMitErwartetemText')]
    public function formatiertLerneinheitJeNachStatus(array $slot, string $expected): void
    {
        self::assertSame($expected, TimeEntryController::formatLearningSlotOptionLabel($slot));
    }

    // ------------------------------------------------------------------
    // validateAndParseManualEntryInput & createManualEntry
    // ------------------------------------------------------------------

    #[Test]
    public function akzeptiertGueltigeManuelleNachbuchungUndBerechnetSekunden(): void
    {
        $this->daoLiefertModul();
        $this->dao->expects(self::once())
            ->method('createManualEntry')
            ->with(
                self::USER_ID,
                5,
                '2026-10-28',
                5400,
                'Scheduling-Aufgaben wiederholt',
                null
            )
            ->willReturn(19);

        $result = $this->controller->createManualEntry(
            self::USER_ID,
            self::nachbuchungEingabe(),
            self::FIXED_TODAY
        );

        self::assertSame(19, $result['entry_id']);
        self::assertSame(5, $result['monthly_goal_id']);
        self::assertSame('Modul Betriebssysteme', $result['monthly_goal_title']);
        self::assertSame('2026-10-28', $result['entry_date']);
        self::assertSame(5400, $result['tracked_seconds']);
        self::assertSame(90, $result['duration_minutes']);
        self::assertSame('1 Std. 30 Min.', $result['formatted_duration']);
        self::assertSame('Scheduling-Aufgaben wiederholt', $result['note']);
        self::assertNull($result['learning_slot_id']);
        self::assertFalse($result['slot_completed']);
    }

    #[Test]
    public function akzeptiertNachbuchungAmHeutigenTagOhneNotiz(): void
    {
        $this->daoLiefertModul();

        $parsed = $this->controller->validateAndParseManualEntryInput(
            self::USER_ID,
            self::nachbuchungEingabe([
                'entry_date'       => self::FIXED_TODAY,
                'duration_hours'   => '0',
                'duration_minutes' => '45',
                'note'             => '   ',
            ]),
            self::FIXED_TODAY
        );

        self::assertSame(self::FIXED_TODAY, $parsed['entry_date']);
        self::assertSame(2700, $parsed['tracked_seconds']);
        self::assertNull($parsed['note']);
    }

    #[Test]
    public function haktVerknuepfteLerneinheitAutomatischAlsErledigtAb(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('findLearningSlotByIdAndUserId')->willReturnCallback(
            static fn (int $slotId, int $userId): ?array => ($slotId === 6 && $userId === self::USER_ID)
                ? self::LEARNING_SLOT
                : null
        );

        $this->dao->expects(self::once())
            ->method('createManualEntry')
            ->with(self::USER_ID, 5, '2026-10-28', 5400, 'Scheduling-Aufgaben wiederholt', 6)
            ->willReturn(21);

        $this->dao->expects(self::once())
            ->method('markLearningSlotCompleted')
            ->with(6, self::USER_ID)
            ->willReturn(true);

        $result = $this->controller->createManualEntry(
            self::USER_ID,
            self::nachbuchungEingabe(['learning_slot_id' => '6']),
            self::FIXED_TODAY
        );

        self::assertSame(6, $result['learning_slot_id']);
        self::assertTrue($result['slot_completed']);
    }

    #[Test]
    public function haktOhneVerknuepfteLerneinheitNichtsAb(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('createManualEntry')->willReturn(19);
        $this->dao->expects(self::never())->method('markLearningSlotCompleted');

        $result = $this->controller->createManualEntry(
            self::USER_ID,
            self::nachbuchungEingabe(),
            self::FIXED_TODAY
        );

        self::assertFalse($result['slot_completed']);
    }

    #[Test]
    public function speichertBeiUngueltigerEingabeNichts(): void
    {
        $this->daoLiefertModul();
        $this->dao->expects(self::never())->method('createManualEntry');
        $this->dao->expects(self::never())->method('markLearningSlotCompleted');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->createManualEntry(
                self::USER_ID,
                self::nachbuchungEingabe(['duration_hours' => '0', 'duration_minutes' => '0']),
                self::FIXED_TODAY
            ),
            'duration_minutes'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function ungueltigeZielAuswahlen(): array
    {
        return [
            'Leerstring' => [''],
            'Null'       => [null],
            'Wert 0'     => ['0'],
            'Negativ'    => ['-3'],
            'Keine Zahl' => ['abc'],
            'Dezimalzahl' => ['5.5'],
            'Array'      => [['5']],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeZielAuswahlen')]
    public function lehntFehlendesOderUngueltigesZielAb(mixed $invalidGoalId): void
    {
        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['monthly_goal_id' => $invalidGoalId]),
                self::FIXED_TODAY
            ),
            'monthly_goal_id'
        );
    }

    #[Test]
    public function akzeptiertZielIdAlsGanzzahlAusJson(): void
    {
        $this->daoLiefertModul();

        $parsed = $this->controller->validateAndParseManualEntryInput(
            self::USER_ID,
            self::nachbuchungEingabe(['monthly_goal_id' => 5]),
            self::FIXED_TODAY
        );

        self::assertSame(5, $parsed['monthly_goal_id']);
    }

    #[Test]
    public function ueberspringtMonatspruefungBeiLeeremPlanungsmonat(): void
    {
        $this->daoLiefertModulImMonat('');

        $parsed = $this->controller->validateAndParseManualEntryInput(
            self::USER_ID,
            self::nachbuchungEingabe(),
            self::FIXED_TODAY
        );

        self::assertSame(5, $parsed['monthly_goal_id']);
    }

    #[Test]
    public function lehntFremdesOderNichtExistierendesZielAb(): void
    {
        $this->daoLiefertModul();

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['monthly_goal_id' => '999']),
                self::FIXED_TODAY
            ),
            'monthly_goal_id'
        );
    }

    #[Test]
    public function lehntModulEinesAnderenMonatsAlsDasDatumAb(): void
    {
        $this->daoLiefertModul(); // Modul 5 gehört zu '2026-10'

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['entry_date' => '2026-09-15']),
                self::FIXED_TODAY
            ),
            'monthly_goal_id'
        );
    }

    #[Test]
    public function akzeptiertGeplanteLerneinheitAnDerMonatsgrenze(): void
    {
        $this->daoLiefertModul(); // Modul 5 gehört zu '2026-10'
        $this->dao->method('findLearningSlotByIdAndUserId')->willReturn(
            array_merge(self::LEARNING_SLOT, ['id' => 9, 'slot_date' => '2026-11-01'])
        );

        $this->dao->expects(self::once())
            ->method('createManualEntry')
            ->with(self::USER_ID, 5, '2026-11-01', 5400, 'Scheduling-Aufgaben wiederholt', 9)
            ->willReturn(22);
        $this->dao->expects(self::once())
            ->method('markLearningSlotCompleted')
            ->with(9, self::USER_ID)
            ->willReturn(true);

        $result = $this->controller->createManualEntry(
            self::USER_ID,
            self::nachbuchungEingabe(['entry_date' => '2026-11-01', 'learning_slot_id' => '9']),
            '2026-11-01'
        );

        self::assertSame(9, $result['learning_slot_id']);
        self::assertTrue($result['slot_completed']);
    }

    #[Test]
    public function lehntLerneinheitAnDerMonatsgrenzeAnEinemAnderenTagAb(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('findLearningSlotByIdAndUserId')->willReturn(self::LEARNING_SLOT);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['entry_date' => '2026-11-01', 'learning_slot_id' => '6']),
                '2026-11-01'
            ),
            'learning_slot_id'
        );
    }

    #[Test]
    public function lehntAnderenMonatBeiLerneinheitOhneDatumAb(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('findLearningSlotByIdAndUserId')->willReturn(
            array_merge(self::LEARNING_SLOT, ['slot_date' => ''])
        );

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['entry_date' => '2026-11-01', 'learning_slot_id' => '6']),
                '2026-11-01'
            ),
            'monthly_goal_id'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ungueltigeDatumsangaben(): array
    {
        return [
            'Leer'                   => [''],
            'Deutsches Format'       => ['28.10.2026'],
            'Nicht existentes Datum' => ['2026-02-30'],
            'Vor dem Jahr 2000'      => ['1999-12-31'],
            'In der Zukunft'         => ['2026-10-31'],
            'Monat 13'               => ['2026-13-01'],
            'Kein Schaltjahr'        => ['2025-02-29'],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeDatumsangaben')]
    public function lehntUngueltigesDatumAb(string $invalidDate): void
    {
        $this->daoLiefertModul();

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['entry_date' => $invalidDate]),
                self::FIXED_TODAY
            ),
            'entry_date'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function datumsangabenFalschenTyps(): array
    {
        return [
            'Null'  => [null],
            'Zahl'  => [20261028],
            'Array' => [['2026-10-28']],
        ];
    }

    #[Test]
    #[DataProvider('datumsangabenFalschenTyps')]
    public function lehntDatumFalschenTypsAb(mixed $invalidDate): void
    {
        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['entry_date' => $invalidDate]),
                self::FIXED_TODAY
            ),
            'entry_date'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function gueltigeDatumsangaben(): array
    {
        return [
            'Frühestes erlaubtes Datum' => ['2000-01-01', '2000-01', '2000-01-01'],
            'Schalttag'                 => ['2024-02-29', '2024-02', '2024-02-29'],
            'Mit Leerzeichen'           => ['  2026-10-28 ', '2026-10', '2026-10-28'],
        ];
    }

    #[Test]
    #[DataProvider('gueltigeDatumsangaben')]
    public function akzeptiertGueltigesDatum(string $date, string $planMonth, string $expected): void
    {
        $this->daoLiefertModulImMonat($planMonth);

        $parsed = $this->controller->validateAndParseManualEntryInput(
            self::USER_ID,
            self::nachbuchungEingabe(['entry_date' => $date]),
            self::FIXED_TODAY
        );

        self::assertSame($expected, $parsed['entry_date']);
    }

    #[Test]
    public function nutztBeiUngueltigemReferenzdatumDenAktuellenTag(): void
    {
        $this->daoLiefertModulImMonat('2000-01');

        $parsed = $this->controller->validateAndParseManualEntryInput(
            self::USER_ID,
            self::nachbuchungEingabe(['entry_date' => '2000-01-15']),
            'kein-datum'
        );
        self::assertSame('2000-01-15', $parsed['entry_date']);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['entry_date' => '2999-01-01']),
                'kein-datum'
            ),
            'entry_date'
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function gueltigeDauerEingaben(): array
    {
        return [
            'Minimum von einer Minute' => [['duration_hours' => '0', 'duration_minutes' => '1'], 60],
            'Knapp unter 24 Stunden'   => [['duration_hours' => '23', 'duration_minutes' => '59'], 86340],
            'Maximum von 24 Stunden'   => [['duration_hours' => '24', 'duration_minutes' => '0'], 86400],
            'Leeres Stundenfeld'       => [['duration_hours' => '', 'duration_minutes' => '30'], 1800],
            'Leeres Minutenfeld'       => [['duration_hours' => '2', 'duration_minutes' => ''], 7200],
            'Ganzzahlen aus JSON'      => [['duration_hours' => 1, 'duration_minutes' => 30], 5400],
        ];
    }

    /**
     * @param array<string, mixed> $durationOverrides
     */
    #[Test]
    #[DataProvider('gueltigeDauerEingaben')]
    public function akzeptiertGueltigeDauer(array $durationOverrides, int $expectedSeconds): void
    {
        $this->daoLiefertModul();

        $parsed = $this->controller->validateAndParseManualEntryInput(
            self::USER_ID,
            self::nachbuchungEingabe($durationOverrides),
            self::FIXED_TODAY
        );

        self::assertSame($expectedSeconds, $parsed['tracked_seconds']);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function minutenOhneStundenfeld(): array
    {
        return [
            'Mehr als 59 Minuten' => ['90', 5400],
            'Maximum 1440 Minuten' => ['1440', 86400],
        ];
    }

    #[Test]
    #[DataProvider('minutenOhneStundenfeld')]
    public function akzeptiertReineMinutenangabeOhneStundenfeld(string $minutes, int $expectedSeconds): void
    {
        $this->daoLiefertModul();
        $eingabe = self::nachbuchungEingabe(['duration_minutes' => $minutes]);
        unset($eingabe['duration_hours']);

        $parsed = $this->controller->validateAndParseManualEntryInput(self::USER_ID, $eingabe, self::FIXED_TODAY);

        self::assertSame($expectedSeconds, $parsed['tracked_seconds']);
    }

    #[Test]
    public function lehntMehrAls1440MinutenOhneStundenfeldAb(): void
    {
        $this->daoLiefertModul();
        $eingabe = self::nachbuchungEingabe(['duration_minutes' => '1441']);
        unset($eingabe['duration_hours']);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(self::USER_ID, $eingabe, self::FIXED_TODAY),
            'duration_minutes'
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function ungueltigeDauerEingaben(): array
    {
        return [
            '0 Stunden und 0 Minuten' => [
                ['duration_hours' => '0', 'duration_minutes' => '0'],
                'duration_minutes',
            ],
            'Beide Felder leer' => [
                ['duration_hours' => '', 'duration_minutes' => ''],
                'duration_minutes',
            ],
            'Negative Stunden' => [
                ['duration_hours' => '-1', 'duration_minutes' => '30'],
                'duration_hours',
            ],
            'Dezimalzahl bei Stunden' => [
                ['duration_hours' => '1.5', 'duration_minutes' => '0'],
                'duration_hours',
            ],
            'Mehr als 24 Stunden' => [
                ['duration_hours' => '25', 'duration_minutes' => '0'],
                'duration_hours',
            ],
            '24 Stunden plus 15 Minuten' => [
                ['duration_hours' => '24', 'duration_minutes' => '15'],
                'duration_hours',
            ],
            'Minuten ab 60 bei vorhandenem Stundenfeld' => [
                ['duration_hours' => '1', 'duration_minutes' => '60'],
                'duration_minutes',
            ],
            'Nicht-numerische Minuten' => [
                ['duration_hours' => '0', 'duration_minutes' => 'dreißig'],
                'duration_minutes',
            ],
            'Komma bei Stunden' => [
                ['duration_hours' => '1,5', 'duration_minutes' => '0'],
                'duration_hours',
            ],
            'Leere Stunden und 60 Minuten' => [
                ['duration_hours' => '', 'duration_minutes' => '60'],
                'duration_minutes',
            ],
            'Nicht-skalare Werte' => [
                ['duration_hours' => ['1'], 'duration_minutes' => ['30']],
                'duration_minutes',
            ],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeDauerEingaben')]
    public function lehntUngueltigeDauerAb(array $durationOverrides, string $expectedField): void
    {
        $this->daoLiefertModul();

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe($durationOverrides),
                self::FIXED_TODAY
            ),
            $expectedField
        );
    }

    #[Test]
    public function lehntZuLangeNotizAb(): void
    {
        $this->daoLiefertModul();
        $zuLangeNotiz = str_repeat('a', TimeEntryController::MAX_NOTE_LENGTH + 1);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['note' => $zuLangeNotiz]),
                self::FIXED_TODAY
            ),
            'note'
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function gueltigeNotizen(): array
    {
        $maximum = TimeEntryController::MAX_NOTE_LENGTH;

        return [
            'Genau Maximallänge'           => [str_repeat('a', $maximum), str_repeat('a', $maximum)],
            'Maximallänge mit Umlauten'    => [str_repeat('ä', $maximum), str_repeat('ä', $maximum)],
            'Leerzeichen zählen nicht mit' => ['  ' . str_repeat('a', $maximum) . '  ', str_repeat('a', $maximum)],
            'Kein String'                  => [123, null],
        ];
    }

    #[Test]
    #[DataProvider('gueltigeNotizen')]
    public function akzeptiertUndBereinigtNotiz(mixed $note, ?string $expected): void
    {
        $this->daoLiefertModul();

        $parsed = $this->controller->validateAndParseManualEntryInput(
            self::USER_ID,
            self::nachbuchungEingabe(['note' => $note]),
            self::FIXED_TODAY
        );

        self::assertSame($expected, $parsed['note']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function eingabenOhneLerneinheit(): array
    {
        return [
            'Feld fehlt' => [[]],
            'Leerstring' => [['learning_slot_id' => '']],
            'Wert 0'     => [['learning_slot_id' => '0']],
        ];
    }

    /**
     * @param array<string, mixed> $slotOverrides
     */
    #[Test]
    #[DataProvider('eingabenOhneLerneinheit')]
    public function behandeltFehlendeLerneinheitAlsOptional(array $slotOverrides): void
    {
        $this->daoLiefertModul();
        $this->dao->expects(self::never())->method('findLearningSlotByIdAndUserId');

        $parsed = $this->controller->validateAndParseManualEntryInput(
            self::USER_ID,
            self::nachbuchungEingabe($slotOverrides),
            self::FIXED_TODAY
        );

        self::assertNull($parsed['learning_slot_id']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ungueltigeLerneinheitIds(): array
    {
        return [
            'Keine Zahl'  => ['abc'],
            'Negativ'     => ['-1'],
            'Dezimalzahl' => ['6.5'],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeLerneinheitIds')]
    public function lehntUngueltigeLerneinheitIdAb(string $invalidSlotId): void
    {
        $this->daoLiefertModul();
        $this->dao->expects(self::never())->method('findLearningSlotByIdAndUserId');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['learning_slot_id' => $invalidSlotId]),
                self::FIXED_TODAY
            ),
            'learning_slot_id'
        );
    }

    #[Test]
    public function lehntFremdeOderNichtExistierendeLerneinheitAb(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('findLearningSlotByIdAndUserId')->willReturn(null);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['learning_slot_id' => '999']),
                self::FIXED_TODAY
            ),
            'learning_slot_id'
        );
    }

    #[Test]
    public function ueberspringtTagespruefungBeiLeeremSlotDatum(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('findLearningSlotByIdAndUserId')->willReturn(
            array_merge(self::LEARNING_SLOT, ['slot_date' => ''])
        );

        $parsed = $this->controller->validateAndParseManualEntryInput(
            self::USER_ID,
            self::nachbuchungEingabe(['learning_slot_id' => '6']),
            self::FIXED_TODAY
        );

        self::assertSame(6, $parsed['learning_slot_id']);
    }

    #[Test]
    public function lehntLerneinheitEinesAnderenModulsAb(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('findLearningSlotByIdAndUserId')->willReturn([
            'id'              => 8,
            'user_id'         => self::USER_ID,
            'monthly_goal_id' => 4, // anderes Modul als 5
            'slot_date'       => '2026-10-28',
        ]);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['learning_slot_id' => '8']),
                self::FIXED_TODAY
            ),
            'learning_slot_id'
        );
    }

    #[Test]
    public function lehntLerneinheitEinesAnderenTagesAb(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('findLearningSlotByIdAndUserId')->willReturn([
            'id'              => 6,
            'user_id'         => self::USER_ID,
            'monthly_goal_id' => 5,
            'slot_date'       => '2026-10-23', // anderes Datum als 2026-10-28
        ]);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseManualEntryInput(
                self::USER_ID,
                self::nachbuchungEingabe(['learning_slot_id' => '6']),
                self::FIXED_TODAY
            ),
            'learning_slot_id'
        );
    }

    // ------------------------------------------------------------------
    // updateManualEntry & deleteEntry
    // ------------------------------------------------------------------

    #[Test]
    public function aktualisiertBestehendeManuelleNachbuchung(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('findByIdAndUserId')
            ->with(9, self::USER_ID)
            ->willReturn([
                'id'              => 9,
                'user_id'         => self::USER_ID,
                'monthly_goal_id' => 5,
                'source'          => 'manual',
                'entry_date'      => '2026-10-28',
                'tracked_seconds' => 2700,
            ]);

        $this->dao->expects(self::once())
            ->method('updateManualEntry')
            ->with(9, self::USER_ID, 5, '2026-10-28', 3600, 'Aktualisierte Notiz', null)
            ->willReturn(true);

        $updated = $this->controller->updateManualEntry(
            9,
            self::USER_ID,
            self::nachbuchungEingabe([
                'duration_hours'   => '1',
                'duration_minutes' => '0',
                'note'             => 'Aktualisierte Notiz',
            ]),
            self::FIXED_TODAY
        );

        self::assertSame(9, $updated['entry_id']);
        self::assertSame(3600, $updated['tracked_seconds']);
        self::assertSame('1 Std.', $updated['formatted_duration']);
    }

    #[Test]
    public function haktLerneinheitBeimAktualisierenAb(): void
    {
        $this->daoLiefertModul();
        $this->daoLiefertManuellenEintrag();
        $this->dao->method('findLearningSlotByIdAndUserId')->willReturn(self::LEARNING_SLOT);

        $this->dao->expects(self::once())
            ->method('updateManualEntry')
            ->with(9, self::USER_ID, 5, '2026-10-28', 5400, 'Scheduling-Aufgaben wiederholt', 6)
            ->willReturn(true);
        $this->dao->expects(self::once())
            ->method('markLearningSlotCompleted')
            ->with(6, self::USER_ID)
            ->willReturn(true);

        $updated = $this->controller->updateManualEntry(
            9,
            self::USER_ID,
            self::nachbuchungEingabe(['learning_slot_id' => '6']),
            self::FIXED_TODAY
        );

        self::assertSame(6, $updated['learning_slot_id']);
        self::assertTrue($updated['slot_completed']);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function ungueltigeEintragsIds(): array
    {
        return [
            'Null'    => [0],
            'Negativ' => [-1],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeEintragsIds')]
    public function lehntAktualisierungMitUngueltigerIdAb(int $invalidEntryId): void
    {
        $this->dao->expects(self::never())->method('findByIdAndUserId');
        $this->dao->expects(self::never())->method('updateManualEntry');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->updateManualEntry(
                $invalidEntryId,
                self::USER_ID,
                self::nachbuchungEingabe(),
                self::FIXED_TODAY
            )
        );
    }

    #[Test]
    public function lehntAktualisierungEinesFremdenEintragsAb(): void
    {
        $this->daoLiefertModul();
        $this->dao->method('findByIdAndUserId')->willReturn(null);
        $this->dao->expects(self::never())->method('updateManualEntry');

        $exception = $this->erwartetValidierungsfehler(
            fn () => $this->controller->updateManualEntry(
                999,
                self::USER_ID,
                self::nachbuchungEingabe(),
                self::FIXED_TODAY
            )
        );

        self::assertStringContainsString('nicht gefunden', $exception->getMessage());
    }

    #[Test]
    public function aktualisiertBeiUngueltigerEingabeNichts(): void
    {
        $this->daoLiefertModul();
        $this->daoLiefertManuellenEintrag();
        $this->dao->expects(self::never())->method('updateManualEntry');
        $this->dao->expects(self::never())->method('markLearningSlotCompleted');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->updateManualEntry(
                9,
                self::USER_ID,
                self::nachbuchungEingabe(['entry_date' => '2026-10-31']),
                self::FIXED_TODAY
            ),
            'entry_date'
        );
    }

    #[Test]
    public function lehntAktualisierungEinesStoppuhrEintragsAb(): void
    {
        $this->daoLiefertModul();
        $this->dao->expects(self::never())->method('updateManualEntry');
        $this->dao->method('findByIdAndUserId')
            ->with(3, self::USER_ID)
            ->willReturn([
                'id'              => 3,
                'user_id'         => self::USER_ID,
                'monthly_goal_id' => 3,
                'source'          => 'stopwatch',
            ]);

        $exception = $this->erwartetValidierungsfehler(
            fn () => $this->controller->updateManualEntry(
                3,
                self::USER_ID,
                self::nachbuchungEingabe(),
                self::FIXED_TODAY
            )
        );

        self::assertStringContainsString('Nur manuell nachgebuchte', $exception->getMessage());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function eintragsQuellen(): array
    {
        return [
            'Manuelle Nachbuchung' => ['manual'],
            'Stoppuhr'             => ['stopwatch'],
        ];
    }

    #[Test]
    #[DataProvider('eintragsQuellen')]
    public function loeschtZeiteintragDesNutzers(string $source): void
    {
        $this->dao->method('findByIdAndUserId')
            ->with(9, self::USER_ID)
            ->willReturn(['id' => 9, 'user_id' => self::USER_ID, 'source' => $source]);

        $this->dao->expects(self::once())
            ->method('deleteByIdAndUserId')
            ->with(9, self::USER_ID)
            ->willReturn(true);

        $this->controller->deleteEntry(9, self::USER_ID);
    }

    #[Test]
    public function lehntLoeschenEinesFremdenEintragsAb(): void
    {
        $this->dao->method('findByIdAndUserId')->willReturn(null);
        $this->dao->expects(self::never())->method('deleteByIdAndUserId');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->deleteEntry(999, self::USER_ID)
        );
    }

    #[Test]
    #[DataProvider('ungueltigeEintragsIds')]
    public function lehntLoeschenMitUngueltigerIdAb(int $invalidEntryId): void
    {
        $this->dao->expects(self::never())->method('findByIdAndUserId');
        $this->dao->expects(self::never())->method('deleteByIdAndUserId');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->deleteEntry($invalidEntryId, self::USER_ID)
        );
    }

    // ------------------------------------------------------------------
    // getSelectableLearningSlots & getSelectableGoalsGroupedBySemester
    // ------------------------------------------------------------------

    #[Test]
    public function ergaenztAuswaehlbareLerneinheitenUmOptionLabel(): void
    {
        $this->dao->expects(self::once())
            ->method('findLearningSlotsByUserId')
            ->with(self::USER_ID)
            ->willReturn([self::lerneinheitOption(['id' => 6, 'notes' => 'Kapitel 3 lesen'])]);

        $slots = $this->controller->getSelectableLearningSlots(self::USER_ID);

        self::assertCount(1, $slots);
        self::assertSame(6, $slots[0]['id']);
        self::assertSame('14:00–15:30 Uhr · 1 Std. 30 Min. · Kapitel 3 lesen', $slots[0]['option_label']);
    }

    #[Test]
    public function liefertLeereListeOhneAuswaehlbareLerneinheiten(): void
    {
        $this->dao->method('findLearningSlotsByUserId')->willReturn([]);

        self::assertSame([], $this->controller->getSelectableLearningSlots(self::USER_ID));
    }

    #[Test]
    public function gruppiertAuswaehlbareModuleDesNutzersNachSemester(): void
    {
        $this->dao->expects(self::once())
            ->method('findSelectableMonthlyGoalsByUserId')
            ->with(self::USER_ID)
            ->willReturn([self::MONTHLY_GOAL]);

        $grouped = $this->controller->getSelectableGoalsGroupedBySemester(self::USER_ID);

        self::assertCount(1, $grouped);
        self::assertSame(1, $grouped[0]['goal_id']);
        self::assertSame('3. Semester – Informatik (WiSe 2026/27)', $grouped[0]['goal_title']);
        self::assertSame(5, $grouped[0]['items'][0]['id']);
    }

    // ------------------------------------------------------------------
    // groupMonthlyGoalsBySemester
    // ------------------------------------------------------------------

    #[Test]
    public function gruppiertModuleNachSemesternUndFormatiertOptionLabels(): void
    {
        $rows = [
            [
                'id'                => 3,
                'goal_id'           => 1,
                'parent_goal_title' => '3. Semester – Informatik',
                'plan_month'        => '2026-10',
                'title'             => 'Modul Software Engineering',
                'planned_hours'     => 18.0,
                'tracked_seconds'   => 6300, // 1,8 h gerundet auf 1 Stelle
            ],
            [
                'id'                => 4,
                'goal_id'           => 1,
                'parent_goal_title' => '3. Semester – Informatik',
                'plan_month'        => '2026-10',
                'title'             => 'Modul Datenbanken',
                'planned_hours'     => 15.0,
                'tracked_seconds'   => 5400, // 1,5 h
            ],
            [
                'id'                => 11,
                'goal_id'           => 2,
                'parent_goal_title' => '4. Semester – Informatik',
                'plan_month'        => '2027-03',
                'title'             => 'Modul IT-Sicherheit',
                'planned_hours'     => 15.0,
                'tracked_seconds'   => 0,
            ],
        ];

        $grouped = TimeEntryController::groupMonthlyGoalsBySemester($rows);

        self::assertCount(2, $grouped);
        self::assertSame(1, $grouped[0]['goal_id']);
        self::assertSame('3. Semester – Informatik', $grouped[0]['goal_title']);
        self::assertCount(2, $grouped[0]['items']);
        self::assertSame(
            'Modul Datenbanken · 1,5 / 15,0 h',
            $grouped[0]['items'][1]['option_label']
        );

        self::assertSame(2, $grouped[1]['goal_id']);
        self::assertSame(
            'Modul IT-Sicherheit · 0,0 / 15,0 h',
            $grouped[1]['items'][0]['option_label']
        );
    }

    #[Test]
    public function liefertBeiLeererModullisteKeineGruppen(): void
    {
        self::assertSame([], TimeEntryController::groupMonthlyGoalsBySemester([]));
    }

    #[Test]
    public function nutztStandardwerteBeiFehlendenModulangaben(): void
    {
        $grouped = TimeEntryController::groupMonthlyGoalsBySemester([
            ['goal_id' => 7, 'title' => 'Modul ohne Semester', 'plan_month' => 'ungültig'],
        ]);

        self::assertSame('Sonstige Ziele', $grouped[0]['goal_title']);

        $item = $grouped[0]['items'][0];
        self::assertSame('ungültig', $item['month_label_short']);
        self::assertSame(0.0, $item['tracked_hours']);
        self::assertSame('Modul ohne Semester · 0,0 / 0,0 h', $item['option_label']);
    }

    #[Test]
    public function ergaenztModuleUmMonatUndGebuchteStunden(): void
    {
        $grouped = TimeEntryController::groupMonthlyGoalsBySemester([
            ['goal_id' => 1, 'title' => 'Modul', 'plan_month' => '2026-10', 'tracked_seconds' => 6300],
        ]);

        $item = $grouped[0]['items'][0];
        self::assertSame('Okt 2026', $item['month_label_short']);
        self::assertSame(1.8, $item['tracked_hours']);
    }
}
