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

$userId = (int) $_SESSION['user_id'];
$db = getDatabaseConnection();
$goalDAO = new GoalDAO($db);
$goalController = new GoalController($goalDAO);

$errorMessage = '';

// Erfolgsmeldung aus der Session übernehmen (gesetzt vor dem Redirect nach POST)
$successMessage = (string) ($_SESSION['goals_success'] ?? '');
unset($_SESSION['goals_success']);

// Formularverarbeitung für 6-Monats-Ziele sowie monatliche Budgets und Zwischenziele.
// Nach erfolgreicher Aktion wird per Redirect auf GET umgeleitet (Post/Redirect/Get),
// damit ein Neuladen der Seite die Aktion nicht erneut ausführt.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectMonth = isset($_POST['redirect_month']) && is_string($_POST['redirect_month'])
        ? trim($_POST['redirect_month'])
        : (isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : '');
    $monthQuery = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $redirectMonth)
        ? '?month=' . urlencode($redirectMonth)
        : '';

    try {
        if ($action === 'create') {
            $goalController->createGoal($userId, $_POST);
            $_SESSION['goals_success'] = 'Das 6-Monats-Lernziel wurde erfolgreich angelegt.';
            header('Location: goals.php' . $monthQuery);
            exit;
        } elseif ($action === 'update') {
            $goalId = isset($_POST['goal_id']) ? (int) $_POST['goal_id'] : 0;
            $goalController->updateGoal($goalId, $userId, $_POST);
            $_SESSION['goals_success'] = 'Das 6-Monats-Lernziel wurde erfolgreich aktualisiert.';
            header('Location: goals.php' . $monthQuery);
            exit;
        } elseif ($action === 'delete') {
            $goalId = isset($_POST['goal_id']) ? (int) $_POST['goal_id'] : 0;
            $goalController->deleteGoal($goalId, $userId);
            $_SESSION['goals_success'] = 'Das 6-Monats-Lernziel wurde erfolgreich gelöscht.';
            header('Location: goals.php' . $monthQuery);
            exit;
        } elseif ($action === 'save_monthly_budget') {
            $targetMonth = $goalController->saveMonthlyBudget($userId, $_POST);
            $_SESSION['goals_success'] = 'Das monatliche Soll-Zeitbudget für ' . GoalController::formatYearMonth($targetMonth) . ' wurde gespeichert.';
            header('Location: goals.php?month=' . urlencode($targetMonth) . '#monatsplanung');
            exit;
        } elseif ($action === 'create_monthly_goal') {
            $targetMonth = $goalController->createMonthlyGoal($userId, $_POST);
            $_SESSION['goals_success'] = 'Das monatliche Zwischenziel wurde erfolgreich angelegt.';
            header('Location: goals.php?month=' . urlencode($targetMonth) . '#monatsplanung');
            exit;
        } elseif ($action === 'update_monthly_goal') {
            $monthlyGoalId = isset($_POST['monthly_goal_id']) ? (int) $_POST['monthly_goal_id'] : 0;
            $targetMonth = $goalController->updateMonthlyGoal($monthlyGoalId, $userId, $_POST);
            $_SESSION['goals_success'] = 'Das monatliche Zwischenziel wurde erfolgreich aktualisiert.';
            header('Location: goals.php?month=' . urlencode($targetMonth) . '#monatsplanung');
            exit;
        } elseif ($action === 'delete_monthly_goal') {
            $monthlyGoalId = isset($_POST['monthly_goal_id']) ? (int) $_POST['monthly_goal_id'] : 0;
            $targetMonth = $goalController->deleteMonthlyGoal($monthlyGoalId, $userId);
            $_SESSION['goals_success'] = 'Das monatliche Zwischenziel wurde erfolgreich gelöscht.';
            header('Location: goals.php?month=' . urlencode($targetMonth) . '#monatsplanung');
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
$monthlyPlanning = $goalController->buildMonthlyPlanningData($userId, $goals, $requestedMonth);
$selectedMonth = (string) $monthlyPlanning['selected_month'];
$selectedMonthData = $monthlyPlanning['selected_month_data'];
$monthsWindow = $monthlyPlanning['months_window'];
$monthlyGoals = $monthlyPlanning['monthly_goals'];
$totalPlannedAllMonths = (float) $monthlyPlanning['total_planned_all_months'];
$isTotalOverAllocated = $totalHours > 0 && $totalPlannedAllMonths > $totalHours;

// Standardwerte für neues 6-Monats-Ziel
$defaultStartDate = date('Y-m-d');
$defaultEndDate = date('Y-m-d', strtotime('+6 months'));

$pageTitle = 'Lernzielplanung – Lernzeit-Manager';
$activeNav = 'goals';

require_once __DIR__ . '/includes/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col-md-7">
        <h1 class="h3 fw-bold text-primary mb-1">6-Monats-Lernzielplanung</h1>
        <p class="text-muted mb-0">
            Definiere deine Module, Zielstunden und Zeiträume für deine langfristige Grobplanung.
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
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <strong>Fehler:</strong> <?= htmlspecialchars($errorMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<?php if ($successMessage !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
        <?= htmlspecialchars($successMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<!-- Kennzahlen-Übersicht (6-Monats-Grobplanung) -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <span class="text-muted small text-uppercase fw-semibold d-block">Geplante Module / 6-Monats-Ziele</span>
                <span class="h3 fw-bold text-dark mb-0"><?= count($goals) ?></span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <span class="text-muted small text-uppercase fw-semibold d-block">Geplante Gesamtlernzeit (6 Monate)</span>
                <span class="h3 fw-bold text-primary mb-0"><?= number_format($totalHours, 1, ',', '.') ?> <span class="fs-6 text-muted fw-normal">Stunden</span></span>
            </div>
        </div>
    </div>
    <div class="col-sm-12 col-md-4">
        <div class="card shadow-sm border-0 h-100 <?= $isTotalOverAllocated ? 'border-start border-warning border-4' : '' ?>">
            <div class="card-body">
                <span class="text-muted small text-uppercase fw-semibold d-block">In Monatsziele aufgeteilt</span>
                <div class="d-flex align-items-baseline justify-content-between flex-wrap gap-2">
                    <span class="h3 fw-bold <?= $isTotalOverAllocated ? 'text-warning-emphasis' : 'text-secondary' ?> mb-0">
                        <?= number_format($totalPlannedAllMonths, 1, ',', '.') ?>
                        <span class="fs-6 text-muted fw-normal">/ <?= number_format($totalHours, 1, ',', '.') ?> Std.</span>
                    </span>
                    <?php if ($isTotalOverAllocated): ?>
                        <span class="badge text-bg-warning">
                            +<?= number_format($totalPlannedAllMonths - $totalHours, 1, ',', '.') ?> h über Grobplanung
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Liste der 6-Monats-Lernziele -->
<?php if (empty($goals)): ?>
    <div class="card shadow-sm border-0 text-center py-5 mb-5">
        <div class="card-body">
            <div class="mb-3 text-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-calendar2-check text-primary" viewBox="0 0 16 16">
                    <path d="M10.854 8.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 0 1 .708-.708L7.5 10.793l2.646-2.647a.5.5 0 0 1 .708 0"/>
                    <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M2 2a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1z"/>
                    <path d="M2.5 4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5H3a.5.5 0 0 1-.5-.5z"/>
                </svg>
            </div>
            <h2 class="h5 fw-bold">Noch keine 6-Monats-Lernziele geplant</h2>
            <p class="text-muted mb-4 max-w-500 mx-auto">
                Lege zuerst deine Module und geplante Lernzeit für die nächsten 6 Monate fest, um sie anschließend in monatliche Zwischenziele herunterzubrechen.
            </p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createGoalModal">
                Erstes Lernziel anlegen
            </button>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0 mb-5">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="min-width: 190px;">Modul / 6-Monats-Ziel</th>
                        <th scope="col" class="text-end" style="min-width: 110px;">Zielstunden</th>
                        <th scope="col" style="min-width: 210px;">Monatsverteilung (Zwischenziele)</th>
                        <th scope="col" style="min-width: 180px;">Zeitraum</th>
                        <th scope="col" style="min-width: 160px;">Beschreibung / Notizen</th>
                        <th scope="col" class="text-end" style="min-width: 220px;">Aktionen</th>
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
                            <td>
                                <strong class="text-dark d-block"><?= htmlspecialchars((string) $goal['title']) ?></strong>
                                <span class="text-muted small">Erstellt am <?= date('d.m.Y', strtotime((string) $goal['created_at'])) ?></span>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold px-2 py-1">
                                    <?= number_format($targetHoursRow, 1, ',', '.') ?> h
                                </span>
                            </td>
                            <td>
                                <div class="d-flex justify-content-between align-items-center small mb-1">
                                    <span class="<?= $allocTextClass ?>">
                                        <?= number_format($allocatedHoursRow, 1, ',', '.') ?> / <?= number_format($targetHoursRow, 1, ',', '.') ?> h
                                    </span>
                                    <span class="badge <?= $isGoalOverAllocated ? 'text-bg-warning' : 'bg-secondary-subtle text-secondary' ?>">
                                        <?= number_format($allocPercent, 0, ',', '.') ?> %
                                    </span>
                                </div>
                                <div class="progress" style="height: 6px;" role="progressbar" aria-label="Verplante Stunden in Monaten" aria-valuenow="<?= (int) $allocBarWidth ?>" aria-valuemin="0" aria-valuemax="100">
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
                            <td>
                                <div class="small fw-semibold text-dark">
                                    <?= $startFormatted ?> &ndash; <?= $endFormatted ?>
                                </div>
                                <div class="text-muted small">
                                    Dauer: <?= $durationWeeks ?> Wochen (<?= $durationDays ?> Tage)
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($goal['description'])): ?>
                                    <span class="text-muted small">
                                        <?= nl2br(htmlspecialchars((string) $goal['description'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small fst-italic">Keine Notiz</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group" aria-label="Aktionen für Lernziel">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary quick-add-monthly-goal-btn"
                                        title="In Monatsziel herunterbrechen"
                                        data-bs-toggle="modal"
                                        data-bs-target="#createMonthlyGoalModal"
                                        data-goal-id="<?= (int) $goal['id'] ?>"
                                        data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                                    >
                                        + Monatsziel
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

<!-- ====================================================================== -->
<!-- BEREICH 2: Monatliche Detailplanung & Zwischenziele (Issue #2)         -->
<!-- ====================================================================== -->
<hr class="my-5 text-muted">

<section id="monatsplanung" class="mb-4">
    <div class="row align-items-center mb-3">
        <div class="col-lg-7">
            <h2 class="h4 fw-bold text-primary mb-1">Monatliche Detailplanung &amp; Zwischenziele</h2>
            <p class="text-muted mb-0">
                Lege dein Soll-Zeitbudget je Monat fest, brich deine 6-Monats-Lernziele in monatliche Zwischenziele herunter und prüfe deine prozentuale Workload-Verteilung.
            </p>
        </div>
        <div class="col-lg-5 mt-3 mt-lg-0">
            <div class="d-flex flex-wrap justify-content-lg-end align-items-center gap-2">
                <!-- Direktauswahl eines beliebigen Monats -->
                <form action="goals.php#monatsplanung" method="GET" class="d-inline-flex align-items-center gap-1">
                    <label for="month_picker" class="visually-hidden">Monat auswählen</label>
                    <input
                        type="month"
                        id="month_picker"
                        name="month"
                        class="form-control form-control-sm"
                        style="width: auto;"
                        value="<?= htmlspecialchars($selectedMonth) ?>"
                        onchange="this.form.submit()"
                    >
                    <noscript>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Wechseln</button>
                    </noscript>
                </form>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#monthlyBudgetModal"
                    data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                    data-month-label="<?= htmlspecialchars((string) $selectedMonthData['label_long'], ENT_QUOTES) ?>"
                    data-budget="<?= $selectedMonthData['budget_hours'] !== null ? htmlspecialchars((string) $selectedMonthData['budget_hours'], ENT_QUOTES) : '' ?>"
                >
                    Monatsbudget anpassen
                </button>

                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#createMonthlyGoalModal"
                    data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                    <?= empty($goals) ? 'disabled title="Bitte lege zuerst ein 6-Monats-Lernziel an"' : '' ?>
                >
                    + Zwischenziel planen
                </button>
            </div>
        </div>
    </div>

    <!-- Monats-Tab-Leiste (6 Monate im Überblick + Vor/Zurück-Navigation) -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <a href="goals.php?month=<?= urlencode((string) $monthlyPlanning['prev_month']) ?>#monatsplanung" class="btn btn-sm btn-outline-secondary">
                    &lsaquo; Vorheriger Monat (<?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['prev_month'], true)) ?>)
                </a>
                <span class="text-muted small fw-semibold d-none d-md-inline">
                    Monat auswählen (Tab-Ansicht)
                </span>
                <a href="goals.php?month=<?= urlencode((string) $monthlyPlanning['next_month']) ?>#monatsplanung" class="btn btn-sm btn-outline-secondary">
                    Nächster Monat (<?= htmlspecialchars(GoalController::formatYearMonth((string) $monthlyPlanning['next_month'], true)) ?>) &rsaquo;
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
                    <div class="col-6 col-md-4 col-lg-2">
                        <a
                            href="goals.php?month=<?= urlencode((string) $ym) ?>#monatsplanung"
                            class="card h-100 text-decoration-none border month-tab-link <?= $tabCardClasses ?>"
                            role="tab"
                            aria-selected="<?= $isTabSelected ? 'true' : 'false' ?>"
                        >
                            <div class="card-body p-2 text-center">
                                <div class="d-flex justify-content-center align-items-center gap-1 mb-1">
                                    <span class="fw-bold small"><?= htmlspecialchars((string) $mTab['label_short']) ?></span>
                                    <?php if ($mTab['is_current_calendar_month']): ?>
                                        <span class="badge <?= $isTabSelected && !$isTabOverBudget ? 'bg-light text-primary' : 'bg-primary-subtle text-primary' ?>" style="font-size: 0.65rem;">Aktuell</span>
                                    <?php endif; ?>
                                </div>
                                <div class="small <?= $isTabSelected && !$isTabOverBudget ? 'text-white' : ($isTabOverBudget ? 'text-warning-emphasis fw-bold' : 'text-muted') ?>">
                                    <?= number_format($tabPlanned, 1, ',', '.') ?>
                                    <?php if ($tabBudget !== null): ?>
                                        / <?= number_format((float) $tabBudget, 1, ',', '.') ?> h
                                    <?php else: ?>
                                        h geplant
                                    <?php endif; ?>
                                </div>
                                <div class="mt-1">
                                    <span class="badge <?= $isTabOverBudget ? 'text-bg-warning' : ($isTabSelected ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary') ?>" style="font-size: 0.7rem;">
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

    <!-- Große Detailansicht des aktiv ausgewählten Monats -->
    <?php
        $selBudget = $selectedMonthData['budget_hours'];
        $selPlanned = (float) $selectedMonthData['planned_hours'];
        $selRemaining = $selectedMonthData['remaining_budget_hours'];
        $selUtilPercent = $selectedMonthData['budget_utilization_percent'];
        $selIsOverBudget = (bool) $selectedMonthData['is_over_budget'];
        $selWorkloadShare = (float) $selectedMonthData['workload_share_percent'];
    ?>
    <div class="card shadow-sm border-0 <?= $selIsOverBudget ? 'border-top border-warning border-4' : 'border-top border-primary border-4' ?>">
        <div class="card-header bg-white py-3 px-4 border-bottom">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span class="text-uppercase text-muted small fw-semibold d-block">Aktiver Planungsmonat (Detailansicht)</span>
                    <h3 class="h4 fw-bold mb-0 d-flex align-items-center flex-wrap gap-2">
                        <span><?= htmlspecialchars((string) $selectedMonthData['label_long']) ?></span>
                        <?php if ($selectedMonthData['is_current_calendar_month']): ?>
                            <span class="badge bg-primary-subtle text-primary fs-6 fw-normal">Aktueller Monat</span>
                        <?php endif; ?>
                        <?php if ($selIsOverBudget): ?>
                            <span class="badge text-bg-warning fs-6">
                                Soll-Zeitbudget um <?= number_format(abs((float) $selRemaining), 1, ',', '.') ?> h überschritten
                            </span>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        data-bs-toggle="modal"
                        data-bs-target="#monthlyBudgetModal"
                        data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                        data-month-label="<?= htmlspecialchars((string) $selectedMonthData['label_long'], ENT_QUOTES) ?>"
                        data-budget="<?= $selBudget !== null ? htmlspecialchars((string) $selBudget, ENT_QUOTES) : '' ?>"
                    >
                        Soll-Zeitbudget: <?= $selBudget !== null ? number_format((float) $selBudget, 1, ',', '.') . ' h' : 'Nicht gesetzt' ?> (Ändern)
                    </button>
                    <?php if (!empty($goals)): ?>
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#createMonthlyGoalModal"
                            data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                        >
                            + Zwischenziel für <?= htmlspecialchars((string) $selectedMonthData['label_short']) ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-body p-4">
            <!-- 3 Kennzahlen-Karten für den ausgewählten Monat -->
            <div class="row g-3 mb-4">
                <!-- Karte 1: Monatliches Soll-Zeitbudget -->
                <div class="col-md-4">
                    <div class="p-3 border rounded h-100 <?= $selIsOverBudget ? 'bg-warning-subtle border-warning' : 'bg-light' ?>">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <span class="text-muted small text-uppercase fw-semibold">Soll-Zeitbudget (<?= htmlspecialchars((string) $selectedMonthData['label_short']) ?>)</span>
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 text-decoration-none"
                                data-bs-toggle="modal"
                                data-bs-target="#monthlyBudgetModal"
                                data-month="<?= htmlspecialchars($selectedMonth, ENT_QUOTES) ?>"
                                data-month-label="<?= htmlspecialchars((string) $selectedMonthData['label_long'], ENT_QUOTES) ?>"
                                data-budget="<?= $selBudget !== null ? htmlspecialchars((string) $selBudget, ENT_QUOTES) : '' ?>"
                            >
                                Bearbeiten
                            </button>
                        </div>
                        <?php if ($selBudget !== null): ?>
                            <div class="h4 fw-bold text-dark mb-1">
                                <?= number_format((float) $selBudget, 1, ',', '.') ?> <span class="fs-6 fw-normal text-muted">Stunden</span>
                            </div>
                            <?php if ($selIsOverBudget): ?>
                                <div class="small text-warning-emphasis fw-semibold">
                                    Überschreitung: +<?= number_format(abs((float) $selRemaining), 1, ',', '.') ?> h über Monatsbudget
                                </div>
                            <?php else: ?>
                                <div class="small text-success fw-semibold">
                                    Noch <?= number_format((float) $selRemaining, 1, ',', '.') ?> h verfügbar
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="h5 fw-semibold text-muted mb-1 fst-italic">Kein Budget hinterlegt</div>
                            <div class="small text-muted">
                                Weise diesem Monat ein Soll-Zeitbudget zu, um die Budget-Auslastung zu messen.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Karte 2: Geplante Zwischenziele & Budget-Auslastung im Monat -->
                <div class="col-md-4">
                    <div class="p-3 border rounded h-100 <?= $selIsOverBudget ? 'bg-warning-subtle border-warning' : 'bg-light' ?>">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1">Verplante Lernzeit &amp; Auslastung</span>
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
                        <div class="progress" style="height: 8px;" role="progressbar" aria-label="Monatsbudget-Auslastung" aria-valuenow="<?= (int) $utilBarWidth ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar <?= $utilBarClass ?>" style="width: <?= $utilBarWidth ?>%"></div>
                        </div>
                        <div class="small text-muted mt-1">
                            <?= count($monthlyGoals) ?> Zwischenziel<?= count($monthlyGoals) === 1 ? '' : 'e' ?> in <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?>
                        </div>
                    </div>
                </div>

                <!-- Karte 3: Prozentuale Workload-Verteilung (Anteil am Gesamt-Workload) -->
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-light h-100">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1">Anteil am Gesamt-Workload</span>
                        <div class="d-flex justify-content-between align-items-baseline mb-2">
                            <span class="h4 fw-bold text-dark mb-0">
                                <?= number_format($selWorkloadShare, 1, ',', '.') ?> %
                            </span>
                            <span class="text-muted small">
                                (<?= number_format($selPlanned, 1, ',', '.') ?> von <?= number_format($totalPlannedAllMonths, 1, ',', '.') ?> h)
                            </span>
                        </div>
                        <?php $shareBarWidth = min(100.0, $selWorkloadShare); ?>
                        <div class="progress" style="height: 8px;" role="progressbar" aria-label="Prozentuale Workload-Verteilung" aria-valuenow="<?= (int) $shareBarWidth ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar bg-info" style="width: <?= $shareBarWidth ?>%"></div>
                        </div>
                        <div class="small text-muted mt-1">
                            Gleichverteilung über 6 Monate entspricht ca. 16,7 % je Monat.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabelle der Zwischenziele im ausgewählten Monat -->
            <?php if (empty($monthlyGoals)): ?>
                <div class="text-center py-4 border rounded bg-light-subtle">
                    <p class="fw-semibold text-dark mb-1">
                        Für <?= htmlspecialchars((string) $selectedMonthData['label_long']) ?> sind noch keine Zwischenziele eingeplant.
                    </p>
                    <p class="text-muted small mb-3">
                        Ordne deine 6-Monats-Lernziele diesem Monat zu und definiere konkrete Zwischenziele und Stunden.
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
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="min-width: 200px;">Zwischenziel im Monat</th>
                                <th scope="col" style="min-width: 220px;">Zugeordnetes 6-Monats-Lernziel</th>
                                <th scope="col" class="text-end" style="min-width: 150px;">Geplante Lernzeit</th>
                                <th scope="col" style="min-width: 180px;">Notizen / Details</th>
                                <th scope="col" class="text-end" style="min-width: 150px;">Aktionen</th>
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
                                    <td>
                                        <strong class="text-dark d-block"><?= htmlspecialchars((string) $mGoal['title']) ?></strong>
                                        <span class="text-muted small">Monat: <?= htmlspecialchars((string) $selectedMonthData['label_short']) ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-dark d-block"><?= htmlspecialchars((string) $mGoal['parent_goal_title']) ?></span>
                                        <span class="small <?= $isParentOver ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>">
                                            Gesamt verplant: <?= number_format($parentAlloc, 1, ',', '.') ?> / <?= number_format($parentTarget, 1, ',', '.') ?> h
                                            <?php if ($isParentOver): ?>
                                                <span class="badge text-bg-warning ms-1">6-Monats-Ziel überschritten</span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold px-2 py-1">
                                            <?= number_format($mgHours, 1, ',', '.') ?> h
                                        </span>
                                        <div class="text-muted small mt-1">
                                            <?php if ($shareOfBudget !== null): ?>
                                                <?= number_format((float) $shareOfBudget, 1, ',', '.') ?> % vom Monatsbudget
                                            <?php else: ?>
                                                <?= number_format($shareOfPlanned, 1, ',', '.') ?> % der Monatsplanung
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($mGoal['description'])): ?>
                                            <span class="text-muted small">
                                                <?= nl2br(htmlspecialchars((string) $mGoal['description'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Keine Notiz</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
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

<!-- Modal: Lernziel erstellen -->
<div class="modal fade" id="createGoalModal" tabindex="-1" aria-labelledby="createGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createGoalModalLabel">Neues Lernziel planen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="create_title" class="form-label fw-semibold">Modul / Lernziel <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="create_title"
                            name="title"
                            maxlength="150"
                            placeholder="z. B. Software Engineering – Vorlesung & Übung"
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
                        <label for="create_description" class="form-label fw-semibold">Notizen / Beschreibung</label>
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
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                <input type="hidden" name="goal_id" id="edit_goal_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editGoalModalLabel">Lernziel bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_title" class="form-label fw-semibold">Modul / Lernziel <span class="text-danger">*</span></label>
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
                        <label for="edit_description" class="form-label fw-semibold">Notizen / Beschreibung</label>
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
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                <input type="hidden" name="goal_id" id="delete_goal_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="deleteGoalModalLabel">Lernziel löschen?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Möchtest du das folgende Lernziel (inklusive der zugehörigen monatlichen Zwischenziele) wirklich unwiderruflich löschen?
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

<!-- Modal: Monatliches Soll-Zeitbudget festlegen / ändern -->
<div class="modal fade" id="monthlyBudgetModal" tabindex="-1" aria-labelledby="monthlyBudgetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST">
                <input type="hidden" name="action" value="save_monthly_budget">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="monthlyBudgetModalLabel">Monatliches Soll-Zeitbudget festlegen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="budget_year_month" class="form-label fw-semibold">Planungsmonat <span class="text-danger">*</span></label>
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
                        <label for="budget_hours_input" class="form-label fw-semibold">Verfügbares Soll-Zeitbudget im Monat</label>
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
                            Wie viele Stunden möchtest du in diesem Monat insgesamt für dein Studium aufwenden? (Feld leeren oder 0 eingeben, um das Budget zurückzusetzen.)
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Monatsbudget speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Monatliches Zwischenziel erstellen -->
<div class="modal fade" id="createMonthlyGoalModal" tabindex="-1" aria-labelledby="createMonthlyGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST">
                <input type="hidden" name="action" value="create_monthly_goal">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createMonthlyGoalModalLabel">Monatliches Zwischenziel planen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="create_mg_goal_id" class="form-label fw-semibold">Zugeordnetes 6-Monats-Lernziel <span class="text-danger">*</span></label>
                        <select class="form-select" id="create_mg_goal_id" name="goal_id" required>
                            <option value="" disabled selected>Bitte 6-Monats-Lernziel wählen…</option>
                            <?php foreach ($goals as $gOption): ?>
                                <?php
                                    $optAlloc = (float) ($gOption['allocated_hours'] ?? 0.0);
                                    $optTarget = (float) $gOption['target_hours'];
                                ?>
                                <option value="<?= (int) $gOption['id'] ?>">
                                    <?= htmlspecialchars((string) $gOption['title']) ?> (<?= number_format($optAlloc, 1, ',', '.') ?> / <?= number_format($optTarget, 1, ',', '.') ?> h verplant)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label for="create_mg_year_month" class="form-label fw-semibold">Planungsmonat <span class="text-danger">*</span></label>
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
                        <label for="create_mg_title" class="form-label fw-semibold">Zwischenziel / Schwerpunkt im Monat <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="create_mg_title"
                            name="title"
                            maxlength="150"
                            placeholder="z. B. Skript Kapitel 1–3 durcharbeiten & zusammenfassen"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="create_mg_description" class="form-label fw-semibold">Notizen zum Zwischenziel</label>
                        <textarea
                            class="form-control"
                            id="create_mg_description"
                            name="description"
                            rows="2"
                            maxlength="2000"
                            placeholder="Optionale Details oder Meilensteine für diesen Monat..."
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

<!-- Modal: Monatliches Zwischenziel bearbeiten -->
<div class="modal fade" id="editMonthlyGoalModal" tabindex="-1" aria-labelledby="editMonthlyGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST">
                <input type="hidden" name="action" value="update_monthly_goal">
                <input type="hidden" name="monthly_goal_id" id="edit_mg_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editMonthlyGoalModalLabel">Zwischenziel bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_mg_goal_id" class="form-label fw-semibold">Zugeordnetes 6-Monats-Lernziel <span class="text-danger">*</span></label>
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
                            <label for="edit_mg_year_month" class="form-label fw-semibold">Planungsmonat <span class="text-danger">*</span></label>
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
                        <label for="edit_mg_title" class="form-label fw-semibold">Zwischenziel / Schwerpunkt im Monat <span class="text-danger">*</span></label>
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
                        <label for="edit_mg_description" class="form-label fw-semibold">Notizen zum Zwischenziel</label>
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

<!-- Modal: Monatliches Zwischenziel löschen -->
<div class="modal fade" id="deleteMonthlyGoalModal" tabindex="-1" aria-labelledby="deleteMonthlyGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST">
                <input type="hidden" name="action" value="delete_monthly_goal">
                <input type="hidden" name="monthly_goal_id" id="delete_mg_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="deleteMonthlyGoalModalLabel">Zwischenziel löschen?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Möchtest du das folgende monatliche Zwischenziel wirklich löschen?
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
$extraScripts = '<script src="assets/js/goals.js"></script>';
require_once __DIR__ . '/includes/footer.php';
?>

