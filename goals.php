<?php
// goals.php
// Seite für die 6-Monats-Lernzielplanung (Grobplanung)

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Classes/User.php';
require_once __DIR__ . '/src/DAO/GoalDAO.php';
require_once __DIR__ . '/src/Controllers/GoalController.php';

use Classes\User;
use Classes\ValidationException;
use Controllers\GoalController;
use DAO\GoalDAO;

// Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Zugriff nur für angemeldete Studierende
if (!User::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

// Kryptografisch sicheres CSRF-Token pro Session erzeugen (Schutz vor Cross-Site Request Forgery)
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$userId = (int) $_SESSION['user_id'];
$db = getDatabaseConnection();
$goalDAO = new GoalDAO($db);
$goalController = new GoalController($goalDAO);

$errorMessage = '';
$errorField = null;
$failedAction = '';

// Erfolgsmeldung aus der Session übernehmen (gesetzt vor dem Redirect nach POST)
$successMessage = (string) ($_SESSION['goals_success'] ?? '');
unset($_SESSION['goals_success']);

// Formularverarbeitung für Lernziele sowie monatliche Budgets und Zwischenziele.
// Lernziele anlegen/bearbeiten läuft per JavaScript über api/goals.php; dieser Block dient dort als Fallback ohne JavaScript.
// Nach erfolgreicher Aktion wird per Redirect auf GET umgeleitet (Post/Redirect/Get),
// damit ein Neuladen der Seite die Aktion nicht erneut ausführt.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $redirectMonth = isset($_POST['redirect_month']) && is_string($_POST['redirect_month'])
        ? trim($_POST['redirect_month'])
        : (isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : '');
    $validRedirectMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $redirectMonth) ? $redirectMonth : '';
    $semesterRedirectUrl = 'goals.php?tab=semester' . ($validRedirectMonth !== '' ? '&month=' . urlencode($validRedirectMonth) : '');

    try {
        // CSRF-Schutz: Jede schreibende POST-Anfrage muss das gültige Session-Token vorweisen
        $submittedCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!hash_equals($csrfToken, $submittedCsrf)) {
            throw new ValidationException('Ungültige Sitzungsanfrage. Bitte lade die Seite neu und versuche es erneut.');
        }

        if ($action === 'create') {
            $goalController->createGoal($userId, $_POST);
            $_SESSION['goals_success'] = 'Das Lernziel wurde erfolgreich angelegt.';
            header('Location: ' . $semesterRedirectUrl);
            exit;
        } elseif ($action === 'update') {
            $goalId = isset($_POST['goal_id']) && is_scalar($_POST['goal_id']) ? (int) $_POST['goal_id'] : 0;
            $goalController->updateGoal($goalId, $userId, $_POST);
            $_SESSION['goals_success'] = 'Das Lernziel wurde erfolgreich aktualisiert.';
            header('Location: ' . $semesterRedirectUrl);
            exit;
        } elseif ($action === 'delete') {
            $goalId = isset($_POST['goal_id']) && is_scalar($_POST['goal_id']) ? (int) $_POST['goal_id'] : 0;
            $goalController->deleteGoal($goalId, $userId);
            $_SESSION['goals_success'] = 'Das Lernziel wurde erfolgreich gelöscht.';
            header('Location: ' . $semesterRedirectUrl);
            exit;
        } elseif ($action === 'save_monthly_budget') {
            $targetMonth = $goalController->saveMonthlyBudget($userId, $_POST);
            $_SESSION['goals_success'] = 'Das Zeitbudget für ' . GoalController::formatYearMonth($targetMonth) . ' wurde gespeichert.';
            header('Location: goals.php?tab=month&month=' . urlencode($targetMonth));
            exit;
        } elseif ($action === 'create_monthly_goal') {
            $targetMonth = $goalController->createMonthlyGoal($userId, $_POST);
            $_SESSION['goals_success'] = 'Das Zwischenziel wurde erfolgreich angelegt.';
            header('Location: goals.php?tab=month&month=' . urlencode($targetMonth));
            exit;
        } elseif ($action === 'update_monthly_goal') {
            $monthlyGoalId = isset($_POST['monthly_goal_id']) && is_scalar($_POST['monthly_goal_id']) ? (int) $_POST['monthly_goal_id'] : 0;
            $targetMonth = $goalController->updateMonthlyGoal($monthlyGoalId, $userId, $_POST);
            $_SESSION['goals_success'] = 'Das Zwischenziel wurde erfolgreich aktualisiert.';
            header('Location: goals.php?tab=month&month=' . urlencode($targetMonth));
            exit;
        } elseif ($action === 'delete_monthly_goal') {
            $monthlyGoalId = isset($_POST['monthly_goal_id']) && is_scalar($_POST['monthly_goal_id']) ? (int) $_POST['monthly_goal_id'] : 0;
            $targetMonth = $goalController->deleteMonthlyGoal($monthlyGoalId, $userId);
            $_SESSION['goals_success'] = 'Das Zwischenziel wurde erfolgreich gelöscht.';
            header('Location: goals.php?tab=month&month=' . urlencode($targetMonth));
            exit;
        } elseif ($action === 'create_learning_slot') {
            $slotResult = $goalController->createLearningSlot($userId, $_POST);
            $_SESSION['goals_success'] = 'Der Lern-Timeslot wurde erfolgreich eingeplant.';
            header('Location: goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']) . '&day=' . urlencode($slotResult['slot_date']));
            exit;
        } elseif ($action === 'update_learning_slot') {
            $slotId = isset($_POST['slot_id']) && is_scalar($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
            $slotResult = $goalController->updateLearningSlot($slotId, $userId, $_POST);
            $_SESSION['goals_success'] = 'Der Lern-Timeslot wurde erfolgreich aktualisiert.';
            header('Location: goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']) . '&day=' . urlencode($slotResult['slot_date']));
            exit;
        } elseif ($action === 'delete_learning_slot') {
            $slotId = isset($_POST['slot_id']) && is_scalar($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
            $slotResult = $goalController->deleteLearningSlot($slotId, $userId);
            $_SESSION['goals_success'] = 'Der Lern-Timeslot wurde erfolgreich gelöscht.';
            header('Location: goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']) . '&day=' . urlencode($slotResult['slot_date']));
            exit;
        } elseif ($action === 'toggle_learning_slot') {
            $slotId = isset($_POST['slot_id']) && is_scalar($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
            $slotResult = $goalController->toggleLearningSlotCompleted($slotId, $userId);
            $_SESSION['goals_success'] = $slotResult['is_completed']
                ? 'Der Lern-Timeslot wurde als erledigt markiert.'
                : 'Der Lern-Timeslot wurde wieder als offen markiert.';
            header('Location: goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']) . '&day=' . urlencode($slotResult['slot_date']));
            exit;
        } else {
            $errorMessage = 'Ungültige Aktion.';
        }
    } catch (ValidationException $e) {
        $errorMessage = $e->getMessage();
        $errorField = $e->getField();
        $failedAction = $action;
    } catch (Throwable $e) {
        error_log((string) $e);
        $errorMessage = 'Es ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.';
        $failedAction = $action;
    }
}

// Lernziele und aggregierte Statistiken abrufen
$goals = $goalController->listGoals($userId);
$totalHours = $goalController->getTotalHours($userId);

// Monatliche & wöchentliche Detailplanung aufbereiten
$requestedMonth = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : null;
$requestedWeek = isset($_GET['week']) && is_string($_GET['week']) ? $_GET['week'] : null;
$requestedDay = isset($_GET['day']) && is_string($_GET['day']) ? $_GET['day'] : null;
$requestedTab = isset($_GET['tab']) && is_string($_GET['tab']) ? trim($_GET['tab']) : '';

if ($requestedTab === 'week' || ($requestedTab === '' && $requestedWeek !== null)) {
    $activeViewTab = 'week';
} elseif ($requestedTab === 'month' || ($requestedTab === '' && $requestedMonth !== null)) {
    $activeViewTab = 'month';
} else {
    $activeViewTab = 'semester';
}

$resolvedMonth = GoalController::resolveMonthForWeek($requestedMonth, $requestedWeek);
$monthlyPlanning = $goalController->buildMonthlyPlanningData($userId, $goals, $resolvedMonth);
$selectedMonth = (string) $monthlyPlanning['selected_month'];
$selectedMonthData = $monthlyPlanning['selected_month_data'];
$monthsWindow = $monthlyPlanning['months_window'];
$monthlyGoals = $monthlyPlanning['monthly_goals'];
$totalPlannedAllMonths = (float) $monthlyPlanning['total_planned_all_months'];
$isTotalOverAllocated = $totalHours > 0 && $totalPlannedAllMonths > $totalHours;

$weeklyPlanning = $goalController->buildWeeklyPlanningData($userId, $monthlyPlanning, $requestedWeek, $requestedDay);
$selectedWeek = (string) $weeklyPlanning['selected_week'];
$selectedWeekData = $weeklyPlanning['selected_week_data'];
$weeksWindow = $weeklyPlanning['weeks_window'];
$weekDays = $weeklyPlanning['days'];
$weekSummary = $weeklyPlanning['week_summary'];
$defaultSlotDate = (string) $weeklyPlanning['default_slot_date'];
$weeklyReferenceHours = $weeklyPlanning['weekly_reference_hours'];
$selectableMonthlyGoals = $weeklyPlanning['selectable_monthly_goals'];

// Standard-Zeiten für Lernzeit-Modal: nächstmöglicher 10-Minuten-Slot basierend auf der aktuellen Uhrzeit
$nowTimestamp = time();
$nowMinutesTotal = ((int) date('H', $nowTimestamp)) * 60 + ((int) date('i', $nowTimestamp));
if (((int) date('s', $nowTimestamp)) > 0) {
    $nowMinutesTotal++;
}
$initStartMinutesTotal = (int) (ceil($nowMinutesTotal / 10) * 10);
if ($initStartMinutesTotal > (23 * 60 + 40)) {
    $initStartMinutesTotal = 23 * 60 + 40;
}
$initStartHour = (int) floor($initStartMinutesTotal / 60);
$initStartMinute = $initStartMinutesTotal % 60;
$initStartTime = sprintf('%02d:%02d', $initStartHour, $initStartMinute);

$initEndMinutesTotal = min(23 * 60 + 50, $initStartMinutesTotal + 60);
if ($initEndMinutesTotal <= $initStartMinutesTotal) {
    $initEndMinutesTotal = min(23 * 60 + 50, $initStartMinutesTotal + 10);
}
$initEndHour = (int) floor($initEndMinutesTotal / 60);
$initEndMinute = $initEndMinutesTotal % 60;
$initEndTime = sprintf('%02d:%02d', $initEndHour, $initEndMinute);
$initDurationMinutes = $initEndMinutesTotal - $initStartMinutesTotal;

