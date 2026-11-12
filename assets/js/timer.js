// assets/js/timer.js
// Clientseitige Interaktion für die Stoppuhr (US-4)

document.addEventListener('DOMContentLoaded', () => {
  const timerDisplay = document.getElementById('timer-display');
  const modalDuration = document.getElementById('modal-stop-duration');
  const stopModalElement = document.getElementById('stopTimerModal');
  const stopForm = document.getElementById('form-stop-timer');
  const resumeBtn = document.getElementById('btn-resume-timer');
  const originalTitle = document.title;

  const clearStopFieldError = window.clearFieldError || (() => {});

  /**
   * Entfernt alle roten Feldmarkierungen und Feld-Fehlermeldungen im Stopp-Modal.
   */
  function clearStopModalErrors() {
    if (typeof window.clearFormErrors === 'function') {
      window.clearFormErrors(stopForm);
    }
  }

  /**
   * Zeigt einen Validierungsfehler direkt am betroffenen Feld im Stopp-Modal oder als Toast an.
   * @param {string} message
   * @param {string|null} [fieldName]
   */
  function showStopModalError(message, fieldName = null) {
    if (typeof window.showFormError === 'function') {
      window.showFormError(stopForm, message, fieldName);
    }
  }

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

  /**
   * Blendet im Stopp-Dialog die Dauerkorrektur ein, wenn die Sitzung auffällig lang ist
   * (vergessene Stoppuhr). Vorbelegt wird die gemessene Zeit, höchstens 24 Stunden.
   * Unterhalb der Schwelle bleiben die Felder deaktiviert und werden nicht mitgesendet.
   */
  function prepareDurationCorrection() {
    const block = document.getElementById('stop-correction');
    const hoursInput = document.getElementById('stop_corrected_hours');
    const minutesInput = document.getElementById('stop_corrected_minutes');
    const hint = document.getElementById('stop-correction-hint');
    if (!block || !hoursInput || !minutesInput) return;

    const threshold = parseInt(block.dataset.thresholdSeconds || '0', 10);
    const maxSeconds = parseInt(block.dataset.maxSeconds || '86400', 10);
    const isLong = threshold > 0 && currentSeconds >= threshold;

    block.classList.toggle('d-none', !isLong);
    hoursInput.disabled = !isLong;
    minutesInput.disabled = !isLong;
    if (!isLong) return;

    const suggestedMinutes = Math.floor(Math.min(currentSeconds, maxSeconds) / 60);
    hoursInput.value = String(Math.floor(suggestedMinutes / 60));
    minutesInput.value = String(suggestedMinutes % 60);

    if (hint) {
      hint.textContent = currentSeconds > maxSeconds
        ? 'Die Stoppuhr lief länger als 24 Stunden. Gespeichert werden höchstens 24 Stunden. Trage bei Bedarf die tatsächliche Lerndauer ein.'
        : 'Die Stoppuhr lief ungewöhnlich lange. Hast du sie vielleicht vergessen? Trage bei Bedarf die tatsächliche Lerndauer ein.';
    }
  }

  // Modal: "Stopp & Speichern" Interaktion
  if (stopModalElement) {
    // Sobald der Nutzer "Stopp & Speichern" klickt und das Modal geöffnet wird:
    stopModalElement.addEventListener('show.bs.modal', () => {
      isSaving = false;
      wasRunningWhenModalOpened = isRunning;
      clearStopModalErrors();

      // Timer sofort pausieren, Anzeige einfrieren und Server synchronisieren
      if (isRunning) {
        stopTicker();
        sendTimerAction('pause');
        updateStatusDisplay(false);
      }

      prepareDurationCorrection();
    });

    // Wenn der Nutzer speichert, wird das Formular per Fetch gesendet, damit Fehler direkt im Modal erscheinen
    if (stopForm) {
      stopForm.querySelectorAll('input, textarea').forEach((inputEl) => {
        inputEl.addEventListener('input', () => {
          if (inputEl.classList.contains('is-invalid')) {
            clearStopFieldError(inputEl);
          }
        });
      });

      stopForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearStopModalErrors();

        const hoursInput = document.getElementById('stop_corrected_hours');
        const minutesInput = document.getElementById('stop_corrected_minutes');

        if (hoursInput && !hoursInput.disabled && hoursInput.validity && hoursInput.validity.badInput) {
          showStopModalError('Die Stunden müssen eine ganze Zahl zwischen 0 und 24 sein.', 'corrected_hours');
          return;
        }
        if (minutesInput && !minutesInput.disabled && minutesInput.validity && minutesInput.validity.badInput) {
          showStopModalError('Die Minuten müssen eine ganze Zahl zwischen 0 und 59 sein.', 'corrected_minutes');
          return;
        }

        isSaving = true;
        const submitBtn = stopForm.querySelector('button[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = true;
        }

        // Nur im Fehlerfall wieder freigeben; nach Erfolg bleibt der Button bis zum Reload gesperrt
        const resetAfterError = (message, field = null) => {
          isSaving = false;
          if (submitBtn) {
            submitBtn.disabled = false;
          }
          showStopModalError(message, field);
        };

        try {
          const params = new URLSearchParams(new FormData(stopForm));
          const response = await fetch('timer.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json',
            },
            body: params.toString(),
          });

          const data = await response.json().catch(() => null);

          if (!response.ok || !data || !data.success) {
            resetAfterError(
              data && data.error ? data.error : 'Es ist ein Fehler beim Speichern der Lerneinheit aufgetreten.',
              data && data.field ? data.field : null
            );
            return;
          }

          window.location.href = 'timer.php';
        } catch {
          resetAfterError('Netzwerkfehler. Bitte überprüfe deine Verbindung und versuche es erneut.');
        }
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
