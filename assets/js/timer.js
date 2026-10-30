// assets/js/timer.js
// Clientseitige Interaktion für die Stoppuhr (US-4)

document.addEventListener('DOMContentLoaded', () => {
  const timerDisplay = document.getElementById('timer-display');
  const modalDuration = document.getElementById('modal-stop-duration');
  const stopModalElement = document.getElementById('stopTimerModal');
  const stopForm = document.getElementById('form-stop-timer');
  const resumeBtn = document.getElementById('btn-resume-timer');
  const originalTitle = document.title;

  /**
   * Formatiert Sekunden in HH:MM:SS
   * @param {number} totalSeconds
   * @returns {string}
   */
  function formatSeconds(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(s / 3600);
    const minutes = Math.floor((s % 3600) / 60);
    const seconds = s % 60;

    return [
      hours.toString().padStart(2, '0'),
      minutes.toString().padStart(2, '0'),
      seconds.toString().padStart(2, '0'),
    ].join(':');
  }

  // Timer-Zustandsvariablen
  let timerInterval = null;
  let currentSeconds = timerDisplay ? parseInt(timerDisplay.dataset.initialSeconds || '0', 10) : 0;
  let isRunning = timerDisplay ? timerDisplay.dataset.isRunning === '1' : false;
  let wasRunningWhenModalOpened = false;
  let isSaving = false;

  // CSRF-Token aus bestehendem Formular
  const csrfInput = document.querySelector('input[name="csrf_token"]');
  const csrfToken = csrfInput ? csrfInput.value : '';

  /**
   * Sendet eine asynchrone Statusänderung (Pause / Resume) an den Server
   * @param {'pause'|'resume'} action
   */
  function sendTimerAction(action) {
    if (!csrfToken) return;
    const params = new URLSearchParams();
    params.append('action', action);
    params.append('csrf_token', csrfToken);

    fetch('timer.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
      },
      body: params.toString(),
    }).catch((err) => {
      console.error('Fehler bei Stoppuhr-Aktion:', err);
    });
  }

  /**
   * Aktualisiert Status-Badge und Steuerungselemente im UI
   * @param {boolean} running
   */
  function updateStatusDisplay(running) {
    const container = document.getElementById('timer-status-container');
    if (container) {
      if (running) {
        container.innerHTML = `
          <span class="timer-pulse-dot" id="timer-pulse-dot"></span>
          <span class="badge bg-success-subtle text-success border border-success-subtle fs-6 px-3 py-1" id="timer-status-badge">
              Stoppuhr läuft
          </span>
        `;
      } else {
        container.innerHTML = `
          <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fs-6 px-3 py-1" id="timer-status-badge">
              Pausiert
          </span>
        `;
      }
    }

    const pauseForm = document.getElementById('form-pause-timer');
    const resumeForm = document.getElementById('form-resume-timer');
    if (pauseForm && resumeForm) {
      if (running) {
        pauseForm.classList.remove('d-none');
        resumeForm.classList.add('d-none');
      } else {
        pauseForm.classList.add('d-none');
        resumeForm.classList.remove('d-none');
      }
    }
  }

  /**
   * Startet den sekündlichen Zeitticker
   */
  function startTicker() {
    if (timerInterval) {
      clearInterval(timerInterval);
    }
    isRunning = true;
    if (timerDisplay) {
      timerDisplay.dataset.isRunning = '1';
    }

    const startTime = performance.now();
    const baseSeconds = currentSeconds;

    timerInterval = setInterval(() => {
      const elapsed = Math.floor((performance.now() - startTime) / 1000);
      currentSeconds = baseSeconds + elapsed;

      const formatted = formatSeconds(currentSeconds);
      if (timerDisplay) {
        timerDisplay.textContent = formatted;
      }
      if (modalDuration) {
        modalDuration.textContent = formatted;
      }
      document.title = `⏱️ ${formatted} – Stoppuhr`;
    }, 1000);

    const formatted = formatSeconds(currentSeconds);
    if (timerDisplay) timerDisplay.textContent = formatted;
    if (modalDuration) modalDuration.textContent = formatted;
    document.title = `⏱️ ${formatted} – Stoppuhr`;
  }

  /**
   * Hält den sekündlichen Zeitticker an und friert die Anzeige ein
   */
  function stopTicker() {
    if (timerInterval) {
      clearInterval(timerInterval);
      timerInterval = null;
    }
    isRunning = false;
    if (timerDisplay) {
      timerDisplay.dataset.isRunning = '0';
    }

    const formatted = formatSeconds(currentSeconds);
    if (timerDisplay) {
      timerDisplay.textContent = formatted;
    }
    if (modalDuration) {
      modalDuration.textContent = formatted;
    }
    document.title = `⏱️ ${formatted} (Pausiert) – Stoppuhr`;
  }

  // Ticker initial starten falls Stoppuhr läuft
  if (isRunning) {
    startTicker();
  }

  // Titel beim Verlassen der Seite zurücksetzen
  window.addEventListener('beforeunload', () => {
    if (timerInterval) {
      clearInterval(timerInterval);
    }
    document.title = originalTitle;
  });

  // Modal: "Stopp & Speichern" Interaktion
  if (stopModalElement) {
    // Sobald der Nutzer "Stopp & Speichern" klickt und das Modal geöffnet wird:
    stopModalElement.addEventListener('show.bs.modal', () => {
      isSaving = false;
      wasRunningWhenModalOpened = isRunning;

      // Timer sofort pausieren, Anzeige einfrieren und Server synchronisieren
      if (isRunning) {
        stopTicker();
        sendTimerAction('pause');
        updateStatusDisplay(false);
      }
    });

    // Wenn der Nutzer speichert, wird das normale Absenden markiert
    if (stopForm) {
      stopForm.addEventListener('submit', () => {
        isSaving = true;
      });
    }

    // Wenn das Fenster geschlossen wird (z. B. "Weiterlaufen lassen", X oder Klick daneben):
    stopModalElement.addEventListener('hidden.bs.modal', () => {
      if (!isSaving && wasRunningWhenModalOpened) {
        // Timer wieder starten und server-seitig fortsetzen
        startTicker();
        sendTimerAction('resume');
        updateStatusDisplay(true);
        wasRunningWhenModalOpened = false;
      }
    });

    // Expliziter Klick auf "Weiterlaufen lassen"
    if (resumeBtn) {
      resumeBtn.addEventListener('click', () => {
        if (!isSaving) {
          wasRunningWhenModalOpened = true;
        }
      });
    }
  }

  // Modal: Zeiteintrag löschen befüllen
  const deleteButtons = document.querySelectorAll('.delete-entry-btn');
  const deleteIdInput = document.getElementById('delete_entry_id');
  const deleteTitleSpan = document.getElementById('delete_entry_title');
  const deleteDateSpan = document.getElementById('delete_entry_date');
  const deleteDurationSpan = document.getElementById('delete_entry_duration');

  deleteButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (deleteIdInput) deleteIdInput.value = btn.dataset.id || '';
      if (deleteTitleSpan) deleteTitleSpan.textContent = btn.dataset.title || '';
      if (deleteDateSpan) deleteDateSpan.textContent = btn.dataset.date || '';
      if (deleteDurationSpan) deleteDurationSpan.textContent = btn.dataset.duration || '';
    });
  });

  // Clientseitige Validierung des Start-Formulars
  const startForm = document.getElementById('start-timer-form');
  if (startForm) {
    startForm.addEventListener('submit', (e) => {
      const goalSelect = document.getElementById('timer_goal_id');
      if (goalSelect && (!goalSelect.value || goalSelect.value === '')) {
        e.preventDefault();
        goalSelect.focus();
        goalSelect.classList.add('is-invalid');
      }
    });
  }
});
