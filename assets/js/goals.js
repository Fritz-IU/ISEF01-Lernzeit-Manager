// assets/js/goals.js

document.addEventListener('DOMContentLoaded', () => {
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
      section.innerHTML = newSection.innerHTML;
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
});