// Standardwerte für neues Lernziel
$defaultStartDate = date('Y-m-d');
$defaultEndDate = date('Y-m-d', strtotime('+6 months'));

// Vorbefüllte Werte bei fehlgeschlagenem POST-Fallback
$createTitleVal = $failedAction === 'create' && is_string($_POST['title'] ?? null) ? $_POST['title'] : '';
$createHoursVal = $failedAction === 'create' && is_string($_POST['target_hours'] ?? null) ? $_POST['target_hours'] : '';
$createStartVal = $failedAction === 'create' && is_string($_POST['start_date'] ?? null) ? $_POST['start_date'] : $defaultStartDate;
$createEndVal = $failedAction === 'create' && is_string($_POST['end_date'] ?? null) ? $_POST['end_date'] : $defaultEndDate;
$createDescVal = $failedAction === 'create' && is_string($_POST['description'] ?? null) ? $_POST['description'] : '';

$editIdVal = $failedAction === 'update' && is_string($_POST['goal_id'] ?? null) ? $_POST['goal_id'] : '';
$editTitleVal = $failedAction === 'update' && is_string($_POST['title'] ?? null) ? $_POST['title'] : '';
$editHoursVal = $failedAction === 'update' && is_string($_POST['target_hours'] ?? null) ? $_POST['target_hours'] : '';
$editStartVal = $failedAction === 'update' && is_string($_POST['start_date'] ?? null) ? $_POST['start_date'] : '';
$editEndVal = $failedAction === 'update' && is_string($_POST['end_date'] ?? null) ? $_POST['end_date'] : '';
$editDescVal = $failedAction === 'update' && is_string($_POST['description'] ?? null) ? $_POST['description'] : '';

$pageTitle = 'Lernzielplanung – Lernzeit-Manager';
$activeNav = 'goals';

require_once __DIR__ . '/includes/header.php';
?>

<div class="row align-items-center mb-3">
    <div class="col-md-7">
        <h1 class="h3 fw-bold text-primary mb-1">Lernziele, Monats- und Wochenplanung</h1>
        <p class="text-muted mb-0">
            Plane deine Lernziele für die nächsten sechs Monate, teile sie in monatliche Zwischenziele auf und plane verfügbare Lern-Timeslots in deiner Woche ein.
        </p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createGoalModal">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-plus-lg me-1 align-text-bottom" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2"/>
            </svg>
            Neues Lernziel planen
        </button>
    </div>
</div>

