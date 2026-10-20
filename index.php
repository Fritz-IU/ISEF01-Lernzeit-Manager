<?php
// index.php
// Einstiegspunkt des Projekts. Die Startseite ist ein Platzhalter und wird durch das Dashboard ersetzt.

declare(strict_types=1);

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

// Anzeigename ist optional – ohne Namen wird die E-Mail-Adresse gezeigt
$displayName = $_SESSION['user_display_name'] ?? '';
if ($displayName === '') {
    $displayName = $_SESSION['user_email'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lernzeit-Manager</title>
    <!-- Ui mit Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
</head>

<body class="bg-light d-flex align-items-center min-vh-100">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 text-center">
                        <h3 class="card-title mb-4 text-primary">Lernzeit-Manager</h3>

                        <p class="mb-1">Angemeldet als <strong><?= htmlspecialchars($displayName) ?></strong></p>
                        <p class="text-muted mb-4">Das Dashboard folgt in einer späteren Story.</p>

                        <form action="logout.php" method="POST">
                            <button type="submit" class="btn btn-outline-secondary w-100">Abmelden</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>

</html>
