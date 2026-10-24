<?php
// api/goals.php
// JSON-Endpunkt für das Anlegen, Bearbeiten und Löschen von Lernzielen (6-Monats-Grobplanung)

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Classes/User.php';
require_once __DIR__ . '/../src/DAO/GoalDAO.php';
require_once __DIR__ . '/../src/Controllers/GoalController.php';

use Classes\User;
use Classes\ValidationException;
use Controllers\GoalController;
use DAO\GoalDAO;

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
    $action = is_string($input['action'] ?? null) ? $input['action'] : '';

    $db = getDatabaseConnection();
    $goalDAO = new GoalDAO($db);
    $goalController = new GoalController($goalDAO);

    if ($action === 'create') {
        $goalId = $goalController->createGoal($userId, $input);
        $_SESSION['goals_success'] = 'Das Lernziel wurde erfolgreich angelegt.';

        echo json_encode([
            'success' => true,
            'message' => 'Das Lernziel wurde erfolgreich angelegt.',
            'goal_id' => $goalId,
        ]);
    } elseif ($action === 'update') {
        $goalId = isset($input['goal_id']) ? (int) $input['goal_id'] : 0;
        $goalController->updateGoal($goalId, $userId, $input);
        $_SESSION['goals_success'] = 'Das Lernziel wurde erfolgreich aktualisiert.';

        echo json_encode([
            'success' => true,
            'message' => 'Das Lernziel wurde erfolgreich aktualisiert.',
            'goal_id' => $goalId,
        ]);
    } elseif ($action === 'delete') {
        $goalId = isset($input['goal_id']) ? (int) $input['goal_id'] : 0;
        $goalController->deleteGoal($goalId, $userId);
        $_SESSION['goals_success'] = 'Das Lernziel wurde erfolgreich gelöscht.';

        echo json_encode([
            'success' => true,
            'message' => 'Das Lernziel wurde erfolgreich gelöscht.',
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Ungültige Aktion.',
        ]);
    }
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

