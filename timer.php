<?php
// timer.php
// Stoppuhr und Zeiterfassung für Lernziele (US-4)

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Classes/User.php';
require_once __DIR__ . '/src/Classes/ValidationException.php';
require_once __DIR__ . '/src/DAO/TimeTrackingDAO.php';
require_once __DIR__ . '/src/Controllers/TimeTrackingController.php';

use Classes\User;
use Classes\ValidationException;
use Controllers\TimeTrackingController;
use DAO\TimeTrackingDAO;

// Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Zugriff nur für angemeldete Studierende
if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// CSRF-Token pro Session sicherstellen
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$userId = (int) $_SESSION['user_id'];
$db = getDatabaseConnection();
$timeTrackingDAO = new TimeTrackingDAO($db);
$timeTrackingController = new TimeTrackingController($timeTrackingDAO);

$errorMessage = '';
$successMessage = (string) ($_SESSION['timer_success'] ?? '');
unset($_SESSION['timer_success']);

// Formularverarbeitung (Post/Redirect/Get)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

    try {
        $submittedCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!hash_equals($csrfToken, $submittedCsrf)) {
            throw new ValidationException('Ungültige Sitzungsanfrage. Bitte lade die Seite neu und versuche es erneut.');
        }

        if ($action === 'start') {
            $timeTrackingController->startStopwatch($userId, $_POST);
            header('Location: timer.php');
            exit;
        } elseif ($action === 'pause') {
            $timeTrackingController->pauseStopwatch($userId);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
            header('Location: timer.php');
            exit;
        } elseif ($action === 'resume') {
            $timeTrackingController->resumeStopwatch($userId);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
            header('Location: timer.php');
            exit;
        } elseif ($action === 'stop') {
            $timeTrackingController->stopAndSaveStopwatch($userId, $_POST);
            $_SESSION['timer_success'] = 'Lerneinheit erfolgreich gespeichert!';
            header('Location: timer.php');
            exit;
        } elseif ($action === 'discard') {
            $timeTrackingController->discardStopwatch($userId);
            header('Location: timer.php');
            exit;
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) && is_scalar($_POST['id']) ? (int) $_POST['id'] : 0;
            $timeTrackingController->deleteTimeEntry($id, $userId);
            $_SESSION['timer_success'] = 'Zeiteintrag gelöscht.';
            header('Location: timer.php');
            exit;
        } else {
            $errorMessage = 'Ungültige Aktion.';
        }
    } catch (ValidationException $e) {
        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $errorMessage = $e->getMessage();
    } catch (Throwable $e) {
        error_log((string) $e);
        if ($isAjax) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Serverfehler']);
            exit;
        }
        $errorMessage = 'Es ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.';
    }
}

// Daten für die Ansicht laden
$currentStopwatch = $timeTrackingController->getCurrentStopwatch($userId);
$availableGoals = $timeTrackingController->getAvailableGoals($userId);
$timeEntries = $timeTrackingController->getTimeEntries($userId, null, 30);
$totalSeconds = $timeTrackingController->getTotalTrackedSeconds($userId);

// Heute erfasste Zeit berechnen
$today = date('Y-m-d');
$todaySeconds = 0;
foreach ($timeEntries as $entry) {
    if (($entry['entry_date'] ?? '') === $today) {
        $todaySeconds += (int) $entry['tracked_seconds'];
    }
}
if ($currentStopwatch !== null && ($currentStopwatch['entry_date'] ?? '') === $today) {
    $todaySeconds += (int) $currentStopwatch['current_tracked_seconds'];
}

$pageTitle = 'Stoppuhr & Zeiterfassung – Lernzeit-Manager';
$activeNav = 'timer';

require_once __DIR__ . '/includes/header.php';
?>

<!-- Kopfzeile -->
<div class="row align-items-center mb-4">
    <div class="col-12">
        <h1 class="h3 fw-bold text-primary mb-1">Stoppuhr & Zeiterfassung</h1>
        <p class="text-muted mb-0">
            Erfasse deine Lernzeiten live per Stoppuhr für deine Lernziele.
        </p>
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

