<?php
// tests/Unit/Controllers/GoalControllerTest.php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Classes\ValidationException;
use Controllers\GoalController;
use DAO\GoalDAO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Beispieltest und Kopiervorlage für weitere Unit-Tests (siehe docs/unit-tests.md).
 * Der GoalDAO wird durch einen Mock ersetzt, es wird keine Datenbank benötigt.
 */
final class GoalControllerTest extends TestCase
{
    private GoalController $controller;

    protected function setUp(): void
    {
        // Vorbereiten: Controller mit einem Mock statt der echten Datenbankzugriffsschicht
        $this->controller = new GoalController($this->createMock(GoalDAO::class));
    }

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
            'Zahl statt Text'  => [202610],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigePlanungsmonate')]
    public function lehntUngueltigenPlanungsmonatMitFeldhinweisAb(mixed $yearMonth): void
    {
        try {
            $this->controller->validateYearMonth($yearMonth);
            self::fail('Es wurde keine ValidationException geworfen.');
        } catch (ValidationException $e) {
            // Prüfen: Das Formular kann das fehlerhafte Feld rot markieren
            self::assertSame('year_month', $e->getField());
            self::assertNotSame('', $e->getMessage());
        }
    }
}
