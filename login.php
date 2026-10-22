<?php
// login.php

declare(strict_types=1);

// Zentrale Session- und Datenbank-Konfiguration laden
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Classes/User.php';

use Classes\User;
use Classes\ValidationException;

// Session starten, falls noch nicht aktiv
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Falls der Nutzer bereits eingeloggt ist, direkt zum Dashboard weiterleiten
if (User::isLoggedIn()) {
    header("Location: index.php");
    exit;
}

/**
 * Liest ein Formularfeld als String. Fehlende oder manipulierte Felder (z. B. email[]=x) ergeben ''.
 */
function postString(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

$errorMessage = '';
$successMessage = '';
$activeTab = 'login'; // Welches Tab (Login oder Registrierung) angezeigt werden soll

// Eingaben, die nach dem Absenden wieder ins Formular eingetragen werden (Passwörter nie)
$loginEmail = '';
$registerEmail = '';
$registerDisplayName = '';

// Formularverarbeitung bei POST-Anfrage
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getDatabaseConnection(); // Verbindung aus config/database.php
        $userModel = new User($db);

        // Aktion 1: Registrierung
        if (postString('action') === 'register') {
            $activeTab = 'register';
            $email = postString('email');
            $password = postString('password');
            $passwordRepeat = postString('password_repeat');
            $displayName = postString('display_name');
            $registerEmail = $email;
            $registerDisplayName = $displayName;

            if ($password !== $passwordRepeat) {
                throw new ValidationException("Die eingegebenen Passwörter stimmen nicht überein.");
            }

            if ($userModel->register($email, $password, $displayName)) {
                $successMessage = "Registrierung erfolgreich! Du kannst dich jetzt einloggen.";
                $activeTab = 'login'; // Nach Erfolg zum Login wechseln
                $loginEmail = trim($email);
                $registerEmail = '';
                $registerDisplayName = '';
            }
        }
        // Aktion 2: Login
        elseif (postString('action') === 'login') {
            $activeTab = 'login';
            $email = postString('email');
            $password = postString('password');
            $loginEmail = $email;

            if ($userModel->login($email, $password)) {
                header("Location: index.php");
                exit;
            }
        }
    } catch (ValidationException $e) {
        $errorMessage = $e->getMessage();
    } catch (Throwable $e) {
        // Technische Details nur ins Server-Log, nie an den Nutzer
        error_log((string) $e);
        $errorMessage = 'Es ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.';
    }
}
?>
<!DOCTYPE html>
<html lang="de" class="page-login">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lernzeit-Manager – Login & Registrierung</title>
    <!-- Favicon & Lesezeichen-Icons -->
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32x32.png?v=4">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicon-16x16.png?v=4">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png?v=4">
    <link rel="icon" type="image/png" href="assets/img/favicon.png?v=4">
    <link rel="shortcut icon" href="favicon.ico?v=4">
    <!-- Ui mit Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/custom.css">
</head>

<!-- custom.css setzt body auf flex-direction: column, daher zentriert justify-content vertikal -->
<body class="bg-light d-flex justify-content-center">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <h3 class="card-title text-center mb-4 text-primary">Lernzeit-Manager</h3>

                        <!-- Rückmeldungen / Fehlermeldungen anzeigen -->
                        <?php if (!empty($errorMessage)): ?>
                            <div class="alert alert-danger" role="alert">
                                <?= htmlspecialchars($errorMessage) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($successMessage)): ?>
                            <div class="alert alert-success" role="alert">
                                <?= htmlspecialchars($successMessage) ?>
                            </div>
                        <?php endif; ?>

                        <!-- Tab-Navigation zur Umschaltung zwischen Login & Registrierung -->
                        <ul class="nav nav-pills nav-justified mb-3" id="pills-tab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $activeTab === 'login' ? 'active' : '' ?>" id="pills-login-tab" data-bs-toggle="pill" data-bs-target="#pills-login" type="button" role="tab">Login</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $activeTab === 'register' ? 'active' : '' ?>" id="pills-register-tab" data-bs-toggle="pill" data-bs-target="#pills-register" type="button" role="tab">Registrieren</button>
                            </li>
                        </ul>

                        <div class="tab-content" id="pills-tabContent">
                            <!-- LOGIN FORMULAR -->
                            <div class="tab-pane fade <?= $activeTab === 'login' ? 'show active' : '' ?>" id="pills-login" role="tabpanel">
                                <form action="login.php" method="POST">
                                    <input type="hidden" name="action" value="login">

                                    <div class="mb-3">
                                        <label for="login-email" class="form-label">E-Mail-Adresse</label>
                                        <input type="email" name="email" id="login-email" class="form-control" required placeholder="vorname.nachname@iu-study.org" value="<?= htmlspecialchars($loginEmail) ?>" autocomplete="email">
                                    </div>

                                    <div class="mb-3">
                                        <label for="login-password" class="form-label">Passwort</label>
                                        <input type="password" name="password" id="login-password" class="form-control" required>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100">Anmelden</button>
                                </form>
                            </div>

                            <!-- REGISTRIERUNGS FORMULAR -->
                            <div class="tab-pane fade <?= $activeTab === 'register' ? 'show active' : '' ?>" id="pills-register" role="tabpanel">
                                <form action="login.php" method="POST">
                                    <input type="hidden" name="action" value="register">

                                    <div class="mb-3">
                                        <label for="register-display-name" class="form-label">Anzeigename (optional)</label>
                                        <input type="text" name="display_name" id="register-display-name" class="form-control" maxlength="<?= User::MAX_DISPLAY_NAME_LENGTH ?>" autocomplete="nickname" value="<?= htmlspecialchars($registerDisplayName) ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="register-email" class="form-label">E-Mail-Adresse</label>
                                        <input type="email" name="email" id="register-email" class="form-control" required placeholder="vorname.nachname@iu-study.org" value="<?= htmlspecialchars($registerEmail) ?>" autocomplete="email">
                                    </div>

                                    <div class="mb-3">
                                        <label for="register-password" class="form-label">Passwort (min. <?= User::MIN_PASSWORD_LENGTH ?> Zeichen)</label>
                                        <input type="password" name="password" id="register-password" class="form-control" required minlength="<?= User::MIN_PASSWORD_LENGTH ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="register-password-repeat" class="form-label">Passwort wiederholen</label>
                                        <input type="password" name="password_repeat" id="register-password-repeat" class="form-control" required minlength="<?= User::MIN_PASSWORD_LENGTH ?>">
                                    </div>

                                    <button type="submit" class="btn btn-success w-100">Konto erstellen</button>
                                </form>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS für Tab-Wechsel -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>

</html>
