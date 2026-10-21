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
exit;