<!-- Kennzahlen-Kacheln -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <span class="text-muted small text-uppercase fw-semibold d-block">Heute gelernt</span>
                <span class="h3 fw-bold text-primary mb-0"><?= TimeTrackingController::formatSecondsHuman($todaySeconds) ?></span>
                <span class="text-muted small d-block mt-1"><?= TimeTrackingController::formatSecondsToTime($todaySeconds) ?></span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <span class="text-muted small text-uppercase fw-semibold d-block">Gesamtlernzeit erfasst</span>
                <span class="h3 fw-bold text-dark mb-0"><?= TimeTrackingController::formatSecondsHuman($totalSeconds) ?></span>
                <span class="text-muted small d-block mt-1"><?= number_format(round($totalSeconds / 3600.0, 1), 1, ',', '.') ?> Stunden</span>
            </div>
        </div>
    </div>
    <div class="col-sm-12 col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <span class="text-muted small text-uppercase fw-semibold d-block">Erfasste Einheiten</span>
                <span class="h3 fw-bold text-secondary mb-0"><?= count($timeEntries) ?></span>
                <span class="text-muted small d-block mt-1">Lern-Sessions</span>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Hauptkachel: Stoppuhr-Zentrum -->
<!-- ========================================================================= -->
<div class="card timer-card shadow-sm mb-4">
    <div class="card-body p-4 p-md-5 text-center">

        <?php if ($currentStopwatch !== null): ?>
            <!-- Aktive Stoppuhr (läuft oder pausiert) -->
            <?php
                $isRunning = (bool) $currentStopwatch['is_running'];
                $initialSeconds = (int) $currentStopwatch['current_tracked_seconds'];
            ?>

            <div class="d-inline-flex align-items-center gap-2 mb-3" id="timer-status-container">
                <?php if ($isRunning): ?>
                    <span class="timer-pulse-dot" id="timer-pulse-dot"></span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle fs-6 px-3 py-1" id="timer-status-badge">
                        Stoppuhr läuft
                    </span>
                <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fs-6 px-3 py-1" id="timer-status-badge">
                        Pausiert
                    </span>
                <?php endif; ?>
            </div>

            <!-- Ziel-Information -->
            <div class="mb-4">
                <span class="text-muted small d-block">Aktuelles Modul / Lernziel:</span>
                <h2 class="h4 fw-bold text-dark mb-1">
                    <?= htmlspecialchars((string) $currentStopwatch['goal_title']) ?>
                </h2>
                <span class="badge bg-secondary-subtle text-secondary">
                    <?= htmlspecialchars((string) $currentStopwatch['parent_goal_title']) ?>
                    <?php if (!empty($currentStopwatch['plan_month'])): ?>
                        &ndash; <?= htmlspecialchars((string) $currentStopwatch['plan_month']) ?>
                    <?php endif; ?>
                </span>
                <?php if (!empty($currentStopwatch['note'])): ?>
                    <p class="text-muted small mt-2 mb-0 fst-italic">
                        &bdquo;<?= htmlspecialchars((string) $currentStopwatch['note']) ?>&ldquo;
                    </p>
                <?php endif; ?>
            </div>

            <!-- Digitale Zeitanzeige -->
            <div class="my-4">
                <div
                    id="timer-display"
                    class="timer-display"
                    data-is-running="<?= $isRunning ? '1' : '0' ?>"
                    data-initial-seconds="<?= $initialSeconds ?>"
                >
                    <?= htmlspecialchars($currentStopwatch['formatted_time']) ?>
                </div>
                <div class="text-muted small mt-2">
                    Start: <?= date('H:i', strtotime((string) $currentStopwatch['started_at'])) ?> Uhr am <?= date('d.m.Y', strtotime((string) $currentStopwatch['started_at'])) ?>
                </div>
            </div>

            <!-- Stoppuhr-Aktionsbuttons -->
            <div class="d-flex justify-content-center gap-2 gap-md-3 flex-wrap mt-4" id="timer-controls">
                <!-- Pause -->
                <form action="timer.php" method="POST" class="m-0 <?= !$isRunning ? 'd-none' : '' ?>" id="form-pause-timer">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                    <input type="hidden" name="action" value="pause">
                    <button type="submit" class="btn btn-warning px-4 py-2 fw-semibold">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-pause-fill me-1 align-text-bottom" viewBox="0 0 16 16">
                            <path d="M5.5 3.5A1.5 1.5 0 0 1 7 5v6a1.5 1.5 0 0 1-3 0V5a1.5 1.5 0 0 1 1.5-1.5m5 0A1.5 1.5 0 0 1 12 5v6a1.5 1.5 0 0 1-3 0V5a1.5 1.5 0 0 1 1.5-1.5"/>
                        </svg>
                        Pause
                    </button>
                </form>

                <!-- Weiter -->
                <form action="timer.php" method="POST" class="m-0 <?= $isRunning ? 'd-none' : '' ?>" id="form-resume-timer">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                    <input type="hidden" name="action" value="resume">
                    <button type="submit" class="btn btn-success px-4 py-2 fw-semibold">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-play-fill me-1 align-text-bottom" viewBox="0 0 16 16">
                            <path d="m11.596 8.697-6.363 3.692c-.54.313-1.233-.066-1.233-.697V4.308c0-.63.692-1.01 1.233-.696l6.363 3.692a.802.802 0 0 1 0 1.393"/>
                        </svg>
                        Weiterlernen
                    </button>
                </form>

                <!-- Stoppen & Speichern (Modal öffnen) -->
                <button
                    type="button"
                    class="btn btn-primary px-4 py-2 fw-semibold"
                    data-bs-toggle="modal"
                    data-bs-target="#stopTimerModal"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-stop-fill me-1 align-text-bottom" viewBox="0 0 16 16">
                        <path d="M5 3.5h6A1.5 1.5 0 0 1 12.5 5v6a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 11V5A1.5 1.5 0 0 1 5 3.5"/>
                    </svg>
                    Stopp & Speichern
                </button>

                <!-- Verwerfen (Modal öffnen) -->
                <button
                    type="button"
                    class="btn btn-outline-danger px-3 py-2"
                    data-bs-toggle="modal"
                    data-bs-target="#discardTimerModal"
                >
                    Verwerfen
                </button>
            </div>

        <?php else: ?>
            <!-- Keine aktive Stoppuhr: Start-Formular -->
            <span class="badge bg-secondary-subtle text-secondary fs-6 px-3 py-1 mb-3">
                Bereit zur Zeiterfassung
            </span>

            <div class="my-3">
                <div id="timer-display" class="timer-display text-muted" data-is-running="0" data-initial-seconds="0">
                    00:00:00
                </div>
            </div>

            <?php if (empty($availableGoals)): ?>
                <div class="alert alert-info max-w-600 mx-auto my-4 text-start">
                    <h6 class="fw-bold mb-1">Noch kein Modul angelegt</h6>
                    <p class="small mb-2">
                        Um Lernzeit zu erfassen, lege bitte zuerst mindestens ein Modul bzw. Lernziel an.
                    </p>
                    <a href="goals.php" class="btn btn-sm btn-primary">Jetzt Lernziel anlegen</a>
                </div>
            <?php else: ?>
                <form action="timer.php" method="POST" id="start-timer-form" class="max-w-500 mx-auto mt-4 text-start">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                    <input type="hidden" name="action" value="start">

                    <div class="mb-3">
                        <label for="timer_goal_id" class="form-label fw-semibold">Modul / Lernziel auswählen <span class="text-danger">*</span></label>
                        <select name="goal_id" id="timer_goal_id" class="form-select form-select-lg" required>
                            <option value="">-- Bitte Modul auswählen --</option>
                            <?php foreach ($availableGoals as $g): ?>
                                <option value="<?= (int) $g['id'] ?>">
                                    <?= htmlspecialchars((string) $g['title']) ?> (<?= htmlspecialchars((string) $g['parent_goal_title']) ?><?= !empty($g['plan_month']) ? ' &ndash; ' . htmlspecialchars((string) $g['plan_month']) : '' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label for="timer_note" class="form-label fw-semibold">Notiz zur Einheit (optional)</label>
                        <input
                            type="text"
                            name="note"
                            id="timer_note"
                            class="form-control"
                            maxlength="255"
                            placeholder="z. B. Kapitel 3 lesen, Übungsaufgaben lösen..."
                        >
                    </div>

                    <div class="text-center">
                        <button type="submit" class="btn btn-success btn-lg px-5 py-3 fw-bold shadow-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" class="bi bi-play-fill me-1 align-text-bottom" viewBox="0 0 16 16">
                                <path d="m11.596 8.697-6.363 3.692c-.54.313-1.233-.066-1.233-.697V4.308c0-.63.692-1.01 1.233-.696l6.363 3.692a.802.802 0 0 1 0 1.393"/>
                            </svg>
                            Stoppuhr starten
                        </button>
                    </div>
                </form>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<!-- ========================================================================= -->
<!-- Tabelle: Kürzlich erfasste Zeiteinträge -->
<!-- ========================================================================= -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom">
        <h2 class="h5 fw-bold text-dark mb-0">Erfasste Lerneinheiten</h2>
    </div>
    <?php if (empty($timeEntries)): ?>
        <div class="card-body text-center py-5">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-clock-history text-muted mb-3" viewBox="0 0 16 16">
                <path d="M8.515 1.019A7 7 0 0 0 8 1V0a8 8 0 0 1 .589.022zm2.004.45a7 7 0 0 0-.985-.299l.219-.976q.576.129 1.126.342zm1.37.71a7 7 0 0 0-.439-.27l.493-.87a8 8 0 0 1 .979.654l-.615.789a7 7 0 0 0-.418-.302zm1.834 1.79a7 7 0 0 0-.653-.796l.724-.69q.406.429.747.91zm.744 1.352a7 7 0 0 0-.214-.468l.893-.45a8 8 0 0 1 .45 1.088l-.95.313a7 7 0 0 0-.179-.483m.53 2.507a7 7 0 0 0-.1-1.025l.985-.17q.1.58.116 1.17zm-.131 1.538q.05-.254.084-.51l.995.106a8 8 0 0 1-.119.764l-.96-.36zm-.559 1.436q.114-.24.205-.49l.94.342a8 8 0 0 1-.36 1.025l-.785-.877zM8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
                <path d="M8.5 4.5a.5.5 0 0 0-1 0v4.793l2.146 2.147a.5.5 0 0 0 .708-.708L8.5 8.707z"/>
            </svg>
            <h3 class="h6 fw-bold">Noch keine Lerneinheiten erfasst</h3>
            <p class="text-muted small mb-0">Starte oben die Stoppuhr, um deine erste Lernzeit zu dokumentieren.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="min-width: 110px;">Datum</th>
                        <th scope="col" style="min-width: 220px;">Modul / Lernziel</th>
                        <th scope="col" class="text-end" style="min-width: 130px;">Lernzeit</th>
                        <th scope="col" style="min-width: 120px;">Art</th>
                        <th scope="col" style="min-width: 200px;">Notiz</th>
                        <th scope="col" class="text-end" style="min-width: 80px;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timeEntries as $entry): ?>
                        <?php
                            $entryDateFormatted = date('d.m.Y', strtotime((string) $entry['entry_date']));
                            $seconds = (int) $entry['tracked_seconds'];
                            $isStopwatch = ($entry['source'] ?? '') === 'stopwatch';
                        ?>
                        <tr>
                            <td>
                                <span class="fw-semibold text-dark"><?= $entryDateFormatted ?></span>
                                <?php if (!empty($entry['started_at'])): ?>
                                    <span class="text-muted small d-block">
                                        <?= date('H:i', strtotime((string) $entry['started_at'])) ?>
                                        <?php if (!empty($entry['ended_at'])): ?>
                                            &ndash; <?= date('H:i', strtotime((string) $entry['ended_at'])) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong class="text-dark d-block"><?= htmlspecialchars((string) $entry['goal_title']) ?></strong>
                                <span class="text-muted small">
                                    <?= htmlspecialchars((string) $entry['parent_goal_title']) ?>
                                    <?php if (!empty($entry['plan_month'])): ?>
                                        &ndash; <?= htmlspecialchars((string) $entry['plan_month']) ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold px-2 py-1">
                                    <?= TimeTrackingController::formatSecondsHuman($seconds) ?>
                                </span>
                                <span class="text-muted small d-block">
                                    <?= TimeTrackingController::formatSecondsToTime($seconds) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isStopwatch): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        ⏱️ Stoppuhr
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                        ✍️ Manuell
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($entry['note'])): ?>
                                    <span class="small text-dark"><?= htmlspecialchars((string) $entry['note']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small fst-italic">Keine Notiz</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger delete-entry-btn"
                                    title="Eintrag löschen"
                                    data-bs-toggle="modal"
                                    data-bs-target="#deleteEntryModal"
                                    data-id="<?= (int) $entry['id'] ?>"
                                    data-title="<?= htmlspecialchars((string) $entry['goal_title'], ENT_QUOTES) ?>"
                                    data-date="<?= $entryDateFormatted ?>"
                                    data-duration="<?= TimeTrackingController::formatSecondsHuman($seconds) ?>"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-trash" viewBox="0 0 16 16">
                                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/>
                                        <path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- Modal: Stoppuhr stoppen & speichern -->
<!-- ========================================================================= -->
<?php if ($currentStopwatch !== null): ?>
<div class="modal fade" id="stopTimerModal" tabindex="-1" aria-labelledby="stopTimerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="timer.php" method="POST" id="form-stop-timer">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="stop">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="stopTimerModalLabel">Lerneinheit speichern</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="p-3 bg-light rounded text-center mb-3">
                        <span class="text-muted small d-block">Erfasste Lernzeit:</span>
                        <div id="modal-stop-duration" class="h3 fw-bold text-primary mb-0">
                            <?= htmlspecialchars($currentStopwatch['formatted_time']) ?>
                        </div>
                        <span class="small text-dark fw-semibold d-block mt-1">
                            <?= htmlspecialchars((string) $currentStopwatch['goal_title']) ?>
                        </span>
                    </div>

                    <div class="mb-3">
                        <label for="stop_note" class="form-label fw-semibold">Notiz zur Einheit (optional)</label>
                        <textarea
                            name="note"
                            id="stop_note"
                            class="form-control"
                            rows="2"
                            maxlength="255"
                            placeholder="Was hast du bearbeitet oder geschafft?"
                        ><?= htmlspecialchars((string) ($currentStopwatch['note'] ?? '')) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="btn-resume-timer">Weiterlaufen lassen</button>
                    <button type="submit" class="btn btn-primary fw-semibold">Lernzeit speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Stoppuhr verwerfen -->
<div class="modal fade" id="discardTimerModal" tabindex="-1" aria-labelledby="discardTimerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="timer.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="discard">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="discardTimerModalLabel">Stoppuhr verwerfen?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">
                        Möchtest du die aktuell laufende Zeiterfassung wirklich verwerfen?
                        Die bisher gemessene Zeit wird dabei <strong>nicht gespeichert</strong>.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger fw-semibold">Unwiderruflich verwerfen</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- Modal: Zeiteintrag löschen -->
<!-- ========================================================================= -->
<div class="modal fade" id="deleteEntryModal" tabindex="-1" aria-labelledby="deleteEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="timer.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_entry_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="deleteEntryModalLabel">Zeiteintrag löschen?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Möchtest du diesen Zeiteintrag wirklich löschen?</p>
                    <div class="p-2 border rounded bg-light small">
                        <div><strong>Modul:</strong> <span id="delete_entry_title"></span></div>
                        <div><strong>Datum:</strong> <span id="delete_entry_date"></span></div>
                        <div><strong>Dauer:</strong> <span id="delete_entry_duration"></span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger fw-semibold">Löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = '<script src="assets/js/timer.js?v=' . filemtime(__DIR__ . '/assets/js/timer.js') . '"></script>';
require_once __DIR__ . '/includes/footer.php';
?>

