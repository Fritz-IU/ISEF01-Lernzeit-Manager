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
    public function lehntAktualisierungEinesStoppuhrEintragsAb(): void
    {
        $this->dao->method('findByIdAndUserId')
            ->with(3, self::USER_ID)
            ->willReturn([
                'id'              => 3,
                'user_id'         => self::USER_ID,
                'monthly_goal_id' => 3,
                'source'          => 'stopwatch',
            ]);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->updateManualEntry(
                3,
                self::USER_ID,
                self::nachbuchungEingabe(),
                self::FIXED_TODAY
            )
        );
    }

    #[Test]
    public function loeschtZeiteintragDesNutzersUndLehntFremdenAb(): void
    {
        $this->dao->method('findByIdAndUserId')->willReturnCallback(
            static fn (int $entryId, int $userId): ?array => ($entryId === 9 && $userId === self::USER_ID)
                ? ['id' => 9, 'user_id' => self::USER_ID, 'source' => 'manual']
                : null
        );

        $this->dao->expects(self::once())
            ->method('deleteByIdAndUserId')
            ->with(9, self::USER_ID)
            ->willReturn(true);

        $this->controller->deleteEntry(9, self::USER_ID);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->deleteEntry(999, self::USER_ID)
        );
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
}
