// assets/js/goals.js

document.addEventListener('DOMContentLoaded', () => {
  const createModalEl = document.getElementById('createGoalModal');
  const createForm = document.getElementById('createGoalForm');
  const editModalEl = document.getElementById('editGoalModal');
  const editForm = document.getElementById('editGoalForm');
  const createMonthlyForm = document.getElementById('createMonthlyGoalForm');
  const createSlotForm = document.getElementById('createSlotForm');
  const editSlotForm = document.getElementById('editSlotForm');

  // Meldungen für Eingaben, die der Browser selbst nicht als Zahl bzw. Datum lesen kann
  const BAD_INPUT_MESSAGES = {
    target_hours: 'Die Zielstunden müssen eine Zahl sein.',
    planned_hours: 'Die geplanten Lernstunden müssen eine Zahl sein.',
    budget_hours: 'Das monatliche Zeitbudget muss eine Zahl sein.',
    start_date: 'Das Startdatum muss im gültigen Format JJJJ-MM-TT vorliegen.',
    end_date: 'Das Enddatum muss im gültigen Format JJJJ-MM-TT vorliegen.',
    year_month: 'Der Monat muss im gültigen Format JJJJ-MM vorliegen.',
    slot_date: 'Das Datum muss im gültigen Format JJJJ-MM-TT vorliegen.',
    start_time: 'Die Startzeit muss im gültigen Format HH:MM vorliegen.',
    end_time: 'Die Endzeit muss im gültigen Format HH:MM vorliegen.',
  };

  // Scroll-Position nach Formular-Submit (Post/Redirect/Get) wiederherstellen,
  // damit die Seite nach dem Speichern ruhig an derselben Stelle bleibt.
  const savedScrollY = sessionStorage.getItem('goals_scroll_y');
  if (savedScrollY !== null) {
    sessionStorage.removeItem('goals_scroll_y');
    const y = parseInt(savedScrollY, 10);
    if (!Number.isNaN(y)) {
      window.scrollTo({ top: y, behavior: 'instant' });
    }
  }

  document.addEventListener('submit', (event) => {
    if (event.target && event.target.tagName === 'FORM') {
      sessionStorage.setItem('goals_scroll_y', String(window.scrollY));
    }
  });

  /**
   * Liefert die Fehlerbox im Modal eines Formulars.
   * @param {HTMLFormElement|null} form
   * @returns {HTMLElement|null}
   */
  function getFormAlert(form) {
    return form ? form.querySelector('.modal-body > .alert-danger') : null;
  }

  /**
   * Entfernt die Rotmarkierung (is-invalid) eines einzelnen Eingabefelds.
   * @param {Element} field
   */
  function clearFieldError(field) {
    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
    field.removeAttribute('aria-describedby');
  }

  /**
   * Entfernt Fehlermeldung und rote Markierungen (is-invalid) innerhalb eines Formulars.
   * @param {HTMLFormElement|null} form
   */
  function clearFormErrors(form) {
    if (!form) return;

    const alertBox = getFormAlert(form);
    if (alertBox) {
      alertBox.className = 'alert alert-danger d-none';
      alertBox.textContent = '';
    }

    form.querySelectorAll('.is-invalid').forEach(clearFieldError);
  }

  /**
   * Zeigt eine Fehlermeldung im Modal an und markiert optional das betroffene Eingabefeld rot.
   * @param {HTMLFormElement} form
   * @param {string} message
   * @param {string|null} [fieldName]
   */
  function showFormError(form, message, fieldName = null) {
    const alertBox = getFormAlert(form);
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
   * Richtet die asynchrone Übermittlung via Fetch an api/goals.php ein. Bei Fehlern bleibt
   * das Modal mit allen Eingaben offen; nach Erfolg wird auf die passende Ansicht weitergeleitet.
   * @param {HTMLFormElement} form
   */
  function setupAjaxForm(form) {
    // Native Browser-Tooltips deaktivieren, damit alle Fehler einheitlich im Modal dargestellt werden
    form.noValidate = true;

    // Rote Markierung eines Feldes entfernen, sobald der Nutzer die Eingabe korrigiert
    form.querySelectorAll('.form-control, .form-select').forEach((field) => {
      const clearSingleField = () => {
        if (field.classList.contains('is-invalid')) {
          clearFieldError(field);
        }
      };
      field.addEventListener('input', clearSingleField);
      field.addEventListener('change', clearSingleField);
    });

    const submitBtn = form.querySelector('[type="submit"]');

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearFormErrors(form);

      // Prüfen, ob der Browser eine syntaktisch ungültige Zahl oder ein ungültiges Datum erkannt hat
      for (const [fieldName, message] of Object.entries(BAD_INPUT_MESSAGES)) {
        const input = form.querySelector(`[name="${fieldName}"]`);
        if (input && input.validity && input.validity.badInput) {
          showFormError(form, message, fieldName);
          return;
        }
      }

      // Alle Formularfelder inkl. CSRF-Token und Aktion übernehmen
      const payload = Object.fromEntries(new FormData(form).entries());

      const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML =
          '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Speichern…';
      }

      try {
        const response = await fetch('api/goals.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify(payload),
        });

        // Antwort ohne gültiges JSON (z. B. PHP-Fehlerseite) als technischen Fehler behandeln
        const result = await response.json().catch(() => null);
        if (!result) {
          showFormError(
            form,
            'Es ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.'
          );
          return;
        }

        if (response.ok && result.success) {
          window.location.href = result.redirect || 'goals.php';
          return;
        }

        showFormError(form, result.message || 'Bitte überprüfe deine Eingaben.', result.field || null);
      } catch (error) {
        showFormError(form, 'Netzwerkfehler: Die Eingaben konnten nicht gespeichert werden.');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnHtml;
        }
      }
    });

    // Fehlermarkierungen beim Schließen des Modals entfernen
    const modalEl = form.closest('.modal');
    if (modalEl) {
      modalEl.addEventListener('hidden.bs.modal', () => clearFormErrors(form));
    }
  }

  /**
   * Setzt das Formular für ein neues Lernziel auf die Standardwerte zurück.
   */
  function resetCreateGoalForm() {
    if (!createForm) return;
    createForm.reset();
    clearFormErrors(createForm);

    const startInput = createForm.querySelector('[name="start_date"]');
    const endInput = createForm.querySelector('[name="end_date"]');
    if (startInput && startInput.dataset.default) {
      startInput.value = startInput.dataset.default;
    }
    if (endInput && endInput.dataset.default) {
      endInput.value = endInput.dataset.default;
    }
  }

  /**
   * Berechnet die Dauer zwischen Start- und Endzeit in einem Timeslot-Formular
   * und zeigt sie als Hinweis unterhalb der Zeitfelder an.
   *
   * @param {HTMLInputElement|null} startInput
   * @param {HTMLInputElement|null} endInput
   * @param {HTMLElement|null} hintEl
   */
  function updateSlotDurationHint(startInput, endInput, hintEl) {
    if (!startInput || !endInput || !hintEl) return;

    const startVal = startInput.value;
    const endVal = endInput.value;
    if (!startVal || !endVal) {
      hintEl.className = 'form-text';
      hintEl.textContent = 'Mindestdauer: 15 Minuten.';
      return;
    }

    const [sh, sm] = startVal.split(':').map(Number);
    const [eh, em] = endVal.split(':').map(Number);
    if (Number.isNaN(sh) || Number.isNaN(sm) || Number.isNaN(eh) || Number.isNaN(em)) {
      return;
    }

    const diffMinutes = eh * 60 + em - (sh * 60 + sm);
    if (diffMinutes <= 0) {
      hintEl.className = 'form-text text-danger';
      hintEl.textContent = 'Die Endzeit muss zeitlich nach der Startzeit liegen.';
      return;
    }

    if (diffMinutes < 15) {
      hintEl.className = 'form-text text-danger';
      hintEl.textContent = 'Ein Lern-Timeslot muss mindestens 15 Minuten dauern.';
      return;
    }

    const hoursFormatted = (diffMinutes / 60).toLocaleString('de-DE', {
      minimumFractionDigits: 1,
      maximumFractionDigits: 2,
    });

    hintEl.className = 'form-text';
    hintEl.textContent = '';
    hintEl.appendChild(document.createTextNode('Geplante Dauer: '));
    const strong = document.createElement('strong');
    strong.textContent = `${hoursFormatted} Stunden`;
    hintEl.appendChild(strong);
    hintEl.appendChild(document.createTextNode(` (${diffMinutes} Min.)`));
  }

  const createSlotStartInput = document.getElementById('create_slot_start_time');
  const createSlotEndInput = document.getElementById('create_slot_end_time');
  const createSlotHintEl = document.getElementById('create_slot_duration_hint');
  if (createSlotStartInput && createSlotEndInput) {
    const refreshCreateHint = () =>
      updateSlotDurationHint(createSlotStartInput, createSlotEndInput, createSlotHintEl);
    createSlotStartInput.addEventListener('input', refreshCreateHint);
    createSlotEndInput.addEventListener('input', refreshCreateHint);
  }

  const editSlotStartInput = document.getElementById('edit_slot_start_time');
  const editSlotEndInput = document.getElementById('edit_slot_end_time');
  const editSlotHintEl = document.getElementById('edit_slot_duration_hint');
  if (editSlotStartInput && editSlotEndInput) {
    const refreshEditHint = () =>
      updateSlotDurationHint(editSlotStartInput, editSlotEndInput, editSlotHintEl);
    editSlotStartInput.addEventListener('input', refreshEditHint);
    editSlotEndInput.addEventListener('input', refreshEditHint);
  }

  document.querySelectorAll('form[data-ajax-form]').forEach(setupAjaxForm);

  // Formular beim Schließen oder erneuten Öffnen des Erstellen-Modals zurücksetzen
  if (createModalEl) {
    createModalEl.addEventListener('show.bs.modal', () => {
      if (createModalEl.dataset.autoOpen !== 'true') {
        resetCreateGoalForm();
      }
    });

    createModalEl.addEventListener('hidden.bs.modal', resetCreateGoalForm);
  }

  // Beim Schließen der Bearbeiten-Modals ungespeicherte Eingaben verwerfen
  [
    editForm,
    createMonthlyForm,
    document.getElementById('editMonthlyGoalForm'),
    createSlotForm,
    editSlotForm,
  ].forEach((form) => {
    const modalEl = form ? form.closest('.modal') : null;
    if (modalEl) {
      modalEl.addEventListener('hidden.bs.modal', () => form.reset());
    }
  });

  // Event-Delegation für alle Modal-Buttons sowie den asynchronen Monats- und Wochenwechsel
  document.addEventListener('click', async (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    // 1a. Monatswechsel ohne Seiten-Reload und ohne Scrollen
    const monthSwitchLink = target.closest('[data-month-switch]');
    if (monthSwitchLink) {
      const targetMonth = monthSwitchLink.getAttribute('data-month-switch');
      if (targetMonth) {
        event.preventDefault();
        await switchMonthWithoutReload(targetMonth);
      }
      return;
    }

    // 1b. Wochenwechsel ohne Seiten-Reload und ohne Scrollen
    const weekSwitchLink = target.closest('[data-week-switch]');
    if (weekSwitchLink) {
      const targetWeek = weekSwitchLink.getAttribute('data-week-switch');
      const targetMonth = weekSwitchLink.getAttribute('data-week-month') || '';
      if (targetWeek) {
        event.preventDefault();
        await switchWeekWithoutReload(targetWeek, targetMonth);
      }
      return;
    }

    // 1c. Schnellwechsel aus der Monatsplanung in den Tab "Wochenplanung"
    const openWeekTabBtn = target.closest('[data-open-week-tab]');
    if (openWeekTabBtn) {
      const weekTabBtn = document.getElementById('tab-week-btn');
      if (weekTabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
        event.preventDefault();
        bootstrap.Tab.getOrCreateInstance(weekTabBtn).show();
      }
      return;
    }

    // 2. Modal: Lernziel bearbeiten
    const editGoalBtn = target.closest('.edit-goal-btn');
    if (editGoalBtn) {
      clearFormErrors(editForm);

      const editIdInput = document.getElementById('edit_goal_id');
      const editTitleInput = document.getElementById('edit_title');
      const editHoursInput = document.getElementById('edit_target_hours');
      const editStartInput = document.getElementById('edit_start_date');
      const editEndInput = document.getElementById('edit_end_date');
      const editDescInput = document.getElementById('edit_description');

      if (editIdInput) editIdInput.value = editGoalBtn.dataset.id || '';
      if (editTitleInput) editTitleInput.value = editGoalBtn.dataset.title || '';
      if (editHoursInput) editHoursInput.value = editGoalBtn.dataset.hours || '';
      if (editStartInput) editStartInput.value = editGoalBtn.dataset.start || '';
      if (editEndInput) editEndInput.value = editGoalBtn.dataset.end || '';
      if (editDescInput) editDescInput.value = editGoalBtn.dataset.description || '';
      return;
    }

    // 3. Modal: Lernziel löschen
    const deleteGoalBtn = target.closest('.delete-goal-btn');
    if (deleteGoalBtn) {
      const deleteIdInput = document.getElementById('delete_goal_id');
      const deleteTitleSpan = document.getElementById('delete_goal_title');

      if (deleteIdInput) deleteIdInput.value = deleteGoalBtn.dataset.id || '';
      if (deleteTitleSpan) deleteTitleSpan.textContent = deleteGoalBtn.dataset.title || '';
      return;
    }

    // 4. Modal: Monatliches Zeitbudget festlegen / bearbeiten
    const budgetBtn = target.closest('[data-bs-target="#monthlyBudgetModal"]');
    if (budgetBtn) {
      const budgetMonthInput = document.getElementById('budget_year_month');
      const budgetHoursInput = document.getElementById('budget_hours_input');

      if (budgetMonthInput && budgetBtn.dataset.month) {
        budgetMonthInput.value = budgetBtn.dataset.month;
      }
      if (budgetHoursInput && typeof budgetBtn.dataset.budget !== 'undefined') {
        budgetHoursInput.value = budgetBtn.dataset.budget;
      }
      return;
    }

    // 5. Modal: Zwischenziel anlegen
    const createMonthlyBtn = target.closest('[data-bs-target="#createMonthlyGoalModal"]');
    if (createMonthlyBtn) {
      const createMgGoalSelect = document.getElementById('create_mg_goal_id');
      const createMgMonthInput = document.getElementById('create_mg_year_month');

      if (createMgMonthInput && createMonthlyBtn.dataset.month) {
        createMgMonthInput.value = createMonthlyBtn.dataset.month;
      }
      if (createMgGoalSelect && createMonthlyBtn.dataset.goalId) {
        createMgGoalSelect.value = createMonthlyBtn.dataset.goalId;
      }
      return;
    }

    // 6. Modal: Zwischenziel bearbeiten
    const editMonthlyBtn = target.closest('.edit-monthly-goal-btn');
    if (editMonthlyBtn) {
      const editMgIdInput = document.getElementById('edit_mg_id');
      const editMgGoalSelect = document.getElementById('edit_mg_goal_id');
      const editMgMonthInput = document.getElementById('edit_mg_year_month');
      const editMgHoursInput = document.getElementById('edit_mg_planned_hours');
      const editMgTitleInput = document.getElementById('edit_mg_title');
      const editMgDescInput = document.getElementById('edit_mg_description');

      if (editMgIdInput) editMgIdInput.value = editMonthlyBtn.dataset.id || '';
      if (editMgGoalSelect) editMgGoalSelect.value = editMonthlyBtn.dataset.goalId || '';
      if (editMgMonthInput) editMgMonthInput.value = editMonthlyBtn.dataset.month || '';
      if (editMgHoursInput) editMgHoursInput.value = editMonthlyBtn.dataset.hours || '';
      if (editMgTitleInput) editMgTitleInput.value = editMonthlyBtn.dataset.title || '';
      if (editMgDescInput) editMgDescInput.value = editMonthlyBtn.dataset.description || '';
      return;
    }

    // 7. Modal: Zwischenziel löschen
    const deleteMonthlyBtn = target.closest('.delete-monthly-goal-btn');
    if (deleteMonthlyBtn) {
      const deleteMgIdInput = document.getElementById('delete_mg_id');
      const deleteMgTitleSpan = document.getElementById('delete_mg_title');

      if (deleteMgIdInput) deleteMgIdInput.value = deleteMonthlyBtn.dataset.id || '';
      if (deleteMgTitleSpan) deleteMgTitleSpan.textContent = deleteMonthlyBtn.dataset.title || '';
      return;
    }

    // 8. Modal: Lern-Timeslot planen
    const createSlotBtn = target.closest('[data-bs-target="#createSlotModal"]');
    if (createSlotBtn) {
      clearFormErrors(createSlotForm);

      const createSlotMgSelect = document.getElementById('create_slot_monthly_goal_id');
      const createSlotDateInput = document.getElementById('create_slot_date');

      if (createSlotDateInput) {
        const weekSection = document.getElementById('wochenplanung');
        const fallbackDate = weekSection
          ? weekSection.getAttribute('data-default-slot-date')
          : createSlotDateInput.dataset.default;
        createSlotDateInput.value = createSlotBtn.dataset.date || fallbackDate || '';
      }

      if (createSlotMgSelect) {
        if (createSlotBtn.dataset.monthlyGoalId) {
          createSlotMgSelect.value = createSlotBtn.dataset.monthlyGoalId;
        } else if (createSlotMgSelect.options.length === 2) {
          // Wenn genau ein Zwischenziel zur Auswahl steht, dieses komfortabel vorauswählen
          createSlotMgSelect.selectedIndex = 1;
        }
      }

      updateSlotDurationHint(createSlotStartInput, createSlotEndInput, createSlotHintEl);
      return;
    }

    // 9. Modal: Lern-Timeslot bearbeiten
    const editSlotBtn = target.closest('.edit-slot-btn');
    if (editSlotBtn) {
      clearFormErrors(editSlotForm);

      const editSlotIdInput = document.getElementById('edit_slot_id');
      const editSlotMgSelect = document.getElementById('edit_slot_monthly_goal_id');
      const editSlotDateInput = document.getElementById('edit_slot_date');
      const editSlotNotesInput = document.getElementById('edit_slot_notes');

      if (editSlotIdInput) editSlotIdInput.value = editSlotBtn.dataset.id || '';
      if (editSlotMgSelect) editSlotMgSelect.value = editSlotBtn.dataset.monthlyGoalId || '';
      if (editSlotDateInput) editSlotDateInput.value = editSlotBtn.dataset.date || '';
      if (editSlotStartInput) editSlotStartInput.value = editSlotBtn.dataset.start || '';
      if (editSlotEndInput) editSlotEndInput.value = editSlotBtn.dataset.end || '';
      if (editSlotNotesInput) editSlotNotesInput.value = editSlotBtn.dataset.notes || '';

      updateSlotDurationHint(editSlotStartInput, editSlotEndInput, editSlotHintEl);
      return;
    }

    // 10. Modal: Lern-Timeslot löschen
    const deleteSlotBtn = target.closest('.delete-slot-btn');
    if (deleteSlotBtn) {
      const deleteSlotIdInput = document.getElementById('delete_slot_id');
      const deleteSlotSummaryEl = document.getElementById('delete_slot_summary');

      if (deleteSlotIdInput) deleteSlotIdInput.value = deleteSlotBtn.dataset.id || '';
      if (deleteSlotSummaryEl) deleteSlotSummaryEl.textContent = deleteSlotBtn.dataset.summary || '';
    }
  });

  // Synchronisiert den Wechsel zwischen "Semesterübersicht", "Monatsplanung" und "Wochenplanung"
  // mit der URL in der Adresszeile, ohne die Seite neu zu laden.
  document.querySelectorAll('[data-view-tab]').forEach((tabBtn) => {
    tabBtn.addEventListener('shown.bs.tab', () => {
      const viewTab = tabBtn.getAttribute('data-view-tab') || 'semester';
      const monthSection = document.getElementById('monatsplanung');
      const weekSection = document.getElementById('wochenplanung');
      const currentMonth = monthSection ? monthSection.getAttribute('data-selected-month') : '';
      const currentWeek = weekSection ? weekSection.getAttribute('data-selected-week') : '';
      const params = new URLSearchParams();
      params.set('tab', viewTab);
      if (currentMonth) {
        params.set('month', currentMonth);
      }
      if (viewTab === 'week' && currentWeek) {
        params.set('week', currentWeek);
      }
      window.history.replaceState(null, '', `goals.php?${params.toString()}`);
    });
  });

  /**
   * Synchronisiert die Bereiche #monatsplanung und #wochenplanung sowie die Auswahlfelder
   * der Timeslot-Modals aus einem per fetch() nachgeladenen HTML-Dokument.
   *
   * @param {Document} doc Geparstes HTML-Dokument
   */
  function applyPlanningSectionsFromDocument(doc) {
    const monthSection = document.getElementById('monatsplanung');
    const newMonthSection = doc.getElementById('monatsplanung');
    if (monthSection && newMonthSection) {
      monthSection.replaceChildren(...newMonthSection.childNodes);
      const newMonth = newMonthSection.getAttribute('data-selected-month') || '';
      const newMonthShort = newMonthSection.getAttribute('data-selected-month-short') || '';
      if (newMonth) {
        monthSection.setAttribute('data-selected-month', newMonth);
      }
      if (newMonthShort) {
        monthSection.setAttribute('data-selected-month-short', newMonthShort);
        const tabMonthBadge = document.getElementById('tab-month-badge');
        if (tabMonthBadge) {
          tabMonthBadge.textContent = newMonthShort;
        }
      }
    }

    const weekSection = document.getElementById('wochenplanung');
    const newWeekSection = doc.getElementById('wochenplanung');
    if (weekSection && newWeekSection) {
      weekSection.replaceChildren(...newWeekSection.childNodes);
      const newWeek = newWeekSection.getAttribute('data-selected-week') || '';
      const newWeekShort = newWeekSection.getAttribute('data-selected-week-short') || '';
      const newDefaultDate = newWeekSection.getAttribute('data-default-slot-date') || '';
      if (newWeek) {
        weekSection.setAttribute('data-selected-week', newWeek);
      }
      if (newWeekShort) {
        weekSection.setAttribute('data-selected-week-short', newWeekShort);
        const tabWeekBadge = document.getElementById('tab-week-badge');
        if (tabWeekBadge) {
          tabWeekBadge.textContent = newWeekShort;
        }
      }
      if (newDefaultDate) {
        weekSection.setAttribute('data-default-slot-date', newDefaultDate);
      }

      // Neu eingefügte Toggle-Formulare innerhalb der Wochenplanung für Fetch aktivieren
      weekSection.querySelectorAll('form[data-ajax-form]').forEach(setupAjaxForm);
    }

    // Auswahlfelder für Zwischenziele in den Timeslot-Modals aktualisieren
    ['create_slot_monthly_goal_id', 'edit_slot_monthly_goal_id'].forEach((selectId) => {
      const currentSelect = document.getElementById(selectId);
      const newSelect = doc.getElementById(selectId);
      if (currentSelect && newSelect) {
        currentSelect.replaceChildren(...newSelect.childNodes);
      }
    });
  }

  /**
   * Lädt den Bereich #monatsplanung (und #wochenplanung) für den gewählten Monat im Hintergrund nach
   * und tauscht ihn ohne Seiten-Reload und ohne Scroll-Sprung aus.
   *
   * @param {string} targetMonth Monat im Format YYYY-MM
   */
  async function switchMonthWithoutReload(targetMonth) {
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(targetMonth)) return;

    const section = document.getElementById('monatsplanung');
    if (!section) return;

    const url = `goals.php?tab=month&month=${encodeURIComponent(targetMonth)}`;

    try {
      const response = await fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      if (!response.ok) {
        window.location.href = url;
        return;
      }

      const htmlText = await response.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(htmlText, 'text/html');
      const newSection = doc.getElementById('monatsplanung');

      if (!newSection) {
        window.location.href = url;
        return;
      }

      applyPlanningSectionsFromDocument(doc);

      // URL in der Adresszeile aktualisieren, ohne zu scrollen
      window.history.replaceState(null, '', url);

      // Vorausgewählten Monat in den Formularen und Schnell-Buttons synchronisieren
      document.querySelectorAll('input[name="redirect_month"]').forEach((input) => {
        input.value = targetMonth;
      });
      document.querySelectorAll('.quick-add-monthly-goal-btn').forEach((btn) => {
        btn.setAttribute('data-month', targetMonth);
      });

      const budgetMonthInput = document.getElementById('budget_year_month');
      if (budgetMonthInput) budgetMonthInput.value = targetMonth;

      const createMgMonthInput = document.getElementById('create_mg_year_month');
      if (createMgMonthInput) createMgMonthInput.value = targetMonth;
    } catch (error) {
      window.location.href = url;
    }
  }

  /**
   * Lädt den Bereich #wochenplanung für die gewählte Kalenderwoche im Hintergrund nach
   * und tauscht ihn ohne Seiten-Reload und ohne Scroll-Sprung aus.
   *
   * @param {string} targetWeek Kalenderwoche im Format YYYY-Www
   * @param {string} [targetMonth] Zugehöriger Monat im Format YYYY-MM
   */
  async function switchWeekWithoutReload(targetWeek, targetMonth = '') {
    if (!/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/.test(targetWeek)) return;

    const weekSection = document.getElementById('wochenplanung');
    if (!weekSection) return;

    const params = new URLSearchParams();
    params.set('tab', 'week');
    if (/^\d{4}-(0[1-9]|1[0-2])$/.test(targetMonth)) {
      params.set('month', targetMonth);
    }
    params.set('week', targetWeek);
    const url = `goals.php?${params.toString()}`;

    try {
      const response = await fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      if (!response.ok) {
        window.location.href = url;
        return;
      }

      const htmlText = await response.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(htmlText, 'text/html');
      const newWeekSection = doc.getElementById('wochenplanung');

      if (!newWeekSection) {
        window.location.href = url;
        return;
      }

      applyPlanningSectionsFromDocument(doc);
      window.history.replaceState(null, '', url);
    } catch (error) {
      window.location.href = url;
    }
  }

  // Falls nach einem serverseitigen POST-Fallback ein Modal automatisch geöffnet werden soll
  [createModalEl, editModalEl].forEach((modalEl) => {
    if (
      modalEl &&
      modalEl.dataset.autoOpen === 'true' &&
      typeof bootstrap !== 'undefined' &&
      bootstrap.Modal
    ) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
  });
});
