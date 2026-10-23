<?php
// includes/header.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Classes/User.php';

use Classes\User;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = $pageTitle ?? 'Lernzeit-Manager';
$activeNav = $activeNav ?? '';

$isLoggedIn = User::isLoggedIn();
$currentUserName = $isLoggedIn ? User::currentNavName() : '';
?>
<!DOCTYPE html>
<html lang="de" class="h-100">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- Favicon & Lesezeichen-Icons -->
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32x32.png?v=4">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicon-16x16.png?v=4">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png?v=4">
    <link rel="icon" type="image/png" href="assets/img/favicon.png?v=4">
    <link rel="shortcut icon" href="assets/img/favicon.ico?v=4">
    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <!-- Eigenes Styling -->
    <link rel="stylesheet" href="assets/css/custom.css">
</head>

<body class="bg-light">

    <!-- Header & Navigation -->
    <header>
        <nav class="navbar navbar-expand-md navbar-dark bg-primary shadow-sm">
            <div class="container">
                <a class="navbar-brand fw-bold d-flex align-items-center" href="index.php">
                    <img src="assets/img/favicon.png" alt="Lernzeit-Manager Logo" width="30" height="30" class="d-inline-block me-2">
                    <span>Lernzeit-Manager</span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Navigation umschalten">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarMain">
                    <?php if ($isLoggedIn): ?>
                        <ul class="navbar-nav me-auto mb-2 mb-md-0">
                            <li class="nav-item">
                                <a class="nav-link <?= $activeNav === 'dashboard' ? 'active" aria-current="page' : '' ?>" href="index.php">Dashboard</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $activeNav === 'goals' ? 'active" aria-current="page' : '' ?>" href="goals.php">Planen</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $activeNav === 'profile' ? 'active" aria-current="page' : '' ?>" href="profile.php">Profil</a>
                            </li>
                        </ul>
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <span class="text-white-50 small" id="nav-user-greeting">
                                Angemeldet als: <strong class="text-white" id="nav-user-name"><?= htmlspecialchars($currentUserName) ?></strong>
                            </span>
                            <form action="logout.php" method="POST" class="m-0">
                                <button type="submit" class="btn btn-sm btn-outline-light">Abmelden</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <ul class="navbar-nav ms-auto mb-2 mb-md-0">
                            <li class="nav-item">
                                <a class="nav-link <?= $activeNav === 'login' ? 'active" aria-current="page' : '' ?>" href="login.php">Anmelden</a>
                            </li>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
    </header>

    <!-- Hauptbereich Beginn -->
    <main class="py-4">
        <div class="container">
