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

// Formularverarbeitung für Create, Update und Delete.
// Nach erfolgreicher Aktion wird per Redirect auf GET umgeleitet (Post/Redirect/Get),
// damit ein Neuladen der Seite die Aktion nicht erneut ausführt.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $goalController->createGoal($userId, $_POST);
            $_SESSION['goals_success'] = 'Das Lernziel wurde erfolgreich angelegt.';
            header('Location: goals.php');
            exit;
        } elseif ($action === 'update') {
            $goalId = isset($_POST['goal_id']) ? (int) $_POST['goal_id'] : 0;
            $goalController->updateGoal($goalId, $userId, $_POST);
            $_SESSION['goals_success'] = 'Das Lernziel wurde erfolgreich aktualisiert.';
            header('Location: goals.php');
            exit;
        } elseif ($action === 'delete') {
            $goalId = isset($_POST['goal_id']) ? (int) $_POST['goal_id'] : 0;
            $goalController->deleteGoal($goalId, $userId);
            $_SESSION['goals_success'] = 'Das Lernziel wurde erfolgreich gelöscht.';
            header('Location: goals.php');
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

<!-- Kennzahlen-Übersicht -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <span class="text-muted small text-uppercase fw-semibold d-block">Geplante Module / Ziele</span>
                <span class="h3 fw-bold text-dark mb-0"><?= count($goals) ?></span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <span class="text-muted small text-uppercase fw-semibold d-block">Geplante Gesamtlernzeit</span>
                <span class="h3 fw-bold text-primary mb-0"><?= number_format($totalHours, 1, ',', '.') ?> <span class="fs-6 text-muted fw-normal">Stunden</span></span>
            </div>
        </div>
    </div>
    <div class="col-sm-12 col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <span class="text-muted small text-uppercase fw-semibold d-block">Planungszeitraum</span>
                <span class="h5 fw-bold text-secondary mb-0">6 Monate Grobplanung</span>
            </div>
        </div>
    </div>
</div>

<!-- Liste der Lernziele -->
<?php if (empty($goals)): ?>
    <div class="card shadow-sm border-0 text-center py-5">
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
                Lege deine Module und geplante Lernzeit für die nächsten 6 Monate fest, um dein Semester optimal vorzubereiten.
            </p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createGoalModal">
                Erstes Lernziel anlegen
            </button>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="min-width: 200px;">Modul / Lernziel</th>
                        <th scope="col" class="text-end" style="min-width: 120px;">Zielstunden</th>
                        <th scope="col" style="min-width: 200px;">Zeitraum</th>
                        <th scope="col" style="min-width: 180px;">Beschreibung / Notizen</th>
                        <th scope="col" class="text-end" style="min-width: 140px;">Aktionen</th>
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
                        ?>
                        <tr>
                            <td>
                                <strong class="text-dark d-block"><?= htmlspecialchars((string) $goal['title']) ?></strong>
                                <span class="text-muted small">Erstellt am <?= date('d.m.Y', strtotime((string) $goal['created_at'])) ?></span>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold px-2 py-1">
                                    <?= number_format((float) $goal['target_hours'], 1, ',', '.') ?> h
                                </span>
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

<!-- Modal: Lernziel erstellen -->
<div class="modal fade" id="createGoalModal" tabindex="-1" aria-labelledby="createGoalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="goals.php" method="POST">
                <input type="hidden" name="action" value="create">
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
                <input type="hidden" name="goal_id" id="delete_goal_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="deleteGoalModalLabel">Lernziel löschen?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Möchtest du das folgende Lernziel wirklich unwiderruflich löschen?
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

<?php
$extraScripts = '<script src="assets/js/goals.js"></script>';
require_once __DIR__ . '/includes/footer.php';
?>

