<?php
// api/time-entries.php
// JSON-Endpunkt für die manuelle Zeitnachbuchung (Issue #9)

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Classes/User.php';
require_once __DIR__ . '/../src/DAO/TimeEntryDAO.php';
require_once __DIR__ . '/../src/Controllers/TimeEntryController.php';

use Classes\User;
use Classes\ValidationException;
use Controllers\TimeEntryController;
use DAO\TimeEntryDAO;

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

    // 4. CSRF-Schutz
    $sessionCsrf = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    $submittedCsrf = is_string($input['csrf_token'] ?? null) ? $input['csrf_token'] : '';
    if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Ungültige Sitzungsanfrage. Bitte lade die Seite neu und versuche es erneut.',
        ]);
        exit;
    }

    $userId = (int) $_SESSION['user_id'];
    $action = is_string($input['action'] ?? null) ? $input['action'] : '';

    $db = getDatabaseConnection();
    $timeEntryDAO = new TimeEntryDAO($db);
    $timeEntryController = new TimeEntryController($timeEntryDAO);

    $response = null;

    if ($action === 'create_manual_entry' || $action === 'create') {
        $entry = $timeEntryController->createManualEntry($userId, $input);
        $slotSuffix = !empty($entry['slot_completed'])
            ? ' und die geplante Lerneinheit als erledigt markiert.'
            : '.';
        $response = [
            'message'  => 'Die Lernzeit (' . $entry['formatted_duration'] . ') für „' . $entry['monthly_goal_title'] . '“ wurde erfolgreich nachgebucht' . $slotSuffix,
            'entry_id' => $entry['entry_id'],
            'entry'    => $entry,
        ];
    } elseif ($action === 'update_manual_entry') {
        $entryId = isset($input['entry_id']) && is_scalar($input['entry_id']) ? (int) $input['entry_id'] : 0;
        $entry = $timeEntryController->updateManualEntry($entryId, $userId, $input);
        $response = [
            'message' => 'Die nachgebuchte Lernzeit wurde erfolgreich aktualisiert.',
            'entry_id' => $entry['entry_id'],
            'entry' => $entry,
        ];
    } elseif ($action === 'delete_entry') {
        $entryId = isset($input['entry_id']) && is_scalar($input['entry_id']) ? (int) $input['entry_id'] : 0;
        $timeEntryController->deleteEntry($entryId, $userId);
        $response = [
            'message' => 'Der Zeiteintrag wurde erfolgreich gelöscht.',
        ];
    }

    if ($response === null) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Ungültige Aktion.',
        ]);
        exit;
    }

    // Erfolgsmeldung für den Seiten-Reload hinterlegen
    $_SESSION['flash_success'] = $response['message'];

    echo json_encode(['success' => true] + $response);
} catch (ValidationException $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'field'   => $e->getField(),
    ]);
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Es ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.',
    ]);
}

