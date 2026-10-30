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

        // Stopp um 10:25:00 -> 15 Minuten (900s) zusätzlich
        $this->dao->expects(self::once())
            ->method('stopStopwatch')
            ->with(
                10,
                self::USER_ID,
                '2026-10-30 10:25:00',
                900,
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
}

