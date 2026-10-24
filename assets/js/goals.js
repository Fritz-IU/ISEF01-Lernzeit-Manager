// assets/js/goals.js

document.addEventListener('DOMContentLoaded', () => {
  const createModalEl = document.getElementById('createGoalModal');
  const createForm = document.getElementById('createGoalForm');
  const editModalEl = document.getElementById('editGoalModal');
  const editForm = document.getElementById('editGoalForm');
  const createMonthlyForm = document.getElementById('createMonthlyGoalForm');

  // Meldungen für Eingaben, die der Browser selbst nicht als Zahl bzw. Datum lesen kann
  const BAD_INPUT_MESSAGES = {
    target_hours: 'Die Zielstunden müssen eine Zahl sein.',
    planned_hours: 'Die geplanten Lernstunden müssen eine Zahl sein.',
    budget_hours: 'Das monatliche Zeitbudget muss eine Zahl sein.',
    start_date: 'Das Startdatum muss im gültigen Format JJJJ-MM-TT vorliegen.',
    end_date: 'Das Enddatum muss im gültigen Format JJJJ-MM-TT vorliegen.',
    year_month: 'Der Monat muss im gültigen Format JJJJ-MM vorliegen.',
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
  [editForm, createMonthlyForm, document.getElementById('editMonthlyGoalForm')].forEach((form) => {
    const modalEl = form ? form.closest('.modal') : null;
    if (modalEl) {
      modalEl.addEventListener('hidden.bs.modal', () => form.reset());
    }
  });

  // Event-Delegation für alle Modal-Buttons und den asynchronen Monatswechsel
  document.addEventListener('click', async (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    // 1. Monatswechsel ohne Seiten-Reload und ohne Scrollen
    const monthSwitchLink = target.closest('[data-month-switch]');
    if (monthSwitchLink) {
      const targetMonth = monthSwitchLink.getAttribute('data-month-switch');
      if (targetMonth) {
        event.preventDefault();
        await switchMonthWithoutReload(targetMonth);
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
    }
  });

  // Synchronisiert den Wechsel zwischen "Semesterübersicht" und "Monatsplanung"
  // mit der URL in der Adresszeile, ohne die Seite neu zu laden.
  document.querySelectorAll('[data-view-tab]').forEach((tabBtn) => {
    tabBtn.addEventListener('shown.bs.tab', () => {
      const viewTab = tabBtn.getAttribute('data-view-tab') || 'semester';
      const section = document.getElementById('monatsplanung');
      const currentMonth = section ? section.getAttribute('data-selected-month') : '';
      const params = new URLSearchParams();
      params.set('tab', viewTab);
      if (currentMonth) {
        params.set('month', currentMonth);
      }
      window.history.replaceState(null, '', `goals.php?${params.toString()}`);
    });
  });

  /**
   * Lädt den Bereich #monatsplanung für den gewählten Monat im Hintergrund nach
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

      // Monatsbereich und Data-Attribute ohne Scroll-Sprung aktualisieren
      section.replaceChildren(...newSection.childNodes);
      section.setAttribute('data-selected-month', targetMonth);
      const newShortLabel = newSection.getAttribute('data-selected-month-short');
      if (newShortLabel) {
        section.setAttribute('data-selected-month-short', newShortLabel);
        const tabMonthBadge = document.getElementById('tab-month-badge');
        if (tabMonthBadge) {
          tabMonthBadge.textContent = newShortLabel;
        }
      }

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
