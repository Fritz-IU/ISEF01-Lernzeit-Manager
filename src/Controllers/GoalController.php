<?php
// src/Controllers/GoalController.php

declare(strict_types=1);

namespace Controllers;

use Classes\ValidationException;
use DAO\GoalDAO;
use DateTimeImmutable;

require_once __DIR__ . '/../Classes/ValidationException.php';
require_once __DIR__ . '/../DAO/GoalDAO.php';

/**
 * Controller für die 6-Monats-Lernzielplanung.
 * Validiert Benutzereingaben, setzt Geschäftsregeln durch und interagiert mit dem GoalDAO.
 */
class GoalController
{
    public const MAX_TITLE_LENGTH = 150;
    public const MAX_DESCRIPTION_LENGTH = 2000;
    public const MIN_TARGET_HOURS = 0.5;
    public const MAX_TARGET_HOURS = 1500.0;

    private GoalDAO $goalDAO;

    /**
     * Dependency Injection des GoalDAO.
     */
    public function __construct(GoalDAO $goalDAO)
    {
        $this->goalDAO = $goalDAO;
    }

    /**
     * Liefert alle Lernziele eines Nutzers.
     *
     * @param int $userId ID des Nutzers
     * @return list<array<string, mixed>>
     */
    public function listGoals(int $userId): array
    {
        return $this->goalDAO->findAllByUserId($userId);
    }

    /**
     * Lädt ein einzelnes Lernziel des Nutzers.
     *
     * @param int $goalId
     * @param int $userId
     * @return array<string, mixed>|null
     */
    public function getGoal(int $goalId, int $userId): ?array
    {
        return $this->goalDAO->findByIdAndUserId($goalId, $userId);
    }

    /**
     * Erstellt ein neues Lernziel nach erfolgreicher Validierung.
     *
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten (z. B. aus $_POST)
     * @return int ID des neu erstellten Lernziels
     * @throws ValidationException Bei ungültigen Eingaben
     */
    public function createGoal(int $userId, array $input): int
    {
        $validated = $this->validateAndParseInput($input);

        return $this->goalDAO->create(
            $userId,
            $validated['title'],
            $validated['description'],
            $validated['target_hours'],
            $validated['start_date'],
            $validated['end_date']
        );
    }

    /**
     * Aktualisiert ein bestehendes Lernziel nach erfolgreicher Validierung.
     *
     * @param int $goalId ID des Lernziels
     * @param int $userId ID des Nutzers
     * @param array<string, mixed> $input Rohdaten
     * @return bool True bei Erfolg
     * @throws ValidationException Bei ungültigen Eingaben oder unberechtigtem Zugriff
     */
    public function updateGoal(int $goalId, int $userId, array $input): bool
    {
        $existing = $this->goalDAO->findByIdAndUserId($goalId, $userId);
        if ($existing === null) {
            throw new ValidationException("Das angegebene Lernziel existiert nicht oder gehört einem anderen Benutzer.");
        }

        $validated = $this->validateAndParseInput($input);

        return $this->goalDAO->update(
            $goalId,
            $userId,
            $validated['title'],
            $validated['description'],
            $validated['target_hours'],
            $validated['start_date'],
            $validated['end_date']
        );
    }

    /**
     * Löscht ein Lernziel eines Nutzers.
     *
     * @param int $goalId ID des Lernziels
     * @param int $userId ID des Nutzers
     * @return bool True bei erfolgreichem Löschen
     * @throws ValidationException Wenn Lernziel nicht existiert
     */
    public function deleteGoal(int $goalId, int $userId): bool
    {
        $existing = $this->goalDAO->findByIdAndUserId($goalId, $userId);
        if ($existing === null) {
            throw new ValidationException("Das angegebene Lernziel existiert nicht oder gehört einem anderen Benutzer.");
        }

        return $this->goalDAO->delete($goalId, $userId);
    }

    /**
     * Summe aller geplanten Lernstunden eines Nutzers.
     */
    public function getTotalHours(int $userId): float
    {
        return $this->goalDAO->getTotalTargetHours($userId);
    }

