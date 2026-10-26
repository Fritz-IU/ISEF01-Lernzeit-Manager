<?php
// api/goals.php
// JSON-Endpunkt für Lernziele (6-Monats-Grobplanung) sowie monatliche Budgets und Zwischenziele

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

    // 4. CSRF-Schutz: Das Token wird in goals.php pro Session erzeugt und von jedem Formular mitgesendet
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

    // Ziel für die Weiterleitung nach Erfolg (Semesteransicht behält den gewählten Monat bei)
    $redirectMonth = is_string($input['redirect_month'] ?? null) ? trim($input['redirect_month']) : '';
    $semesterRedirectUrl = 'goals.php?tab=semester'
        . (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $redirectMonth) ? '&month=' . urlencode($redirectMonth) : '');

    $db = getDatabaseConnection();
    $goalDAO = new GoalDAO($db);
    $goalController = new GoalController($goalDAO);

    $response = null;

    if ($action === 'create') {
        $goalId = $goalController->createGoal($userId, $input);
        $response = [
            'message'  => 'Das Lernziel wurde erfolgreich angelegt.',
            'goal_id'  => $goalId,
            'redirect' => $semesterRedirectUrl,
        ];
    } elseif ($action === 'update') {
        $goalId = isset($input['goal_id']) && is_scalar($input['goal_id']) ? (int) $input['goal_id'] : 0;
        $goalController->updateGoal($goalId, $userId, $input);
        $response = [
            'message'  => 'Das Lernziel wurde erfolgreich aktualisiert.',
            'goal_id'  => $goalId,
            'redirect' => $semesterRedirectUrl,
        ];
    } elseif ($action === 'delete') {
        $goalId = isset($input['goal_id']) && is_scalar($input['goal_id']) ? (int) $input['goal_id'] : 0;
        $goalController->deleteGoal($goalId, $userId);
        $response = [
            'message'  => 'Das Lernziel wurde erfolgreich gelöscht.',
            'redirect' => $semesterRedirectUrl,
        ];
    } elseif ($action === 'save_monthly_budget') {
        $targetMonth = $goalController->saveMonthlyBudget($userId, $input);
        $response = [
            'message'  => 'Das Zeitbudget für ' . GoalController::formatYearMonth($targetMonth) . ' wurde gespeichert.',
            'redirect' => 'goals.php?tab=month&month=' . urlencode($targetMonth),
        ];
    } elseif ($action === 'create_monthly_goal') {
        $targetMonth = $goalController->createMonthlyGoal($userId, $input);
        $response = [
            'message'  => 'Das Zwischenziel wurde erfolgreich angelegt.',
            'redirect' => 'goals.php?tab=month&month=' . urlencode($targetMonth),
        ];
    } elseif ($action === 'update_monthly_goal') {
        $monthlyGoalId = isset($input['monthly_goal_id']) && is_scalar($input['monthly_goal_id']) ? (int) $input['monthly_goal_id'] : 0;
        $targetMonth = $goalController->updateMonthlyGoal($monthlyGoalId, $userId, $input);
        $response = [
            'message'  => 'Das Zwischenziel wurde erfolgreich aktualisiert.',
            'redirect' => 'goals.php?tab=month&month=' . urlencode($targetMonth),
        ];
    } elseif ($action === 'delete_monthly_goal') {
        $monthlyGoalId = isset($input['monthly_goal_id']) && is_scalar($input['monthly_goal_id']) ? (int) $input['monthly_goal_id'] : 0;
        $targetMonth = $goalController->deleteMonthlyGoal($monthlyGoalId, $userId);
        $response = [
            'message'  => 'Das Zwischenziel wurde erfolgreich gelöscht.',
            'redirect' => 'goals.php?tab=month&month=' . urlencode($targetMonth),
        ];
    } elseif ($action === 'create_learning_slot') {
        $slotResult = $goalController->createLearningSlot($userId, $input);
        $response = [
            'message'  => 'Der Lern-Timeslot wurde erfolgreich eingeplant.',
            'slot_id'  => $slotResult['slot_id'],
            'redirect' => 'goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']),
        ];
    } elseif ($action === 'update_learning_slot') {
        $slotId = isset($input['slot_id']) && is_scalar($input['slot_id']) ? (int) $input['slot_id'] : 0;
        $slotResult = $goalController->updateLearningSlot($slotId, $userId, $input);
        $response = [
            'message'  => 'Der Lern-Timeslot wurde erfolgreich aktualisiert.',
            'slot_id'  => $slotResult['slot_id'],
            'redirect' => 'goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']),
        ];
    } elseif ($action === 'delete_learning_slot') {
        $slotId = isset($input['slot_id']) && is_scalar($input['slot_id']) ? (int) $input['slot_id'] : 0;
        $slotResult = $goalController->deleteLearningSlot($slotId, $userId);
        $response = [
            'message'  => 'Der Lern-Timeslot wurde erfolgreich gelöscht.',
            'redirect' => 'goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']),
        ];
    } elseif ($action === 'toggle_learning_slot') {
        $slotId = isset($input['slot_id']) && is_scalar($input['slot_id']) ? (int) $input['slot_id'] : 0;
        $slotResult = $goalController->toggleLearningSlotCompleted($slotId, $userId);
        $response = [
            'message'      => $slotResult['is_completed']
                ? 'Der Lern-Timeslot wurde als erledigt markiert.'
                : 'Der Lern-Timeslot wurde wieder als offen markiert.',
            'is_completed' => $slotResult['is_completed'],
            'redirect'     => 'goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']),
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

    // Erfolgsmeldung für die Seite nach der Weiterleitung hinterlegen
    $_SESSION['goals_success'] = $response['message'];

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
