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
$currentShortUserName = $isLoggedIn ? User::currentShortNavName() : '';
$currentUserEmail = $isLoggedIn ? (string) ($_SESSION['user_email'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="de" class="h-100">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <!-- Eigenes Styling -->
    <link rel="stylesheet" href="assets/css/custom.css?v=<?= filemtime(__DIR__ . '/../assets/css/custom.css') ?>">
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
                        </ul>
                        <div class="dropdown">
                            <button
                                class="btn btn-sm btn-outline-light dropdown-toggle d-inline-flex align-items-center gap-2 mw-100"
                                type="button"
                                id="userDropdown"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-person-circle flex-shrink-0" viewBox="0 0 16 16" aria-hidden="true">
                                    <path d="M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0"/>
                                    <path fill-rule="evenodd" d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1"/>
                                </svg>
                                <span class="text-truncate nav-user-truncate" id="nav-user-name"><?= htmlspecialchars($currentShortUserName) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 nav-user-dropdown" aria-labelledby="userDropdown">
                                <li>
                                    <div class="px-3 py-2" id="nav-user-greeting">
                                        <span class="d-block text-muted small">Angemeldet als</span>
                                        <strong class="d-block text-dark text-break" id="nav-dropdown-user-name"><?= htmlspecialchars($currentUserName) ?></strong>
                                        <?php if ($currentUserEmail !== ''): ?>
                                            <span class="d-block text-muted small text-break mt-1 <?= $currentUserName === $currentUserEmail ? 'd-none' : '' ?>" id="nav-dropdown-user-email"><?= htmlspecialchars($currentUserEmail) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item <?= $activeNav === 'profile' ? 'active" aria-current="page' : '' ?>" href="profile.php">
                                        Profil bearbeiten
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form action="logout.php" method="POST" class="m-0">
                                        <button type="submit" class="dropdown-item text-danger">Abmelden</button>
                                    </form>
                                </li>
                            </ul>
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
