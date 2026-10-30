<?php
// includes/manual-time-modal.php
// Wiederverwendbares Bootstrap-Modal für die manuelle Zeitnachbuchung (Issue #9).
// Kann auf beliebigen Seiten (z. B. index.php, Dashboard, goals.php) vor includes/footer.php eingebunden werden.

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Classes/User.php';
require_once __DIR__ . '/../src/DAO/TimeEntryDAO.php';
require_once __DIR__ . '/../src/Controllers/TimeEntryController.php';

use Classes\User;
use Controllers\TimeEntryController;
use DAO\TimeEntryDAO;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!User::isLoggedIn()) {
    return;
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$manualModalCsrfToken = $_SESSION['csrf_token'];

$manualModalUserId = (int) $_SESSION['user_id'];
$manualModalDb = isset($db) && $db instanceof PDO ? $db : getDatabaseConnection();
$manualModalDao = new TimeEntryDAO($manualModalDb);
$manualModalController = new TimeEntryController($manualModalDao);

$manualTimeGoalGroups = $manualModalController->getSelectableGoalsGroupedBySemester($manualModalUserId);
$hasSelectableManualGoals = !empty($manualTimeGoalGroups);
$defaultManualEntryDate = date('Y-m-d');

// Zugehöriges JavaScript automatisch für includes/footer.php vormerken
$manualTimeJsPath = __DIR__ . '/../assets/js/manual-time.js';
$manualTimeJsVersion = is_file($manualTimeJsPath) ? (string) filemtime($manualTimeJsPath) : '1';
$manualTimeScriptTag = '<script src="assets/js/manual-time.js?v=' . $manualTimeJsVersion . '"></script>';
if (!isset($extraScripts) || !is_string($extraScripts)) {
    $extraScripts = $manualTimeScriptTag;
} elseif (!str_contains($extraScripts, 'assets/js/manual-time.js')) {
    $extraScripts .= "\n" . $manualTimeScriptTag;
}
?>

<!-- Modal: Lernzeit manuell nachbuchen (Issue #9) -->
<div class="modal fade" id="manualTimeModal" tabindex="-1" aria-labelledby="manualTimeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="api/time-entries.php" method="POST" id="manualTimeForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($manualModalCsrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="action" id="manual_time_action" value="create_manual_entry">
                <input type="hidden" name="entry_id" id="manual_time_entry_id" value="">
                <input type="hidden" name="learning_slot_id" id="manual_time_learning_slot_id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="manualTimeModalLabel">Lernzeit nachbuchen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>

                <div class="modal-body">
                    <div id="manual-time-alert" class="alert alert-danger d-none" role="alert"></div>

                    <?php if (!$hasSelectableManualGoals): ?>
                        <div class="alert alert-light border mb-0">
                            <strong class="text-dark d-block mb-1">Noch keine Module (Zwischenziele) vorhanden</strong>
                            <p class="text-muted small mb-3">
                                Um Lernzeiten nachbuchen und einem Ziel zuordnen zu können, lege bitte zuerst unter „Planen“ ein Semester (Lernziel) und mindestens ein Modul (Zwischenziel) an.
                            </p>
                            <a href="goals.php?tab=month" class="btn btn-sm btn-primary">
                                Zur Lernzielplanung
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <label for="manual_time_monthly_goal_id" class="form-label fw-semibold">
                                Zugeordnetes Ziel (Modul) <span class="text-danger">*</span>
                            </label>
                            <select
                                class="form-select"
                                id="manual_time_monthly_goal_id"
                                name="monthly_goal_id"
                                required>
                                <option value="" disabled selected>Bitte Modul auswählen…</option>
                                <?php foreach ($manualTimeGoalGroups as $group): ?>
                                    <optgroup label="<?= htmlspecialchars((string) $group['goal_title'], ENT_QUOTES) ?>">
                                        <?php foreach ($group['items'] as $goalItem): ?>
                                            <option value="<?= (int) $goalItem['id'] ?>">
                                                <?= htmlspecialchars((string) $goalItem['option_label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Wähle das Modul aus, auf das die geleistete Lernzeit angerechnet werden soll.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="manual_time_entry_date" class="form-label fw-semibold">
                                Lerntag (Datum) <span class="text-danger">*</span>
                            </label>
                            <input
                                type="date"
                                class="form-control"
                                id="manual_time_entry_date"
                                name="entry_date"
                                value="<?= htmlspecialchars($defaultManualEntryDate, ENT_QUOTES) ?>"
                                max="<?= htmlspecialchars($defaultManualEntryDate, ENT_QUOTES) ?>"
                                data-default="<?= htmlspecialchars($defaultManualEntryDate, ENT_QUOTES) ?>"
                                required>
                            <div class="form-text">
                                Nachbuchungen sind für heute oder vergangene Tage möglich.
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold mb-1">
                                Geleistete Lernzeit (Netto-Dauer) <span class="text-danger">*</span>
                            </label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="input-group">
                                        <input
                                            type="number"
                                            class="form-control"
                                            id="manual_time_duration_hours"
                                            name="duration_hours"
                                            min="0"
                                            max="24"
                                            step="1"
                                            value="1"
                                            data-default="1"
                                            aria-label="Stunden"
                                            required>
                                        <span class="input-group-text">Std.</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="input-group">
                                        <input
                                            type="number"
                                            class="form-control"
                                            id="manual_time_duration_minutes"
                                            name="duration_minutes"
                                            min="0"
                                            max="59"
                                            step="1"
                                            value="0"
                                            data-default="0"
                                            aria-label="Minuten"
                                            required>
                                        <span class="input-group-text">Min.</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex flex-wrap align-items-center gap-1 mb-1" role="group" aria-label="Schnellauswahl für die Dauer">
                                <span class="text-muted small me-1">Schnellwahl:</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 manual-time-preset-btn" data-quick-minutes="15">15 Min.</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 manual-time-preset-btn" data-quick-minutes="30">30 Min.</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 manual-time-preset-btn" data-quick-minutes="45">45 Min.</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 manual-time-preset-btn" data-quick-minutes="60">1 Std.</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 manual-time-preset-btn" data-quick-minutes="90">1,5 Std.</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 manual-time-preset-btn" data-quick-minutes="120">2 Std.</button>
                            </div>
                            <div class="form-text" id="manual_time_duration_hint">
                                Erfasste Netto-Lernzeit: <strong>1 Std.</strong>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label for="manual_time_note" class="form-label fw-semibold">
                                Notiz <span class="text-muted fw-normal small">(optional)</span>
                            </label>
                            <input
                                type="text"
                                class="form-control"
                                id="manual_time_note"
                                name="note"
                                maxlength="255"
                                placeholder="z. B. Offline in der Bibliothek gelernt, Übungsaufgaben gelöst">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <?php if ($hasSelectableManualGoals): ?>
                        <button type="submit" class="btn btn-primary" id="manualTimeSubmitBtn">
                            Lernzeit speichern
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