<!-- Statusmeldungen -->
<?php if ($errorMessage !== '' && $failedAction !== 'create' && $failedAction !== 'update'): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
        <strong>Fehler:</strong> <?= htmlspecialchars($errorMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<?php if ($successMessage !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        <?= htmlspecialchars($successMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<!-- Kompakte Kennzahlen-Leiste -->
<div class="card shadow-sm border mb-4">
    <div class="card-body py-2 px-3">
        <div class="row align-items-center g-2 text-center text-sm-start">
            <div class="col-6 col-sm-4 border-end-sm">
                <span class="text-muted small d-block">Geplante Lernziele</span>
                <span class="fs-5 fw-bold text-dark"><?= count($goals) ?></span>
            </div>
            <div class="col-6 col-sm-4 border-end-sm">
                <span class="text-muted small d-block">Geplante Gesamtlernzeit</span>
                <span class="fs-5 fw-bold text-primary"><?= number_format($totalHours, 1, ',', '.') ?> <span class="fs-6 text-muted fw-normal">Stunden</span></span>
            </div>
            <div class="col-12 col-sm-4 border-top border-sm-top-0 pt-2 pt-sm-0">
                <span class="text-muted small d-block">In Zwischenziele aufgeteilt</span>
                <div class="d-flex align-items-center justify-content-center justify-content-sm-start flex-wrap gap-2">
                    <span class="fs-5 fw-bold <?= $isTotalOverAllocated ? 'text-warning-emphasis' : 'text-dark' ?>">
                        <?= number_format($totalPlannedAllMonths, 1, ',', '.') ?>
                        <span class="fs-6 text-muted fw-normal">von <?= number_format($totalHours, 1, ',', '.') ?> h</span>
                    </span>
                    <?php if ($isTotalOverAllocated): ?>
                        <span class="badge text-bg-warning">
                            +<?= number_format($totalPlannedAllMonths - $totalHours, 1, ',', '.') ?> h über Zielwert
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hauptansicht-Umschalter: Semesterübersicht vs. Monatsplanung vs. Wochenplanung -->
<ul class="nav nav-pills goals-view-switcher mb-4 p-1 bg-white rounded shadow-sm d-inline-flex flex-wrap gap-1" id="goalsMainTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button
            class="nav-link fw-semibold px-3 py-2 <?= $activeViewTab === 'semester' ? 'active' : '' ?>"
            id="tab-semester-btn"
            data-bs-toggle="tab"
            data-bs-target="#pane-semester"
            data-view-tab="semester"
            type="button"
            role="tab"
            aria-controls="pane-semester"
            aria-selected="<?= $activeViewTab === 'semester' ? 'true' : 'false' ?>"
        >
            Semesterübersicht
            <span class="badge rounded-pill bg-secondary-subtle text-secondary ms-1"><?= count($goals) ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button
            class="nav-link fw-semibold px-3 py-2 <?= $activeViewTab === 'month' ? 'active' : '' ?>"
            id="tab-month-btn"
            data-bs-toggle="tab"
            data-bs-target="#pane-month"
            data-view-tab="month"
            type="button"
            role="tab"
            aria-controls="pane-month"
            aria-selected="<?= $activeViewTab === 'month' ? 'true' : 'false' ?>"
        >
            Monatsplanung
            <span class="badge rounded-pill bg-secondary-subtle text-secondary ms-1" id="tab-month-badge"><?= htmlspecialchars((string) $selectedMonthData['label_short']) ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button
            class="nav-link fw-semibold px-3 py-2 <?= $activeViewTab === 'week' ? 'active' : '' ?>"
            id="tab-week-btn"
            data-bs-toggle="tab"
            data-bs-target="#pane-week"
            data-view-tab="week"
            type="button"
            role="tab"
            aria-controls="pane-week"
            aria-selected="<?= $activeViewTab === 'week' ? 'true' : 'false' ?>"
        >
            Wochenplanung
            <span class="badge rounded-pill bg-secondary-subtle text-secondary ms-1" id="tab-week-badge"><?= htmlspecialchars((string) $selectedWeekData['label_short']) ?></span>
        </button>
    </li>
</ul>

<div class="tab-content" id="goalsMainTabContent">
    <!-- ================================================================== -->
    <!-- ANSICHT 1: Semesterübersicht (Lernziele für 6 Monate)              -->
    <!-- ================================================================== -->
    <div
        class="tab-pane fade <?= $activeViewTab === 'semester' ? 'show active' : '' ?>"
        id="pane-semester"
        role="tabpanel"
        aria-labelledby="tab-semester-btn"
        tabindex="0"
    >
        <?php if (empty($goals)): ?>
            <div class="card shadow-sm border-0 text-center py-5 mb-4">
                <div class="card-body">
                    <div class="mb-3 text-muted">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-calendar2-check text-primary" viewBox="0 0 16 16">
                            <path d="M10.854 8.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 0 1 .708-.708L7.5 10.793l2.646-2.647a.5.5 0 0 1 .708 0"/>
                            <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M2 2a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1z"/>
                            <path d="M2.5 4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5H3a.5.5 0 0 1-.5-.5z"/>
                        </svg>
                    </div>
                    <h2 class="h5 fw-bold">Noch keine Lernziele geplant</h2>
                    <p class="text-muted mb-4 max-w-500 mx-auto">
                        Lege zuerst deine Lernziele und die geplante Lernzeit für die nächsten sechs Monate fest, um sie anschließend in monatliche Zwischenziele aufzuteilen.
                    </p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createGoalModal">
                        Erstes Lernziel anlegen
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border goals-table-card mb-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-mobile-cards">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Lernziel</th>
                                <th scope="col" class="text-end">Zielstunden</th>
                                <th scope="col" style="min-width: 210px;">Aufgeteilte Stunden</th>
                                <th scope="col">Zeitraum</th>
                                <th scope="col" class="text-end">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($goals as $goal): ?>
                                <?php
                                    $startFormatted = date('d.m.Y', strtotime((string) $goal['start_date']));
                                    $endFormatted = date('d.m.Y', strtotime((string) $goal['end_date']));
                                    $startDateObj = new DateTimeImmutable((string) $goal['start_date']);
                                    $endDateObj = new DateTimeImmutable((string) $goal['end_date']);
                                    $durationDays = $startDateObj->diff($endDateObj)->days;
                                    $durationWeeks = (int) ceil($durationDays / 7);

                                    $targetHoursRow = (float) $goal['target_hours'];
                                    $allocatedHoursRow = (float) ($goal['allocated_hours'] ?? 0.0);
                                    $monthlyGoalCount = (int) ($goal['monthly_goal_count'] ?? 0);
                                    $isGoalOverAllocated = $allocatedHoursRow > $targetHoursRow;
                                    $allocPercent = $targetHoursRow > 0 ? round(($allocatedHoursRow / $targetHoursRow) * 100, 1) : 0.0;
                                    $allocBarWidth = min(100.0, $allocPercent);

                                    if ($isGoalOverAllocated) {
                                        $allocBarClass = 'bg-warning';
                                        $allocTextClass = 'text-warning-emphasis fw-bold';
                                    } elseif ($allocPercent >= 100.0) {
                                        $allocBarClass = 'bg-success';
                                        $allocTextClass = 'text-success fw-semibold';
                                    } else {
                                        $allocBarClass = 'bg-primary';
                                        $allocTextClass = 'text-dark fw-semibold';
                                    }
                                ?>
                                <tr class="<?= $isGoalOverAllocated ? 'table-warning' : '' ?>">
                                    <td class="td-mobile-header" data-label="Lernziel">
                                        <strong class="text-dark d-block"><?= htmlspecialchars((string) $goal['title']) ?></strong>
                                        <?php if (!empty($goal['description'])): ?>
                                            <p class="text-muted small mb-0 mt-1">
                                                <?= nl2br(htmlspecialchars((string) $goal['description'])) ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" data-label="Zielstunden">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold px-2 py-1">
                                            <?= number_format($targetHoursRow, 1, ',', '.') ?> h
                                        </span>
                                    </td>
                                    <td class="td-mobile-block" data-label="Aufgeteilte Stunden">
                                        <div class="d-flex justify-content-between align-items-center small mb-1">
                                            <span class="<?= $allocTextClass ?>">
                                                <?= number_format($allocatedHoursRow, 1, ',', '.') ?> von <?= number_format($targetHoursRow, 1, ',', '.') ?> h
                                            </span>
                                            <span class="badge <?= $isGoalOverAllocated ? 'text-bg-warning' : 'bg-secondary-subtle text-secondary' ?>">
                                                <?= number_format($allocPercent, 0, ',', '.') ?> %
                                            </span>
                                        </div>
                                        <div class="progress" style="height: 6px;" role="progressbar" aria-label="Verplante Stunden" aria-valuenow="<?= (int) $allocBarWidth ?>" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar <?= $allocBarClass ?>" style="width: <?= $allocBarWidth ?>%"></div>
                                        </div>
                                        <div class="mt-1 d-flex justify-content-between align-items-center">
                                            <span class="text-muted small"><?= $monthlyGoalCount ?> Zwischenziel<?= $monthlyGoalCount === 1 ? '' : 'e' ?></span>
                                            <?php if ($isGoalOverAllocated): ?>
                                                <span class="text-warning-emphasis small fw-semibold">
                                                    +<?= number_format($allocatedHoursRow - $targetHoursRow, 1, ',', '.') ?> h über Ziel
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Zeitraum">
                                        <div>
                                            <div class="small fw-semibold text-dark">
                                                <?= $startFormatted ?> &ndash; <?= $endFormatted ?>
                                            </div>
                                            <div class="text-muted small">
                                                Dauer: <?= $durationWeeks ?> Wochen
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end td-mobile-actions" data-label="Aktionen">
                                        <div class="btn-group btn-group-sm" role="group" aria-label="Aktionen für Lernziel">
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary quick-add-monthly-goal-btn"
                                                title="Zwischenziel für dieses Lernziel anlegen"
                                                data-bs-toggle="modal"
                                                data-bs-target="#createMonthlyGoalModal"
                                                data-goal-id="<?= (int) $goal['id'] ?>"
                                                data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                                            >
                                                + Zwischenziel
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary edit-goal-btn"
                                                title="Lernziel bearbeiten"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editGoalModal"
                                                data-id="<?= (int) $goal['id'] ?>"
                                                data-title="<?= htmlspecialchars((string) $goal['title'], ENT_QUOTES) ?>"
                                                data-hours="<?= htmlspecialchars((string) $goal['target_hours'], ENT_QUOTES) ?>"
                                                data-start="<?= htmlspecialchars((string) $goal['start_date'], ENT_QUOTES) ?>"
                                                data-end="<?= htmlspecialchars((string) $goal['end_date'], ENT_QUOTES) ?>"
                                                data-description="<?= htmlspecialchars((string) ($goal['description'] ?? ''), ENT_QUOTES) ?>"
                                            >
                                                Bearbeiten
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-outline-danger delete-goal-btn"
                                                title="Lernziel löschen"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteGoalModal"
                                                data-id="<?= (int) $goal['id'] ?>"
                                                data-title="<?= htmlspecialchars((string) $goal['title'], ENT_QUOTES) ?>"
                                            >
                                                Löschen
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================== -->
    <!-- ANSICHT 2: Monatsplanung (Zeitbudget & Zwischenziele je Monat)     -->
    <!-- ================================================================== -->
    <div
        class="tab-pane fade <?= $activeViewTab === 'month' ? 'show active' : '' ?>"
        id="pane-month"
        role="tabpanel"
        aria-labelledby="tab-month-btn"
        tabindex="0"
    >
        <section id="monatsplanung" class="mb-4" data-selected-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>" data-selected-month-short="<?= htmlspecialchars((string) $selectedMonthData['label_short'], ENT_QUOTES) ?>">
            <!-- Monats-Tab-Leiste -->
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body p-2 p-sm-3">
                    <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                        <a
                            href="goals.php?tab=month&month=<?= urlencode((string) $monthlyPlanning['prev_month']) ?>"
                            data-month-switch="<?= htmlspecialchars((string) $monthlyPlanning['prev_month'], ENT_QUOTES) ?>"
                            class="btn btn-sm btn-outline-secondary"
                        >
                            <span class="d-sm-none">&lsaquo; <?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['prev_month'], true)) ?></span>
                            <span class="d-none d-sm-inline">&lsaquo; <?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['prev_month'], false)) ?></span>
                        </a>
                        <span class="text-muted small fw-semibold text-center">
                            Monatsauswahl
                        </span>
                        <a
                            href="goals.php?tab=month&month=<?= urlencode((string) $monthlyPlanning['next_month']) ?>"
                            data-month-switch="<?= htmlspecialchars((string) $monthlyPlanning['next_month'], ENT_QUOTES) ?>"
                            class="btn btn-sm btn-outline-secondary"
                        >
                            <span class="d-sm-none"><?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['next_month'], true)) ?> &rsaquo;</span>
                            <span class="d-none d-sm-inline"><?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['next_month'], false)) ?> &rsaquo;</span>
                        </a>
                    </div>

                    <div class="row g-2" role="tablist" aria-label="Monatsauswahl">
                        <?php foreach ($monthsWindow as $ym => $mTab): ?>
                            <?php
                                $isTabSelected = (bool) $mTab['is_selected'];
                                $isTabOverBudget = (bool) $mTab['is_over_budget'];
                                $tabPlanned = (float) $mTab['planned_hours'];
                                $tabBudget = $mTab['budget_hours'];
                                $tabWorkloadShare = (float) $mTab['workload_share_percent'];

                                if ($isTabSelected) {
                                    $tabCardClasses = $isTabOverBudget
                                        ? 'border-warning bg-warning-subtle text-dark shadow-sm active'
                                        : 'border-primary bg-primary text-white shadow-sm active';
                                } else {
                                    $tabCardClasses = $isTabOverBudget
                                        ? 'border-warning bg-warning-subtle text-dark'
                                        : 'border-light-subtle bg-light text-dark';
                                }
                            ?>
                            <div class="col-4 col-lg-2">
                                <a
                                    href="goals.php?tab=month&month=<?= urlencode((string) $ym) ?>"
                                    data-month-switch="<?= htmlspecialchars((string) $ym, ENT_QUOTES) ?>"
                                    class="card h-100 text-decoration-none border month-tab-link <?= $tabCardClasses ?>"
                                    role="tab"
                                    aria-selected="<?= $isTabSelected ? 'true' : 'false' ?>"
                                >
                                    <div class="card-body p-2 text-center">
                                        <div class="d-flex flex-wrap justify-content-center align-items-center gap-1 mb-1">
                                            <span class="fw-bold small"><?= htmlspecialchars((string) $mTab['label_short']) ?></span>
                                            <?php if ($mTab['is_current_calendar_month']): ?>
                                                <span class="badge <?= $isTabSelected && !$isTabOverBudget ? 'bg-light text-primary' : 'bg-primary-subtle text-primary' ?> d-none d-sm-inline-block" style="font-size: 0.65rem;">Aktuell</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small <?= $isTabSelected && !$isTabOverBudget ? 'text-white' : ($isTabOverBudget ? 'text-warning-emphasis fw-bold' : 'text-muted') ?>" style="font-size: 0.78rem;">
                                            <?= number_format($tabPlanned, 1, ',', '.') ?>
                                            <?php if ($tabBudget !== null): ?>
                                                / <?= number_format((float) $tabBudget, 1, ',', '.') ?> h
                                            <?php else: ?>
                                                h
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-1">
                                            <span class="badge <?= $isTabOverBudget ? 'text-bg-warning' : ($isTabSelected ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary') ?>" style="font-size: 0.68rem;">
                                                <?= number_format($tabWorkloadShare, 0, ',', '.') ?> % Workload
                                            </span>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Detailansicht des aktiv ausgewählten Monats -->
            <?php
                $selBudget = $selectedMonthData['budget_hours'];
                $selPlanned = (float) $selectedMonthData['planned_hours'];
                $selRemaining = $selectedMonthData['remaining_budget_hours'];
                $selUtilPercent = $selectedMonthData['budget_utilization_percent'];
                $selIsOverBudget = (bool) $selectedMonthData['is_over_budget'];
                $selWorkloadShare = (float) $selectedMonthData['workload_share_percent'];
            ?>
            <div class="card shadow-sm border mb-4">
                <div class="card-header bg-white py-3 px-4 border-bottom">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h2 class="h4 fw-bold mb-0 d-flex align-items-center flex-wrap gap-2">
                                <span><?= htmlspecialchars((string) $selectedMonthData['label_long']) ?></span>
                                <?php if ($selectedMonthData['is_current_calendar_month']): ?>
                                    <span class="badge bg-primary-subtle text-primary fs-6 fw-normal">Aktueller Monat</span>
                                <?php endif; ?>
                                <?php if ($selIsOverBudget): ?>
                                    <span class="badge text-bg-warning fs-6">
                                        Zeitbudget um <?= number_format(abs((float) $selRemaining), 1, ',', '.') ?> h überschritten
                                    </span>
                                <?php endif; ?>
                            </h2>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (!empty($goals)): ?>
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#createMonthlyGoalModal"
                                    data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                                >
                                    + Zwischenziel planen
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card-body p-3 p-sm-4">
                    <!-- 3 Kennzahlen-Kacheln für den ausgewählten Monat -->
                    <div class="row g-3 mb-4">
                        <!-- Kachel 1: Monatliches Zeitbudget -->
                        <div class="col-md-4">
                            <div class="p-3 border rounded h-100 <?= $selIsOverBudget ? 'bg-warning-subtle border-warning' : 'bg-light' ?>">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <span class="text-muted small text-uppercase fw-semibold">Monatliches Zeitbudget</span>
                                    <?php if ($selBudget !== null): ?>
                                        <button
                                            type="button"
                                            class="btn btn-link btn-sm p-0 text-decoration-none"
                                            data-bs-toggle="modal"
                                            data-bs-target="#monthlyBudgetModal"
                                            data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                                            data-month-label="<?= htmlspecialchars((string) $selectedMonthData['label_long'], ENT_QUOTES) ?>"
                                            data-budget="<?= htmlspecialchars((string) $selBudget, ENT_QUOTES) ?>"
                                        >
                                            Bearbeiten
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <?php if ($selBudget !== null): ?>
                                    <div class="h4 fw-bold text-dark mb-1">
                                        <?= number_format((float) $selBudget, 1, ',', '.') ?> <span class="fs-6 fw-normal text-muted">Stunden</span>
                                    </div>
                                    <?php if ($selIsOverBudget): ?>
                                        <div class="small text-warning-emphasis fw-semibold">
                                            Um <?= number_format(abs((float) $selRemaining), 1, ',', '.') ?> h über dem Zeitbudget
                                        </div>
                                    <?php else: ?>
                                        <div class="small text-success fw-semibold">
                                            Noch <?= number_format((float) $selRemaining, 1, ',', '.') ?> h verfügbar
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="h5 fw-semibold text-muted mb-2 fst-italic">Kein Budget hinterlegt</div>
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#monthlyBudgetModal"
                                        data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                                        data-month-label="<?= htmlspecialchars((string) $selectedMonthData['label_long'], ENT_QUOTES) ?>"
                                        data-budget=""
                                    >
                                        Zeitbudget festlegen
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Kachel 2: Verplante Lernzeit im Monat -->
                        <div class="col-md-4">
                            <div class="p-3 border rounded h-100 <?= $selIsOverBudget ? 'bg-warning-subtle border-warning' : 'bg-light' ?>">
                                <span class="text-muted small text-uppercase fw-semibold d-block mb-1">Verplante Lernzeit</span>
                                <div class="d-flex justify-content-between align-items-baseline mb-2">
                                    <span class="h4 fw-bold <?= $selIsOverBudget ? 'text-warning-emphasis' : 'text-primary' ?> mb-0">
                                        <?= number_format($selPlanned, 1, ',', '.') ?> <span class="fs-6 fw-normal text-muted">Stunden</span>
                                    </span>
                                    <?php if ($selUtilPercent !== null): ?>
                                        <span class="badge <?= $selIsOverBudget ? 'text-bg-warning' : 'bg-primary-subtle text-primary' ?> fs-6">
                                            <?= number_format((float) $selUtilPercent, 1, ',', '.') ?> % des Budgets
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php
                                    $utilBarWidth = $selUtilPercent !== null ? min(100.0, (float) $selUtilPercent) : ($selPlanned > 0 ? 100.0 : 0.0);
                                    $utilBarClass = $selIsOverBudget ? 'bg-warning' : ($selUtilPercent !== null && $selUtilPercent >= 90.0 ? 'bg-success' : 'bg-primary');
                                ?>
                                <div class="progress" style="height: 8px;" role="progressbar" aria-label="Auslastung des Zeitbudgets" aria-valuenow="<?= (int) $utilBarWidth ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar <?= $utilBarClass ?>" style="width: <?= $utilBarWidth ?>%"></div>
                                </div>
                                <div class="small text-muted mt-1">
                                    <?= count($monthlyGoals) ?> Zwischenziel<?= count($monthlyGoals) === 1 ? '' : 'e' ?> in <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?>
                                </div>
                            </div>
                        </div>

                        <!-- Kachel 3: Prozentuale Verteilung des Workloads -->
                        <div class="col-md-4">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="text-muted small text-uppercase fw-semibold d-block mb-1">Verteilung deines Workloads</span>
                                <div class="d-flex justify-content-between align-items-baseline mb-2">
                                    <span class="h4 fw-bold text-dark mb-0">
                                        <?= number_format($selWorkloadShare, 1, ',', '.') ?> %
                                    </span>
                                    <span class="text-muted small">
                                        <?= number_format($selPlanned, 1, ',', '.') ?> von <?= number_format($totalPlannedAllMonths, 1, ',', '.') ?> h
                                    </span>
                                </div>
                                <?php $shareBarWidth = min(100.0, $selWorkloadShare); ?>
                                <div class="progress" style="height: 8px;" role="progressbar" aria-label="Prozentuale Verteilung deines Workloads" aria-valuenow="<?= (int) $shareBarWidth ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar bg-info" style="width: <?= $shareBarWidth ?>%"></div>
                                </div>
                                <div class="small text-muted mt-1">
                                    Gleichmäßig auf sechs Monate verteilt entspricht etwa 16,7 % je Monat.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabelle der Zwischenziele im ausgewählten Monat -->
                    <?php if (empty($monthlyGoals)): ?>
                        <div class="text-center py-4 border rounded bg-light-subtle">
                            <p class="fw-semibold text-dark mb-1">
                                Für <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?> sind noch keine Zwischenziele geplant.
                            </p>
                            <p class="text-muted small mb-3">
                                Ordne deine Lernziele diesem Monat zu und lege konkrete Zwischenziele und Stunden fest.
                            </p>
                            <?php if (!empty($goals)): ?>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#createMonthlyGoalModal"
                                    data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                                >
                                    Erstes Zwischenziel für <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?> anlegen
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive goals-table-frame">
                            <table class="table table-hover align-middle mb-0 table-mobile-cards">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Zwischenziel</th>
                                        <th scope="col">Zugehöriges Lernziel</th>
                                        <th scope="col" class="text-end">Geplante Lernzeit</th>
                                        <th scope="col" class="text-end">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthlyGoals as $mGoal): ?>
                                        <?php
                                            $mgHours = (float) $mGoal['planned_hours'];
                                            $parentTarget = (float) $mGoal['parent_target_hours'];
                                            $parentAlloc = (float) $mGoal['parent_allocated_hours'];
                                            $isParentOver = (bool) $mGoal['is_parent_over_allocated'];
                                            $shareOfBudget = $mGoal['share_of_month_budget_percent'];
                                            $shareOfPlanned = (float) $mGoal['share_of_month_planned_percent'];
                                            $mgScheduled = (float) ($mGoal['scheduled_hours'] ?? 0.0);
                                            $mgSlotCount = (int) ($mGoal['slot_count'] ?? 0);
                                            $isMgOverScheduled = (bool) ($mGoal['is_over_scheduled'] ?? false);
                                        ?>
                                        <tr class="<?= ($isParentOver || $isMgOverScheduled) ? 'table-warning' : '' ?>">
                                            <td class="td-mobile-header" data-label="Zwischenziel">
                                                <strong class="text-dark d-block"><?= htmlspecialchars((string) $mGoal['title']) ?></strong>
                                                <?php if (!empty($mGoal['description'])): ?>
                                                    <p class="text-muted small mb-0 mt-1">
                                                        <?= nl2br(htmlspecialchars((string) $mGoal['description'])) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="td-mobile-block" data-label="Zugehöriges Lernziel">
                                                <span class="fw-semibold text-dark d-block"><?= htmlspecialchars((string) $mGoal['parent_goal_title']) ?></span>
                                                <span class="small <?= $isParentOver ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>">
                                                    Insgesamt verplant: <?= number_format($parentAlloc, 1, ',', '.') ?> von <?= number_format($parentTarget, 1, ',', '.') ?> h
                                                    <?php if ($isParentOver): ?>
                                                        <span class="badge text-bg-warning ms-1">Lernziel überschritten</span>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="text-end" data-label="Geplante Lernzeit">
                                                <div>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold px-2 py-1">
                                                        <?= number_format($mgHours, 1, ',', '.') ?> h
                                                    </span>
                                                    <div class="text-muted small mt-1">
                                                        <?php if ($shareOfBudget !== null): ?>
                                                            <?= number_format((float) $shareOfBudget, 1, ',', '.') ?> % vom Zeitbudget
                                                        <?php else: ?>
                                                            <?= number_format($shareOfPlanned, 1, ',', '.') ?> % der Monatsplanung
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="small mt-1 <?= $isMgOverScheduled ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>">
                                                        In Timeslots: <?= number_format($mgScheduled, 1, ',', '.') ?> / <?= number_format($mgHours, 1, ',', '.') ?> h (<?= $mgSlotCount ?>)
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-end td-mobile-actions" data-label="Aktionen">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Aktionen für Zwischenziel">
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-secondary edit-monthly-goal-btn"
                                                        title="Zwischenziel bearbeiten"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#editMonthlyGoalModal"
                                                        data-id="<?= (int) $mGoal['id'] ?>"
                                                        data-goal-id="<?= (int) $mGoal['goal_id'] ?>"
                                                        data-month="<?= htmlspecialchars((string) $mGoal['year_month'], ENT_QUOTES) ?>"
                                                        data-title="<?= htmlspecialchars((string) $mGoal['title'], ENT_QUOTES) ?>"
                                                        data-hours="<?= htmlspecialchars((string) $mGoal['planned_hours'], ENT_QUOTES) ?>"
                                                        data-description="<?= htmlspecialchars((string) ($mGoal['description'] ?? ''), ENT_QUOTES) ?>"
                                                    >
                                                        Bearbeiten
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-danger delete-monthly-goal-btn"
                                                        title="Zwischenziel löschen"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#deleteMonthlyGoalModal"
                                                        data-id="<?= (int) $mGoal['id'] ?>"
                                                        data-title="<?= htmlspecialchars((string) $mGoal['title'], ENT_QUOTES) ?>"
                                                        data-month="<?= htmlspecialchars((string) $mGoal['year_month'], ENT_QUOTES) ?>"
                                                    >
                                                        Löschen
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <!-- ================================================================== -->
    <!-- ANSICHT 3: Wochenplanung (Verfügbare Lern-Timeslots je Woche)      -->
    <!-- ================================================================== -->
    <div
        class="tab-pane fade <?= $activeViewTab === 'week' ? 'show active' : '' ?>"
        id="pane-week"
        role="tabpanel"
        aria-labelledby="tab-week-btn"
        tabindex="0"
    >
        <section
            id="wochenplanung"
            class="mb-4"
            data-selected-week="<?= htmlspecialchars($selectedWeek, ENT_QUOTES) ?>"
            data-selected-week-short="<?= htmlspecialchars((string) $selectedWeekData['label_short'], ENT_QUOTES) ?>"
            data-selected-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
            data-selected-month-short="<?= htmlspecialchars((string) $selectedMonthData['label_short'], ENT_QUOTES) ?>"
            data-default-slot-date="<?= htmlspecialchars($defaultSlotDate, ENT_QUOTES) ?>"
            data-week-view-mode="day"
        >
            <!-- Kalenderwochen-Auswahl-Leiste -->
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body p-2 p-sm-3">
                    <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                        <a
                            href="goals.php?tab=week&month=<?= urlencode((string) $weeklyPlanning['prev_week_month']) ?>&week=<?= urlencode((string) $weeklyPlanning['prev_week']) ?>"
                            data-week-switch="<?= htmlspecialchars((string) $weeklyPlanning['prev_week'], ENT_QUOTES) ?>"
                            data-week-month="<?= htmlspecialchars((string) $weeklyPlanning['prev_week_month'], ENT_QUOTES) ?>"
                            class="btn btn-sm btn-outline-secondary"
                        >
                            &lsaquo; KW <?= (int) substr((string) $weeklyPlanning['prev_week'], -2) ?>
                        </a>
                        <div class="text-center">
                            <span class="text-muted small fw-semibold d-block">
                                Kalenderwochen in <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?>
                            </span>
                        </div>
                        <a
                            href="goals.php?tab=week&month=<?= urlencode((string) $weeklyPlanning['next_week_month']) ?>&week=<?= urlencode((string) $weeklyPlanning['next_week']) ?>"
                            data-week-switch="<?= htmlspecialchars((string) $weeklyPlanning['next_week'], ENT_QUOTES) ?>"
                            data-week-month="<?= htmlspecialchars((string) $weeklyPlanning['next_week_month'], ENT_QUOTES) ?>"
                            class="btn btn-sm btn-outline-secondary"
                        >
                            KW <?= (int) substr((string) $weeklyPlanning['next_week'], -2) ?> &rsaquo;
                        </a>
                    </div>

                    <div class="row g-2" role="tablist" aria-label="Kalenderwochenauswahl">
                        <?php foreach ($weeksWindow as $isoW => $wTab): ?>
                            <?php
                                $isWSelected = (bool) $wTab['is_selected'];
                                $wScheduled = (float) $wTab['scheduled_hours'];
                                $wSlots = (int) $wTab['slot_count'];

                                if ($isWSelected) {
                                    $wCardClasses = 'border-primary bg-primary text-white shadow-sm active';
                                } else {
                                    $wCardClasses = 'border-light-subtle bg-light text-dark';
                                }
                            ?>
                            <div class="col-6 col-sm-4 col-lg">
                                <a
                                    href="goals.php?tab=week&month=<?= urlencode($selectedMonth) ?>&week=<?= urlencode((string) $isoW) ?>"
                                    data-week-switch="<?= htmlspecialchars((string) $isoW, ENT_QUOTES) ?>"
                                    data-week-month="<?= htmlspecialchars((string) $selectedMonth, ENT_QUOTES) ?>"
                                    class="card h-100 text-decoration-none border week-tab-link <?= $wCardClasses ?>"
                                    role="tab"
                                    aria-selected="<?= $isWSelected ? 'true' : 'false' ?>"
                                >
                                    <div class="card-body p-2 text-center">
                                        <div class="d-flex flex-wrap justify-content-center align-items-center gap-1">
                                            <span class="fw-bold small"><?= htmlspecialchars((string) $wTab['label_short']) ?></span>
                                            <?php if ($wTab['is_current_week']): ?>
                                                <span class="badge <?= $isWSelected ? 'bg-light text-primary' : 'bg-primary-subtle text-primary' ?>" style="font-size: 0.65rem;">Aktuell</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small <?= $isWSelected ? 'text-white-50' : 'text-muted' ?>" style="font-size: 0.72rem;">
                                            <?= htmlspecialchars((string) $wTab['date_range_short']) ?>
                                        </div>
                                        <div class="mt-1">
                                            <span class="badge <?= $isWSelected ? 'bg-white text-primary' : ($wSlots > 0 ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary') ?>" style="font-size: 0.68rem;">
                                                <?= number_format($wScheduled, 1, ',', '.') ?> h &middot; <?= $wSlots ?> Slot<?= $wSlots === 1 ? '' : 's' ?>
                                            </span>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Detailansicht der aktiv ausgewählten Kalenderwoche -->
            <?php
                $wSumScheduled = (float) $weekSummary['scheduled_hours'];
                $wSumCompleted = (float) $weekSummary['completed_hours'];
                $wSumSlots = (int) $weekSummary['slot_count'];
                $wSumCompletedSlots = (int) $weekSummary['completed_slot_count'];
                $wHasOverlaps = (bool) $weekSummary['has_overlaps'];
                $monthTotalScheduled = (float) ($selectedMonthData['scheduled_hours'] ?? 0.0);
                $monthTotalPlanned = (float) $selectedMonthData['planned_hours'];
                $isMonthOverScheduled = $monthTotalPlanned > 0 && $monthTotalScheduled > $monthTotalPlanned;
            ?>
            <div class="card shadow-sm border mb-4">
                <div class="card-header bg-white py-3 px-4 border-bottom">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <h2 class="h4 fw-bold mb-1 d-flex align-items-center flex-wrap gap-2">
                                <span><?= htmlspecialchars((string) $selectedWeekData['label_long']) ?></span>
                                <?php if ($selectedWeekData['is_current_week']): ?>
                                    <span class="badge bg-primary-subtle text-primary fs-6 fw-normal">Aktuelle Woche</span>
                                <?php endif; ?>
                                <?php if ($wHasOverlaps): ?>
                                    <span class="badge text-bg-warning fs-6">
                                        Zeitüberschneidung bei Timeslots
                                    </span>
                                <?php endif; ?>
                            </h2>
                            <div class="text-muted small d-flex flex-wrap align-items-center gap-2">
                                <span>
                                    <strong class="text-primary"><?= number_format($wSumScheduled, 1, ',', '.') ?> h</strong> geplant (<?= $wSumSlots ?> Slot<?= $wSumSlots === 1 ? '' : 's' ?>)
                                </span>
                                <span>&middot;</span>
                                <span>
                                    <strong class="text-success"><?= number_format($wSumCompleted, 1, ',', '.') ?> h</strong> erledigt (<?= $wSumCompletedSlots ?>/<?= $wSumSlots ?>)
                                </span>
                                <span>&middot;</span>
                                <span class="<?= $isMonthOverScheduled ? 'text-warning-emphasis fw-semibold' : '' ?>">
                                    <?= htmlspecialchars((string) $selectedMonthData['label_short']) ?>:
                                    <strong><?= number_format($monthTotalScheduled, 1, ',', '.') ?> / <?= number_format($monthTotalPlanned, 1, ',', '.') ?> h</strong> terminiert
                                </span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <!-- Umschalter: Tagesfokus vs. Ganze Woche -->
                            <div class="btn-group btn-group-sm" role="group" aria-label="Darstellungsmodus der Woche">
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary active"
                                    data-week-view-mode-btn="day"
                                >
                                    Tagesfokus
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    data-week-view-mode-btn="week"
                                >
                                    Ganze Woche
                                </button>
                            </div>

                            <?php if (empty($selectableMonthlyGoals) && !empty($goals)): ?>
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm px-3 py-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#createMonthlyGoalModal"
                                    data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                                >
                                    + Zwischenziel für <?= htmlspecialchars((string) $selectedMonthData['label_short']) ?> anlegen
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card-body p-3 p-sm-4">
                    <!-- Einklappbare Übersicht der Zwischenziele des Monats -->
                    <?php if (empty($selectableMonthlyGoals)): ?>
                        <div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <strong class="text-dark d-block">Noch keine Zwischenziele für <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?> vorhanden</strong>
                                <span class="text-muted small">
                                    Lege zuerst ein Zwischenziel für <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?> an, um anschließend konkrete Lern-Timeslots in den Wochentagen einzuplanen.
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="border rounded bg-light-subtle mb-3">
                            <button
                                class="btn btn-link w-100 text-decoration-none text-dark d-flex justify-content-between align-items-center px-3 py-2 collapsed week-collapse-toggle"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#weekMonthlyGoalsCollapse"
                                aria-expanded="false"
                                aria-controls="weekMonthlyGoalsCollapse"
                            >
                                <span class="small fw-semibold">
                                    Zwischenziele im <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?>
                                    <span class="text-muted fw-normal ms-1">
                                        (<?= count($monthlyGoals) ?> Ziel<?= count($monthlyGoals) === 1 ? '' : 'e' ?> &middot; <?= number_format($monthTotalScheduled, 1, ',', '.') ?> von <?= number_format($monthTotalPlanned, 1, ',', '.') ?> h in Timeslots verplant)
                                    </span>
                                </span>
                                <span class="text-muted small d-flex align-items-center gap-1">
                                    <span class="d-none d-sm-inline">Details</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-chevron-down week-collapse-chevron" viewBox="0 0 16 16">
                                        <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708"/>
                                    </svg>
                                </span>
                            </button>
                            <div class="collapse" id="weekMonthlyGoalsCollapse">
                                <div class="p-3 pt-1 border-top">
                                    <div class="row g-2 mt-1">
                                        <?php foreach ($monthlyGoals as $mgItem): ?>
                                            <?php
                                                $mgItemPlanned = (float) $mgItem['planned_hours'];
                                                $mgItemSched = (float) ($mgItem['scheduled_hours'] ?? 0.0);
                                                $mgItemRem = round($mgItemPlanned - $mgItemSched, 2);
                                                $mgItemOver = $mgItemSched > $mgItemPlanned;
                                                $mgItemPct = $mgItemPlanned > 0 ? min(100.0, round(($mgItemSched / $mgItemPlanned) * 100, 1)) : 0.0;
                                            ?>
                                            <div class="col-md-6 col-xl-4">
                                                <div class="p-2 px-3 border rounded h-100 d-flex flex-column justify-content-between <?= $mgItemOver ? 'bg-warning-subtle border-warning' : 'bg-white' ?>">
                                                    <div>
                                                        <strong class="small text-dark d-block"><?= htmlspecialchars((string) $mgItem['title']) ?></strong>
                                                        <div class="text-muted" style="font-size: 0.74rem;">
                                                            <?= htmlspecialchars((string) $mgItem['parent_goal_title']) ?>
                                                        </div>
                                                    </div>
                                                    <div class="mt-2">
                                                        <div class="d-flex justify-content-between align-items-center small" style="font-size: 0.75rem;">
                                                            <span class="<?= $mgItemOver ? 'text-warning-emphasis fw-bold' : 'text-dark fw-semibold' ?>">
                                                                <?= number_format($mgItemSched, 1, ',', '.') ?> / <?= number_format($mgItemPlanned, 1, ',', '.') ?> h
                                                            </span>
                                                            <?php if ($mgItemOver): ?>
                                                                <span class="badge text-bg-warning">+<?= number_format(abs($mgItemRem), 1, ',', '.') ?> h</span>
                                                            <?php elseif ($mgItemRem > 0): ?>
                                                                <span class="text-muted">noch <?= number_format($mgItemRem, 1, ',', '.') ?> h offen</span>
                                                            <?php else: ?>
                                                                <span class="text-success fw-semibold">vollständig</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="progress mt-1" style="height: 4px;">
                                                            <div class="progress-bar <?= $mgItemOver ? 'bg-warning' : ($mgItemRem <= 0 ? 'bg-success' : 'bg-primary') ?>" style="width: <?= $mgItemPct ?>%"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Wochenband (Mo bis So) -->
                    <div class="week-day-strip mb-3" role="tablist" aria-label="Wochentagsauswahl">
                        <?php foreach ($weekDays as $day): ?>
                            <?php
                                $dayDate = (string) $day['date'];
                                $daySelected = (bool) ($day['is_selected'] ?? ($dayDate === $defaultSlotDate));
                                $dayToday = (bool) $day['is_today'];
                                $dayHours = (float) $day['scheduled_hours'];
                                $daySlotCount = (int) $day['slot_count'];
                                $dayOverlap = (bool) $day['has_overlap'];
                            ?>
                            <button
                                type="button"
                                class="week-day-strip-btn btn text-center p-2 border rounded <?= $daySelected ? 'active' : '' ?> <?= $dayToday ? 'is-today' : '' ?>"
                                data-day-select="<?= htmlspecialchars($dayDate, ENT_QUOTES) ?>"
                                role="tab"
                                aria-selected="<?= $daySelected ? 'true' : 'false' ?>"
                            >
                                <div class="d-flex justify-content-center align-items-center gap-1">
                                    <span class="fw-bold small"><?= htmlspecialchars((string) $day['day_name_short']) ?></span>
                                    <?php if ($dayToday): ?>
                                        <span class="week-day-today-dot" title="Heute"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="week-day-strip-date small"><?= htmlspecialchars((string) $day['date_formatted']) ?></div>
                                <div class="mt-1">
                                    <?php if ($daySlotCount > 0): ?>
                                        <span class="badge rounded-pill week-day-strip-badge <?= $dayOverlap ? 'text-bg-warning' : '' ?>">
                                            <?= number_format($dayHours, 1, ',', '.') ?> h
                                        </span>
                                    <?php else: ?>
                                        <span class="week-day-strip-empty small">&ndash;</span>
                                    <?php endif; ?>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Tages-Kacheln (im Tagesfokus nur der gewählte Tag sichtbar, in Ganze Woche alle 7 als kompakte Agenda) -->
                    <div class="d-flex flex-column gap-2" id="week-days-container">
                        <?php foreach ($weekDays as $day): ?>
                            <?php
                                $dayDate = (string) $day['date'];
                                $isSelectedDay = (bool) ($day['is_selected'] ?? ($dayDate === $defaultSlotDate));
                                $isToday = (bool) $day['is_today'];
                                $inMonth = (bool) $day['is_in_selected_month'];
                                $dayHours = (float) $day['scheduled_hours'];
                                $daySlotCount = (int) $day['slot_count'];
                                $dayHasOverlap = (bool) $day['has_overlap'];
                                $daySlots = $day['slots'];
                                $hasSlots = !empty($daySlots);
                                $collapseId = 'day-collapse-' . $dayDate;
                                // Im Standardmodus "Tagesfokus" ist der aktive Tag aufgeklappt;
                                // für die übrigen Tage sind nur Tage mit Slots standardmäßig aufgeklappt, leere Tage eingeklappt.
                                $isInitiallyExpanded = $isSelectedDay || $hasSlots;
                            ?>
                            <div
                                class="card week-day-card <?= $isToday ? 'border-primary-subtle' : ($dayHasOverlap ? 'border-warning' : 'border') ?> <?= !$inMonth ? 'bg-light-subtle' : 'bg-white' ?> <?= $isSelectedDay ? '' : 'd-none' ?>"
                                data-day-card="<?= htmlspecialchars($dayDate, ENT_QUOTES) ?>"
                                data-has-slots="<?= $hasSlots ? 'true' : 'false' ?>"
                            >
                                <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center <?= $isToday ? 'bg-primary-subtle border-primary-subtle' : 'bg-light-subtle' ?>">
                                    <div
                                        class="d-flex align-items-center flex-wrap gap-2 flex-grow-1 week-day-header-toggle <?= $isInitiallyExpanded ? '' : 'collapsed' ?>"
                                        role="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= htmlspecialchars($collapseId, ENT_QUOTES) ?>"
                                        aria-expanded="<?= $isInitiallyExpanded ? 'true' : 'false' ?>"
                                        aria-controls="<?= htmlspecialchars($collapseId, ENT_QUOTES) ?>"
                                    >
                                        <span class="fw-bold text-dark"><?= htmlspecialchars((string) $day['day_name_long']) ?></span>
                                        <span class="text-muted small"><?= htmlspecialchars((string) $day['date_full_formatted']) ?></span>
                                        <?php if ($isToday): ?>
                                            <span class="badge bg-primary text-white" style="font-size: 0.68rem;">Heute</span>
                                        <?php endif; ?>
                                        <?php if (!$inMonth): ?>
                                            <span class="badge bg-secondary-subtle text-secondary fw-normal" style="font-size: 0.68rem;">Nachbarmonat</span>
                                        <?php endif; ?>
                                        <?php if ($dayHasOverlap): ?>
                                            <span class="badge text-bg-warning" style="font-size: 0.68rem;">Zeitüberschneidung</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                        <?php if ($hasSlots): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold">
                                                <?= number_format($dayHours, 1, ',', '.') ?> h &middot; <?= $daySlotCount ?> Slot<?= $daySlotCount === 1 ? '' : 's' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">Keine Slots</span>
                                        <?php endif; ?>
                                        <?php if (!empty($selectableMonthlyGoals)): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary py-0 px-2 quick-add-slot-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#createSlotModal"
                                                data-date="<?= htmlspecialchars($dayDate, ENT_QUOTES) ?>"
                                                title="Lernzeit für <?= htmlspecialchars((string) $day['day_name_long']) ?> (<?= htmlspecialchars((string) $day['date_full_formatted']) ?>) planen"
                                            >
                                                Lernzeit planen
                                            </button>
                                        <?php endif; ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm p-0 border-0 text-muted week-day-header-toggle <?= $isInitiallyExpanded ? '' : 'collapsed' ?>"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#<?= htmlspecialchars($collapseId, ENT_QUOTES) ?>"
                                            aria-expanded="<?= $isInitiallyExpanded ? 'true' : 'false' ?>"
                                            aria-controls="<?= htmlspecialchars($collapseId, ENT_QUOTES) ?>"
                                            aria-label="Details für <?= htmlspecialchars((string) $day['day_name_long']) ?> ein- oder ausklappen"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-chevron-down week-collapse-chevron" viewBox="0 0 16 16">
                                                <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1 0-.708"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="collapse <?= $isInitiallyExpanded ? 'show' : '' ?>" id="<?= htmlspecialchars($collapseId, ENT_QUOTES) ?>">
                                    <div class="card-body p-2 p-sm-3 d-flex flex-column gap-2">
                                        <?php if (!$hasSlots): ?>
                                            <div class="text-muted small py-2 px-1 fst-italic">
                                                Für diesen Tag sind noch keine Lern-Timeslots eingeplant.
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($daySlots as $slot): ?>
                                                <?php
                                                    $slotCompleted = (int) $slot['is_completed'] === 1;
                                                    $slotOverlap = (bool) $slot['has_overlap'];
                                                    $slotDur = (float) $slot['duration_hours'];
                                                    $slotTimeRange = $slot['start_time_formatted'] . ' – ' . $slot['end_time_formatted'] . ' Uhr';
                                                ?>
                                                <div class="p-2 px-3 border rounded slot-item-card d-flex align-items-start justify-content-between gap-3 <?= $slotOverlap ? 'bg-warning-subtle border-warning' : ($slotCompleted ? 'bg-light text-muted' : 'bg-white') ?>">
                                                    <div class="d-flex align-items-start gap-3 flex-grow-1 min-w-0">
                                                        <!-- Ruhiger Status-Check-Button links -->
                                                        <form action="goals.php" method="POST" class="m-0 pt-1 flex-shrink-0" data-ajax-form>
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                                                            <input type="hidden" name="action" value="toggle_learning_slot">
                                                            <input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>">
                                                            <button
                                                                type="submit"
                                                                class="slot-check-btn <?= $slotCompleted ? 'is-completed' : '' ?>"
                                                                title="<?= $slotCompleted ? 'Als offen markieren' : 'Als erledigt abhaken' ?>"
                                                                aria-label="<?= $slotCompleted ? 'Als offen markieren' : 'Als erledigt abhaken' ?>"
                                                            >
                                                                <?php if ($slotCompleted): ?>
                                                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16">
                                                                        <path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425z"/>
                                                                    </svg>
                                                                <?php endif; ?>
                                                            </button>
                                                        </form>

                                                        <!-- Zeitspanne, Zwischenziel und optionale Notiz in der Mitte -->
                                                        <div class="flex-grow-1 min-w-0">
                                                            <div class="d-flex align-items-center flex-wrap gap-2 small">
                                                                <span class="fw-semibold <?= $slotCompleted ? 'text-muted' : 'text-primary' ?>">
                                                                    <?= htmlspecialchars($slotTimeRange) ?>
                                                                </span>
                                                                <span class="text-muted">&middot; <?= number_format($slotDur, 1, ',', '.') ?> h</span>
                                                                <?php if ($slotCompleted): ?>
                                                                    <span class="badge bg-success-subtle text-success fw-normal" style="font-size: 0.68rem;">Erledigt</span>
                                                                <?php endif; ?>
                                                                <?php if ($slotOverlap): ?>
                                                                    <span class="badge text-bg-warning" style="font-size: 0.68rem;" title="Dieser Timeslot überschneidet sich zeitlich mit einem anderen Slot an diesem Tag.">
                                                                        Kollision
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>

                                                            <div class="fw-semibold <?= $slotCompleted ? 'text-decoration-line-through text-muted' : 'text-dark' ?>">
                                                                <?= htmlspecialchars((string) $slot['monthly_goal_title']) ?>
                                                            </div>
                                                            <div class="text-muted small" style="font-size: 0.78rem;">
                                                                <?= htmlspecialchars((string) $slot['parent_goal_title']) ?>
                                                                <?php if (!empty($slot['notes'])): ?>
                                                                    <span class="mx-1">&middot;</span>
                                                                    <span class="fst-italic"><?= htmlspecialchars((string) $slot['notes']) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Dezentes Aktionsmenü (⋮) rechts -->
                                                    <div class="dropdown flex-shrink-0">
                                                        <button
                                                            class="btn btn-sm btn-link text-secondary p-1 text-decoration-none slot-action-menu-btn"
                                                            type="button"
                                                            data-bs-toggle="dropdown"
                                                            aria-expanded="false"
                                                            title="Aktionen für Timeslot"
                                                            aria-label="Aktionen für Timeslot"
                                                        >
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                                                <path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/>
                                                            </svg>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                            <li>
                                                                <button
                                                                    type="button"
                                                                    class="dropdown-item small edit-slot-btn"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#editSlotModal"
                                                                    data-id="<?= (int) $slot['id'] ?>"
                                                                    data-monthly-goal-id="<?= (int) $slot['monthly_goal_id'] ?>"
                                                                    data-date="<?= htmlspecialchars((string) $slot['slot_date'], ENT_QUOTES) ?>"
                                                                    data-start="<?= htmlspecialchars((string) $slot['start_time_formatted'], ENT_QUOTES) ?>"
                                                                    data-end="<?= htmlspecialchars((string) $slot['end_time_formatted'], ENT_QUOTES) ?>"
                                                                    data-notes="<?= htmlspecialchars((string) ($slot['notes'] ?? ''), ENT_QUOTES) ?>"
                                                                >
                                                                    Bearbeiten
                                                                </button>
                                                            </li>
                                                            <li>
                                                                <button
                                                                    type="button"
                                                                    class="dropdown-item small text-danger delete-slot-btn"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#deleteSlotModal"
                                                                    data-id="<?= (int) $slot['id'] ?>"
                                                                    data-summary="<?= htmlspecialchars((string) $day['day_name_long'] . ', ' . (string) $day['date_full_formatted'] . ' (' . $slotTimeRange . ') – ' . (string) $slot['monthly_goal_title'], ENT_QUOTES) ?>"
                                                                >
                                                                    Löschen
                                                                </button>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Modal: Lernziel erstellen -->
