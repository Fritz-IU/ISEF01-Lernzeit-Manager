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
  const dateInput = document.getElementById('manual_time_entry_date');
  const goalSelect = document.getElementById('manual_time_monthly_goal_id');
  const goalPlaceholder = document.getElementById('manual_time_goal_placeholder');
  const goalHint = document.getElementById('manual_time_goal_hint');
  const slotWrapper = document.getElementById('manual_time_slot_wrapper');
  const slotSelect = document.getElementById('manual_time_learning_slot_id');
  const slotHint = document.getElementById('manual_time_slot_hint');
  const hoursInput = document.getElementById('manual_time_duration_hours');
  const minutesInput = document.getElementById('manual_time_duration_minutes');
  const durationHint = document.getElementById('manual_time_duration_hint');
  const noteInput = document.getElementById('manual_time_note');

  const MONTH_NAMES = [
    'Januar',
    'Februar',
    'März',
    'April',
    'Mai',
    'Juni',
    'Juli',
    'August',
    'September',
    'Oktober',
    'November',
    'Dezember',
  ];

  const BAD_INPUT_MESSAGES = {
    entry_date: 'Das Datum muss im gültigen Format JJJJ-MM-TT vorliegen.',
    duration_hours: 'Die Stunden müssen eine ganze Zahl ab 0 sein.',
    duration_minutes: 'Die Minuten müssen eine ganze Zahl zwischen 0 und 59 sein.',
  };

  let hasUnsavedErrorState = false;
  let lastTriggerSignature = '';

  /**
   * Formatiert einen Monat im Format YYYY-MM als deutschen Monatsnamen (z. B. "Oktober 2026").
   * @param {string} yearMonth
   * @returns {string}
   */
  function formatMonthLabel(yearMonth) {
    const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(yearMonth);
    if (!match) {
      return yearMonth;
    }
    const monthIndex = parseInt(match[2], 10) - 1;
    return `${MONTH_NAMES[monthIndex]} ${match[1]}`;
  }

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
      durationHint.className = 'form-text text-danger mt-1';
      durationHint.textContent = 'Bitte gib eine Dauer von mindestens einer Minute an.';
      updateSlotHintText();
      return;
    }

    if (totalMinutes > 1440) {
      durationHint.className = 'form-text text-danger mt-1';
      durationHint.textContent = 'Maximal 24 Stunden pro Eintrag möglich.';
      updateSlotHintText();
      return;
    }

    durationHint.className = 'form-text d-none';
    updateSlotHintText();
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

  /**
   * Aktualisiert den Hinweistext unter der Lerneinheiten-Auswahl.
   */
  function updateSlotHintText() {
    if (!slotSelect || !slotHint) {
      return;
    }

    const selectedOpt = slotSelect.selectedOptions[0];
    if (!selectedOpt || !selectedOpt.value) {
      slotHint.className = 'form-text text-muted';
      slotHint.textContent = 'Wird als freie Lernzeit auf das Modul gebucht.';
      return;
    }

    const isCompleted = selectedOpt.getAttribute('data-is-completed') === '1';
    if (isCompleted) {
      slotHint.className = 'form-text text-muted';
      slotHint.textContent = 'Diese Lerneinheit ist bereits als erledigt markiert.';
      return;
    }

    const slotMinutes = parseInt(selectedOpt.getAttribute('data-duration-minutes') || '0', 10);
    const rawHours = hoursInput ? parseInt(hoursInput.value, 10) : 0;
    const rawMinutes = minutesInput ? parseInt(minutesInput.value, 10) : 0;
    const hours = Number.isNaN(rawHours) || rawHours < 0 ? 0 : rawHours;
    const minutes = Number.isNaN(rawMinutes) || rawMinutes < 0 ? 0 : rawMinutes;
    const totalMinutes = hours * 60 + minutes;

    if (totalMinutes > 0 && slotMinutes > 0 && totalMinutes < slotMinutes) {
      const missingHours = Math.max(0.1, Math.round(((slotMinutes - totalMinutes) / 60) * 10) / 10);
      const missingFormatted = missingHours.toFixed(1).replace('.', ',');
      slotHint.className = 'form-text text-warning-emphasis fw-semibold';
      slotHint.textContent = `${missingFormatted} h weniger als geplant`;
    } else {
      slotHint.className = 'form-text text-success fw-semibold';
      slotHint.textContent = '✓ Wird beim Speichern als erledigt abgehakt.';
    }
  }

  /**
   * Filtert die geplanten Lerneinheiten (learning_slots) passend zum gewählten Datum und Modul.
   * @param {boolean} [autoSelectOpenSlot=false]
   */
  function filterSlotsByDateAndGoal(autoSelectOpenSlot = false) {
    if (!slotWrapper || !slotSelect || !dateInput || !goalSelect) {
      return;
    }

    const selectedDate = dateInput.value.trim();
    const selectedGoalId = goalSelect.value.trim();

    if (!selectedDate || !selectedGoalId) {
      slotWrapper.classList.add('d-none');
      slotSelect.value = '';
      return;
    }

    let matchingCount = 0;
    let firstOpenOption = null;
    const previousSlotId = slotSelect.value;
    let previousStillValid = false;

    slotSelect.querySelectorAll('option[data-slot-date]').forEach((opt) => {
      const matchesDate = opt.getAttribute('data-slot-date') === selectedDate;
      const matchesGoal = opt.getAttribute('data-monthly-goal-id') === selectedGoalId;
      const isVisible = matchesDate && matchesGoal;

      opt.hidden = !isVisible;
      opt.disabled = !isVisible;

      if (isVisible) {
        matchingCount += 1;
        if (opt.value === previousSlotId) {
          previousStillValid = true;
        }
        if (!firstOpenOption && opt.getAttribute('data-is-completed') === '0') {
          firstOpenOption = opt;
        }
      }
    });

    if (matchingCount === 0) {
      slotWrapper.classList.add('d-none');
      slotSelect.value = '';
      return;
    }

    slotWrapper.classList.remove('d-none');

    if (previousStillValid && !autoSelectOpenSlot) {
      slotSelect.value = previousSlotId;
    } else if (autoSelectOpenSlot && firstOpenOption) {
      slotSelect.value = firstOpenOption.value;
      const slotMinutes = parseInt(
        firstOpenOption.getAttribute('data-duration-minutes') || '0',
        10
      );
      if (slotMinutes > 0) {
        setDurationFromMinutes(slotMinutes);
      }
    } else if (!previousStillValid) {
      slotSelect.value = '';
    }

    updateSlotHintText();
  }

  /**
   * Filtert die Optionen im Modul-Dropdown (monthly_goal_id) auf den Monat (YYYY-MM) des gewählten Lerntags.
   */
  function filterGoalsBySelectedDate() {
    if (!dateInput || !goalSelect) {
      return;
    }

    const dateVal = dateInput.value.trim();
    const selectedMonth = /^\d{4}-(0[1-9]|1[0-2])/.test(dateVal) ? dateVal.slice(0, 7) : '';
    const previousGoalId = goalSelect.value;
    let totalVisible = 0;
    let previousStillValid = false;

    goalSelect.querySelectorAll('optgroup').forEach((group) => {
      let visibleInGroup = 0;
      group.querySelectorAll('option[data-plan-month]').forEach((opt) => {
        const matchesMonth =
          selectedMonth !== '' && opt.getAttribute('data-plan-month') === selectedMonth;
        opt.hidden = !matchesMonth;
        opt.disabled = !matchesMonth;

        if (matchesMonth) {
          visibleInGroup += 1;
          totalVisible += 1;
          if (opt.value === previousGoalId) {
            previousStillValid = true;
          }
        }
      });

      group.hidden = visibleInGroup === 0;
    });

    if (!previousStillValid) {
      goalSelect.value = '';
    }

    if (selectedMonth === '') {
      goalSelect.disabled = true;
      if (goalPlaceholder) {
        goalPlaceholder.textContent = 'Bitte zuerst ein Datum wählen…';
      }
      if (goalHint) {
        goalHint.className = 'form-text d-none';
        goalHint.textContent = '';
      }
    } else if (totalVisible === 0) {
      goalSelect.disabled = true;
      if (goalPlaceholder) {
        goalPlaceholder.textContent = `Keine Module im ${formatMonthLabel(selectedMonth)}`;
      }
      if (goalHint) {
        goalHint.className = 'form-text text-warning-emphasis';
        goalHint.textContent = '';
        goalHint.appendChild(
          document.createTextNode(
            `Für ${formatMonthLabel(selectedMonth)} sind noch keine Module angelegt. `
          )
        );
        const link = document.createElement('a');
        link.href = `goals.php?tab=month&month=${encodeURIComponent(selectedMonth)}`;
        link.className = 'fw-semibold';
        link.textContent = 'Jetzt anlegen';
        goalHint.appendChild(link);
      }
    } else {
      goalSelect.disabled = false;
      if (goalPlaceholder) {
        goalPlaceholder.textContent = 'Bitte Modul auswählen…';
      }
      if (goalHint) {
        goalHint.className = 'form-text d-none';
        goalHint.textContent = '';
      }
    }

    filterSlotsByDateAndGoal(false);
  }

  // Bei Datumswechsel: Modul-Liste auf den neuen Monat filtern
  if (dateInput) {
    dateInput.addEventListener('change', () => {
      filterGoalsBySelectedDate();
    });
    dateInput.addEventListener('input', () => {
      if (/^\d{4}-\d{2}-\d{2}$/.test(dateInput.value)) {
        filterGoalsBySelectedDate();
      }
    });
  }

  // Bei Modulwechsel: Geplante Lerneinheiten an diesem Tag prüfen und ggf. offene Einheit vorschlagen
  if (goalSelect) {
    goalSelect.addEventListener('change', () => {
      filterSlotsByDateAndGoal(true);
    });
  }

  // Bei Wechsel der geplanten Lerneinheit: Geplante Dauer übernehmen und Hinweis aktualisieren
  if (slotSelect) {
    slotSelect.addEventListener('change', () => {
      const selectedOpt = slotSelect.selectedOptions[0];
      if (selectedOpt && selectedOpt.value) {
        const slotMinutes = parseInt(selectedOpt.getAttribute('data-duration-minutes') || '0', 10);
        if (slotMinutes > 0) {
          setDurationFromMinutes(slotMinutes);
        }
      }
      updateSlotHintText();
    });
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

  // Vorbefüllen des Modals beim Öffnen über Trigger-Buttons
  modalEl.addEventListener('show.bs.modal', (event) => {
    const triggerBtn = event.relatedTarget;
    if (!triggerBtn) {
      filterGoalsBySelectedDate();
      return;
    }

    const entryId = triggerBtn.getAttribute('data-entry-id') || '';
    const goalId = triggerBtn.getAttribute('data-monthly-goal-id') || '';
    const entryDate = triggerBtn.getAttribute('data-entry-date') || '';
    const durationMinAttr = triggerBtn.getAttribute('data-duration-minutes') || '';
    const trackedSecAttr = triggerBtn.getAttribute('data-tracked-seconds') || '';
    const slotId = triggerBtn.getAttribute('data-learning-slot-id') || '';
    const note = triggerBtn.getAttribute('data-note') || '';

    const currentSignature = [
      entryId,
      goalId,
      entryDate,
      durationMinAttr,
      trackedSecAttr,
      slotId,
    ].join('|');

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
    if (modalTitle) {
      modalTitle.textContent = isEditMode ? 'Nachbuchung bearbeiten' : 'Lernzeit nachbuchen';
    }
    if (submitBtn) {
      submitBtn.textContent = isEditMode ? 'Änderungen speichern' : 'Lernzeit speichern';
    }

    if (dateInput) {
      const defaultDate = dateInput.getAttribute('data-default') || '';
      dateInput.value = entryDate !== '' ? entryDate : defaultDate;
    }

    filterGoalsBySelectedDate();

    if (goalSelect) {
      const targetOpt =
        goalId !== '' ? goalSelect.querySelector(`option[value="${CSS.escape(goalId)}"]`) : null;
      if (targetOpt && !targetOpt.disabled) {
        goalSelect.value = goalId;
      } else {
        goalSelect.value = '';
      }
    }

    filterSlotsByDateAndGoal(slotId === '');

    if (slotSelect && slotId !== '') {
      const slotOpt = slotSelect.querySelector(`option[value="${CSS.escape(slotId)}"]`);
      if (slotOpt && !slotOpt.disabled) {
        slotSelect.value = slotId;
        updateSlotHintText();
      }
    }

    if (durationMinAttr !== '') {
      setDurationFromMinutes(parseInt(durationMinAttr, 10) || 60);
    } else if (trackedSecAttr !== '') {
      const sec = parseInt(trackedSecAttr, 10) || 3600;
      setDurationFromMinutes(Math.max(1, Math.round(sec / 60)));
    } else if (!slotSelect || !slotSelect.value) {
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

  filterGoalsBySelectedDate();
  updateDurationHint();
});
