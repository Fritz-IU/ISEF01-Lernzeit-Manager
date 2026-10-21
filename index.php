<?php
// index.php
// Einstiegspunkt des Projekts. Die Startseite ist ein Platzhalter und wird durch das Dashboard ersetzt.

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Classes/User.php';

use Classes\User;

// Session starten, falls noch nicht aktiv
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Nicht eingeloggte Nutzer direkt zum Login weiterleiten
if (!User::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$db = getDatabaseConnection();
$userModel = new User($db);
$user = $userModel->findById($userId);

// Anzeigename ermitteln
$displayName = $user['display_name'] ?? '';
if ($displayName === '') {
    $displayName = $user['email'] ?? ($_SESSION['user_email'] ?? '');
}

$studyProgram = $user['study_program'] ?? null;
$semester = $user['semester'] ?? null;

$pageTitle = 'Dashboard – Lernzeit-Manager';
$activeNav = 'dashboard';

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body p-4 p-md-5 text-center">
                <h2 class="card-title h3 mb-3 text-primary">Willkommen, <?= htmlspecialchars((string) $displayName) ?>!</h2>
                <p class="text-muted mb-4">
                    Das Dashboard zur Erfassung von Lernzeiten und Zielen folgt in einer späteren Story.
                </p>

                <div class="row g-3 justify-content-center mb-4 text-start">
                    <div class="col-sm-6 col-md-5">
                        <div class="p-3 border rounded bg-white h-100">
                            <span class="text-muted small d-block">Studiengang</span>
                            <span class="fw-semibold text-dark">
                                <?= $studyProgram !== null ? htmlspecialchars((string) $studyProgram) : '<span class="text-muted fst-italic">Noch nicht hinterlegt</span>' ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 border rounded bg-white h-100">
                            <span class="text-muted small d-block">Semester</span>
                            <span class="fw-semibold text-dark">
                                <?= $semester !== null ? htmlspecialchars((string) $semester) . '. Semester' : '<span class="text-muted fst-italic">Noch nicht hinterlegt</span>' ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div>
                    <a href="profile.php" class="btn btn-outline-primary">
                        Profil anpassen
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