<div class="modal fade" id="createGoalModal" tabindex="-1" aria-labelledby="createGoalModalLabel" aria-hidden="true"<?= $failedAction === 'create' ? ' data-auto-open="true"' : '' ?>>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST" id="createGoalForm" data-ajax-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createGoalModalLabel">Neues Lernziel planen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div id="create-goal-alert" class="alert alert-danger<?= ($failedAction === 'create' && $errorMessage !== '') ? '' : ' d-none' ?>" role="alert">
                        <?php if ($failedAction === 'create' && $errorMessage !== ''): ?>
                            <strong>Fehler:</strong> <?= htmlspecialchars($errorMessage) ?>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="create_title" class="form-label fw-semibold">Lernziel <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control<?= ($failedAction === 'create' && $errorField === 'title') ? ' is-invalid' : '' ?>"
                            id="create_title"
                            name="title"
                            maxlength="150"
                            placeholder="z. B. Software Engineering"
                            value="<?= htmlspecialchars($createTitleVal) ?>"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="create_target_hours" class="form-label fw-semibold">Geplante Zielstunden <span class="text-danger">*</span></label>
                        <div class="input-group has-validation">
                            <input
                                type="number"
                                class="form-control<?= ($failedAction === 'create' && $errorField === 'target_hours') ? ' is-invalid' : '' ?>"
                                id="create_target_hours"
                                name="target_hours"
                                step="0.5"
                                min="0.5"
                                max="1500"
                                placeholder="z. B. 150"
                                value="<?= htmlspecialchars($createHoursVal) ?>"
                                required
                            >
                            <span class="input-group-text">Stunden</span>
                        </div>
                        <div class="form-text">Empfehlung für 5 ECTS: ca. 125 &ndash; 150 Stunden.</div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label for="create_start_date" class="form-label fw-semibold">Startdatum <span class="text-danger">*</span></label>
                            <input
                                type="date"
                                class="form-control<?= ($failedAction === 'create' && $errorField === 'start_date') ? ' is-invalid' : '' ?>"
                                id="create_start_date"
                                name="start_date"
                                value="<?= htmlspecialchars($createStartVal) ?>"
                                data-default="<?= htmlspecialchars($defaultStartDate) ?>"
                                required
                            >
                        </div>
                        <div class="col-sm-6">
                            <label for="create_end_date" class="form-label fw-semibold">Enddatum <span class="text-danger">*</span></label>
                            <input
                                type="date"
                                class="form-control<?= ($failedAction === 'create' && $errorField === 'end_date') ? ' is-invalid' : '' ?>"
                                id="create_end_date"
                                name="end_date"
                                value="<?= htmlspecialchars($createEndVal) ?>"
                                data-default="<?= htmlspecialchars($defaultEndDate) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="create_description" class="form-label fw-semibold">Notizen</label>
                        <textarea
                            class="form-control<?= ($failedAction === 'create' && $errorField === 'description') ? ' is-invalid' : '' ?>"
                            id="create_description"
                            name="description"
                            rows="3"
                            maxlength="2000"
                            placeholder="Optionale Notizen, Themenblöcke oder Klausurtermin..."
                        ><?= htmlspecialchars($createDescVal) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" id="create-goal-submit" class="btn btn-primary">Lernziel speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Lernziel bearbeiten -->
