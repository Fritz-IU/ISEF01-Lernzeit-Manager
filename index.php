<?php
// index.php
// Einstiegspunkt des Projekts: Soll-Ist-Dashboard (Story #6, rein lesend).
// Erfassen und Planen passiert auf timer.php bzw. goals.php, das Dashboard verlinkt nur dorthin.

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Classes/User.php';
require_once __DIR__ . '/src/DAO/DashboardDAO.php';
require_once __DIR__ . '/src/Controllers/DashboardController.php';

use Classes\User;
use Controllers\DashboardController;
use DAO\DashboardDAO;

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

// Gewähltes Semesterziel (nur lesende Auswahl per GET)
$requestedGoalId = filter_input(INPUT_GET, 'goal', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requestedGoalId = is_int($requestedGoalId) ? $requestedGoalId : null;

$dashboardController = new DashboardController(new DashboardDAO($db));
$dashboard = $dashboardController->buildDashboard($userId, $requestedGoalId);

$semester = $dashboard['semester'];
$week = $dashboard['week'];
$currentMonth = $dashboard['current_month'] ?? null;
$modules = $dashboard['modules'] ?? [];
$treeMonths = $dashboard['tree_months'] ?? [];

// Inaktivitäts-Hinweis (#7): verpasste Lerneinheiten seit dem vorherigen Login (gemerkt in User::login).
// Die Modulfarben kommen aus der Zielstruktur, damit ein Modul im Hinweis und im Zielbaum gleich aussieht.
$previousLoginAt = isset($_SESSION['previous_login_at']) && is_string($_SESSION['previous_login_at'])
    ? $_SESSION['previous_login_at']
    : null;
$inactivityNotice = $dashboardController->buildInactivityNotice($userId, $previousLoginAt, null, $modules);

/** Stunden im deutschen Format, z. B. "12,5 h" */
$fmtHours = static fn (float $hours): string => number_format($hours, 1, ',', '.') . ' h';
/** Stunden ohne überflüssige Null, z. B. "9 h" oder "1,5 h" */
$fmtHoursShort = static fn (float $hours): string => str_replace(',0 ', ' ', number_format($hours, 1, ',', '.') . ' h');
/** Modulfarbe aus der festen Reihe --series-1 … --series-8 */
$seriesColor = static fn (int $colorIndex): string => 'var(--series-' . (($colorIndex % 8) + 1) . ')';
/** Zahl für CSS-Werte, immer mit Punkt als Dezimaltrenner (unabhängig von der Locale) */
$cssNumber = static fn (float $value): string => number_format($value, 2, '.', '');
/** Prozent ohne Nachkommastelle, z. B. "42 %" */
$fmtPercent = static fn (float $percent): string => number_format($percent, 0, ',', '.') . ' %';
/** Datum YYYY-MM-DD als TT.MM.JJJJ */
$fmtDate = static fn (string $date): string => date('d.m.Y', (int) strtotime($date));

/**
 * Bootstrap-Farbklasse für einen Fortschrittsbalken (Ist im Verhältnis zum Soll).
 */
$barClass = static function (float $percent, bool $completed = false): string {
    if ($completed || $percent >= 100.0) {
        return 'bg-success';
    }

    return 'bg-primary';
};

// Statusicons (Form + Text, nie nur Farbe)
$statusIcons = [
    'success'   => '<path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>',
    'warning'   => '<path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>',
    'danger'    => '<path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M8 4a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 4m.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2"/>',
    'secondary' => '<path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0"/>',
];
$checkIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425z"/></svg>';

// Daten für die Diagramme (assets/js/dashboard.js)
$chartData = [
    'cumulative' => $dashboard['cumulative'] ?? null,
    'monthly'    => $dashboard['monthly_chart'] ?? null,
    'week'       => $week,
];

$pageTitle = 'Dashboard – Lernzeit-Manager';
$activeNav = 'dashboard';
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>'
    . "\n" . '<script src="assets/js/dashboard.js?v=' . filemtime(__DIR__ . '/assets/js/dashboard.js') . '"></script>';

require_once __DIR__ . '/includes/header.php';
?>

<!-- Kopfbereich: Begrüßung, Semesterauswahl und Schnellzugriffe -->
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
    <div class="min-w-0">
        <h1 class="h3 mb-0 text-primary">Hallo, <?= htmlspecialchars((string) $displayName) ?>!</h1>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <?php if (count($dashboard['semesters']) > 1): ?>
            <form method="GET" action="index.php" class="d-flex align-items-center gap-2 m-0" id="semesterSelectForm">
                <label for="semesterSelect" class="visually-hidden">Semesterziel auswählen</label>
                <select name="goal" id="semesterSelect" class="form-select form-select-sm dashboard-semester-select">
                    <?php foreach ($dashboard['semesters'] as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= $option['is_selected'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($option['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn btn-sm btn-outline-primary">Anzeigen</button></noscript>
            </form>
        <?php endif; ?>
        <a href="timer.php" class="btn btn-sm btn-primary">Lernzeit erfassen</a>
        <?php if ($dashboard['semester'] !== null): ?>
            <!-- Im leeren Zustand führt schon der Button in der Karte zur Planung -->
            <a href="goals.php" class="btn btn-sm btn-outline-primary">Planen</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($inactivityNotice !== null): ?>
    <!-- Inaktivitäts-Hinweis (#7): bleibt für die Sitzung sichtbar, bis er geschlossen wird (dashboard.js) -->
    <section class="alert alert-dismissible fade show shadow-sm mb-4 inactivity-notice" id="inactivityNotice"
             aria-labelledby="inactivityNoticeTitle" data-since="<?= htmlspecialchars((string) $inactivityNotice['since']) ?>">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-6 col-lg-5">
                <div class="d-flex align-items-start gap-2">
                    <svg class="inactivity-notice-icon flex-shrink-0" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><?= $statusIcons['warning'] ?></svg>
                    <div>
                        <h2 class="h5 mb-0" id="inactivityNoticeTitle"><?= htmlspecialchars($fmtHoursShort((float) $inactivityNotice['missed_hours'])) ?> Lernzeit verpasst</h2>
                        <p class="small text-body-secondary mb-0">
                            <?= (int) $inactivityNotice['missed_count'] === 1 ? '1 Lerneinheit' : (int) $inactivityNotice['missed_count'] . ' Lerneinheiten' ?>
                            seit <?= htmlspecialchars(date('d.m.', (int) strtotime((string) $inactivityNotice['since']))) ?>
                        </p>
                    </div>
                </div>

                <?php if ($inactivityNotice['open_modules'] !== []): ?>
                    <ul class="list-unstyled d-flex flex-wrap gap-2 mt-3 mb-0" aria-label="Offene Ziele mit verpasster Lernzeit">
                        <?php foreach ($inactivityNotice['open_modules'] as $openModule): ?>
                            <li>
                                <a href="<?= htmlspecialchars((string) $openModule['url']) ?>" class="inactivity-chip"
                                   style="--module-color: <?= $seriesColor((int) $openModule['color_index']) ?>;">
                                    <span class="inactivity-chip-dot" aria-hidden="true"></span>
                                    <?= htmlspecialchars((string) $openModule['title']) ?>
                                    <span class="text-body-secondary"><?= htmlspecialchars($fmtHoursShort((float) $openModule['missed_hours'])) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <a href="timer.php" class="btn btn-sm btn-warning mt-3">Lernzeit nachtragen</a>
            </div>

            <div class="col-12 col-md-6 col-lg-7">
                <p class="inactivity-days-caption mb-2" id="inactivityDaysCaption">Verpasste Lernzeit pro Tag</p>
                <?php $noticeDayCount = count($inactivityNotice['days']); ?>
                <ul class="inactivity-days list-unstyled mb-0" aria-labelledby="inactivityDaysCaption">
                    <?php foreach ($inactivityNotice['days'] as $dayIndex => $noticeDay): ?>
                        <?php
                        $hasMissed = $noticeDay['hours'] > 0;
                        $dayHeading = $noticeDay['weekday'] . ' ' . date('d.m.', (int) strtotime((string) $noticeDay['date']));
                        // Ausgabe für Screenreader: Tag, Summe und die einzelnen Einheiten in einem Satz
                        $dayLabel = $dayHeading . ': ' . ($hasMissed ? $fmtHoursShort((float) $noticeDay['hours']) . ' verpasst' : 'nichts verpasst');
                        foreach ($noticeDay['slots'] as $daySlot) {
                            $dayLabel .= '; ' . $daySlot['start'] . ' bis ' . $daySlot['end'] . ' Uhr ' . $daySlot['module_title']
                                . ' (' . $fmtHoursShort((float) $daySlot['hours']) . ')'
                                . ($daySlot['notes'] !== '' ? ', ' . $daySlot['notes'] : '');
                        }
                        // Tooltip am Rand nicht aus der Karte laufen lassen
                        $tipAlign = $dayIndex < 3 ? ' is-start' : ($dayIndex >= $noticeDayCount - 3 ? ' is-end' : '');
                        ?>
                        <li class="inactivity-day<?= $noticeDay['is_today'] ? ' is-today' : '' ?><?= $hasMissed ? ' has-missed' : '' ?>"
                            <?= $hasMissed ? 'tabindex="0"' : '' ?> aria-label="<?= htmlspecialchars($dayLabel) ?>">
                            <div class="inactivity-bar" aria-hidden="true">
                                <?php foreach ($noticeDay['segments'] as $segment): ?>
                                    <span class="inactivity-bar-segment"
                                          style="height: <?= $cssNumber((float) $segment['height_pct']) ?>%; --module-color: <?= $seriesColor((int) $segment['color_index']) ?>;"></span>
                                <?php endforeach; ?>
                            </div>
                            <span class="inactivity-day-label" aria-hidden="true"><?= htmlspecialchars((string) $noticeDay['weekday']) ?><br><?= htmlspecialchars((string) $noticeDay['day']) ?></span>

                            <!-- Tooltip: erscheint bei Hover und bei Fokus (Tastatur, Antippen auf dem Handy) -->
                            <div class="inactivity-tip<?= $tipAlign ?>" aria-hidden="true">
                                <div class="d-flex justify-content-between gap-3 fw-semibold">
                                    <span><?= htmlspecialchars($dayHeading) ?></span>
                                    <span><?= $hasMissed ? htmlspecialchars($fmtHoursShort((float) $noticeDay['hours'])) . ' verpasst' : 'nichts verpasst' ?></span>
                                </div>
                                <?php if (count($noticeDay['segments']) > 1): ?>
                                    <!-- Mehrere Module an einem Tag: Aufteilung als Mini-Balken in den Modulfarben -->
                                    <div class="inactivity-tip-split mt-1">
                                        <?php foreach ($noticeDay['segments'] as $segment): ?>
                                            <span style="flex-grow: <?= $cssNumber((float) $segment['hours']) ?>; --module-color: <?= $seriesColor((int) $segment['color_index']) ?>;"></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="inactivity-tip-notes mt-1"><?= count($noticeDay['segments']) ?> Module an diesem Tag</div>
                                <?php endif; ?>
                                <?php if ($hasMissed): ?>
                                    <ul class="list-unstyled mb-0 mt-2">
                                        <?php foreach ($noticeDay['slots'] as $daySlot): ?>
                                            <li class="inactivity-tip-slot" style="--module-color: <?= $seriesColor((int) $daySlot['color_index']) ?>;">
                                                <div class="d-flex justify-content-between gap-3">
                                                    <span class="fw-semibold"><?= htmlspecialchars((string) $daySlot['module_title']) ?></span>
                                                    <span class="inactivity-tip-hours"><?= htmlspecialchars($fmtHoursShort((float) $daySlot['hours'])) ?></span>
                                                </div>
                                                <div class="inactivity-tip-notes">
                                                    <span class="inactivity-tip-time"><?= htmlspecialchars($daySlot['start']) ?>–<?= htmlspecialchars($daySlot['end']) ?> Uhr</span><?= $daySlot['notes'] !== '' ? ' · ' . htmlspecialchars($daySlot['notes']) : '' ?>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($inactivityNotice['days_truncated']): ?>
                    <p class="small text-body-secondary text-end mb-0 mt-1">Grafik: letzte <?= DashboardController::INACTIVITY_MAX_DAYS ?> Tage</p>
                <?php endif; ?>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Hinweis schließen"></button>
    </section>
<?php endif; ?>

<?php if ($semester === null): ?>
    <!-- Leerer Zustand: noch kein Semesterziel geplant -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-4 p-md-5 text-center">
            <h2 class="h5 mb-2">Noch keine Lernziele geplant</h2>
            <p class="text-muted mb-4">
                Sobald du ein Semesterziel mit Zwischenzielen angelegt hast, siehst du hier, ob deine erfasste Lernzeit mit deiner Planung mithält.
            </p>
            <a href="goals.php" class="btn btn-primary px-4">Zur Planung</a>
        </div>
    </div>
<?php else: ?>
    <?php
    $status = $semester['status'];
    $delta = (float) $semester['delta_hours'];
    $progressDeg = min(100.0, (float) $semester['progress_percent']) * 3.6;
    ?>

    <!-- Semester-Überschrift -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h2 class="h5 mb-0"><?= htmlspecialchars($semester['title']) ?></h2>
        <span class="text-muted small">
            <?= htmlspecialchars($fmtDate($semester['start_date'])) ?> – <?= htmlspecialchars($fmtDate($semester['end_date'])) ?>
        </span>
    </div>

    <!-- Kennzahlen -->
    <!-- Auf dem Handy wischbar (Scroll-Snap), ab 576 px als Raster -->
    <div class="row g-3 mb-4 dashboard-kpis" role="region" aria-label="Kennzahlen" tabindex="0">
        <!-- Semesterziel -->
        <div class="col-sm-6 col-xl-3">
            <div class="card dashboard-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="dashboard-ring <?= $semester['progress_percent'] >= 100 ? 'is-complete' : '' ?>"
                        style="--ring-deg: <?= number_format($progressDeg, 1, '.', '') ?>deg;"
                        role="img"
                        aria-label="<?= htmlspecialchars($fmtPercent((float) $semester['progress_percent'])) ?> des Semesterziels erreicht">
                        <span><?= htmlspecialchars($fmtPercent((float) $semester['progress_percent'])) ?></span>
                    </div>
                    <div class="min-w-0">
                        <div class="dashboard-kpi-label">Semesterziel</div>
                        <div class="dashboard-kpi-value"><?= htmlspecialchars($fmtHours((float) $semester['tracked_hours'])) ?></div>
                        <div class="small text-muted">von <?= htmlspecialchars($fmtHours((float) $semester['target_hours'])) ?> erfasst</div>
                        <div class="small text-muted"><?= htmlspecialchars($fmtHours((float) $semester['planned_hours'])) ?> auf Monate verplant</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Zeitplan-Status -->
        <div class="col-sm-6 col-xl-3">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <div class="dashboard-kpi-label">Zeitplan</div>
                        <span class="badge rounded-pill text-bg-<?= htmlspecialchars($status['variant']) ?> d-inline-flex align-items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><?= $statusIcons[$status['variant']] ?? '' ?></svg>
                            <?= htmlspecialchars($status['label']) ?>
                        </span>
                    </div>
                    <div class="dashboard-kpi-value <?= $status['variant'] === 'secondary' ? '' : ($delta < 0 ? 'text-danger-emphasis' : 'text-success') ?>">
                        <?= $delta >= 0 ? '+' : '−' ?><?= htmlspecialchars($fmtHours(abs($delta))) ?>
                    </div>
                    <div class="small text-muted mb-2">
                        Ist <?= htmlspecialchars($fmtHours((float) $semester['tracked_until_today'])) ?>
                        &middot; Soll bis heute <?= htmlspecialchars($fmtHours((float) $semester['expected_hours'])) ?>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span><?= htmlspecialchars($fmtPercent((float) $semester['elapsed_percent'])) ?> der Zeit vergangen</span>
                        <span>noch <?= (int) $semester['days_left'] ?> Tage</span>
                    </div>
                    <div class="progress" style="height: 6px;" role="progressbar" aria-label="Vergangene Semesterzeit" aria-valuenow="<?= (int) $semester['elapsed_percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar bg-secondary" style="width: <?= min(100.0, (float) $semester['elapsed_percent']) ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Aktueller Monat -->
        <div class="col-sm-6 col-xl-3">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="dashboard-kpi-label mb-1">Aktueller Monat</div>
                    <div class="dashboard-kpi-value">
                        <?= htmlspecialchars($fmtHours((float) $currentMonth['tracked_hours'])) ?>
                        <span class="fs-6 fw-normal text-muted">/ <?= htmlspecialchars($fmtHours((float) $currentMonth['planned_hours'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mb-1 mt-2">
                        <span><?= htmlspecialchars($currentMonth['label']) ?></span>
                        <span><?= htmlspecialchars($fmtPercent((float) $currentMonth['percent'])) ?></span>
                    </div>
                    <div class="progress" style="height: 6px;" role="progressbar" aria-label="Ist im Verhältnis zum Soll des Monats" aria-valuenow="<?= (int) min(100, $currentMonth['percent']) ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar <?= $barClass((float) $currentMonth['percent']) ?>" style="width: <?= min(100.0, (float) $currentMonth['percent']) ?>%"></div>
                    </div>
                    <div class="small text-muted mt-2">
                        <?php if ($currentMonth['budget_hours'] !== null): ?>
                            Monatsbudget <?= htmlspecialchars($fmtHours((float) $currentMonth['budget_hours'])) ?>
                        <?php else: ?>
                            Kein Monatsbudget hinterlegt
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Aktuelle Woche -->
        <div class="col-sm-6 col-xl-3">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="dashboard-kpi-label mb-1">Aktuelle Woche</div>
                    <div class="dashboard-kpi-value">
                        <?= htmlspecialchars($fmtHours((float) $week['tracked_hours'])) ?>
                        <span class="fs-6 fw-normal text-muted">/ <?= htmlspecialchars($fmtHours((float) $week['scheduled_hours'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mb-1 mt-2">
                        <span><?= htmlspecialchars($week['label']) ?></span>
                        <span><?= $week['scheduled_hours'] > 0 ? htmlspecialchars($fmtPercent((float) $week['percent'])) : '–' ?></span>
                    </div>
                    <div class="progress" style="height: 6px;" role="progressbar" aria-label="Ist im Verhältnis zu den eingeplanten Lerneinheiten der Woche" aria-valuenow="<?= (int) min(100, $week['percent']) ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar <?= $barClass((float) $week['percent']) ?>" style="width: <?= min(100.0, (float) $week['percent']) ?>%"></div>
                    </div>
                    <div class="small text-muted mt-2">Über alle Lernziele</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Verlauf im Semester -->
    <div class="card dashboard-card mb-4">
        <div class="card-body">
            <h3 class="h6 mb-3">Lernzeit im Semesterverlauf</h3>
            <div class="dashboard-chart dashboard-chart-lg">
                <canvas id="chartCumulative" role="img" aria-label="Kumulierte Soll- und Ist-Lernzeit im Semesterverlauf"></canvas>
                <div class="dashboard-chart-fallback small text-muted">
                    Ist bis heute <?= htmlspecialchars($fmtHours((float) $semester['tracked_until_today'])) ?>
                    &middot; Soll bis heute <?= htmlspecialchars($fmtHours((float) $semester['expected_hours'])) ?>
                    &middot; Semesterziel <?= htmlspecialchars($fmtHours((float) $semester['target_hours'])) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <h3 class="h6 mb-3">Lernzeit pro Monat</h3>
                    <div class="dashboard-chart">
                        <canvas id="chartMonthly" role="img" aria-label="Geplante und erfasste Stunden je Monat mit Monatsbudget"></canvas>
                        <table class="table table-sm small mb-0 dashboard-chart-fallback">
                            <thead><tr><th scope="col">Monat</th><th scope="col" class="text-end">Soll</th><th scope="col" class="text-end">Ist</th></tr></thead>
                            <tbody>
                                <?php foreach (($dashboard['monthly_chart']['labels'] ?? []) as $i => $monthLabel): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $monthLabel) ?></td>
                                        <td class="text-end"><?= htmlspecialchars($fmtHours((float) $dashboard['monthly_chart']['soll'][$i])) ?></td>
                                        <td class="text-end"><?= htmlspecialchars($fmtHours((float) $dashboard['monthly_chart']['ist'][$i])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
                        <h3 class="h6 mb-0">Lernzeit aktuelle Woche</h3>
                        <span class="small text-muted"><?= htmlspecialchars($week['label']) ?></span>
                    </div>
                    <div class="dashboard-chart">
                        <canvas id="chartWeek" role="img" aria-label="Eingeplante und erfasste Stunden je Wochentag"></canvas>
                        <table class="table table-sm small mb-0 dashboard-chart-fallback">
                            <thead><tr><th scope="col">Tag</th><th scope="col" class="text-end">Eingeplant</th><th scope="col" class="text-end">Erfasst</th></tr></thead>
                            <tbody>
                                <?php foreach ($week['days'] as $day): ?>
                                    <tr<?= $day['is_today'] ? ' class="fw-semibold"' : '' ?>>
                                        <td><?= htmlspecialchars($day['label']) ?></td>
                                        <td class="text-end"><?= htmlspecialchars($fmtHours((float) $day['scheduled_hours'])) ?></td>
                                        <td class="text-end"><?= htmlspecialchars($fmtHours((float) $day['tracked_hours'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Zielstruktur: Semester → Module → Zwischenziele -->
    <div class="card dashboard-card mb-2">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
                <h3 class="h6 mb-0">Zielstruktur</h3>
                <span class="small text-muted">
                    <?= (int) $semester['completed_count'] ?>/<?= (int) $semester['monthly_goal_count'] ?> Zwischenziele erledigt
                </span>
            </div>

            <?php if ($modules === []): ?>
                <div class="dashboard-empty">
                    Für dieses Semester sind noch keine Zwischenziele geplant. <a href="goals.php">Zur Planung</a>
                </div>
            <?php else: ?>
                <div class="goal-tree">
                    <!-- Wurzel: Semesterziel -->
                    <a class="goal-tree-node goal-tree-link goal-tree-root" href="<?= htmlspecialchars(DashboardController::semesterGoalUrl((int) $semester['id']), ENT_QUOTES) ?>" title="Semesterziel in der Planung öffnen">
                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                            <strong class="text-truncate"><?= htmlspecialchars($semester['title']) ?></strong>
                            <?php if ($semester['is_completed']): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $checkIcon ?> Erledigt</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mt-1 mb-1">
                            <span><?= htmlspecialchars($fmtHours((float) $semester['tracked_hours'])) ?> von <?= htmlspecialchars($fmtHours((float) $semester['target_hours'])) ?></span>
                            <span><?= htmlspecialchars($fmtPercent((float) $semester['progress_percent'])) ?></span>
                        </div>
                        <div class="progress" style="height: 6px;" role="progressbar" aria-label="Fortschritt Semesterziel" aria-valuenow="<?= (int) min(100, $semester['progress_percent']) ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar <?= $barClass((float) $semester['progress_percent'], $semester['is_completed']) ?>" style="width: <?= min(100.0, (float) $semester['progress_percent']) ?>%"></div>
                        </div>
                    </a>

                    <!-- Äste: Module zweigen nebeneinander vom Semesterziel ab, Zeilen sind die Monate -->
                    <ul class="goal-tree-branches list-unstyled mb-0"
                        style="--tree-cols: <?= count($modules) ?>; --tree-rows: <?= max(1, count($treeMonths)) ?>;">
                        <?php foreach ($modules as $module): ?>
                            <?php
                            // Zwischenziele je Monat bündeln, damit jeder Monat genau eine Zeile belegt
                            $childrenByMonth = [];
                            foreach ($module['children'] as $child) {
                                $childrenByMonth[$child['year_month']][] = $child;
                            }
                            ?>
                            <li class="goal-tree-branch">
                                <a class="goal-tree-node goal-tree-link goal-tree-module"
                                    href="<?= htmlspecialchars($module['target'] !== null
                                        ? DashboardController::monthlyGoalUrl($module['target']['id'], $module['target']['year_month'])
                                        : DashboardController::semesterGoalUrl((int) $semester['id']), ENT_QUOTES) ?>"
                                    title="Modul in der Planung öffnen"
                                    style="--module-color: var(--series-<?= ((int) $module['color_index'] % 8) + 1 ?>);">
                                    <div class="d-flex justify-content-between align-items-baseline gap-2">
                                        <span class="d-inline-flex align-items-center gap-2 min-w-0">
                                            <span class="goal-tree-swatch" aria-hidden="true"></span>
                                            <strong class="text-break"><?= htmlspecialchars($module['title']) ?></strong>
                                        </span>
                                        <?php if ($module['is_completed']): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle flex-shrink-0"><?= $checkIcon ?> Erledigt</span>
                                        <?php else: ?>
                                            <span class="small text-muted flex-shrink-0"><?= (int) $module['completed_count'] ?>/<?= count($module['children']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex justify-content-between small text-muted mt-1 mb-1">
                                        <span><?= htmlspecialchars($fmtHours((float) $module['tracked_hours'])) ?> von <?= htmlspecialchars($fmtHours((float) $module['planned_hours'])) ?></span>
                                        <span><?= htmlspecialchars($fmtPercent((float) $module['percent'])) ?></span>
                                    </div>
                                    <div class="progress" style="height: 6px;" role="progressbar" aria-label="Fortschritt <?= htmlspecialchars($module['title'], ENT_QUOTES) ?>" aria-valuenow="<?= (int) min(100, $module['percent']) ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar <?= $barClass((float) $module['percent'], $module['is_completed']) ?>" style="width: <?= min(100.0, (float) $module['percent']) ?>%"></div>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        <?= htmlspecialchars($fmtPercent((float) $module['share_percent'])) ?> deiner Lernzeit
                                    </div>
                                </a>

                                <!-- Blätter: monatliche Zwischenziele, je Monat in der passenden Zeile -->
                                <?php
                                $leafRows = [];
                                foreach (array_keys($childrenByMonth) as $yearMonth) {
                                    $leafRows[$yearMonth] = (int) array_search((string) $yearMonth, $treeMonths, true) + 1;
                                }
                                asort($leafRows);
                                $prevRow = 0;
                                ?>
                                <ul class="goal-tree-leaves list-unstyled mb-0" style="--tree-leaf-end: <?= max($leafRows) + 2 ?>;">
                                    <?php foreach ($leafRows as $yearMonth => $leafRow): ?>
                                        <?php $monthChildren = $childrenByMonth[$yearMonth]; ?>
                                        <?php for ($gapRow = $prevRow + 1; $gapRow < $leafRow; $gapRow++): ?>
                                            <!-- Monat ohne Zwischenziel: trägt nur die Verbindungslinie weiter -->
                                            <li class="goal-tree-gap" style="--tree-row: <?= $gapRow ?>;" aria-hidden="true"></li>
                                        <?php endfor; ?>
                                        <?php $prevRow = $leafRow; ?>
                                        <li class="goal-tree-leaf" style="--tree-row: <?= $leafRow ?>;">
                                            <?php foreach ($monthChildren as $child): ?>
                                                <a class="goal-tree-node goal-tree-link goal-tree-month <?= $child['is_completed'] ? 'is-completed' : '' ?>"
                                                    href="<?= htmlspecialchars(DashboardController::monthlyGoalUrl($child['id'], $child['year_month']), ENT_QUOTES) ?>"
                                                    title="<?= htmlspecialchars(($child['description'] !== '' ? $child['description'] . ' – ' : '') . 'In der Planung öffnen', ENT_QUOTES) ?>">
                                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                                        <span class="fw-semibold small"><?= htmlspecialchars($child['month_label']) ?></span>
                                                        <?php if ($child['is_completed']): ?>
                                                            <span class="goal-tree-check" aria-label="Erledigt"><?= $checkIcon ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="small text-muted my-1">
                                                        <?= htmlspecialchars($fmtHours((float) $child['tracked_hours'])) ?> / <?= htmlspecialchars($fmtHours((float) $child['planned_hours'])) ?>
                                                    </div>
                                                    <div class="progress" style="height: 4px;" role="progressbar" aria-label="Fortschritt <?= htmlspecialchars($child['month_label'], ENT_QUOTES) ?>" aria-valuenow="<?= (int) min(100, $child['percent']) ?>" aria-valuemin="0" aria-valuemax="100">
                                                        <div class="progress-bar <?= $barClass((float) $child['percent'], $child['is_completed']) ?>" style="width: <?= min(100.0, (float) $child['percent']) ?>%"></div>
                                                    </div>
                                                </a>
                                            <?php endforeach; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Diagrammdaten für assets/js/dashboard.js -->
<script type="application/json" id="dashboard-data"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
