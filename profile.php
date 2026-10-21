<?php
// profile.php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Classes/User.php';

use Classes\User;
use Classes\ValidationException;

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
$userModel = new User($db);

$errorMessage = '';
$successMessage = '';

// Fallback für reguläre POST-Formularübermittlung (ohne JavaScript)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $displayName = is_string($_POST['display_name'] ?? null) ? trim($_POST['display_name']) : '';
    $studyProgram = is_string($_POST['study_program'] ?? null) ? trim($_POST['study_program']) : '';
    $semesterRaw = $_POST['semester'] ?? '';

    $studyProgram = $studyProgram === '' ? null : $studyProgram;
    $semester = ($semesterRaw === '' || $semesterRaw === null) ? null : (int) $semesterRaw;

    try {
        $userModel->updateProfile($userId, $displayName, $studyProgram, $semester);
        $successMessage = 'Profildaten erfolgreich gespeichert.';
    } catch (ValidationException $e) {
        $errorMessage = $e->getMessage();
    } catch (Throwable $e) {
        error_log((string) $e);
        $errorMessage = 'Es ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.';
    }
}

// Aktuelle Profildaten aus der MariaDB laden
$user = $userModel->findById($userId);
if (!$user) {
    User::logout();
    header("Location: login.php");
    exit;
}

$pageTitle = 'Profil – Lernzeit-Manager';
$activeNav = 'profile';

// Header einbinden
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-7 col-md-9">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5">
                <div class="mb-4">
                    <h2 class="h4 text-primary fw-bold mb-1">Mein Profil</h2>
                    <p class="text-muted small">
                        Hier kannst du deine persönlichen Angaben wie Studiengang und aktuelles Semester verwalten.
                    </p>
                </div>

                <!-- Alert-Container für serverseitige Meldungen (Fallback) -->
                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger mb-4" role="alert">
                        <?= htmlspecialchars($errorMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage !== ''): ?>
                    <div class="alert alert-success mb-4" role="alert">
                        <?= htmlspecialchars($successMessage) ?>
                    </div>
                <?php endif; ?>

                <!-- Dynamischer Alert-Container für JavaScript fetch() -->
                <div id="profile-alert" class="d-none mb-4" role="alert"></div>

                <form id="profile-form" action="profile.php" method="POST" novalidate>
                    <!-- E-Mail (schreibgeschützt) -->
                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">E-Mail-Adresse</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control bg-light"
                            value="<?= htmlspecialchars((string) $user['email']) ?>"
                            disabled
                            aria-describedby="emailHelp">
                        <div id="emailHelp" class="form-text">
                            Deine E-Mail-Adresse dient als Login-Kennung und kann nicht geändert werden.
                        </div>
                    </div>

                    <!-- Anzeigename -->
                    <div class="mb-3">
                        <label for="display_name" class="form-label fw-semibold">Anzeigename</label>
                        <input
                            type="text"
                            id="display_name"
                            name="display_name"
                            class="form-control"
                            maxlength="<?= User::MAX_DISPLAY_NAME_LENGTH ?>"
                            placeholder="z. B. Max Mustermann"
                            value="<?= htmlspecialchars((string) ($user['display_name'] ?? '')) ?>"
                            aria-describedby="displayNameHelp">
                        <div id="displayNameHelp" class="form-text">
                            Optional. Wird in der Kopfzeile und bei Aktivitäten angezeigt (max. <?= User::MAX_DISPLAY_NAME_LENGTH ?> Zeichen).
                        </div>
                    </div>

                    <div class="row">
                        <!-- Studiengang -->
                        <div class="col-md-8 mb-3">
                            <label for="study_program" class="form-label fw-semibold">Studiengang</label>
                            <input
                                type="text"
                                id="study_program"
                                name="study_program"
                                class="form-control"
                                maxlength="<?= User::MAX_STUDY_PROGRAM_LENGTH ?>"
                                placeholder="z. B. Informatik B.Sc."
                                value="<?= htmlspecialchars((string) ($user['study_program'] ?? '')) ?>"
                                aria-describedby="studyProgramHelp">
                            <div id="studyProgramHelp" class="form-text">
                                Dein aktueller Studiengang (max. <?= User::MAX_STUDY_PROGRAM_LENGTH ?> Zeichen).
                            </div>
                        </div>

                        <!-- Semester -->
                        <div class="col-md-4 mb-3">
                            <label for="semester" class="form-label fw-semibold">Semester</label>
                            <input
                                type="number"
                                id="semester"
                                name="semester"
                                class="form-control"
                                min="<?= User::MIN_SEMESTER ?>"
                                max="<?= User::MAX_SEMESTER ?>"
                                placeholder="z. B. 3"
                                value="<?= htmlspecialchars((string) ($user['semester'] ?? '')) ?>"
                                aria-describedby="semesterHelp">
                            <div id="semesterHelp" class="form-text">
                                Fachsemester (<?= User::MIN_SEMESTER ?>–<?= User::MAX_SEMESTER ?>).
                            </div>
                        </div>
                    </div>

                    <hr class="my-4 text-muted">

                    <div class="d-flex justify-content-end gap-2">
                        <a href="index.php" class="btn btn-outline-secondary">Abbrechen</a>
                        <button type="submit" id="submit-btn" class="btn btn-primary px-4">
                            Profil speichern
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// Footer einbinden und clientseitiges JavaScript laden
$extraScripts = '<script src="assets/js/profile.js"></script>';
require_once __DIR__ . '/includes/footer.php';
?>
