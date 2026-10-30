// assets/js/manual-time.js
// Clientseitige Logik für das Modal der manuellen Zeitnachbuchung (Issue #9)

document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('manualTimeModal');
  const form = document.getElementById('manualTimeForm');

  if (!modalEl || !form) {
    return;
  }

  const alertBox = document.getElementById('manual-time-alert');
  const modalTitle = document.getElementById('manualTimeModalLabel');
  const submitBtn = document.getElementById('manualTimeSubmitBtn');
  const actionInput = document.getElementById('manual_time_action');
  const entryIdInput = document.getElementById('manual_time_entry_id');
  const slotIdInput = document.getElementById('manual_time_learning_slot_id');
  const goalSelect = document.getElementById('manual_time_monthly_goal_id');
  const dateInput = document.getElementById('manual_time_entry_date');
  const hoursInput = document.getElementById('manual_time_duration_hours');
  const minutesInput = document.getElementById('manual_time_duration_minutes');
  const durationHint = document.getElementById('manual_time_duration_hint');
  const noteInput = document.getElementById('manual_time_note');

  const BAD_INPUT_MESSAGES = {
    entry_date: 'Das Datum muss im gültigen Format JJJJ-MM-TT vorliegen.',
    duration_hours: 'Die Stunden müssen eine ganze Zahl ab 0 sein.',
    duration_minutes: 'Die Minuten müssen eine ganze Zahl zwischen 0 und 59 sein.',
  };

  let hasUnsavedErrorState = false;
  let lastTriggerSignature = '';

  /**
   * Entfernt die Rotmarkierung eines einzelnen Eingabefelds.
   * @param {Element} field
   */
  function clearFieldError(field) {
    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
    field.removeAttribute('aria-describedby');
  }

  /**
   * Entfernt Fehlermeldung und alle roten Feldmarkierungen im Formular.
   */
  function clearFormErrors() {
    if (alertBox) {
      alertBox.className = 'alert alert-danger d-none';
      alertBox.textContent = '';
    }

    form.querySelectorAll('.is-invalid').forEach(clearFieldError);
  }

  /**
   * Zeigt eine Fehlermeldung im Modal an und markiert optional das betroffene Feld rot.
   * @param {string} message
   * @param {string|null} [fieldName]
   */
  function showFormError(message, fieldName = null) {
    hasUnsavedErrorState = true;

    if (alertBox) {
      alertBox.className = 'alert alert-danger mb-3';
      alertBox.textContent = '';

      const strong = document.createElement('strong');
      strong.textContent = 'Fehler: ';
      alertBox.appendChild(strong);
      alertBox.appendChild(document.createTextNode(message));
    }

    if (fieldName) {
      const fieldEl = form.querySelector(`[name="${fieldName}"]`);
      if (fieldEl) {
        fieldEl.classList.add('is-invalid');
        fieldEl.setAttribute('aria-invalid', 'true');
        if (alertBox && alertBox.id) {
          fieldEl.setAttribute('aria-describedby', alertBox.id);
        }
        fieldEl.focus();
      }
    }
  }

  /**
   * Aktualisiert die Vorschau der Gesamtdauer sowie den aktiven Zustand der Schnellwahl-Buttons.
   */
  function updateDurationHint() {
    if (!hoursInput || !minutesInput || !durationHint) {
      return;
    }

    const rawHours = parseInt(hoursInput.value, 10);
    const rawMinutes = parseInt(minutesInput.value, 10);
    const hours = Number.isNaN(rawHours) || rawHours < 0 ? 0 : rawHours;
    const minutes = Number.isNaN(rawMinutes) || rawMinutes < 0 ? 0 : rawMinutes;
    const totalMinutes = hours * 60 + minutes;

    form.querySelectorAll('.manual-time-preset-btn').forEach((btn) => {
      const presetMin = parseInt(btn.getAttribute('data-quick-minutes') || '0', 10);
      const isActive = totalMinutes > 0 && presetMin === totalMinutes && minutes < 60;
      btn.classList.toggle('btn-secondary', isActive);
      btn.classList.toggle('text-white', isActive);
      btn.classList.toggle('btn-outline-secondary', !isActive);
    });

    durationHint.textContent = '';

    if (totalMinutes <= 0) {
      durationHint.className = 'form-text text-danger';
      durationHint.textContent = 'Bitte gib eine Dauer von mindestens einer Minute an.';
      return;
    }

    if (totalMinutes > 1440) {
      durationHint.className = 'form-text text-danger';
      durationHint.textContent = 'Maximal 24 Stunden (1440 Minuten) pro Eintrag möglich.';
      return;
    }

    durationHint.className = 'form-text';
    durationHint.appendChild(document.createTextNode('Erfasste Netto-Lernzeit: '));

    const strong = document.createElement('strong');
    const displayHours = Math.floor(totalMinutes / 60);
    const displayMinutes = totalMinutes % 60;
    if (displayHours > 0 && displayMinutes > 0) {
      strong.textContent = `${displayHours} Std. ${displayMinutes} Min.`;
    } else if (displayHours > 0) {
      strong.textContent = `${displayHours} Std.`;
    } else {
      strong.textContent = `${displayMinutes} Min.`;
    }
    durationHint.appendChild(strong);
  }

  /**
   * Setzt die Dauer-Eingabefelder anhand einer Gesamtminutenzahl.
   * @param {number} totalMinutes
   */
  function setDurationFromMinutes(totalMinutes) {
    if (!hoursInput || !minutesInput) {
      return;
    }
    const safeMinutes = Math.max(0, Math.min(1440, Math.round(totalMinutes)));
    hoursInput.value = String(Math.floor(safeMinutes / 60));
    minutesInput.value = String(safeMinutes % 60);
    clearFieldError(hoursInput);
    clearFieldError(minutesInput);
    updateDurationHint();
  }

  // Schnellauswahl-Buttons für gängige Lerndauern
  form.querySelectorAll('.manual-time-preset-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const minutes = parseInt(btn.getAttribute('data-quick-minutes') || '0', 10);
      if (minutes > 0) {
        setDurationFromMinutes(minutes);
      }
    });
  });

  if (hoursInput) {
    hoursInput.addEventListener('input', updateDurationHint);
    hoursInput.addEventListener('change', updateDurationHint);
  }

  if (minutesInput) {
    minutesInput.addEventListener('input', updateDurationHint);
    minutesInput.addEventListener('change', updateDurationHint);
  }

  // Rote Feldmarkierung entfernen, sobald der Nutzer ein Feld korrigiert
  form.querySelectorAll('.form-control, .form-select').forEach((field) => {
    const onFieldChange = () => {
      if (field.classList.contains('is-invalid')) {
        clearFieldError(field);
      }
    };
    field.addEventListener('input', onFieldChange);
    field.addEventListener('change', onFieldChange);
  });

  // Vorbefüllen des Modals beim Öffnen über Trigger-Buttons (z. B. Neuanlage oder Bearbeiten)
  modalEl.addEventListener('show.bs.modal', (event) => {
    const triggerBtn = event.relatedTarget;
    if (!triggerBtn) {
      return;
    }

    const entryId = triggerBtn.getAttribute('data-entry-id') || '';
    const goalId = triggerBtn.getAttribute('data-monthly-goal-id') || '';
    const entryDate = triggerBtn.getAttribute('data-entry-date') || '';
    const durationMinAttr = triggerBtn.getAttribute('data-duration-minutes') || '';
    const trackedSecAttr = triggerBtn.getAttribute('data-tracked-seconds') || '';
    const slotId = triggerBtn.getAttribute('data-learning-slot-id') || '';
    const note = triggerBtn.getAttribute('data-note') || '';

    const currentSignature = [entryId, goalId, entryDate, durationMinAttr, trackedSecAttr, slotId].join('|');

    // Wenn im vorherigen Versuch ein Validierungsfehler auftrat und derselbe Button erneut geöffnet wird,
    // bleiben die vom Nutzer eingegebenen Werte erhalten (wie bei Issue #21).
    if (hasUnsavedErrorState && currentSignature === lastTriggerSignature) {
      return;
    }

    lastTriggerSignature = currentSignature;
    hasUnsavedErrorState = false;
    clearFormErrors();

    const isEditMode = entryId !== '';
    if (actionInput) {
      actionInput.value = isEditMode ? 'update_manual_entry' : 'create_manual_entry';
    }
    if (entryIdInput) {
      entryIdInput.value = entryId;
    }
    if (slotIdInput) {
      slotIdInput.value = slotId;
    }
    if (modalTitle) {
      modalTitle.textContent = isEditMode ? 'Nachbuchung bearbeiten' : 'Lernzeit nachbuchen';
    }
    if (submitBtn) {
      submitBtn.textContent = isEditMode ? 'Änderungen speichern' : 'Lernzeit speichern';
    }

    if (goalSelect) {
      if (goalId !== '' && goalSelect.querySelector(`option[value="${CSS.escape(goalId)}"]`)) {
        goalSelect.value = goalId;
      } else {
        goalSelect.value = '';
      }
    }

    if (dateInput) {
      const defaultDate = dateInput.getAttribute('data-default') || '';
      dateInput.value = entryDate !== '' ? entryDate : defaultDate;
    }

    if (durationMinAttr !== '') {
      setDurationFromMinutes(parseInt(durationMinAttr, 10) || 60);
    } else if (trackedSecAttr !== '') {
      const sec = parseInt(trackedSecAttr, 10) || 3600;
      setDurationFromMinutes(Math.max(1, Math.round(sec / 60)));
    } else {
      setDurationFromMinutes(60);
    }

    if (noteInput) {
      noteInput.value = note;
    }
  });

  // Formular per Fetch an api/time-entries.php absenden
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFormErrors();

    for (const [fieldName, message] of Object.entries(BAD_INPUT_MESSAGES)) {
      const input = form.querySelector(`[name="${fieldName}"]`);
      if (input && input.validity && input.validity.badInput) {
        showFormError(message, fieldName);
        return;
      }
    }

    const payload = Object.fromEntries(new FormData(form).entries());
    const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Speichern…';
    }

    try {
      const response = await fetch('api/time-entries.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(payload),
      });

      const data = await response.json().catch(() => null);

      if (!response.ok || !data || !data.success) {
        const message =
          data && data.message
            ? data.message
            : 'Es ist ein Fehler beim Speichern der Lernzeit aufgetreten.';
        showFormError(message, data && data.field ? data.field : null);
        return;
      }

      hasUnsavedErrorState = false;
      sessionStorage.setItem('goals_scroll_y', String(window.scrollY));
      window.location.reload();
    } catch {
      showFormError('Netzwerkfehler. Bitte überprüfe deine Verbindung und versuche es erneut.');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnHtml;
      }
    }
  });

  // Optional: Löschen-Buttons für manuell nachgebuchte Einträge
  document.querySelectorAll('.delete-manual-time-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const entryId = btn.getAttribute('data-entry-id') || '';
      const csrfInput = form.querySelector('[name="csrf_token"]');
      const csrfToken = csrfInput ? csrfInput.value : '';

      if (!entryId || !csrfToken) {
        return;
      }

      if (!window.confirm('Möchtest du diesen nachgebuchten Zeiteintrag wirklich löschen?')) {
        return;
      }

      btn.disabled = true;

      try {
        const response = await fetch('api/time-entries.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify({
            action: 'delete_entry',
            entry_id: entryId,
            csrf_token: csrfToken,
          }),
        });

        const data = await response.json().catch(() => null);
        if (response.ok && data && data.success) {
          window.location.reload();
        } else {
          window.alert((data && data.message) || 'Der Eintrag konnte nicht gelöscht werden.');
          btn.disabled = false;
        }
      } catch {
        window.alert('Netzwerkfehler beim Löschen des Eintrags.');
        btn.disabled = false;
      }
    });
  });

  updateDurationHint();
});

