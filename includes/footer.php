<?php
// includes/footer.php

declare(strict_types=1);

$initialToasts = [];

// 1. Session-Flash-Meldungen (nach Weiterleitungen / Reloads) einsammeln und löschen
$sessionToastMap = [
    'flash_success' => 'success',
    'flash_error'   => 'danger',
    'flash_info'    => 'info',
];

foreach ($sessionToastMap as $sessionKey => $toastType) {
    if (isset($_SESSION[$sessionKey]) && is_string($_SESSION[$sessionKey]) && trim($_SESSION[$sessionKey]) !== '') {
        $initialToasts[] = [
            'type'    => $toastType,
            'message' => trim($_SESSION[$sessionKey]),
        ];
    }
    unset($_SESSION[$sessionKey]);
}

// 2. Direkte Seiten-Meldungen (z. B. aus serverseitigen POST-Fallbacks ohne Redirect) übernehmen
if (isset($successMessage) && is_string($successMessage) && trim($successMessage) !== '') {
    $initialToasts[] = [
        'type'    => 'success',
        'message' => trim($successMessage),
    ];
}

// Fehlermeldungen nur als Toast ausgeben, sofern sie nicht bereits direkt einem Modal-Feld zugeordnet sind
$hasModalFieldError = isset($errorField) && is_string($errorField) && $errorField !== '';
if (!$hasModalFieldError && isset($errorMessage) && is_string($errorMessage) && trim($errorMessage) !== '') {
    $initialToasts[] = [
        'type'    => 'danger',
        'message' => trim($errorMessage),
    ];
}

$initialToastsAttr = $initialToasts !== []
    ? ' data-initial-toasts="' . htmlspecialchars((string) json_encode($initialToasts, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '"'
    : '';
?>
</div>
</main>
<!-- Hauptbereich Ende -->

<!-- Zentraler Toast-Container (Floating Alerts, Issue #22) -->
<div
    id="toast-container"
    class="toast-container position-fixed bottom-0 end-0 p-3 app-toast-container"
    aria-live="polite"
    aria-atomic="true"<?= $initialToastsAttr ?>></div>

<!-- Footer -->
<footer class="bg-white border-top py-3 mt-auto text-center text-muted small">
    <div class="container">
        <span>&copy; <?= date('Y') ?> Lernzeit-Manager &ndash; ISEF01 Software Engineering</span>
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle via CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="assets/js/toast.js?v=<?= filemtime(__DIR__ . '/../assets/js/toast.js') ?>"></script>

<?php if (!empty($extraScripts)): ?>
    <?= $extraScripts ?>
<?php endif; ?>
</body>

</html>