<div class="modal fade" id="editGoalModal" tabindex="-1" aria-labelledby="editGoalModalLabel" aria-hidden="true"<?= $failedAction === 'update' ? ' data-auto-open="true"' : '' ?>>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST" id="editGoalForm" data-ajax-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                <input type="hidden" name="goal_id" id="edit_goal_id" value="<?= htmlspecialchars($editIdVal) ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editGoalModalLabel">Lernziel bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div id="edit-goal-alert" class="alert alert-danger<?= ($failedAction === 'update' && $errorMessage !== '') ? '' : ' d-none' ?>" role="alert">
                        <?php if ($failedAction === 'update' && $errorMessage !== ''): ?>
                            <strong>Fehler:</strong> <?= htmlspecialchars($errorMessage) ?>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="edit_title" class="form-label fw-semibold">Lernziel <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control<?= ($failedAction === 'update' && $errorField === 'title') ? ' is-invalid' : '' ?>"
                            id="edit_title"
                            name="title"
                            maxlength="150"
                            value="<?= htmlspecialchars($editTitleVal) ?>"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="edit_target_hours" class="form-label fw-semibold">Geplante Zielstunden <span class="text-danger">*</span></label>
                        <div class="input-group has-validation">
                            <input
                                type="number"
                                class="form-control<?= ($failedAction === 'update' && $errorField === 'target_hours') ? ' is-invalid' : '' ?>"
                                id="edit_target_hours"
                                name="target_hours"
                                step="0.5"
                                min="0.5"
                                max="1500"
                                value="<?= htmlspecialchars($editHoursVal) ?>"
                                required
                            >
                            <span class="input-group-text">Stunden</span>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label for="edit_start_date" class="form-label fw-semibold">Startdatum <span class="text-danger">*</span></label>
                            <input
                                type="date"
                                class="form-control<?= ($failedAction === 'update' && $errorField === 'start_date') ? ' is-invalid' : '' ?>"
                                id="edit_start_date"
                                name="start_date"
                                value="<?= htmlspecialchars($editStartVal) ?>"
                                required
                            >
                        </div>
                        <div class="col-sm-6">
                            <label for="edit_end_date" class="form-label fw-semibold">Enddatum <span class="text-danger">*</span></label>
                            <input
                                type="date"
                                class="form-control<?= ($failedAction === 'update' && $errorField === 'end_date') ? ' is-invalid' : '' ?>"
                                id="edit_end_date"
                                name="end_date"
                                value="<?= htmlspecialchars($editEndVal) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-semibold">Notizen</label>
                        <textarea
                            class="form-control<?= ($failedAction === 'update' && $errorField === 'description') ? ' is-invalid' : '' ?>"
                            id="edit_description"
                            name="description"
                            rows="3"
                            maxlength="2000"
                        ><?= htmlspecialchars($editDescVal) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" id="edit-goal-submit" class="btn btn-primary">Änderungen speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Lernziel löschen -->
