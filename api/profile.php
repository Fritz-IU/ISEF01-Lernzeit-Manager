<?php
// api/profile.php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Classes/User.php';

use Classes\User;
use Classes\ValidationException;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Authentifizierung prüfen
if (!User::isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Nicht angemeldet. Bitte melde dich an.',
    ]);
    exit;
}

// 2. Nur POST-Anfragen zulassen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Nur POST-Anfragen sind erlaubt.',
    ]);
    exit;
}

try {
    // 3. Eingabedaten aus JSON-Body oder POST-Feldern auslesen
    $rawInput = (string) file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $userId = (int) $_SESSION['user_id'];
    $displayName = trim((string) ($input['display_name'] ?? ''));
    $studyProgram = trim((string) ($input['study_program'] ?? ''));
    $semesterRaw = $input['semester'] ?? null;

    $studyProgram = $studyProgram === '' ? null : $studyProgram;
    $semester = ($semesterRaw === '' || $semesterRaw === null) ? null : (int) $semesterRaw;

    $db = getDatabaseConnection();
    $userModel = new User($db);

    // 4. Profil über User-Klasse aktualisieren (validiert Eingaben und speichert per PDO)
    $userModel->updateProfile($userId, $displayName, $studyProgram, $semester);

    echo json_encode([
        'success' => true,
        'message' => 'Profildaten erfolgreich gespeichert.',
        'data' => [
            'display_name'  => $displayName,
            'study_program' => $studyProgram,
            'semester'      => $semester,
        ],
    ]);
} catch (ValidationException $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Es ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.',
    ]);
}

