<?php
// logout.php

declare(strict_types=1);

require_once __DIR__ . '/src/Classes/User.php';

use Classes\User;

// Session starten, falls noch nicht aktiv
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Abmelden nur per POST, damit fremde Seiten (z. B. per <img src="logout.php">) niemanden abmelden können
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    User::logout();
}

header("Location: login.php");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Abmelden – Lernzeit-Manager</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
    <p>Weiterleitung zu <a href="login.php">login.php</a>…</p>
</body>
</html>
<?php
exit;
