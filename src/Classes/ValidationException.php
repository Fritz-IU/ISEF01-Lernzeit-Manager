<?php
// src/Classes/ValidationException.php

declare(strict_types=1);

namespace Classes;

use Exception;
use Throwable;

/**
 * Fehler, deren Meldung für Nutzer bestimmt ist (z. B. ungültige Eingaben).
 * Alle anderen Exceptions gelten als technisch und werden nicht angezeigt.
 */
class ValidationException extends Exception
{
    private ?string $field;

    public function __construct(
        string $message = '',
        ?string $field = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->field = $field;
    }

    /**
     * Gibt optional den Namen des fehlerhaften Formularfelds zurück (z. B. 'title', 'target_hours', 'end_date').
     */
    public function getField(): ?string
    {
        return $this->field;
    }
}