<div class="modal fade" id="deleteGoalModal" tabindex="-1" aria-labelledby="deleteGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                <input type="hidden" name="goal_id" id="delete_goal_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="deleteGoalModalLabel">Lernziel löschen?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Möchtest du das folgende Lernziel und alle zugehörigen Zwischenziele wirklich unwiderruflich löschen?
                    </p>
                    <p class="fw-bold text-dark mb-0" id="delete_goal_title"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">Unwiderruflich löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Monatliches Zeitbudget festlegen / ändern -->
<div class="modal fade" id="monthlyBudgetModal" tabindex="-1" aria-labelledby="monthlyBudgetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST" id="monthlyBudgetForm" data-ajax-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="save_monthly_budget">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="monthlyBudgetModalLabel">Monatliches Zeitbudget festlegen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div id="monthly-budget-alert" class="alert alert-danger d-none" role="alert"></div>

                    <div class="mb-3">
                        <label for="budget_year_month" class="form-label fw-semibold">Monat <span class="text-danger">*</span></label>
                        <input
                            type="month"
                            class="form-control"
                            id="budget_year_month"
                            name="year_month"
                            value="<?= htmlspecialchars($selectedMonth) ?>"
                            required
                        >
                    </div>
                    <div class="mb-3">
                        <label for="budget_hours_input" class="form-label fw-semibold">Verfügbares Zeitbudget im Monat</label>
                        <div class="input-group">
                            <input
                                type="number"
                                class="form-control"
                                id="budget_hours_input"
                                name="budget_hours"
                                step="0.5"
                                min="0"
                                max="744"
                                value="<?= $selectedMonthData['budget_hours'] !== null ? htmlspecialchars((string) $selectedMonthData['budget_hours']) : '' ?>"
                                placeholder="z. B. 40"
                            >
                            <span class="input-group-text">Stunden</span>
                        </div>
                        <div class="form-text">
                            Wie viele Stunden möchtest du in diesem Monat insgesamt für dein Studium aufwenden? Leere das Feld oder gib 0 ein, um das Budget zurückzusetzen.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Zeitbudget speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Zwischenziel erstellen -->
