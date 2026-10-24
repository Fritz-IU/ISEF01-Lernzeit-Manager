// assets/js/goals.js

document.addEventListener('DOMContentLoaded', () => {
  const createModalEl = document.getElementById('createGoalModal');
  const createForm = document.getElementById('createGoalForm');
  const createAlert = document.getElementById('create-goal-alert');
  const createSubmitBtn = document.getElementById('create-goal-submit');

  const editModalEl = document.getElementById('editGoalModal');
  const editForm = document.getElementById('editGoalForm');
  const editAlert = document.getElementById('edit-goal-alert');
  const editSubmitBtn = document.getElementById('edit-goal-submit');

  const editButtons = document.querySelectorAll('.edit-goal-btn');
  const editIdInput = document.getElementById('edit_goal_id');
  const editTitleInput = document.getElementById('edit_title');
  const editHoursInput = document.getElementById('edit_target_hours');
  const editStartInput = document.getElementById('edit_start_date');
  const editEndInput = document.getElementById('edit_end_date');
  const editDescInput = document.getElementById('edit_description');

  /**
   * Entfernt Fehlermeldung und rote Markierungen (is-invalid) innerhalb eines Formulars.
   * @param {HTMLFormElement|null} form
   * @param {HTMLElement|null} alertBox
   */
  function clearFormErrors(form, alertBox) {
    if (alertBox) {
      alertBox.className = 'alert alert-danger d-none';
      alertBox.textContent = '';
    }

    if (!form) return;

    const invalidFields = form.querySelectorAll('.is-invalid');
    invalidFields.forEach((field) => {
      field.classList.remove('is-invalid');
      field.removeAttribute('aria-invalid');
      field.removeAttribute('aria-describedby');
    });
  }

  /**
   * Zeigt eine Fehlermeldung im Modal an und markiert optional das betroffene Eingabefeld rot.
   * @param {HTMLFormElement} form
   * @param {HTMLElement|null} alertBox
   * @param {string} message
   * @param {string|null} [fieldName]
   */
  function showFormError(form, alertBox, message, fieldName = null) {
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
   * Richtet die asynchrone Formularübermittlung via Fetch für ein Lernziel-Formular ein.
   * @param {HTMLFormElement|null} form
   * @param {HTMLElement|null} alertBox
   * @param {HTMLButtonElement|null} submitBtn
   */
  function setupGoalForm(form, alertBox, submitBtn) {
    if (!form) return;

    // Native Browser-Tooltips deaktivieren, damit alle Fehler einheitlich im Modal dargestellt werden
    form.noValidate = true;

    // Rote Markierung eines Feldes entfernen, sobald der Nutzer die Eingabe korrigiert
    const fields = form.querySelectorAll('.form-control');
    fields.forEach((field) => {
      const clearSingleField = () => {
        if (field.classList.contains('is-invalid')) {
          field.classList.remove('is-invalid');
          field.removeAttribute('aria-invalid');
          field.removeAttribute('aria-describedby');
        }
      };
      field.addEventListener('input', clearSingleField);
      field.addEventListener('change', clearSingleField);
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearFormErrors(form, alertBox);

      const actionInput = form.querySelector('[name="action"]');
      const goalIdInput = form.querySelector('[name="goal_id"]');
      const titleInput = form.querySelector('[name="title"]');
      const hoursInput = form.querySelector('[name="target_hours"]');
      const startInput = form.querySelector('[name="start_date"]');
      const endInput = form.querySelector('[name="end_date"]');
      const descInput = form.querySelector('[name="description"]');

      // Prüfen, ob der Browser eine syntaktisch ungültige Zahl oder ein ungültiges Datum erkannt hat
      if (hoursInput && hoursInput.validity && hoursInput.validity.badInput) {
        showFormError(form, alertBox, 'Die Zielstunden müssen eine Zahl sein.', 'target_hours');
        return;
      }
      if (startInput && startInput.validity && startInput.validity.badInput) {
        showFormError(
          form,
          alertBox,
          'Das Startdatum muss im gültigen Format JJJJ-MM-TT vorliegen.',
          'start_date'
        );
        return;
      }
      if (endInput && endInput.validity && endInput.validity.badInput) {
        showFormError(
          form,
          alertBox,
          'Das Enddatum muss im gültigen Format JJJJ-MM-TT vorliegen.',
          'end_date'
        );
        return;
      }

      const payload = {
        action: actionInput ? actionInput.value : '',
        goal_id: goalIdInput ? goalIdInput.value : '',
        title: titleInput ? titleInput.value : '',
        target_hours: hoursInput ? hoursInput.value : '',
        start_date: startInput ? startInput.value : '',
        end_date: endInput ? endInput.value : '',
        description: descInput ? descInput.value : '',
      };

      const originalBtnHtml = submitBtn ? submitBtn.innerHTML : 'Speichern';
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

        const result = await response.json();

        if (response.ok && result.success) {
          window.location.href = 'goals.php';
          return;
        }

        showFormError(
          form,
          alertBox,
          result.message || 'Bitte überprüfe deine Eingaben.',
          result.field || null
        );
      } catch (error) {
        showFormError(
          form,
          alertBox,
          'Netzwerkfehler: Das Lernziel konnte nicht gespeichert werden.',
          null
        );
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnHtml;
        }
      }
    });
  }

  /**
   * Setzt das Formular für ein neues Lernziel auf die Standardwerte zurück.
   */
  function resetCreateGoalForm() {
    if (!createForm) return;
    createForm.reset();
    clearFormErrors(createForm, createAlert);

    const startInput = createForm.querySelector('[name="start_date"]');
    const endInput = createForm.querySelector('[name="end_date"]');
    if (startInput && startInput.dataset.default) {
      startInput.value = startInput.dataset.default;
    }
    if (endInput && endInput.dataset.default) {
      endInput.value = endInput.dataset.default;
    }
  }

  setupGoalForm(createForm, createAlert, createSubmitBtn);
  setupGoalForm(editForm, editAlert, editSubmitBtn);

  // Formular und Fehler beim Schließen oder erneuten Öffnen des Erstellen-Modals zurücksetzen
  if (createModalEl) {
    createModalEl.addEventListener('show.bs.modal', () => {
      if (createModalEl.dataset.autoOpen !== 'true') {
        resetCreateGoalForm();
      }
    });

    createModalEl.addEventListener('hidden.bs.modal', () => {
      resetCreateGoalForm();
    });
  }

  // Beim Schließen des Bearbeiten-Modals Fehler und ungespeicherte Eingaben zurücksetzen
  if (editModalEl) {
    editModalEl.addEventListener('hidden.bs.modal', () => {
      clearFormErrors(editForm, editAlert);
      if (editForm) {
        editForm.reset();
      }
    });
  }

  // Modal zur Bearbeitung eines Lernziels befüllen
  editButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      clearFormErrors(editForm, editAlert);
      if (editIdInput) editIdInput.value = btn.dataset.id || '';
      if (editTitleInput) editTitleInput.value = btn.dataset.title || '';
      if (editHoursInput) editHoursInput.value = btn.dataset.hours || '';
      if (editStartInput) editStartInput.value = btn.dataset.start || '';
      if (editEndInput) editEndInput.value = btn.dataset.end || '';
      if (editDescInput) editDescInput.value = btn.dataset.description || '';
    });
  });

  // Modal zur Löschbestätigung eines Lernziels befüllen
  const deleteButtons = document.querySelectorAll('.delete-goal-btn');
  const deleteIdInput = document.getElementById('delete_goal_id');
  const deleteTitleSpan = document.getElementById('delete_goal_title');

  deleteButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (deleteIdInput) deleteIdInput.value = btn.dataset.id || '';
      if (deleteTitleSpan) deleteTitleSpan.textContent = btn.dataset.title || '';
    });
  });

  // Falls nach einem serverseitigen POST-Fallback ein Modal automatisch geöffnet werden soll
  [createModalEl, editModalEl].forEach((modalEl) => {
    if (
      modalEl &&
      modalEl.dataset.autoOpen === 'true' &&
      typeof bootstrap !== 'undefined' &&
      bootstrap.Modal
    ) {
      const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
      modalInstance.show();
    }
  });
});
