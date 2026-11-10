<?php
// tests/Unit/Controllers/TimeTrackingControllerTest.php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Classes\ValidationException;
use Controllers\TimeTrackingController;
use DAO\TimeTrackingDAO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für die Validierung und Geschäftsregeln der Stoppuhr und Zeiterfassung (US-4).
 * Das TimeTrackingDAO wird durch einen Mock ersetzt, es wird keine Datenbank benötigt.
 */
final class TimeTrackingControllerTest extends TestCase
{
    private const USER_ID = 42;
    private const VALID_GOAL_ID = 7;

    private TimeTrackingDAO&MockObject $dao;
    private TimeTrackingController $controller;

    protected function setUp(): void
    {
        $this->dao = $this->createMock(TimeTrackingDAO::class);
        $this->controller = new TimeTrackingController($this->dao);

        // Standard: Lernziel 7 existiert für Nutzer 42
        $this->dao->method('goalExistsForUser')->willReturnCallback(
            static fn (int $goalId, int $userId): bool => ($goalId === self::VALID_GOAL_ID && $userId === self::USER_ID)
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

        self::fail("Erwartete ValidationException für Feld '{$expectedField}' wurde nicht geworfen.");
    }

    // =========================================================================
    // 1. Validierung des Pflichtfelds goal_id
    // =========================================================================

    /**
     * @return array<string, array{mixed}>
     */
    public static function ungueltigeGoalIdProvider(): array
    {
        return [
            'fehlt (null)'             => [null],
            'leerer String'            => [''],
            'nur Leerzeichen'          => ['   '],
            'Zahl 0'                   => [0],
            'String 0'                 => ['0'],
            'negative Zahl'            => [-5],
            'Text statt Zahl'          => ['nicht-numerisch'],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeGoalIdProvider')]
    public function lehntFehlendesOderUngueltigesLernzielAb(mixed $goalId): void
    {
        $input = [
            'goal_id'    => $goalId,
            'started_at' => '2026-10-30 10:00:00',
            'ended_at'   => '2026-10-30 11:00:00',
        ];

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseInput($input, self::USER_ID),
            'goal_id'
        );
    }

    #[Test]
    public function lehntFremdesOderNichtExistierendesLernzielAb(): void
    {
        $input = [
            'goal_id'    => 999, // existiert nicht für Nutzer 42
            'started_at' => '2026-10-30 10:00:00',
            'ended_at'   => '2026-10-30 11:00:00',
        ];

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseInput($input, self::USER_ID),
            'goal_id'
        );
    }

    // =========================================================================
    // 2. Validierung von Start- und Endzeiten
    // =========================================================================

    /**
     * @return array<string, array{mixed}>
     */
    public static function ungueltigeStartzeitProvider(): array
    {
        return [
            'fehlt (null)'     => [null],
            'leerer String'    => [''],
            'nur Leerzeichen'  => ['   '],
            'ungültiger Text'  => ['gestern vormittag'],
            'unsinniges Datum' => ['2026-99-99 25:99:00'],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeStartzeitProvider')]
    public function lehntFehlendeOderUngueltigeStartzeitAb(mixed $startedAt): void
    {
        $input = [
            'goal_id'    => self::VALID_GOAL_ID,
            'started_at' => $startedAt,
            'ended_at'   => '2026-10-30 11:00:00',
        ];

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseInput($input, self::USER_ID),
            'started_at'
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function ungueltigeEndzeitProvider(): array
    {
        return [
            'fehlt (null)'     => [null],
            'leerer String'    => [''],
            'nur Leerzeichen'  => ['   '],
            'ungültiger Text'  => ['später'],
            'unsinniges Datum' => ['2026-13-45 10:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeEndzeitProvider')]
    public function lehntUngueltigeEndzeitAb(mixed $endedAt): void
    {
        $input = [
            'goal_id'    => self::VALID_GOAL_ID,
            'started_at' => '2026-10-30 10:00:00',
            'ended_at'   => $endedAt,
        ];

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseInput($input, self::USER_ID),
            'ended_at'
        );
    }

    #[Test]
    public function lehntEndzeitVorStartzeitAb(): void
    {
        $input = [
            'goal_id'    => self::VALID_GOAL_ID,
            'started_at' => '2026-10-30 14:00:00',
            'ended_at'   => '2026-10-30 13:00:00', // 1 Stunde vor Start!
        ];

        $e = $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseInput($input, self::USER_ID),
            'ended_at'
        );

        self::assertStringContainsString('Endzeit', $e->getMessage());
        self::assertStringContainsString('Startzeit', $e->getMessage());
    }

    // =========================================================================
    // 3. Dauerberechnung
    // =========================================================================

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function dauerBerechnungProvider(): array
    {
        return [
            'gleiche Minute (0 Sekunden)'           => ['2026-10-30 10:00:00', '2026-10-30 10:00:00', 0],
            'exakt 30 Minuten (1800 Sekunden)'      => ['2026-10-30 10:00:00', '2026-10-30 10:30:00', 1800],
            '1 Stunde 15 Minuten (4500 Sekunden)'   => ['2026-10-30 08:45:00', '2026-10-30 10:00:00', 4500],
            'Tagesübergreifend (über Mitternacht)'   => ['2026-10-30 23:30:00', '2026-10-31 01:00:00', 5400],
            'Format mit T Trenner (HTML datetime)'  => ['2026-10-30T10:00', '2026-10-30T11:30', 5400],
        ];
    }

    #[Test]
    #[DataProvider('dauerBerechnungProvider')]
    public function berechnetDauerKorrekt(string $startedAt, string $endedAt, int $expectedSeconds): void
    {
        $duration = $this->controller->calculateDuration($startedAt, $endedAt);
        self::assertSame($expectedSeconds, $duration);
    }

    #[Test]
    public function lehntEndzeitVorStartzeitBeiDauerberechnungAb(): void
    {
        $this->erwartetValidierungsfehler(
            fn () => $this->controller->calculateDuration('2026-10-30 12:00:00', '2026-10-30 11:59:59'),
            'ended_at'
        );
    }

    // =========================================================================
    // 4. Validierung von Notiz und Zeitgrenzen
    // =========================================================================

    #[Test]
    public function lehntZuLangeNotizAb(): void
    {
        $input = [
            'goal_id'    => self::VALID_GOAL_ID,
            'started_at' => '2026-10-30 10:00:00',
            'ended_at'   => '2026-10-30 11:00:00',
            'note'       => str_repeat('a', 256), // Max ist 255
        ];

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseInput($input, self::USER_ID),
            'note'
        );
    }

    #[Test]
    public function akzeptiertGueltigeEingabe(): void
    {
        $input = [
            'goal_id'    => self::VALID_GOAL_ID,
            'started_at' => '2026-10-30 10:00:00',
            'ended_at'   => '2026-10-30 11:30:00',
            'note'       => 'Kapitel 1 Zusammenfassung',
        ];

        $parsed = $this->controller->validateAndParseInput($input, self::USER_ID);

        self::assertSame(self::VALID_GOAL_ID, $parsed['goal_id']);
        self::assertSame('2026-10-30', $parsed['entry_date']);
        self::assertSame('2026-10-30 10:00:00', $parsed['started_at']);
        self::assertSame('2026-10-30 11:30:00', $parsed['ended_at']);
        self::assertSame(5400, $parsed['tracked_seconds']);
        self::assertSame('Kapitel 1 Zusammenfassung', $parsed['note']);
    }

    #[Test]
    public function lehntNegativVerstricheneZeitAb(): void
    {
        $input = [
            'goal_id'         => self::VALID_GOAL_ID,
            'started_at'      => '2026-10-30 10:00:00',
            'ended_at'        => '2026-10-30 11:00:00',
            'tracked_seconds' => -10,
        ];

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseInput($input, self::USER_ID),
            'tracked_seconds'
        );
    }

    #[Test]
    public function lehntUebermaessigLangeDauerAb(): void
    {
        $input = [
            'goal_id'         => self::VALID_GOAL_ID,
            'started_at'      => '2026-10-30 10:00:00',
            'ended_at'        => '2026-10-30 11:00:00',
            'tracked_seconds' => 86401, // Mehr als 24 Stunden
        ];

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->validateAndParseInput($input, self::USER_ID),
            'tracked_seconds'
        );
    }