<div class="modal fade" id="createMonthlyGoalModal" tabindex="-1" aria-labelledby="createMonthlyGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST" id="createMonthlyGoalForm" data-ajax-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="create_monthly_goal">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createMonthlyGoalModalLabel">Zwischenziel planen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div id="create-mg-alert" class="alert alert-danger d-none" role="alert"></div>

                    <div class="mb-3">
                        <label for="create_mg_goal_id" class="form-label fw-semibold">Zugehöriges Lernziel <span class="text-danger">*</span></label>
                        <select class="form-select" id="create_mg_goal_id" name="goal_id" required>
                            <option value="" disabled selected>Bitte Lernziel wählen…</option>
                            <?php foreach ($goals as $gOption): ?>
                                <?php
                                    $optAlloc = (float) ($gOption['allocated_hours'] ?? 0.0);
                                    $optTarget = (float) $gOption['target_hours'];
                                ?>
                                <option value="<?= (int) $gOption['id'] ?>">
                                    <?= htmlspecialchars((string) $gOption['title']) ?> &ndash; <?= number_format($optAlloc, 1, ',', '.') ?> von <?= number_format($optTarget, 1, ',', '.') ?> h verplant
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label for="create_mg_year_month" class="form-label fw-semibold">Monat <span class="text-danger">*</span></label>
                            <input
                                type="month"
                                class="form-control"
                                id="create_mg_year_month"
                                name="year_month"
                                value="<?= htmlspecialchars($selectedMonth) ?>"
                                required
                            >
                        </div>
                        <div class="col-sm-6">
                            <label for="create_mg_planned_hours" class="form-label fw-semibold">Lernzeit im Monat <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input
                                    type="number"
                                    class="form-control"
                                    id="create_mg_planned_hours"
                                    name="planned_hours"
                                    step="0.5"
                                    min="0.5"
                                    max="744"
                                    placeholder="z. B. 15"
                                    required
                                >
                                <span class="input-group-text">Std.</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="create_mg_title" class="form-label fw-semibold">Zwischenziel <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="create_mg_title"
                            name="title"
                            maxlength="150"
                            placeholder="z. B. Skript Kapitel 1 bis 3 durcharbeiten"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="create_mg_description" class="form-label fw-semibold">Notizen</label>
                        <textarea
                            class="form-control"
                            id="create_mg_description"
                            name="description"
                            rows="2"
                            maxlength="2000"
                            placeholder="Optionale Notizen für diesen Monat..."
                        ></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Zwischenziel speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Zwischenziel bearbeiten -->
