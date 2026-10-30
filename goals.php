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
            $_SESSION['goals_success'] = 'Die Lerneinheit wurde erfolgreich eingeplant.';
            header('Location: goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']) . '&day=' . urlencode($slotResult['slot_date']));
            exit;
        } elseif ($action === 'update_learning_slot') {
            $slotId = isset($_POST['slot_id']) && is_scalar($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
            $slotResult = $goalController->updateLearningSlot($slotId, $userId, $_POST);
            $_SESSION['goals_success'] = 'Die Lerneinheit wurde erfolgreich aktualisiert.';
            header('Location: goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']) . '&day=' . urlencode($slotResult['slot_date']));
            exit;
        } elseif ($action === 'delete_learning_slot') {
            $slotId = isset($_POST['slot_id']) && is_scalar($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
            $slotResult = $goalController->deleteLearningSlot($slotId, $userId);
            $_SESSION['goals_success'] = 'Die Lerneinheit wurde erfolgreich gelöscht.';
            header('Location: goals.php?tab=week&month=' . urlencode($slotResult['year_month']) . '&week=' . urlencode($slotResult['iso_week']) . '&day=' . urlencode($slotResult['slot_date']));
            exit;
        } elseif ($action === 'toggle_learning_slot') {
            $slotId = isset($_POST['slot_id']) && is_scalar($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
            $slotResult = $goalController->toggleLearningSlotCompleted($slotId, $userId);
            $_SESSION['goals_success'] = $slotResult['is_completed']
                ? 'Die Lerneinheit wurde als erledigt markiert.'
                : 'Die Lerneinheit wurde wieder als offen markiert.';
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

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="h3 fw-bold text-primary mb-1">Planung</h1>
        <p class="text-muted mb-0">
            Setze dir Lernziele, verteile sie auf die Monate und plane deine Lernzeiten in der Woche.
        </p>
    </div>
    <!-- Nur in der Semesteransicht sichtbar; goals.js blendet ihn beim Tabwechsel ein/aus -->
    <button type="button" class="btn btn-primary <?= $activeViewTab === 'semester' ? '' : 'd-none' ?>" id="createGoalBtn" data-bs-toggle="modal" data-bs-target="#createGoalModal">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-plus-lg me-1 align-text-bottom" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2" />
        </svg>
        Neues Lernziel
    </button>
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

<!-- Hauptansicht-Umschalter: Semester vs. Monat vs. Woche -->
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
            aria-selected="<?= $activeViewTab === 'semester' ? 'true' : 'false' ?>">
            Semester
            <span class="badge rounded-pill bg-secondary-subtle text-secondary ms-1" title="Anzahl Lernziele"><?= count($goals) ?></span>
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
            aria-selected="<?= $activeViewTab === 'month' ? 'true' : 'false' ?>">
            Monat
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
            aria-selected="<?= $activeViewTab === 'week' ? 'true' : 'false' ?>">
            Woche
            <span class="badge rounded-pill bg-secondary-subtle text-secondary ms-1" id="tab-week-badge"><?= htmlspecialchars((string) $selectedWeekData['label_short']) ?></span>
        </button>
    </li>
</ul>

<div class="tab-content" id="goalsMainTabContent">
    <!-- ================================================================== -->
    <!-- ANSICHT 1: Semester (Lernziele für 6 Monate)                       -->
    <!-- ================================================================== -->
    <div
        class="tab-pane fade <?= $activeViewTab === 'semester' ? 'show active' : '' ?>"
        id="pane-semester"
        role="tabpanel"
        aria-labelledby="tab-semester-btn"
        tabindex="0">
        <?php if (empty($goals)): ?>
            <div class="card shadow-sm border-0 text-center py-5 mb-4">
                <div class="card-body">
                    <div class="mb-3 text-muted">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-calendar2-check text-primary" viewBox="0 0 16 16">
                            <path d="M10.854 8.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 0 1 .708-.708L7.5 10.793l2.646-2.647a.5.5 0 0 1 .708 0" />
                            <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M2 2a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1z" />
                            <path d="M2.5 4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5H3a.5.5 0 0 1-.5-.5z" />
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
            <!-- Kurze Zusammenfassung über alle Lernziele -->
            <p class="text-muted mb-3">
                <strong class="text-dark"><?= count($goals) ?> Lernziel<?= count($goals) === 1 ? '' : 'e' ?></strong>
                mit insgesamt <strong class="text-primary"><?= number_format($totalHours, 1, ',', '.') ?> h</strong> &middot;
                davon <strong class="<?= $isTotalOverAllocated ? 'text-warning-emphasis' : 'text-dark' ?>"><?= number_format($totalPlannedAllMonths, 1, ',', '.') ?> h</strong> auf Monate verteilt
                <?php if ($isTotalOverAllocated): ?>
                    <span class="badge text-bg-warning ms-1">
                        +<?= number_format($totalPlannedAllMonths - $totalHours, 1, ',', '.') ?> h über Zielwert
                    </span>
                <?php endif; ?>
            </p>

            <div class="card shadow-sm border goals-table-card mb-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-mobile-cards">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Lernziel</th>
                                <th scope="col" style="min-width: 220px;">Auf Monate verteilt</th>
                                <th scope="col">Zeitraum</th>
                                <th scope="col" class="text-end"><span class="visually-hidden">Aktionen</span></th>
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
                                    <td class="td-mobile-block" data-label="Auf Monate verteilt">
                                        <div class="d-flex justify-content-between align-items-baseline small mb-1">
                                            <span>
                                                <span class="<?= $allocTextClass ?>"><?= number_format($allocatedHoursRow, 1, ',', '.') ?></span>
                                                <span class="text-muted">von <?= number_format($targetHoursRow, 1, ',', '.') ?> h</span>
                                            </span>
                                            <span class="<?= $isGoalOverAllocated ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>">
                                                <?= number_format($allocPercent, 0, ',', '.') ?> %
                                            </span>
                                        </div>
                                        <div class="progress" style="height: 6px;" role="progressbar" aria-label="Auf Monate verteilte Stunden" aria-valuenow="<?= (int) $allocBarWidth ?>" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar <?= $allocBarClass ?>" style="width: <?= $allocBarWidth ?>%"></div>
                                        </div>
                                        <div class="mt-1 small text-muted">
                                            <?= $monthlyGoalCount ?> Zwischenziel<?= $monthlyGoalCount === 1 ? '' : 'e' ?>
                                            <?php if ($isGoalOverAllocated): ?>
                                                &middot; <span class="text-warning-emphasis fw-semibold">+<?= number_format($allocatedHoursRow - $targetHoursRow, 1, ',', '.') ?> h über Ziel</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Zeitraum">
                                        <div class="small fw-semibold text-dark">
                                            <?= $startFormatted ?> &ndash; <?= $endFormatted ?>
                                        </div>
                                        <div class="text-muted small"><?= $durationWeeks ?> Wochen</div>
                                    </td>
                                    <td class="text-end td-mobile-actions" data-label="Aktionen">
                                        <div class="d-flex justify-content-end align-items-center gap-1">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary text-nowrap quick-add-monthly-goal-btn"
                                                title="Zwischenziel für dieses Lernziel anlegen"
                                                data-bs-toggle="modal"
                                                data-bs-target="#createMonthlyGoalModal"
                                                data-goal-id="<?= (int) $goal['id'] ?>"
                                                data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>">
                                                + Zwischenziel
                                            </button>
                                            <div class="dropdown">
                                                <button
                                                    class="btn btn-sm btn-link text-secondary p-1 text-decoration-none row-action-menu-btn"
                                                    type="button"
                                                    data-bs-toggle="dropdown"
                                                    aria-expanded="false"
                                                    title="Weitere Aktionen"
                                                    aria-label="Weitere Aktionen für Lernziel">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                                        <path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0" />
                                                    </svg>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                    <li>
                                                        <button
                                                            type="button"
                                                            class="dropdown-item small edit-goal-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editGoalModal"
                                                            data-id="<?= (int) $goal['id'] ?>"
                                                            data-title="<?= htmlspecialchars((string) $goal['title'], ENT_QUOTES) ?>"
                                                            data-hours="<?= htmlspecialchars((string) $goal['target_hours'], ENT_QUOTES) ?>"
                                                            data-start="<?= htmlspecialchars((string) $goal['start_date'], ENT_QUOTES) ?>"
                                                            data-end="<?= htmlspecialchars((string) $goal['end_date'], ENT_QUOTES) ?>"
                                                            data-description="<?= htmlspecialchars((string) ($goal['description'] ?? ''), ENT_QUOTES) ?>">
                                                            Bearbeiten
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button
                                                            type="button"
                                                            class="dropdown-item small text-danger delete-goal-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#deleteGoalModal"
                                                            data-id="<?= (int) $goal['id'] ?>"
                                                            data-title="<?= htmlspecialchars((string) $goal['title'], ENT_QUOTES) ?>">
                                                            Löschen
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
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
    <!-- ANSICHT 2: Monat (Zeitbudget & Zwischenziele je Monat)             -->
    <!-- ================================================================== -->
    <div
        class="tab-pane fade <?= $activeViewTab === 'month' ? 'show active' : '' ?>"
        id="pane-month"
        role="tabpanel"
        aria-labelledby="tab-month-btn"
        tabindex="0">
        <section id="monatsplanung" class="mb-4" data-selected-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>" data-selected-month-short="<?= htmlspecialchars((string) $selectedMonthData['label_short'], ENT_QUOTES) ?>">
            <!-- Monatsauswahl: Pfeile links/rechts verschieben den Zeitraum um einen Monat -->
            <div class="d-flex align-items-stretch gap-2 mb-3">
                <a
                    href="goals.php?tab=month&month=<?= urlencode((string) $monthlyPlanning['prev_month']) ?>"
                    data-month-switch="<?= htmlspecialchars((string) $monthlyPlanning['prev_month'], ENT_QUOTES) ?>"
                    class="btn btn-light border period-nav-btn"
                    title="Vorheriger Monat: <?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['prev_month'], false)) ?>"
                    aria-label="Vorheriger Monat: <?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['prev_month'], false)) ?>">
                    &lsaquo;
                </a>
                <div class="row g-2 flex-grow-1" role="tablist" aria-label="Monatsauswahl">
                    <?php foreach ($monthsWindow as $ym => $mTab): ?>
                        <?php
                        $isTabSelected = (bool) $mTab['is_selected'];
                        $isTabOverBudget = (bool) $mTab['is_over_budget'];
                        $tabPlanned = (float) $mTab['planned_hours'];
                        $tabBudget = $mTab['budget_hours'];
                        $tabWorkloadShare = (float) $mTab['workload_share_percent'];
                        $tabUtil = ($tabBudget !== null && (float) $tabBudget > 0) ? min(100.0, $tabPlanned / (float) $tabBudget * 100) : 0.0;

                        if ($isTabSelected) {
                            $tabCardClasses = $isTabOverBudget
                                ? 'border-warning bg-warning-subtle text-dark shadow-sm active'
                                : 'border-primary bg-primary text-white shadow-sm active';
                        } else {
                            $tabCardClasses = $isTabOverBudget
                                ? 'border-warning bg-warning-subtle text-dark'
                                : 'border-light-subtle bg-white text-dark';
                        }
                        ?>
                        <div class="col-4 col-lg-2">
                            <a
                                href="goals.php?tab=month&month=<?= urlencode((string) $ym) ?>"
                                data-month-switch="<?= htmlspecialchars((string) $ym, ENT_QUOTES) ?>"
                                class="card h-100 text-decoration-none border month-tab-link <?= $tabCardClasses ?>"
                                role="tab"
                                aria-selected="<?= $isTabSelected ? 'true' : 'false' ?>"
                                title="<?= number_format($tabWorkloadShare, 0, ',', '.') ?> % deiner gesamten Planung">
                                <div class="card-body p-2 text-center">
                                    <div class="d-flex justify-content-center align-items-center gap-1">
                                        <span class="fw-bold small"><?= htmlspecialchars((string) $mTab['label_short']) ?></span>
                                        <?php if ($mTab['is_current_calendar_month']): ?>
                                            <span class="period-today-dot" title="Aktueller Monat"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small <?= $isTabSelected && !$isTabOverBudget ? 'text-white' : ($isTabOverBudget ? 'text-warning-emphasis fw-bold' : 'text-muted') ?>" style="font-size: 0.78rem;">
                                        <?= number_format($tabPlanned, 1, ',', '.') ?><?= $tabBudget !== null ? ' / ' . number_format((float) $tabBudget, 1, ',', '.') : '' ?> h
                                    </div>
                                    <div class="progress period-mini-progress mt-1" role="presentation">
                                        <div class="progress-bar <?= $isTabOverBudget ? 'bg-warning' : '' ?>" style="width: <?= $isTabOverBudget ? 100 : $tabUtil ?>%"></div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a
                    href="goals.php?tab=month&month=<?= urlencode((string) $monthlyPlanning['next_month']) ?>"
                    data-month-switch="<?= htmlspecialchars((string) $monthlyPlanning['next_month'], ENT_QUOTES) ?>"
                    class="btn btn-light border period-nav-btn"
                    title="Nächster Monat: <?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['next_month'], false)) ?>"
                    aria-label="Nächster Monat: <?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['next_month'], false)) ?>">
                    &rsaquo;
                </a>
            </div>

            <!-- Detailansicht des aktiv ausgewählten Monats -->
            <?php
            $selBudget = $selectedMonthData['budget_hours'];
            $selPlanned = (float) $selectedMonthData['planned_hours'];
            $selRemaining = $selectedMonthData['remaining_budget_hours'];
            $selUtilPercent = $selectedMonthData['budget_utilization_percent'];
            $selIsOverBudget = (bool) $selectedMonthData['is_over_budget'];
            $selWorkloadShare = (float) $selectedMonthData['workload_share_percent'];
            $utilBarWidth = $selUtilPercent !== null ? min(100.0, (float) $selUtilPercent) : ($selPlanned > 0 ? 100.0 : 0.0);
            $utilBarClass = $selIsOverBudget ? 'bg-warning' : ($selUtilPercent !== null && $selUtilPercent >= 90.0 ? 'bg-success' : 'bg-primary');
            ?>
            <div class="card shadow-sm border mb-4">
                <div class="card-header bg-white py-3 px-3 px-sm-4 border-bottom">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h2 class="h4 fw-bold mb-0 d-flex align-items-center flex-wrap gap-2">
                            <span><?= htmlspecialchars((string) $selectedMonthData['label_long']) ?></span>
                            <?php if ($selectedMonthData['is_current_calendar_month']): ?>
                                <span class="badge bg-primary-subtle text-primary fs-6 fw-normal">Aktueller Monat</span>
                            <?php endif; ?>
                        </h2>
                        <?php if (!empty($goals)): ?>
                            <button
                                type="button"
                                class="btn btn-primary"
                                data-bs-toggle="modal"
                                data-bs-target="#createMonthlyGoalModal"
                                data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>">
                                + Zwischenziel
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body p-3 p-sm-4">
                    <!-- Zeitbudget, Auslastung und Anteil an der Gesamtplanung in einem Block -->
                    <div class="row g-3 align-items-center p-3 mb-4 mx-0 border rounded <?= $selIsOverBudget ? 'bg-warning-subtle border-warning' : 'bg-light' ?>">
                        <div class="col-md-8 ps-0">
                            <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
                                <div>
                                    <span class="h4 fw-bold <?= $selIsOverBudget ? 'text-warning-emphasis' : 'text-primary' ?> mb-0"><?= number_format($selPlanned, 1, ',', '.') ?> h</span>
                                    <span class="text-muted">
                                        <?php if ($selBudget !== null): ?>
                                            von <?= number_format((float) $selBudget, 1, ',', '.') ?> h Zeitbudget verplant
                                        <?php else: ?>
                                            verplant
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($selUtilPercent !== null): ?>
                                    <span class="small fw-semibold <?= $selIsOverBudget ? 'text-warning-emphasis' : 'text-muted' ?>">
                                        <?= number_format((float) $selUtilPercent, 1, ',', '.') ?> %
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="progress" style="height: 8px;" role="progressbar" aria-label="Auslastung des Zeitbudgets" aria-valuenow="<?= (int) $utilBarWidth ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar <?= $utilBarClass ?>" style="width: <?= $utilBarWidth ?>%"></div>
                            </div>
                            <div class="small mt-2 d-flex flex-wrap align-items-center gap-2">
                                <?php if ($selBudget === null): ?>
                                    <span class="text-muted fst-italic">Noch kein Zeitbudget festgelegt.</span>
                                <?php elseif ($selIsOverBudget): ?>
                                    <span class="text-warning-emphasis fw-semibold">Zeitbudget um <?= number_format(abs((float) $selRemaining), 1, ',', '.') ?> h überschritten</span>
                                <?php else: ?>
                                    <span class="text-success fw-semibold">Noch <?= number_format((float) $selRemaining, 1, ',', '.') ?> h frei</span>
                                <?php endif; ?>
                                <span class="text-muted">&middot;</span>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm p-0 text-decoration-none align-baseline"
                                    data-bs-toggle="modal"
                                    data-bs-target="#monthlyBudgetModal"
                                    data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                                    data-month-label="<?= htmlspecialchars((string) $selectedMonthData['label_long'], ENT_QUOTES) ?>"
                                    data-budget="<?= htmlspecialchars((string) ($selBudget ?? ''), ENT_QUOTES) ?>">
                                    <?= $selBudget !== null ? 'Zeitbudget ändern' : 'Zeitbudget festlegen' ?>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4 month-share-col small text-muted">
                            <span class="fs-5 fw-bold text-dark"><?= number_format($selWorkloadShare, 1, ',', '.') ?> %</span>
                            deiner gesamten Planung
                            <div><?= number_format($selPlanned, 1, ',', '.') ?> von <?= number_format($totalPlannedAllMonths, 1, ',', '.') ?> h &middot; gleichmäßig wären ca. 16,7 %</div>
                        </div>
                    </div>

                    <!-- Zwischenziele im ausgewählten Monat -->
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
                                    data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>">
                                    Erstes Zwischenziel anlegen
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive goals-table-frame">
                            <table class="table table-hover align-middle mb-0 table-mobile-cards">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Zwischenziel</th>
                                        <th scope="col" class="text-end">Geplant</th>
                                        <th scope="col" style="min-width: 180px;">In der Woche eingeplant</th>
                                        <th scope="col" class="text-end"><span class="visually-hidden">Aktionen</span></th>
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
                                        $mgTrackedHours = (float) ($mGoal['tracked_hours'] ?? 0.0);
                                        $mgTrackedSeconds = (int) ($mGoal['tracked_seconds'] ?? 0);
                                        $mgTrackedPct = (float) ($mGoal['tracked_percent'] ?? 0.0);
                                        $mgCompleted = (bool) ($mGoal['is_completed'] ?? false);
                                        $mgSlotCount = (int) ($mGoal['slot_count'] ?? 0);
                                        $isMgOverScheduled = (bool) ($mGoal['is_over_scheduled'] ?? false);
                                        $mgSchedPct = $mgHours > 0 ? min(100.0, $mgScheduled / $mgHours * 100) : 0.0;
                                        ?>
                                        <tr class="<?= ($isParentOver || $isMgOverScheduled) ? 'table-warning' : '' ?>">
                                            <td class="td-mobile-header" data-label="Zwischenziel">
                                                <div class="d-flex align-items-center flex-wrap gap-2">
                                                    <strong class="text-dark"><?= htmlspecialchars((string) $mGoal['title']) ?></strong>
                                                    <?php if ($mgCompleted): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.7rem;">
                                                            &#10003; Erledigt
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($mGoal['description'])): ?>
                                                    <span class="text-muted small d-block"><?= nl2br(htmlspecialchars((string) $mGoal['description'])) ?></span>
                                                <?php endif; ?>
                                                <span
                                                    class="small d-block mt-1 <?= $isParentOver ? 'text-warning-emphasis fw-semibold' : 'text-secondary' ?>"
                                                    title="Lernziel insgesamt: <?= number_format($parentAlloc, 1, ',', '.') ?> von <?= number_format($parentTarget, 1, ',', '.') ?> h verteilt">
                                                    &#8627; <?= htmlspecialchars((string) $mGoal['parent_goal_title']) ?>
                                                    <?php if ($isParentOver): ?>
                                                        &middot; Lernziel überschritten (<?= number_format($parentAlloc, 1, ',', '.') ?> / <?= number_format($parentTarget, 1, ',', '.') ?> h)
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="text-end" data-label="Geplant">
                                                <div class="fw-bold text-primary"><?= number_format($mgHours, 1, ',', '.') ?> h</div>
                                                <div class="text-muted small">
                                                    <?php if ($shareOfBudget !== null): ?>
                                                        <?= number_format((float) $shareOfBudget, 0, ',', '.') ?> % vom Budget
                                                    <?php else: ?>
                                                        <?= number_format($shareOfPlanned, 0, ',', '.') ?> % des Monats
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="td-mobile-block" data-label="In der Woche eingeplant">
                                                <div class="d-flex justify-content-between small mb-1 <?= $isMgOverScheduled ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>">
                                                    <span><?= number_format($mgScheduled, 1, ',', '.') ?> von <?= number_format($mgHours, 1, ',', '.') ?> h geplant</span>
                                                    <span><?= $mgSlotCount ?> Lerneinheit<?= $mgSlotCount === 1 ? '' : 'en' ?></span>
                                                </div>
                                                <div class="progress mb-2" style="height: 4px;" role="presentation">
                                                    <div class="progress-bar <?= $isMgOverScheduled ? 'bg-warning' : ($mgSchedPct >= 100 ? 'bg-success' : 'bg-primary') ?>" style="width: <?= $isMgOverScheduled ? 100 : $mgSchedPct ?>%"></div>
                                                </div>
                                                <div class="d-flex justify-content-between small mb-1" style="font-size: 0.75rem;">
                                                    <span class="<?= $mgCompleted ? 'text-success fw-semibold' : 'text-muted' ?>">
                                                        ⏱️ Ist-Zeit: <strong><?= number_format($mgTrackedHours, 1, ',', '.') ?></strong> / <?= number_format($mgHours, 1, ',', '.') ?> h
                                                    </span>
                                                    <span class="<?= $mgCompleted ? 'text-success fw-semibold' : 'text-muted' ?>">
                                                        <?= number_format($mgTrackedPct, 0, ',', '.') ?> %
                                                    </span>
                                                </div>
                                                <div class="progress" style="height: 4px;" role="progressbar" aria-label="Gestoppte Ist-Zeit" aria-valuenow="<?= (int) $mgTrackedPct ?>" aria-valuemin="0" aria-valuemax="100">
                                                    <div class="progress-bar bg-success" style="width: <?= $mgTrackedPct ?>%"></div>
                                                </div>
                                            </td>
                                            <td class="text-end td-mobile-actions" data-label="Aktionen">
                                                <div class="dropdown">
                                                    <button
                                                        class="btn btn-sm btn-link text-secondary p-1 text-decoration-none row-action-menu-btn"
                                                        type="button"
                                                        data-bs-toggle="dropdown"
                                                        aria-expanded="false"
                                                        title="Aktionen"
                                                        aria-label="Aktionen für Zwischenziel">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                                            <path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0" />
                                                        </svg>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                        <li>
                                                            <button
                                                                type="button"
                                                                class="dropdown-item small edit-monthly-goal-btn"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#editMonthlyGoalModal"
                                                                data-id="<?= (int) $mGoal['id'] ?>"
                                                                data-goal-id="<?= (int) $mGoal['goal_id'] ?>"
                                                                data-month="<?= htmlspecialchars((string) $mGoal['year_month'], ENT_QUOTES) ?>"
                                                                data-title="<?= htmlspecialchars((string) $mGoal['title'], ENT_QUOTES) ?>"
                                                                data-hours="<?= htmlspecialchars((string) $mGoal['planned_hours'], ENT_QUOTES) ?>"
                                                                data-description="<?= htmlspecialchars((string) ($mGoal['description'] ?? ''), ENT_QUOTES) ?>">
                                                                Bearbeiten
                                                            </button>
                                                        </li>
                                                        <li>
                                                            <button
                                                                type="button"
                                                                class="dropdown-item small text-danger delete-monthly-goal-btn"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#deleteMonthlyGoalModal"
                                                                data-id="<?= (int) $mGoal['id'] ?>"
                                                                data-title="<?= htmlspecialchars((string) $mGoal['title'], ENT_QUOTES) ?>"
                                                                data-month="<?= htmlspecialchars((string) $mGoal['year_month'], ENT_QUOTES) ?>">
                                                                Löschen
                                                            </button>
                                                        </li>
                                                    </ul>
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
    <!-- ANSICHT 3: Woche (Lerneinheiten je Kalenderwoche)                  -->
    <!-- ================================================================== -->
    <div
        class="tab-pane fade <?= $activeViewTab === 'week' ? 'show active' : '' ?>"
        id="pane-week"
        role="tabpanel"
        aria-labelledby="tab-week-btn"
        tabindex="0">
        <section
            id="wochenplanung"
            class="mb-4"
            data-selected-week="<?= htmlspecialchars($selectedWeek, ENT_QUOTES) ?>"
            data-selected-week-short="<?= htmlspecialchars((string) $selectedWeekData['label_short'], ENT_QUOTES) ?>"
            data-selected-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
            data-selected-month-short="<?= htmlspecialchars((string) $selectedMonthData['label_short'], ENT_QUOTES) ?>"
            data-default-slot-date="<?= htmlspecialchars($defaultSlotDate, ENT_QUOTES) ?>"
            data-week-view-mode="day">
            <!-- Kalenderwochen-Auswahl: Monat mit Pfeilen wechseln, darunter die Wochen des Monats -->
            <div class="d-flex justify-content-center align-items-center gap-2 mb-2">
                <a
                    href="goals.php?tab=week&month=<?= urlencode((string) $weeklyPlanning['prev_month']) ?>"
                    data-month-switch="<?= htmlspecialchars((string) $weeklyPlanning['prev_month'], ENT_QUOTES) ?>"
                    class="btn btn-sm btn-light border period-nav-btn"
                    title="Vorheriger Monat: <?= htmlspecialchars(GoalController::formatYearMonth((string) $weeklyPlanning['prev_month'], false)) ?>"
                    aria-label="Vorheriger Monat: <?= htmlspecialchars(GoalController::formatYearMonth((string) $weeklyPlanning['prev_month'], false)) ?>">
                    &lsaquo;
                </a>
                <span class="fw-semibold text-dark text-center" style="min-width: 9rem;">
                    <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?>
                </span>
                <a
                    href="goals.php?tab=week&month=<?= urlencode((string) $weeklyPlanning['next_month']) ?>"
                    data-month-switch="<?= htmlspecialchars((string) $weeklyPlanning['next_month'], ENT_QUOTES) ?>"
                    class="btn btn-sm btn-light border period-nav-btn"
                    title="Nächster Monat: <?= htmlspecialchars(GoalController::formatYearMonth((string) $weeklyPlanning['next_month'], false)) ?>"
                    aria-label="Nächster Monat: <?= htmlspecialchars(GoalController::formatYearMonth((string) $weeklyPlanning['next_month'], false)) ?>">
                    &rsaquo;
                </a>
            </div>

            <div class="row g-2 mb-3" role="tablist" aria-label="Kalenderwochenauswahl">
                <?php foreach ($weeksWindow as $isoW => $wTab): ?>
                    <?php
                    $isWSelected = (bool) $wTab['is_selected'];
                    $wScheduled = (float) $wTab['scheduled_hours'];
                    $wSlots = (int) $wTab['slot_count'];
                    $wCardClasses = $isWSelected
                        ? 'border-primary bg-primary text-white shadow-sm active'
                        : 'border-light-subtle bg-white text-dark';
                    ?>
                    <div class="col-6 col-sm-4 col-lg">
                        <a
                            href="goals.php?tab=week&month=<?= urlencode($selectedMonth) ?>&week=<?= urlencode((string) $isoW) ?>"
                            data-week-switch="<?= htmlspecialchars((string) $isoW, ENT_QUOTES) ?>"
                            data-week-month="<?= htmlspecialchars((string) $selectedMonth, ENT_QUOTES) ?>"
                            class="card h-100 text-decoration-none border week-tab-link <?= $wCardClasses ?>"
                            role="tab"
                            aria-selected="<?= $isWSelected ? 'true' : 'false' ?>">
                            <div class="card-body p-2 text-center">
                                <div class="d-flex justify-content-center align-items-center gap-1">
                                    <span class="fw-bold small"><?= htmlspecialchars((string) $wTab['label_short']) ?></span>
                                    <?php if ($wTab['is_current_week']): ?>
                                        <span class="period-today-dot" title="Aktuelle Woche"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="small <?= $isWSelected ? 'text-white-50' : 'text-muted' ?>" style="font-size: 0.72rem;">
                                    <?= htmlspecialchars((string) $wTab['date_range_short']) ?>
                                </div>
                                <div class="small fw-semibold <?= $isWSelected ? 'text-white' : ($wSlots > 0 ? 'text-primary' : 'text-muted fw-normal') ?>" style="font-size: 0.75rem;">
                                    <?php if ($wSlots > 0): ?>
                                        <?= number_format($wScheduled, 1, ',', '.') ?> h &middot; <?= $wSlots ?> Einheit<?= $wSlots === 1 ? '' : 'en' ?>
                                    <?php else: ?>
                                        &ndash;
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Detailansicht der aktiv ausgewählten Kalenderwoche -->
            <?php
            $wSumScheduled = (float) $weekSummary['scheduled_hours'];
            $wSumCompleted = (float) $weekSummary['completed_hours'];
            $wSumTrackedHours = (float) ($weekSummary['tracked_hours'] ?? 0.0);
            $wSumTrackedSeconds = (int) ($weekSummary['tracked_seconds'] ?? 0);
            $wSumTrackedFormatted = (string) ($weekSummary['tracked_formatted'] ?? '0 Min.');
            $wSumSlots = (int) $weekSummary['slot_count'];
            $wSumCompletedSlots = (int) $weekSummary['completed_slot_count'];
            $wHasOverlaps = (bool) $weekSummary['has_overlaps'];
            $wDonePct = $wSumScheduled > 0 ? min(100.0, $wSumCompleted / $wSumScheduled * 100) : 0.0;
            $monthTotalScheduled = (float) ($selectedMonthData['scheduled_hours'] ?? 0.0);
            $monthTotalPlanned = (float) $selectedMonthData['planned_hours'];
            $isMonthOverScheduled = $monthTotalPlanned > 0 && $monthTotalScheduled > $monthTotalPlanned;
            ?>
            <div class="card shadow-sm border mb-4">
                <div class="card-header bg-white py-3 px-3 px-sm-4 border-bottom">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <h2 class="h4 fw-bold mb-1 d-flex align-items-center flex-wrap gap-2">
                                <span><?= htmlspecialchars((string) $selectedWeekData['label_long']) ?></span>
                                <?php if ($selectedWeekData['is_current_week']): ?>
                                    <span class="badge bg-primary-subtle text-primary fs-6 fw-normal">Aktuelle Woche</span>
                                <?php endif; ?>
                                <?php if ($wHasOverlaps): ?>
                                    <span class="badge text-bg-warning fs-6">Zeitüberschneidung</span>
                                <?php endif; ?>
                            </h2>
                            <?php if ($wSumSlots > 0 || $wSumTrackedSeconds > 0): ?>
                                <div class="d-flex align-items-center flex-wrap gap-2 small text-muted">
                                    <?php if ($wSumSlots > 0): ?>
                                        <div class="progress flex-shrink-0" style="height: 6px; width: 120px;" role="progressbar" aria-label="Erledigte Lernzeit dieser Woche" aria-valuenow="<?= (int) $wDonePct ?>" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar bg-success" style="width: <?= $wDonePct ?>%"></div>
                                        </div>
                                        <span>
                                            <strong class="text-success"><?= number_format($wSumCompleted, 1, ',', '.') ?></strong> von <strong class="text-primary"><?= number_format($wSumScheduled, 1, ',', '.') ?> h</strong> erledigt
                                            (<?= $wSumCompletedSlots ?>/<?= $wSumSlots ?> Lerneinheiten)
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge bg-light text-dark border fw-normal">
                                        ⏱️ Ist-Zeit Woche: <strong><?= number_format($wSumTrackedHours, 1, ',', '.') ?> h</strong> (<?= htmlspecialchars($wSumTrackedFormatted) ?>)
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="small text-muted">Für diese Woche ist noch keine Lernzeit geplant.</div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <!-- Umschalter: einzelner Tag vs. ganze Woche -->
                            <div class="btn-group btn-group-sm" role="group" aria-label="Darstellung der Woche">
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary active"
                                    data-week-view-mode-btn="day">
                                    Tag
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    data-week-view-mode-btn="week">
                                    Woche
                                </button>
                            </div>

                            <?php if (!empty($selectableMonthlyGoals)): ?>
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    id="primary-create-slot-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#createSlotModal"
                                    data-date="<?= htmlspecialchars($defaultSlotDate, ENT_QUOTES) ?>">
                                    + Lernzeit planen
                                </button>
                            <?php elseif (!empty($goals)): ?>
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#createMonthlyGoalModal"
                                    data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>">
                                    + Zwischenziel für <?= htmlspecialchars((string) $selectedMonthData['label_short']) ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card-body p-3 p-sm-4">
                    <!-- Einklappbare Übersicht der Zwischenziele des Monats -->
                    <?php if (empty($selectableMonthlyGoals)): ?>
                        <div class="alert alert-light border mb-3">
                            <strong class="text-dark d-block">Noch keine Zwischenziele für <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?></strong>
                            <span class="text-muted small">
                                Lege zuerst ein Zwischenziel an – danach kannst du hier Lernzeiten an einzelnen Tagen einplanen.
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="border rounded bg-light-subtle mb-3">
                            <button
                                class="btn btn-link w-100 text-decoration-none text-dark d-flex justify-content-between align-items-center gap-2 px-3 py-2 collapsed week-collapse-toggle"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#weekMonthlyGoalsCollapse"
                                aria-expanded="false"
                                aria-controls="weekMonthlyGoalsCollapse">
                                <span class="small text-start">
                                    <span class="fw-semibold">Zwischenziele im <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?></span>
                                    <span class="<?= $isMonthOverScheduled ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?> ms-1">
                                        &middot; <?= number_format($monthTotalScheduled, 1, ',', '.') ?> von <?= number_format($monthTotalPlanned, 1, ',', '.') ?> h eingeplant
                                    </span>
                                </span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-chevron-down week-collapse-chevron text-muted flex-shrink-0" viewBox="0 0 16 16">
                                    <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708" />
                                </svg>
                            </button>
                            <div class="collapse" id="weekMonthlyGoalsCollapse">
                                <div class="p-3 pt-1 border-top">
                                    <div class="row g-2 mt-1">
                                        <?php foreach ($monthlyGoals as $mgItem): ?>
                                            <?php
                                            $mgItemPlanned = (float) $mgItem['planned_hours'];
                                            $mgItemSched = (float) ($mgItem['scheduled_hours'] ?? 0.0);
                                            $mgItemTracked = (float) ($mgItem['tracked_hours'] ?? 0.0);
                                            $mgItemTrackedPct = (float) ($mgItem['tracked_percent'] ?? 0.0);
                                            $mgItemDone = (bool) ($mgItem['is_completed'] ?? false);
                                            $mgItemRem = round($mgItemPlanned - $mgItemSched, 2);
                                            $mgItemOver = $mgItemSched > $mgItemPlanned;
                                            $mgItemPct = $mgItemPlanned > 0 ? min(100.0, round(($mgItemSched / $mgItemPlanned) * 100, 1)) : 0.0;
                                            ?>
                                            <div class="col-md-6 col-xl-4">
                                                <div class="p-2 px-3 border rounded h-100 d-flex flex-column justify-content-between <?= $mgItemOver ? 'bg-warning-subtle border-warning' : 'bg-white' ?>">
                                                    <div>
                                                        <div class="d-flex align-items-center justify-content-between gap-1">
                                                            <strong class="small text-dark"><?= htmlspecialchars((string) $mgItem['title']) ?></strong>
                                                            <?php if ($mgItemDone): ?>
                                                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.65rem;">&#10003; Erledigt</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-muted" style="font-size: 0.74rem;">
                                                            <?= htmlspecialchars((string) $mgItem['parent_goal_title']) ?>
                                                        </div>
                                                    </div>
                                                    <div class="mt-2">
                                                        <div class="d-flex justify-content-between align-items-center small" style="font-size: 0.75rem;">
                                                            <span class="<?= $mgItemOver ? 'text-warning-emphasis fw-bold' : 'text-dark fw-semibold' ?>">
                                                                <?= number_format($mgItemSched, 1, ',', '.') ?> / <?= number_format($mgItemPlanned, 1, ',', '.') ?> h geplant
                                                            </span>
                                                            <span class="<?= $mgItemDone ? 'text-success fw-semibold' : 'text-muted' ?>">
                                                                ⏱️ Ist: <?= number_format($mgItemTracked, 1, ',', '.') ?> h
                                                            </span>
                                                        </div>
                                                        <div class="progress mt-1" style="height: 4px;">
                                                            <div class="progress-bar <?= $mgItemDone ? 'bg-success' : ($mgItemOver ? 'bg-warning' : 'bg-primary') ?>" style="width: <?= max($mgItemPct, $mgItemTrackedPct) ?>%"></div>
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

                    <!-- Wochenband (Mo bis So), nur in der Tagesansicht sichtbar -->
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
                                aria-selected="<?= $daySelected ? 'true' : 'false' ?>">
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

                    <!-- Tages-Kacheln (in der Tagesansicht nur der gewählte Tag, in der Wochenansicht alle 7 als Agenda) -->
                    <div class="d-flex flex-column gap-2" id="week-days-container">
                        <?php foreach ($weekDays as $day): ?>
                            <?php
                            $dayDate = (string) $day['date'];
                            $isSelectedDay = (bool) ($day['is_selected'] ?? ($dayDate === $defaultSlotDate));
                            $isToday = (bool) $day['is_today'];
                            $inMonth = (bool) $day['is_in_selected_month'];
                            $dayHours = (float) $day['scheduled_hours'];
                            $dayTrackedSeconds = (int) ($day['tracked_seconds'] ?? 0);
                            $dayTrackedFormatted = (string) ($day['tracked_formatted'] ?? '0 Min.');
                            $daySlotCount = (int) $day['slot_count'];
                            $dayHasOverlap = (bool) $day['has_overlap'];
                            $daySlots = $day['slots'];
                            $hasSlots = !empty($daySlots);
                            ?>
                            <div
                                class="card week-day-card <?= $isToday ? 'border-primary-subtle' : ($dayHasOverlap ? 'border-warning' : 'border') ?> <?= !$inMonth ? 'bg-light-subtle' : 'bg-white' ?> <?= $isSelectedDay ? '' : 'd-none' ?>"
                                data-day-card="<?= htmlspecialchars($dayDate, ENT_QUOTES) ?>"
                                data-has-slots="<?= $hasSlots ? 'true' : 'false' ?>">
                                <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center gap-2 <?= $isToday ? 'bg-primary-subtle border-primary-subtle' : 'bg-light-subtle' ?>">
                                    <div class="d-flex align-items-center flex-wrap gap-2">
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
                                        <span class="small <?= $hasSlots ? 'text-primary fw-semibold' : 'text-muted' ?>">
                                            <?php if ($hasSlots): ?>
                                                <?= number_format($dayHours, 1, ',', '.') ?> h &middot; <?= $daySlotCount ?> Einheit<?= $daySlotCount === 1 ? '' : 'en' ?>
                                                <?php if ($dayTrackedSeconds > 0): ?>
                                                    &middot; <span class="text-success">⏱️ <?= htmlspecialchars($dayTrackedFormatted) ?></span>
                                                <?php endif; ?>
                                            <?php elseif ($dayTrackedSeconds > 0): ?>
                                                <span class="text-success">⏱️ <?= htmlspecialchars($dayTrackedFormatted) ?> gestoppt</span>
                                            <?php else: ?>
                                                Frei
                                            <?php endif; ?>
                                        </span>
                                        <?php if (!empty($selectableMonthlyGoals)): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary py-0 px-2 quick-add-slot-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#createSlotModal"
                                                data-date="<?= htmlspecialchars($dayDate, ENT_QUOTES) ?>"
                                                title="Lernzeit für <?= htmlspecialchars((string) $day['day_name_long']) ?> (<?= htmlspecialchars((string) $day['date_full_formatted']) ?>) planen"
                                                aria-label="Lernzeit für <?= htmlspecialchars((string) $day['day_name_long']) ?> planen">
                                                +
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card-body p-2 p-sm-3 d-flex flex-column gap-2 <?= $hasSlots ? '' : 'week-day-empty' ?>">
                                    <?php if (!$hasSlots): ?>
                                        <div class="text-muted small py-2 px-1 fst-italic">
                                            Für diesen Tag ist noch keine Lernzeit geplant.
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($daySlots as $slot): ?>
                                            <?php
                                            $slotCompleted = (int) $slot['is_completed'] === 1;
                                            $slotOverlap = (bool) $slot['has_overlap'];
                                            $slotDur = (float) $slot['duration_hours'];
                                            $slotTimeRange = $slot['start_time_formatted'] . ' – ' . $slot['end_time_formatted'] . ' Uhr';
                                            $slotTrackedSeconds = (int) ($slot['tracked_seconds'] ?? 0);
                                            $slotTrackedHours = (float) ($slot['tracked_hours'] ?? 0.0);
                                            $slotTrackedFormatted = (string) ($slot['tracked_formatted'] ?? '0 Min.');
                                            $slotProgressPct = (float) ($slot['progress_percent'] ?? ($slotCompleted ? 100.0 : 0.0));
                                            $slotTargetReached = (bool) ($slot['is_target_reached'] ?? false);
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
                                                            title="<?= $slotCompleted ? 'Erledigt – als offen markieren' : 'Als erledigt abhaken' ?>"
                                                            aria-label="<?= $slotCompleted ? 'Erledigt – als offen markieren' : 'Als erledigt abhaken' ?>">
                                                            <?php if ($slotCompleted): ?>
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16">
                                                                    <path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425z" />
                                                                </svg>
                                                            <?php endif; ?>
                                                        </button>
                                                    </form>

                                                    <!-- Zeitspanne, Zwischenziel, Ist-Zeit-Fortschritt und optionale Notiz in der Mitte -->
                                                    <div class="flex-grow-1 min-w-0">
                                                        <div class="d-flex align-items-center flex-wrap gap-2 small">
                                                            <span class="fw-semibold <?= $slotCompleted ? 'text-muted' : 'text-primary' ?>">
                                                                <?= htmlspecialchars($slotTimeRange) ?>
                                                            </span>
                                                            <span class="text-muted">&middot; Soll: <?= number_format($slotDur, 1, ',', '.') ?> h</span>
                                                            <?php if ($slotCompleted): ?>
                                                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.68rem;">
                                                                    &#10003; Erledigt
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php if ($slotOverlap): ?>
                                                                <span class="badge text-bg-warning" style="font-size: 0.68rem;" title="Diese Lerneinheit überschneidet sich zeitlich mit einer anderen an diesem Tag.">
                                                                    Überschneidung
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

                                                        <!-- Ist-Zeit & Fortschrittsbalken -->
                                                        <div class="mt-2" style="max-width: 340px;">
                                                            <div class="d-flex justify-content-between align-items-center small mb-1" style="font-size: 0.74rem;">
                                                                <span class="text-muted">
                                                                    ⏱️ Ist-Zeit:
                                                                    <strong class="<?= $slotCompleted ? 'text-success' : ($slotTrackedSeconds > 0 ? 'text-primary' : 'text-dark') ?>">
                                                                        <?= htmlspecialchars($slotTrackedFormatted) ?>
                                                                    </strong>
                                                                    (<?= number_format($slotTrackedHours, 1, ',', '.') ?> / <?= number_format($slotDur, 1, ',', '.') ?> h)
                                                                </span>
                                                                <span class="fw-semibold <?= $slotCompleted ? 'text-success' : 'text-muted' ?>">
                                                                    <?= number_format($slotProgressPct, 0, ',', '.') ?> %
                                                                </span>
                                                            </div>
                                                            <div class="progress" style="height: 5px;" role="progressbar" aria-label="Gestoppte Ist-Zeit für dieses Tagesziel" aria-valuenow="<?= (int) $slotProgressPct ?>" aria-valuemin="0" aria-valuemax="100">
                                                                <div class="progress-bar <?= $slotCompleted ? 'bg-success' : 'bg-primary' ?>" style="width: <?= $slotProgressPct ?>%"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Dezentes Aktionsmenü (⋮) rechts -->
                                                <div class="dropdown flex-shrink-0">
                                                    <button
                                                        class="btn btn-sm btn-link text-secondary p-1 text-decoration-none row-action-menu-btn"
                                                        type="button"
                                                        data-bs-toggle="dropdown"
                                                        aria-expanded="false"
                                                        title="Aktionen"
                                                        aria-label="Aktionen für Lerneinheit">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                                            <path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0" />
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
                                                                data-notes="<?= htmlspecialchars((string) ($slot['notes'] ?? ''), ENT_QUOTES) ?>">
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
                                                                data-summary="<?= htmlspecialchars((string) $day['day_name_long'] . ', ' . (string) $day['date_full_formatted'] . ' (' . $slotTimeRange . ') – ' . (string) $slot['monthly_goal_title'], ENT_QUOTES) ?>">
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
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Modal: Lernziel erstellen -->
<div class="modal fade" id="createGoalModal" tabindex="-1" aria-labelledby="createGoalModalLabel" aria-hidden="true" <?= $failedAction === 'create' ? ' data-auto-open="true"' : '' ?>>
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
                            required>
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
                                required>
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
                                required>
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
                                required>
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
                            placeholder="Optionale Notizen, Themenblöcke oder Klausurtermin..."><?= htmlspecialchars($createDescVal) ?></textarea>
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
<div class="modal fade" id="editGoalModal" tabindex="-1" aria-labelledby="editGoalModalLabel" aria-hidden="true" <?= $failedAction === 'update' ? ' data-auto-open="true"' : '' ?>>
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
                            required>
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
                                required>
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
                                required>
                        </div>
                        <div class="col-sm-6">
                            <label for="edit_end_date" class="form-label fw-semibold">Enddatum <span class="text-danger">*</span></label>
                            <input
                                type="date"
                                class="form-control<?= ($failedAction === 'update' && $errorField === 'end_date') ? ' is-invalid' : '' ?>"
                                id="edit_end_date"
                                name="end_date"
                                value="<?= htmlspecialchars($editEndVal) ?>"
                                required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-semibold">Notizen</label>
                        <textarea
                            class="form-control<?= ($failedAction === 'update' && $errorField === 'description') ? ' is-invalid' : '' ?>"
                            id="edit_description"
                            name="description"
                            rows="3"
                            maxlength="2000"><?= htmlspecialchars($editDescVal) ?></textarea>
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
                            required>
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
                                placeholder="z. B. 40">
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
                                required>
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
                                    required>
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
                            required>
                    </div>

                    <div class="mb-3">
                        <label for="create_mg_description" class="form-label fw-semibold">Notizen</label>
                        <textarea
                            class="form-control"
                            id="create_mg_description"
                            name="description"
                            rows="2"
                            maxlength="2000"
                            placeholder="Optionale Notizen für diesen Monat..."></textarea>
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
                                required>
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
                                    required>
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
                            required>
                    </div>

                    <div class="mb-3">
                        <label for="edit_mg_description" class="form-label fw-semibold">Notizen</label>
                        <textarea
                            class="form-control"
                            id="edit_mg_description"
                            name="description"
                            rows="2"
                            maxlength="2000"></textarea>
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
                        Möchtest du das folgende Zwischenziel und alle zugehörigen Lerneinheiten wirklich löschen?
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
                            required>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Startzeit <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select
                                    class="form-select text-center slot-hour-select"
                                    id="create_slot_start_hour"
                                    aria-label="Startzeit Stunden"
                                    required>
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
                                    required>
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
                                    required>
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
                                    required>
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
                            placeholder="z. B. Kapitel 3 wiederholen">
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
                            required>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Startzeit <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select
                                    class="form-select text-center slot-hour-select"
                                    id="edit_slot_start_hour"
                                    aria-label="Startzeit Stunden"
                                    required>
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?= sprintf('%02d', $h) ?>"><?= sprintf('%02d', $h) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text fw-bold px-2">:</span>
                                <select
                                    class="form-select text-center slot-minute-select"
                                    id="edit_slot_start_minute"
                                    aria-label="Startzeit Minuten"
                                    required>
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
                                    required>
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?= sprintf('%02d', $h) ?>"><?= sprintf('%02d', $h) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span class="input-group-text fw-bold px-2">:</span>
                                <select
                                    class="form-select text-center slot-minute-select"
                                    id="edit_slot_end_minute"
                                    aria-label="Endzeit Minuten"
                                    required>
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
                            placeholder="z. B. Kapitel 3 wiederholen">
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