    // =========================================================================
    // 5. Stoppuhr-Zustandslogik (Start, Pause, Weiter, Stopp, Verwerfen)
    // =========================================================================

    #[Test]
    public function startetStoppuhrErfolgreich(): void
    {
        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn(null);

        $this->dao->expects(self::once())
            ->method('startStopwatch')
            ->with(
                self::USER_ID,
                self::VALID_GOAL_ID,
                '2026-10-30 10:00:00',
                'Klausurvorbereitung'
            )
            ->willReturn(123);

        $id = $this->controller->startStopwatch(
            self::USER_ID,
            [
                'goal_id' => self::VALID_GOAL_ID,
                'note'    => 'Klausurvorbereitung',
            ],
            '2026-10-30 10:00:00'
        );

        self::assertSame(123, $id);
    }

    #[Test]
    public function verhindertZweitenStoppuhrStartWennBereitsAktiv(): void
    {
        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn([
                'id'         => 99,
                'user_id'    => self::USER_ID,
                'started_at' => '2026-10-30 09:00:00',
                'resumed_at' => '2026-10-30 09:00:00',
                'ended_at'   => null,
            ]);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->startStopwatch(self::USER_ID, ['goal_id' => self::VALID_GOAL_ID]),
            'stopwatch'
        );
    }

    #[Test]
    public function pausiertLaufendeStoppuhrUndBerechnetVerstricheneSekunden(): void
    {
        $openEntry = [
            'id'              => 10,
            'user_id'         => self::USER_ID,
            'started_at'      => '2026-10-30 10:00:00',
            'resumed_at'      => '2026-10-30 10:00:00',
            'ended_at'        => null,
            'tracked_seconds' => 100,
        ];

        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn($openEntry);

        // Pause nach genau 600 Sekunden (10 Minuten): 10:00:00 bis 10:10:00
        $this->dao->expects(self::once())
            ->method('pauseStopwatch')
            ->with(10, self::USER_ID, 600)
            ->willReturn(true);

        $this->controller->pauseStopwatch(self::USER_ID, '2026-10-30 10:10:00');
    }

    #[Test]
    public function pausiertBereitsPausierteStoppuhrOhneAenderung(): void
    {
        $pausedEntry = [
            'id'              => 10,
            'user_id'         => self::USER_ID,
            'started_at'      => '2026-10-30 10:00:00',
            'resumed_at'      => null, // Bereits pausiert
            'ended_at'        => null,
            'tracked_seconds' => 300,
        ];

        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn($pausedEntry);

        $this->dao->expects(self::never())->method('pauseStopwatch');

        $result = $this->controller->pauseStopwatch(self::USER_ID, '2026-10-30 10:15:00');
        self::assertSame($pausedEntry, $result);
    }

    #[Test]
    public function setztPausierteStoppuhrFort(): void
    {
        $pausedEntry = [
            'id'              => 10,
            'user_id'         => self::USER_ID,
            'started_at'      => '2026-10-30 10:00:00',
            'resumed_at'      => null,
            'ended_at'        => null,
            'tracked_seconds' => 300,
        ];

        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn($pausedEntry);

        $this->dao->expects(self::once())
            ->method('resumeStopwatch')
            ->with(10, self::USER_ID, '2026-10-30 10:20:00')
            ->willReturn(true);

        $this->controller->resumeStopwatch(self::USER_ID, '2026-10-30 10:20:00');
    }

    #[Test]
    public function beendetUndSpeichertStoppuhrErfolgreich(): void
    {
        $runningEntry = [
            'id'              => 10,
            'user_id'         => self::USER_ID,
            'started_at'      => '2026-10-30 10:00:00',
            'resumed_at'      => '2026-10-30 10:10:00', // Weitergelaufen ab 10:10:00
            'ended_at'        => null,
            'tracked_seconds' => 600, // Bereits 10 Minuten aus erstem Intervall
        ];

        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn($runningEntry);

        // Stopp um 10:25:00 -> 15 Minuten (900s) zusätzlich, gespeichert wird die Gesamtzeit 1500s
        $this->dao->expects(self::once())
            ->method('stopStopwatch')
            ->with(
                10,
                self::USER_ID,
                '2026-10-30 10:25:00',
                1500,
                'Erfolgreich beendet'
            )
            ->willReturn(true);

        $this->controller->stopAndSaveStopwatch(
            self::USER_ID,
            ['note' => 'Erfolgreich beendet'],
            '2026-10-30 10:25:00'
        );
    }

    #[Test]
    public function verwirftOffeneStoppuhr(): void
    {
        $openEntry = [
            'id'         => 10,
            'user_id'    => self::USER_ID,
            'started_at' => '2026-10-30 10:00:00',
            'resumed_at' => '2026-10-30 10:00:00',
            'ended_at'   => null,
        ];

        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn($openEntry);

        $this->dao->expects(self::once())
            ->method('discardStopwatch')
            ->with(10, self::USER_ID)
            ->willReturn(true);

        $result = $this->controller->discardStopwatch(self::USER_ID);
        self::assertTrue($result);
    }

    #[Test]
    public function liefertAktuelleStoppuhrMitBerechneterLaufzeit(): void
    {
        $runningEntry = [
            'id'              => 10,
            'user_id'         => self::USER_ID,
            'started_at'      => '2026-10-30 10:00:00',
            'resumed_at'      => '2026-10-30 10:00:00',
            'ended_at'        => null,
            'tracked_seconds' => 120,
        ];

        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn($runningEntry);

        // Abfrage nach 60 Sekunden (10:01:00)
        $current = $this->controller->getCurrentStopwatch(self::USER_ID, '2026-10-30 10:01:00');

        self::assertNotNull($current);
        self::assertTrue($current['is_running']);
        self::assertFalse($current['is_paused']);
        // 120 + 60 = 180 Sekunden (3 Minuten)
        self::assertSame(180, $current['current_tracked_seconds']);
        self::assertSame('00:03:00', $current['formatted_time']);
    }

    // =========================================================================
    // 6. Formatierungshelfer
    // =========================================================================

    /**
     * @return array<string, array{int, string}>
     */
    public static function formatSekundenProvider(): array
    {
        return [
            '0 Sekunden'         => [0, '00:00:00'],
            '45 Sekunden'        => [45, '00:00:45'],
            '1 Minute 5 Sekunden'=> [65, '00:01:05'],
            '1 Stunde exakt'     => [3600, '01:00:00'],
            '1 Std 1 Min 5 Sek'  => [3665, '01:01:05'],
            '10 Stunden'         => [36000, '10:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('formatSekundenProvider')]
    public function formatiertSekundenInUhrzeitString(int $seconds, string $expected): void
    {
        self::assertSame($expected, TimeTrackingController::formatSecondsToTime($seconds));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function formatHumanProvider(): array
    {
        return [
            '0 Sekunden'        => [0, '0 Sek.'],
            '45 Sekunden'       => [45, '45 Sek.'],
            '2 Minuten'         => [120, '2 Min.'],
            '1 Stunde glatt'    => [3600, '1 Std.'],
            '1 Std. 30 Min.'    => [5400, '1 Std. 30 Min.'],
        ];
    }

    #[Test]
    #[DataProvider('formatHumanProvider')]
    public function formatiertSekundenInLesbareForm(int $seconds, string $expected): void
    {
        self::assertSame($expected, TimeTrackingController::formatSecondsHuman($seconds));
    }

    // =========================================================================
    // 7. Auto-Completion / Verknüpfung mit Tages- und Wochenzielen
    // =========================================================================

    #[Test]
    public function markiertZielAlsErledigtWennSollzeitErreicht(): void
    {
        $runningEntry = [
            'id'              => 15,
            'user_id'         => self::USER_ID,
            'monthly_goal_id' => self::VALID_GOAL_ID,
            'entry_date'      => '2026-10-30',
            'started_at'      => '2026-10-30 14:00:00',
            'resumed_at'      => '2026-10-30 14:30:00',
            'ended_at'        => null,
            'tracked_seconds' => 1800, // Bereits 30 Min. gestoppt
        ];

        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn($runningEntry);

        $this->dao->expects(self::once())
            ->method('stopStopwatch')
            ->with(15, self::USER_ID, '2026-10-30 15:00:00', 3600, null)
            ->willReturn(true);

        // Aufsummierte gestoppte Zeit am Tag: 3600 Sekunden (1 Stunde)
        $this->dao->method('getTrackedSecondsForGoalAndDate')
            ->with(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30')
            ->willReturn(3600);

        // Geplantes Tagesziel (Slot) über 1 Stunde (3600s), noch offen (is_completed = 0)
        $this->dao->method('findLearningSlotsByGoalAndDate')
            ->with(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30')
            ->willReturn([
                [
                    'id'              => 99,
                    'user_id'         => self::USER_ID,
                    'monthly_goal_id' => self::VALID_GOAL_ID,
                    'slot_date'       => '2026-10-30',
                    'start_time'      => '14:00:00',
                    'end_time'        => '15:00:00',
                    'planned_seconds' => 3600,
                    'duration_hours'  => 1.0,
                    'is_completed'    => 0,
                ],
            ]);

        // Erwartung: Tagesziel (Slot 99) wird automatisch als erledigt markiert
        $this->dao->expects(self::once())
            ->method('markLearningSlotCompleted')
            ->with(99, self::USER_ID, true)
            ->willReturn(true);

        $result = $this->controller->stopAndSaveStopwatch(
            self::USER_ID,
            [],
            '2026-10-30 15:00:00'
        );

        self::assertTrue($result['goal_completed']);
    }

    #[Test]
    public function markiertZielNichtAlsErledigtWennSollzeitNochNichtErreicht(): void
    {
        $runningEntry = [
            'id'              => 16,
            'user_id'         => self::USER_ID,
            'monthly_goal_id' => self::VALID_GOAL_ID,
            'entry_date'      => '2026-10-30',
            'started_at'      => '2026-10-30 14:00:00',
            'resumed_at'      => '2026-10-30 14:00:00',
            'ended_at'        => null,
            'tracked_seconds' => 0,
        ];

        $this->dao->method('findOpenStopwatch')
            ->with(self::USER_ID)
            ->willReturn($runningEntry);

        $this->dao->expects(self::once())
            ->method('stopStopwatch')
            ->willReturn(true);

        // Erst 1800s (30 Min.) von geplanten 3600s (60 Min.) erreicht
        $this->dao->method('getTrackedSecondsForGoalAndDate')
            ->with(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30')
            ->willReturn(1800);

        $this->dao->method('findLearningSlotsByGoalAndDate')
            ->with(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30')
            ->willReturn([
                [
                    'id'              => 99,
                    'user_id'         => self::USER_ID,
                    'monthly_goal_id' => self::VALID_GOAL_ID,
                    'slot_date'       => '2026-10-30',
                    'planned_seconds' => 3600,
                    'is_completed'    => 0,
                ],
            ]);

        $this->dao->expects(self::never())
            ->method('markLearningSlotCompleted');

        $result = $this->controller->stopAndSaveStopwatch(
            self::USER_ID,
            [],
            '2026-10-30 14:30:00'
        );

        self::assertFalse($result['goal_completed']);
    }

    #[Test]
    public function markiertMonatszielAlsErledigtWennAufsummierteZeitSollzeitUeberschreitet(): void
    {
        $this->dao->method('getTrackedSecondsForGoalAndDate')
            ->with(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30')
            ->willReturn(7500);

        $this->dao->method('findLearningSlotsByGoalAndDate')
            ->willReturn([]);

        $this->dao->method('findGoalByIdAndUserId')
            ->with(self::VALID_GOAL_ID, self::USER_ID)
            ->willReturn([
                'id'            => self::VALID_GOAL_ID,
                'user_id'       => self::USER_ID,
                'planned_hours' => 2.0, // 7200 Sekunden Soll-Zeit
                'completed_at'  => null,
            ]);

        $this->dao->method('getTotalTrackedSecondsByUserId')
            ->with(self::USER_ID, self::VALID_GOAL_ID)
            ->willReturn(7500); // 7500s >= 7200s

        $this->dao->expects(self::once())
            ->method('markMonthlyGoalCompleted')
            ->with(self::VALID_GOAL_ID, self::USER_ID, '2026-10-30')
            ->willReturn(true);

        $completed = $this->controller->evaluateAndCompleteGoals(
            self::USER_ID,
            self::VALID_GOAL_ID,
            '2026-10-30'
        );

        self::assertTrue($completed);
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function sollIstVergleichProvider(): array
    {
        return [
            'Unter Soll-Zeit (1 Sekunde fehlt)' => [3599, 3600, false],
            'Exakt Soll-Zeit erreicht'          => [3600, 3600, true],
            'Soll-Zeit überschritten'           => [4200, 3600, true],
            'Keine Soll-Zeit (0s)'              => [100, 0, false],
        ];
    }

    #[Test]
    #[DataProvider('sollIstVergleichProvider')]
    public function bewertetErreichenDerSollzeitKorrekt(int $trackedSeconds, int $targetSeconds, bool $expected): void
    {
        self::assertSame($expected, TimeTrackingController::hasReachedTargetTime($trackedSeconds, $targetSeconds));
    }

    /**
     * Geplantes Tagesziel für das Standard-Lernziel am 30.10.2026.
     *
     * @return array<string, mixed>
     */
    private static function tagesziel(int $id, int $plannedSeconds, int $isCompleted = 0): array
    {
        return [
            'id'              => $id,
            'user_id'         => self::USER_ID,
            'monthly_goal_id' => self::VALID_GOAL_ID,
            'slot_date'       => '2026-10-30',
            'planned_seconds' => $plannedSeconds,
            'is_completed'    => $isCompleted,
        ];
    }

    /**
     * Mock-Daten: Am 30.10.2026 sind $trackedSeconds gestoppt und die übergebenen Tagesziele geplant.
     *
     * @param list<array<string, mixed>> $slots
     */
    private function daoLiefertTagesdaten(int $trackedSeconds, array $slots): void
    {
        $this->dao->method('getTrackedSecondsForGoalAndDate')->willReturn($trackedSeconds);
        $this->dao->method('findLearningSlotsByGoalAndDate')->willReturn($slots);
    }

    #[Test]
    public function verteiltTageszeitNacheinanderAufMehrereTagesziele(): void
    {
        // 1,5 h reichen nur für das erste von zwei Tageszielen zu je 1 h
        $this->daoLiefertTagesdaten(5400, [self::tagesziel(1, 3600), self::tagesziel(2, 3600)]);

        $this->dao->expects(self::once())
            ->method('markLearningSlotCompleted')
            ->with(1, self::USER_ID, true)
            ->willReturn(true);

        self::assertTrue($this->controller->evaluateAndCompleteGoals(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30'));
    }

    #[Test]
    public function erledigtMehrereTageszieleWennZeitFuerAlleReicht(): void
    {
        $this->daoLiefertTagesdaten(7200, [self::tagesziel(1, 3600), self::tagesziel(2, 3600)]);

        $this->dao->expects(self::exactly(2))
            ->method('markLearningSlotCompleted')
            ->willReturn(true);

        self::assertTrue($this->controller->evaluateAndCompleteGoals(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30'));
    }

    #[Test]
    public function schreibtBereitsErledigtesTageszielNichtErneut(): void
    {
        $this->daoLiefertTagesdaten(3600, [self::tagesziel(1, 3600, 1)]);

        $this->dao->expects(self::never())->method('markLearningSlotCompleted');

        self::assertTrue($this->controller->evaluateAndCompleteGoals(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30'));
    }

    #[Test]
    public function bereitsErledigtesTageszielVerbrauchtSeineZeit(): void
    {
        // Slot 1 ist schon erledigt und belegt die volle Stunde, für Slot 2 bleibt nichts übrig
        $this->daoLiefertTagesdaten(3600, [self::tagesziel(1, 3600, 1), self::tagesziel(2, 3600)]);

        $this->dao->expects(self::never())->method('markLearningSlotCompleted');

        $this->controller->evaluateAndCompleteGoals(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30');
    }

    #[Test]
    public function ignoriertTageszielOhneSollzeit(): void
    {
        $this->daoLiefertTagesdaten(3600, [self::tagesziel(1, 0)]);

        $this->dao->expects(self::never())->method('markLearningSlotCompleted');

        self::assertFalse($this->controller->evaluateAndCompleteGoals(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30'));
    }

    #[Test]
    public function nutztAktuelleEinheitWennTagessummeNiedrigerIst(): void
    {
        $this->daoLiefertTagesdaten(0, [self::tagesziel(1, 3600)]);

        $this->dao->expects(self::once())
            ->method('markLearningSlotCompleted')
            ->with(1, self::USER_ID, true)
            ->willReturn(true);

        self::assertTrue($this->controller->evaluateAndCompleteGoals(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30', 3600));
    }

    #[Test]
    public function liefertFalseOhneTageszielUndOhneMonatsziel(): void
    {
        $this->daoLiefertTagesdaten(3600, []);
        $this->dao->method('findGoalByIdAndUserId')->willReturn(null);

        $this->dao->expects(self::never())->method('markMonthlyGoalCompleted');

        self::assertFalse($this->controller->evaluateAndCompleteGoals(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30'));
    }

    #[Test]
    public function markiertMonatszielNichtWennSollzeitNochNichtErreicht(): void
    {
        $this->daoLiefertTagesdaten(3600, []);
        $this->dao->method('findGoalByIdAndUserId')->willReturn(['planned_hours' => 2.0, 'completed_at' => null]);
        $this->dao->method('getTotalTrackedSecondsByUserId')->willReturn(7199);

        $this->dao->expects(self::never())->method('markMonthlyGoalCompleted');

        self::assertFalse($this->controller->evaluateAndCompleteGoals(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function bereitsErledigteMonatszieleProvider(): array
    {
        return [
            'completed_at gesetzt' => [['planned_hours' => 1.0, 'completed_at' => '2026-10-29']],
            'is_completed gesetzt' => [['planned_hours' => 1.0, 'completed_at' => null, 'is_completed' => 1]],
        ];
    }

    #[Test]
    #[DataProvider('bereitsErledigteMonatszieleProvider')]
    public function schreibtBereitsErledigtesMonatszielNichtErneut(array $goal): void
    {
        $this->daoLiefertTagesdaten(3600, []);
        $this->dao->method('findGoalByIdAndUserId')->willReturn($goal);
        $this->dao->method('getTotalTrackedSecondsByUserId')->willReturn(3600);

        $this->dao->expects(self::never())->method('markMonthlyGoalCompleted');

        self::assertTrue($this->controller->evaluateAndCompleteGoals(self::USER_ID, self::VALID_GOAL_ID, '2026-10-30'));
    }

    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function sollzeitQuellenProvider(): array
    {
        return [
            'planned_seconds'                  => [['planned_seconds' => 1800], 1800],
            'planned_seconds als Text'         => [['planned_seconds' => '1800'], 1800],
            'planned_seconds hat Vorrang'      => [['planned_seconds' => 1800, 'duration_hours' => 2.0], 1800],
            'negative Sekunden werden zu 0'    => [['planned_seconds' => -5], 0],
            'target_seconds'                   => [['target_seconds' => 900], 900],
            'duration_hours'                   => [['duration_hours' => 1.5], 5400],
            'planned_hours als Text'           => [['planned_hours' => '2.00'], 7200],
            'target_hours'                     => [['target_hours' => 0.5], 1800],
            'Zeitspanne aus Start und Ende'    => [['start_time' => '14:00:00', 'end_time' => '15:30:00'], 5400],
            'Ende vor Start'                   => [['start_time' => '15:00:00', 'end_time' => '14:00:00'], 0],
            'nicht numerischer Wert'           => [['planned_seconds' => 'abc'], 0],
            'keine Angabe'                     => [[], 0],
        ];
    }

    #[Test]
    #[DataProvider('sollzeitQuellenProvider')]
    public function ermitteltSollzeitAusDatensatz(array $goalOrSlot, int $expected): void
    {
        self::assertSame($expected, TimeTrackingController::resolvePlannedSeconds($goalOrSlot));
    }

    // =========================================================================
    // 8. Randfälle der Stoppuhr
    // =========================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function aktionenOhneOffeneStoppuhrProvider(): array
    {
        return [
            'Pausieren'  => ['pauseStopwatch'],
            'Fortsetzen' => ['resumeStopwatch'],
            'Stoppen'    => ['stopAndSaveStopwatch'],
            'Verwerfen'  => ['discardStopwatch'],
        ];
    }

    #[Test]
    #[DataProvider('aktionenOhneOffeneStoppuhrProvider')]
    public function lehntAktionOhneOffeneStoppuhrAb(string $method): void
    {
        $this->dao->method('findOpenStopwatch')->willReturn(null);

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->$method(self::USER_ID),
            'stopwatch'
        );
    }

    #[Test]
    public function setztLaufendeStoppuhrNichtErneutFort(): void
    {
        $runningEntry = ['id' => 10, 'resumed_at' => '2026-10-30 10:00:00', 'tracked_seconds' => 0];
        $this->dao->method('findOpenStopwatch')->willReturn($runningEntry);

        $this->dao->expects(self::never())->method('resumeStopwatch');

        self::assertSame($runningEntry, $this->controller->resumeStopwatch(self::USER_ID, '2026-10-30 10:05:00'));
    }

    #[Test]
    public function addiertBeimStoppenEinerPausiertenStoppuhrKeineZeit(): void
    {
        $this->dao->method('findOpenStopwatch')->willReturn([
            'id'              => 10,
            'monthly_goal_id' => self::VALID_GOAL_ID,
            'entry_date'      => '2026-10-30',
            'resumed_at'      => null,
            'tracked_seconds' => 600,
        ]);

        $this->dao->expects(self::once())
            ->method('stopStopwatch')
            ->with(10, self::USER_ID, '2026-10-30 12:00:00', 600, null)
            ->willReturn(true);

        $result = $this->controller->stopAndSaveStopwatch(self::USER_ID, [], '2026-10-30 12:00:00');

        self::assertSame(600, $result['tracked_seconds']);
    }

    #[Test]
    public function akzeptiertStoppuhrMitGenau24Stunden(): void
    {
        $this->dao->method('findOpenStopwatch')->willReturn([
            'id'              => 10,
            'resumed_at'      => null,
            'tracked_seconds' => TimeTrackingController::MAX_DURATION_SECONDS,
        ]);

        $this->dao->expects(self::once())->method('stopStopwatch')->willReturn(true);

        $this->controller->stopAndSaveStopwatch(self::USER_ID, [], '2026-10-30 12:00:00');
    }

    #[Test]
    public function kapptVergesseneStoppuhrUeber24StundenStattAbzulehnen(): void
    {
        // Issue #50: Läuft die Stoppuhr länger als 24 h, wird gespeichert statt in einer Sackgasse zu enden
        $this->dao->method('findOpenStopwatch')->willReturn([
            'id'              => 10,
            'resumed_at'      => '2026-10-29 10:00:00',
            'tracked_seconds' => 0,
        ]);

        $this->dao->expects(self::once())
            ->method('stopStopwatch')
            ->with(10, self::USER_ID, '2026-10-30 12:00:00', TimeTrackingController::MAX_DURATION_SECONDS, null)
            ->willReturn(true);

        $result = $this->controller->stopAndSaveStopwatch(self::USER_ID, [], '2026-10-30 12:00:00');

        self::assertSame(TimeTrackingController::MAX_DURATION_SECONDS, $result['tracked_seconds']);
    }

    #[Test]
    public function speichertKorrigierteDauerBeiVergessenerStoppuhr(): void
    {
        $this->dao->method('findOpenStopwatch')->willReturn([
            'id'              => 10,
            'resumed_at'      => '2026-10-29 10:00:00',
            'tracked_seconds' => 0,
        ]);

        $this->dao->expects(self::once())
            ->method('stopStopwatch')
            ->with(10, self::USER_ID, '2026-10-30 12:00:00', 2 * 3600 + 15 * 60, null)
            ->willReturn(true);

        $this->controller->stopAndSaveStopwatch(
            self::USER_ID,
            ['corrected_hours' => '2', 'corrected_minutes' => '15'],
            '2026-10-30 12:00:00'
        );
    }

    #[Test]
    public function behaeltAngebrocheneMinuteBeiUnveraendertemVorschlag(): void
    {
        // Gemessen 6:30:42 h, Vorschlag im Dialog 6 Std. 30 Min. -> die 42 Sekunden gehen nicht verloren
        self::assertSame(
            23442,
            $this->controller->resolveStoppedSeconds(23442, ['corrected_hours' => '6', 'corrected_minutes' => '30'])
        );
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function ungueltigeDauerkorrekturen(): array
    {
        return [
            'Mehr als gemessen'    => [['corrected_hours' => '7', 'corrected_minutes' => '0'], 'corrected_hours'],
            'Null Minuten'         => [['corrected_hours' => '0', 'corrected_minutes' => '0'], 'corrected_minutes'],
            'Beide Felder leer'    => [['corrected_hours' => '', 'corrected_minutes' => ''], 'corrected_minutes'],
            'Minuten über 59'      => [['corrected_hours' => '1', 'corrected_minutes' => '60'], 'corrected_minutes'],
            'Stunden über 24'      => [['corrected_hours' => '25', 'corrected_minutes' => '0'], 'corrected_hours'],
            'Negative Stunden'     => [['corrected_hours' => '-1', 'corrected_minutes' => '0'], 'corrected_hours'],
            'Keine ganze Zahl'     => [['corrected_hours' => '1.5', 'corrected_minutes' => ''], 'corrected_hours'],
        ];
    }

    /**
     * @param array<string, string> $input
     */
    #[Test]
    #[DataProvider('ungueltigeDauerkorrekturen')]
    public function lehntUngueltigeDauerkorrekturAbOhneZuSpeichern(array $input, string $expectedField): void
    {
        // Gemessen: 6:30 h
        $this->dao->method('findOpenStopwatch')->willReturn([
            'id'              => 10,
            'resumed_at'      => null,
            'tracked_seconds' => 23400,
        ]);

        $this->dao->expects(self::never())->method('stopStopwatch');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->stopAndSaveStopwatch(self::USER_ID, $input, '2026-10-30 12:00:00'),
            $expectedField
        );
    }

    #[Test]
    public function lehntZuLangeNotizBeimStoppenAbOhneZuSpeichern(): void
    {
        $this->dao->method('findOpenStopwatch')->willReturn([
            'id'              => 10,
            'resumed_at'      => null,
            'tracked_seconds' => 60,
        ]);

        $this->dao->expects(self::never())->method('stopStopwatch');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->stopAndSaveStopwatch(
                self::USER_ID,
                ['note' => str_repeat('a', TimeTrackingController::MAX_NOTE_LENGTH + 1)]
            ),
            'note'
        );
    }

    #[Test]
    public function wertetZieleBeimStoppenNurMitLernzielAus(): void
    {
        $this->dao->method('findOpenStopwatch')->willReturn([
            'id'              => 10,
            'resumed_at'      => null,
            'tracked_seconds' => 60,
        ]);
        $this->dao->method('stopStopwatch')->willReturn(true);

        $this->dao->expects(self::never())->method('getTrackedSecondsForGoalAndDate');

        $result = $this->controller->stopAndSaveStopwatch(self::USER_ID, [], '2026-10-30 12:00:00');

        self::assertFalse($result['goal_completed']);
    }

    #[Test]
    public function leitetDatumDerZielauswertungAusStartzeitAb(): void
    {
        $this->dao->method('findOpenStopwatch')->willReturn([
            'id'              => 10,
            'monthly_goal_id' => self::VALID_GOAL_ID,
            'started_at'      => '2026-10-29 23:30:00',
            'resumed_at'      => null,
            'tracked_seconds' => 60,
        ]);
        $this->dao->method('stopStopwatch')->willReturn(true);

        $this->dao->expects(self::once())
            ->method('getTrackedSecondsForGoalAndDate')
            ->with(self::USER_ID, self::VALID_GOAL_ID, '2026-10-29')
            ->willReturn(60);

        $this->controller->stopAndSaveStopwatch(self::USER_ID, [], '2026-10-30 00:30:00');
    }

    #[Test]
    public function liefertNullWennKeineStoppuhrOffenIst(): void
    {
        $this->dao->method('findOpenStopwatch')->willReturn(null);

        self::assertNull($this->controller->getCurrentStopwatch(self::USER_ID));
    }

    #[Test]
    public function liefertPausierteStoppuhrOhneWeitereLaufzeit(): void
    {
        $this->dao->method('findOpenStopwatch')->willReturn([
            'id'              => 10,
            'resumed_at'      => null,
            'tracked_seconds' => 120,
        ]);

        $current = $this->controller->getCurrentStopwatch(self::USER_ID, '2026-10-30 12:00:00');

        self::assertNotNull($current);
        self::assertFalse($current['is_running']);
        self::assertTrue($current['is_paused']);
        self::assertSame(120, $current['current_tracked_seconds']);
    }

    // =========================================================================
    // 9. Zeiteintrag löschen
    // =========================================================================

    #[Test]
    public function loeschtEigenenZeiteintrag(): void
    {
        $this->dao->method('findByIdAndUserId')->with(5, self::USER_ID)->willReturn(['id' => 5]);

        $this->dao->expects(self::once())
            ->method('delete')
            ->with(5, self::USER_ID)
            ->willReturn(true);

        self::assertTrue($this->controller->deleteTimeEntry(5, self::USER_ID));
    }

    #[Test]
    public function lehntLoeschenFremdenZeiteintragsAb(): void
    {
        $this->dao->method('findByIdAndUserId')->willReturn(null);

        $this->dao->expects(self::never())->method('delete');

        $this->erwartetValidierungsfehler(
            fn () => $this->controller->deleteTimeEntry(5, self::USER_ID),
            'id'
        );
    }

    #[Test]
    public function stoppuhrZeigtNurZwischenzieleDesAktuellenMonats(): void
    {
        $picker = TimeTrackingController::groupGoalsForPicker([
            ['id' => 1, 'title' => 'Modul A', 'plan_month' => '2026-11', 'parent_goal_title' => 'WiSe 26/27'],
            ['id' => 2, 'title' => 'Modul A', 'plan_month' => '2026-10', 'parent_goal_title' => 'WiSe 26/27'],
            ['id' => 3, 'title' => 'Modul B', 'plan_month' => '2026-11', 'parent_goal_title' => 'WiSe 26/27'],
        ], '2026-11');

        $this->assertTrue($picker['filtered']);
        $this->assertSame(['WiSe 26/27' => [
            ['id' => 1, 'title' => 'Modul A'],
            ['id' => 3, 'title' => 'Modul B'],
        ]], $picker['groups']);
    }

    #[Test]
    public function stoppuhrZeigtAlleZwischenzieleWennImMonatKeinesGeplantIst(): void
    {
        $picker = TimeTrackingController::groupGoalsForPicker([
            ['id' => 2, 'title' => 'Modul A', 'plan_month' => '2026-10', 'parent_goal_title' => 'WiSe 26/27'],
        ], '2026-11');

        $this->assertFalse($picker['filtered']);
        $this->assertSame(['WiSe 26/27 – Oktober 2026' => [['id' => 2, 'title' => 'Modul A']]], $picker['groups']);
    }
}
