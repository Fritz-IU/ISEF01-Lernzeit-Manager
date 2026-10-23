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

// Erfolgsmeldung aus der Session übernehmen (gesetzt vor dem Redirect nach POST)
$successMessage = (string) ($_SESSION['goals_success'] ?? '');
unset($_SESSION['goals_success']);

// Formularverarbeitung für Lernziele sowie monatliche Budgets und Zwischenziele.
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
        } else {
            $errorMessage = 'Ungültige Aktion.';
        }
    } catch (ValidationException $e) {
        $errorMessage = $e->getMessage();
    } catch (Throwable $e) {
        error_log((string) $e);
        $errorMessage = 'Es ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.';
    }
}

// Lernziele und aggregierte Statistiken abrufen
$goals = $goalController->listGoals($userId);
$totalHours = $goalController->getTotalHours($userId);

// Monatliche Detailplanung & Workload-Daten aufbereiten
$requestedMonth = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : null;
$requestedTab = isset($_GET['tab']) && is_string($_GET['tab']) ? trim($_GET['tab']) : '';
$activeViewTab = ($requestedTab === 'month' || ($requestedTab === '' && $requestedMonth !== null))
    ? 'month'
    : 'semester';

$monthlyPlanning = $goalController->buildMonthlyPlanningData($userId, $goals, $requestedMonth);
$selectedMonth = (string) $monthlyPlanning['selected_month'];
$selectedMonthData = $monthlyPlanning['selected_month_data'];
$monthsWindow = $monthlyPlanning['months_window'];
$monthlyGoals = $monthlyPlanning['monthly_goals'];
$totalPlannedAllMonths = (float) $monthlyPlanning['total_planned_all_months'];
$isTotalOverAllocated = $totalHours > 0 && $totalPlannedAllMonths > $totalHours;

// Standardwerte für neues Lernziel
$defaultStartDate = date('Y-m-d');
$defaultEndDate = date('Y-m-d', strtotime('+6 months'));

$pageTitle = 'Lernzielplanung – Lernzeit-Manager';
$activeNav = 'goals';

require_once __DIR__ . '/includes/header.php';
?>

<div class="row align-items-center mb-3">
    <div class="col-md-7">
        <h1 class="h3 fw-bold text-primary mb-1">Lernziele und Monatsplanung</h1>
        <p class="text-muted mb-0">
            Plane deine Lernziele für die nächsten sechs Monate und teile sie in monatliche Zwischenziele auf.
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
<?php if ($errorMessage !== ''): ?>
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

<!-- Hauptansicht-Umschalter: Semesterübersicht vs. Monatsplanung -->
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
                        <div>
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
                                        ?>
                                        <tr class="<?= $isParentOver ? 'table-warning' : '' ?>">
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
</div>

<!-- Modal: Lernziel erstellen -->
<div class="modal fade" id="createGoalModal" tabindex="-1" aria-labelledby="createGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createGoalModalLabel">Neues Lernziel planen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="create_title" class="form-label fw-semibold">Lernziel <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="create_title"
                            name="title"
                            maxlength="150"
                            placeholder="z. B. Software Engineering"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="create_target_hours" class="form-label fw-semibold">Geplante Zielstunden <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input
                                type="number"
                                class="form-control"
                                id="create_target_hours"
                                name="target_hours"
                                step="0.5"
                                min="0.5"
                                max="1500"
                                placeholder="z. B. 150"
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
                                class="form-control"
                                id="create_start_date"
                                name="start_date"
                                value="<?= htmlspecialchars($defaultStartDate) ?>"
                                required
                            >
                        </div>
                        <div class="col-sm-6">
                            <label for="create_end_date" class="form-label fw-semibold">Enddatum <span class="text-danger">*</span></label>
                            <input
                                type="date"
                                class="form-control"
                                id="create_end_date"
                                name="end_date"
                                value="<?= htmlspecialchars($defaultEndDate) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="create_description" class="form-label fw-semibold">Notizen</label>
                        <textarea
                            class="form-control"
                            id="create_description"
                            name="description"
                            rows="3"
                            maxlength="2000"
                            placeholder="Optionale Notizen, Themenblöcke oder Klausurtermin..."
                        ></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Lernziel speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Lernziel bearbeiten -->
<div class="modal fade" id="editGoalModal" tabindex="-1" aria-labelledby="editGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST" id="editGoalForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                <input type="hidden" name="goal_id" id="edit_goal_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editGoalModalLabel">Lernziel bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_title" class="form-label fw-semibold">Lernziel <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="edit_title"
                            name="title"
                            maxlength="150"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="edit_target_hours" class="form-label fw-semibold">Geplante Zielstunden <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input
                                type="number"
                                class="form-control"
                                id="edit_target_hours"
                                name="target_hours"
                                step="0.5"
                                min="0.5"
                                max="1500"
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
                                class="form-control"
                                id="edit_start_date"
                                name="start_date"
                                required
                            >
                        </div>
                        <div class="col-sm-6">
                            <label for="edit_end_date" class="form-label fw-semibold">Enddatum <span class="text-danger">*</span></label>
                            <input
                                type="date"
                                class="form-control"
                                id="edit_end_date"
                                name="end_date"
                                required
                            >
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-semibold">Notizen</label>
                        <textarea
                            class="form-control"
                            id="edit_description"
                            name="description"
                            rows="3"
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
            <form action="goals.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="save_monthly_budget">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="monthlyBudgetModalLabel">Monatliches Zeitbudget festlegen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
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
            <form action="goals.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="create_monthly_goal">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createMonthlyGoalModalLabel">Zwischenziel planen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
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
            <form action="goals.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="update_monthly_goal">
                <input type="hidden" name="monthly_goal_id" id="edit_mg_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editMonthlyGoalModalLabel">Zwischenziel bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
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
                        Möchtest du das folgende Zwischenziel wirklich löschen?
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

<?php
$extraScripts = '<script src="assets/js/goals.js?v=' . filemtime(__DIR__ . '/assets/js/goals.js') . '"></script>';
require_once __DIR__ . '/includes/footer.php';
?>

