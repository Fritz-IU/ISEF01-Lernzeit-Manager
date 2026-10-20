<?php
// src/Classes/ValidationException.php

declare(strict_types=1);

namespace Classes;

use Exception;

/**
 * Fehler, deren Meldung für Nutzer bestimmt ist (z. B. ungültige Eingaben).
 * Alle anderen Exceptions gelten als technisch und werden nicht angezeigt.
 */
class ValidationException extends Exception
{
}
