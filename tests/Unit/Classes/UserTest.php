<?php
// tests/Unit/Classes/UserTest.php

declare(strict_types=1);

namespace Tests\Unit\Classes;

use Classes\User;
use Classes\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für die statischen Methoden der User-Klasse: Profil-Parser und Namensanzeige.
 * $_SESSION wird als normales Array gesetzt, es läuft keine echte PHP-Session.
 */
final class UserTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $sessionBackup = null;
    private bool $sessionWasSet = false;

    protected function setUp(): void
    {
        $this->sessionWasSet = isset($_SESSION);
        $this->sessionBackup = $_SESSION ?? null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->sessionWasSet) {
            $_SESSION = $this->sessionBackup;
        } else {
            unset($_SESSION);
        }
    }

    // ------------------------------------------------------------------
    // parseProfileInput
    // ------------------------------------------------------------------

    #[Test]
    public function normalisiertFormulareingabe(): void
    {
        $result = User::parseProfileInput([
            'display_name'  => '  Max Mustermann ',
            'study_program' => '   ',
            'semester'      => ' 3 ',
        ]);

        self::assertSame([
            'display_name'  => 'Max Mustermann',
            'study_program' => null,
            'semester'      => 3,
        ], $result);
    }

    #[Test]
    public function uebernimmtJsonEingabeMitSemesterAlsZahl(): void
    {
        $result = User::parseProfileInput([
            'display_name'  => 'Anna',
            'study_program' => ' Informatik ',
            'semester'      => 5,
        ]);

        self::assertSame('Informatik', $result['study_program']);
        self::assertSame(5, $result['semester']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function leereSemesterwerte(): array
    {
        return [
            'fehlt'       => [null],
            'leer'        => [''],
            'Leerzeichen' => ['   '],
        ];
    }

    #[Test]
    #[DataProvider('leereSemesterwerte')]
    public function behandeltLeeresSemesterAlsNichtGesetzt(mixed $semester): void
    {
        $result = User::parseProfileInput(['display_name' => 'Anna', 'semester' => $semester]);

        self::assertNull($result['semester']);
    }

    #[Test]
    public function liefertLeereWerteBeiFehlendenOderManipuliertenFeldern(): void
    {
        self::assertSame(
            ['display_name' => '', 'study_program' => null, 'semester' => null],
            User::parseProfileInput([])
        );

        // display_name[]=x und study_program[]=y aus einem manipulierten Formular
        $result = User::parseProfileInput(['display_name' => ['x'], 'study_program' => ['y']]);
        self::assertSame('', $result['display_name']);
        self::assertNull($result['study_program']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function ungueltigeSemesterwerte(): array
    {
        return [
            'Kommazahl als Text'      => ['3.0'],
            'Buchstaben'              => ['abc'],
            'Exponentialschreibweise' => ['1e2'],
            'Wahrheitswert'           => [true],
            'Kommazahl'               => [3.0],
            'Array'                   => [[3]],
        ];
    }

    #[Test]
    #[DataProvider('ungueltigeSemesterwerte')]
    public function lehntSemesterAbDasKeineGanzeZahlIst(mixed $semester): void
    {
        try {
            User::parseProfileInput(['semester' => $semester]);
            self::fail('Es wurde keine ValidationException geworfen.');
        } catch (ValidationException $e) {
            // Die Meldung nennt den gültigen Bereich
            self::assertStringContainsString((string) User::MIN_SEMESTER, $e->getMessage());
            self::assertStringContainsString((string) User::MAX_SEMESTER, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // isLoggedIn, currentNavName, currentShortNavName
    // ------------------------------------------------------------------

    #[Test]
    public function erkenntAngemeldetenNutzerAnDerSession(): void
    {
        self::assertFalse(User::isLoggedIn());

        $_SESSION['user_id'] = 42;
        self::assertTrue(User::isLoggedIn());
    }

    #[Test]
    public function zeigtAnzeigenamenSonstEmailInDerNavigation(): void
    {
        self::assertSame('', User::currentNavName());

        $_SESSION['user_email'] = 'testuser@iu-demo.de';
        self::assertSame('testuser@iu-demo.de', User::currentNavName());

        $_SESSION['user_display_name'] = 'Max Mustermann';
        self::assertSame('Max Mustermann', User::currentNavName());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function anzeigenamenMitKurzform(): array
    {
        return [
            'Vor- und Nachname'        => ['Max Mustermann', 'Max'],
            'Doppelname'               => ['Anna-Lena Müller', 'Anna'],
            'Leerzeichen außen'        => ['  Lisa  ', 'Lisa'],
            'führender Bindestrich'    => ['-Max', 'Max'],
            'Bindestrich im Nachnamen' => ['Jean Paul-Sartre', 'Jean'],
            'nur ein Wort'             => ['Max', 'Max'],
            'nur Bindestriche'         => ['---', '---'],
            'leer → E-Mail'            => ['', 'testuser@iu-demo.de'],
            'Leerzeichen → E-Mail'     => ['   ', 'testuser@iu-demo.de'],
        ];
    }

    #[Test]
    #[DataProvider('anzeigenamenMitKurzform')]
    public function kuerztAnzeigenamenFuerDenNavigationsButton(string $displayName, string $expected): void
    {
        $_SESSION['user_email'] = 'testuser@iu-demo.de';
        $_SESSION['user_display_name'] = $displayName;

        self::assertSame($expected, User::currentShortNavName());
    }

    #[Test]
    public function zeigtEmailWennKeinAnzeigenameInDerSessionLiegt(): void
    {
        $_SESSION['user_email'] = 'testuser@iu-demo.de';

        self::assertSame('testuser@iu-demo.de', User::currentShortNavName());
    }
}