    /**
     * Validiert und bereinigt die Eingabedaten für Lernziele.
     *
     * @param array<string, mixed> $input
     * @return array{title: string, description: ?string, target_hours: float, start_date: string, end_date: string}
     * @throws ValidationException
     */
    public function validateAndParseInput(array $input): array
    {
        // 1. Modul / Titel (Pflichtfeld)
        $titleRaw = $input['title'] ?? null;
        if (!is_string($titleRaw) || trim($titleRaw) === '') {
            throw new ValidationException("Bitte gib einen Modul- bzw. Lernzielnamen an.");
        }
        $title = trim($titleRaw);
        if (mb_strlen($title, 'UTF-8') > self::MAX_TITLE_LENGTH) {
            throw new ValidationException("Der Name darf höchstens " . self::MAX_TITLE_LENGTH . " Zeichen lang sein.");
        }

        // 2. Zielstunden (Pflichtfeld, positiv)
        $hoursRaw = $input['target_hours'] ?? null;
        if ($hoursRaw === null || (is_string($hoursRaw) && trim($hoursRaw) === '')) {
            throw new ValidationException("Bitte gib die geplanten Zielstunden an.");
        }

        // Kommas durch Punkte ersetzen (deutsches Eingabeformat unterstützen)
        if (is_string($hoursRaw)) {
            $hoursRaw = str_replace(',', '.', trim($hoursRaw));
        }

        if (!is_numeric($hoursRaw)) {
            throw new ValidationException("Die Zielstunden müssen eine Zahl sein.");
        }

        $targetHours = (float) $hoursRaw;
        if ($targetHours < self::MIN_TARGET_HOURS || $targetHours > self::MAX_TARGET_HOURS) {
            throw new ValidationException(sprintf(
                "Die Zielstunden müssen zwischen %.1f und %.1f Stunden liegen.",
                self::MIN_TARGET_HOURS,
                self::MAX_TARGET_HOURS
            ));
        }

        // 3. Startdatum (Pflichtfeld, Format Y-m-d)
        $startDateRaw = $input['start_date'] ?? null;
        if (!is_string($startDateRaw) || trim($startDateRaw) === '') {
            throw new ValidationException("Bitte gib ein Startdatum an.");
        }
        $startDate = trim($startDateRaw);
        $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if (!$startDt || $startDt->format('Y-m-d') !== $startDate) {
            throw new ValidationException("Das Startdatum muss im gültigen Format JJJJ-MM-TT vorliegen.");
        }

        // 4. Enddatum (Pflichtfeld, Format Y-m-d)
        $endDateRaw = $input['end_date'] ?? null;
        if (!is_string($endDateRaw) || trim($endDateRaw) === '') {
            throw new ValidationException("Bitte gib ein Enddatum an.");
        }
        $endDate = trim($endDateRaw);
        $endDt = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
        if (!$endDt || $endDt->format('Y-m-d') !== $endDate) {
            throw new ValidationException("Das Enddatum muss im gültigen Format JJJJ-MM-TT vorliegen.");
        }

        // Logische Prüfung: Enddatum darf nicht vor dem Startdatum liegen
        if ($endDt < $startDt) {
            throw new ValidationException("Das Enddatum darf zeitlich nicht vor dem Startdatum liegen.");
        }

        // 5. Beschreibung (optional)
        $description = null;
        if (isset($input['description']) && is_string($input['description'])) {
            $descTrimmed = trim($input['description']);
            if ($descTrimmed !== '') {
                if (mb_strlen($descTrimmed, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
                    throw new ValidationException("Die Beschreibung darf höchstens " . self::MAX_DESCRIPTION_LENGTH . " Zeichen lang sein.");
                }
                $description = $descTrimmed;
            }
        }

        return [
            'title'        => $title,
            'description'  => $description,
            'target_hours' => round($targetHours, 2),
            'start_date'   => $startDate,
            'end_date'     => $endDate,
        ];
    }
}