<div class="modal fade" id="editMonthlyGoalModal" tabindex="-1" aria-labelledby="editMonthlyGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST" id="editMonthlyGoalForm" data-ajax-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="update_monthly_goal">
                <input type="hidden" name="monthly_goal_id" id="edit_mg_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editMonthlyGoalModalLabel">Zwischenziel bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div id="edit-mg-alert" class="alert alert-danger d-none" role="alert"></div>

                    <div class="mb-3">
                        <label for="edit_mg_goal_id" class="form-label fw-semibold">Zugehöriges Lernziel <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_mg_goal_id" name="goal_id" required>
                            <?php foreach ($goals as $gOption): ?>
                                <option value="<?= (int) $gOption['id'] ?>">
                                    <?= htmlspecialchars((string) $gOption['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label for="edit_mg_year_month" class="form-label fw-semibold">Monat <span class="text-danger">*</span></label>
                            <input
                                type="month"
                                class="form-control"
                                id="edit_mg_year_month"
                                name="year_month"
                                required
                            >
                        </div>
                        <div class="col-sm-6">
                            <label for="edit_mg_planned_hours" class="form-label fw-semibold">Lernzeit im Monat <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input
                                    type="number"
                                    class="form-control"
                                    id="edit_mg_planned_hours"
                                    name="planned_hours"
                                    step="0.5"
                                    min="0.5"
                                    max="744"
                                    required
                                >
                                <span class="input-group-text">Std.</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_mg_title" class="form-label fw-semibold">Zwischenziel <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="edit_mg_title"
                            name="title"
                            maxlength="150"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="edit_mg_description" class="form-label fw-semibold">Notizen</label>
                        <textarea
                            class="form-control"
                            id="edit_mg_description"
                            name="description"
                            rows="2"
                            maxlength="2000"
                        ></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Zwischenziel löschen -->
<div class="modal fade" id="deleteMonthlyGoalModal" tabindex="-1" aria-labelledby="deleteMonthlyGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="delete_monthly_goal">
                <input type="hidden" name="monthly_goal_id" id="delete_mg_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="deleteMonthlyGoalModalLabel">Zwischenziel löschen?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Möchtest du das folgende Zwischenziel und alle zugehörigen Lern-Timeslots wirklich löschen?
                    </p>
                    <p class="fw-bold text-dark mb-0" id="delete_mg_title"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">Zwischenziel löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Lernzeit planen -->
<div class="modal fade" id="createSlotModal" tabindex="-1" aria-labelledby="createSlotModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST" id="createSlotForm" data-ajax-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="create_learning_slot">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createSlotModalLabel">Lernzeit planen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div id="create-slot-alert" class="alert alert-danger d-none" role="alert"></div>

                    <div class="mb-3">
                        <label for="create_slot_monthly_goal_id" class="form-label fw-semibold">Monatliches Zwischenziel <span class="text-danger">*</span></label>
                        <select class="form-select" id="create_slot_monthly_goal_id" name="monthly_goal_id" required>
                            <option value="" disabled selected>Bitte Zwischenziel wählen…</option>
                            <?php foreach ($selectableMonthlyGoals as $mgOpt): ?>
                                <?php
                                    $mgOptSched = (float) ($mgOpt['scheduled_hours'] ?? 0.0);
                                    $mgOptPlan = (float) $mgOpt['planned_hours'];
                                    $mgOptMonthPrefix = ((string) $mgOpt['plan_month'] !== $selectedMonth)
                                        ? '[' . GoalController::formatYearMonth((string) $mgOpt['plan_month'], true) . '] '
                                        : '';
                                ?>
                                <option value="<?= (int) $mgOpt['id'] ?>">
                                    <?= htmlspecialchars($mgOptMonthPrefix . (string) $mgOpt['title'] . ' (' . (string) $mgOpt['parent_goal_title'] . ')') ?> &ndash; <?= number_format($mgOptSched, 1, ',', '.') ?> / <?= number_format($mgOptPlan, 1, ',', '.') ?> h
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="create_slot_date" class="form-label fw-semibold">Datum <span class="text-danger">*</span></label>
                        <input
                            type="date"
                            class="form-control"
                            id="create_slot_date"
                            name="slot_date"
                            value="<?= htmlspecialchars($defaultSlotDate) ?>"
                            data-default="<?= htmlspecialchars($defaultSlotDate) ?>"
                            required
                        >
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Startzeit <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select
                                    class="form-select text-center slot-hour-select"
                                    id="create_slot_start_hour"
                                    aria-label="Startzeit Stunden"
                                    required
                                >
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?= sprintf('%02d', $h) ?>" <?= $h === $initStartHour ? 'selected' : '' ?>>
                                            <?= sprintf('%02d', $h) ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text fw-bold px-2">:</span>
                                <select
                                    class="form-select text-center slot-minute-select"
                                    id="create_slot_start_minute"
                                    aria-label="Startzeit Minuten"
                                    required
                                >
                                    <?php for ($m = 0; $m < 60; $m += 10): ?>
                                        <option value="<?= sprintf('%02d', $m) ?>" <?= $m === $initStartMinute ? 'selected' : '' ?>>
                                            <?= sprintf('%02d', $m) ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text text-muted small">Uhr</span>
                            </div>
                            <input type="hidden" name="start_time" id="create_slot_start_time" value="<?= $initStartTime ?>" data-default="<?= $initStartTime ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Endzeit <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select
                                    class="form-select text-center slot-hour-select"
                                    id="create_slot_end_hour"
                                    aria-label="Endzeit Stunden"
                                    required
                                >
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?= sprintf('%02d', $h) ?>" <?= $h === $initEndHour ? 'selected' : '' ?>>
                                            <?= sprintf('%02d', $h) ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text fw-bold px-2">:</span>
                                <select
                                    class="form-select text-center slot-minute-select"
                                    id="create_slot_end_minute"
                                    aria-label="Endzeit Minuten"
                                    required
                                >
                                    <?php for ($m = 0; $m < 60; $m += 10): ?>
                                        <option value="<?= sprintf('%02d', $m) ?>" <?= $m === $initEndMinute ? 'selected' : '' ?>>
                                            <?= sprintf('%02d', $m) ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text text-muted small">Uhr</span>
                            </div>
                            <input type="hidden" name="end_time" id="create_slot_end_time" value="<?= $initEndTime ?>" data-default="<?= $initEndTime ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-text" id="create_slot_duration_hint">
                            Geplante Dauer: <strong><?= $initDurationMinutes ?> Minuten</strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="create_slot_notes" class="form-label fw-semibold">Notiz</label>
                        <input
                            type="text"
                            class="form-control"
                            id="create_slot_notes"
                            name="notes"
                            maxlength="255"
                            placeholder="z. B. Kapitel 3 wiederholen"
                        >
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Lernzeit planen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Lernzeit bearbeiten -->
<div class="modal fade" id="editSlotModal" tabindex="-1" aria-labelledby="editSlotModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST" id="editSlotForm" data-ajax-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="update_learning_slot">
                <input type="hidden" name="slot_id" id="edit_slot_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editSlotModalLabel">Lernzeit bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div id="edit-slot-alert" class="alert alert-danger d-none" role="alert"></div>

                    <div class="mb-3">
                        <label for="edit_slot_monthly_goal_id" class="form-label fw-semibold">Monatliches Zwischenziel <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_slot_monthly_goal_id" name="monthly_goal_id" required>
                            <?php foreach ($selectableMonthlyGoals as $mgOpt): ?>
                                <?php
                                    $mgOptMonthPrefix = ((string) $mgOpt['plan_month'] !== $selectedMonth)
                                        ? '[' . GoalController::formatYearMonth((string) $mgOpt['plan_month'], true) . '] '
                                        : '';
                                ?>
                                <option value="<?= (int) $mgOpt['id'] ?>">
                                    <?= htmlspecialchars($mgOptMonthPrefix . (string) $mgOpt['title'] . ' (' . (string) $mgOpt['parent_goal_title'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="edit_slot_date" class="form-label fw-semibold">Datum <span class="text-danger">*</span></label>
                        <input
                            type="date"
                            class="form-control"
                            id="edit_slot_date"
                            name="slot_date"
                            required
                        >
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Startzeit <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select
                                    class="form-select text-center slot-hour-select"
                                    id="edit_slot_start_hour"
                                    aria-label="Startzeit Stunden"
                                    required
                                >
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?= sprintf('%02d', $h) ?>"><?= sprintf('%02d', $h) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text fw-bold px-2">:</span>
                                <select
                                    class="form-select text-center slot-minute-select"
                                    id="edit_slot_start_minute"
                                    aria-label="Startzeit Minuten"
                                    required
                                >
                                    <?php for ($m = 0; $m < 60; $m += 10): ?>
                                        <option value="<?= sprintf('%02d', $m) ?>"><?= sprintf('%02d', $m) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text text-muted small">Uhr</span>
                            </div>
                            <input type="hidden" name="start_time" id="edit_slot_start_time" value="">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Endzeit <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select
                                    class="form-select text-center slot-hour-select"
                                    id="edit_slot_end_hour"
                                    aria-label="Endzeit Stunden"
                                    required
                                >
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?= sprintf('%02d', $h) ?>"><?= sprintf('%02d', $h) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text fw-bold px-2">:</span>
                                <select
                                    class="form-select text-center slot-minute-select"
                                    id="edit_slot_end_minute"
                                    aria-label="Endzeit Minuten"
                                    required
                                >
                                    <?php for ($m = 0; $m < 60; $m += 10): ?>
                                        <option value="<?= sprintf('%02d', $m) ?>"><?= sprintf('%02d', $m) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text text-muted small">Uhr</span>
                            </div>
                            <input type="hidden" name="end_time" id="edit_slot_end_time" value="">
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-text" id="edit_slot_duration_hint"></div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_slot_notes" class="form-label fw-semibold">Notiz</label>
                        <input
                            type="text"
                            class="form-control"
                            id="edit_slot_notes"
                            name="notes"
                            maxlength="255"
                            placeholder="z. B. Kapitel 3 wiederholen"
                        >
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Lernzeit löschen -->
<div class="modal fade" id="deleteSlotModal" tabindex="-1" aria-labelledby="deleteSlotModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST" data-ajax-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="delete_learning_slot">
                <input type="hidden" name="slot_id" id="delete_slot_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="deleteSlotModalLabel">Lernzeit löschen?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div id="delete-slot-alert" class="alert alert-danger d-none" role="alert"></div>
                    <p class="mb-2">
                        Möchtest du diese geplante Lernzeit wirklich löschen?
                    </p>
                    <p class="fw-bold text-dark mb-0" id="delete_slot_summary"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">Lernzeit löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = '<script src="assets/js/goals.js?v=' . filemtime(__DIR__ . '/assets/js/goals.js') . '"></script>';
require_once __DIR__ . '/includes/footer.php';
?>

