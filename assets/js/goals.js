// assets/js/goals.js

document.addEventListener('DOMContentLoaded', () => {
  // Modal zur Bearbeitung eines Lernziels befüllen
  const editButtons = document.querySelectorAll('.edit-goal-btn');
  const editIdInput = document.getElementById('edit_goal_id');
  const editTitleInput = document.getElementById('edit_title');
  const editHoursInput = document.getElementById('edit_target_hours');
  const editStartInput = document.getElementById('edit_start_date');
  const editEndInput = document.getElementById('edit_end_date');
  const editDescInput = document.getElementById('edit_description');

  editButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (editIdInput) editIdInput.value = btn.dataset.id || '';
      if (editTitleInput) editTitleInput.value = btn.dataset.title || '';
      if (editHoursInput) editHoursInput.value = btn.dataset.hours || '';
      if (editStartInput) editStartInput.value = btn.dataset.start || '';
      if (editEndInput) editEndInput.value = btn.dataset.end || '';
      if (editDescInput) editDescInput.value = btn.dataset.description || '';
    });
  });

  // Modal zur Löschbestätigung eines 6-Monats-Lernziels befüllen
  const deleteButtons = document.querySelectorAll('.delete-goal-btn');
  const deleteIdInput = document.getElementById('delete_goal_id');
  const deleteTitleSpan = document.getElementById('delete_goal_title');

  deleteButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (deleteIdInput) deleteIdInput.value = btn.dataset.id || '';
      if (deleteTitleSpan) deleteTitleSpan.textContent = btn.dataset.title || '';
    });
  });

  // Modal zur Anpassung des monatlichen Soll-Zeitbudgets befüllen
  const budgetButtons = document.querySelectorAll('[data-bs-target="#monthlyBudgetModal"]');
  const budgetMonthInput = document.getElementById('budget_year_month');
  const budgetHoursInput = document.getElementById('budget_hours_input');

  budgetButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (budgetMonthInput && btn.dataset.month) {
        budgetMonthInput.value = btn.dataset.month;
      }
      if (budgetHoursInput && typeof btn.dataset.budget !== 'undefined') {
        budgetHoursInput.value = btn.dataset.budget;
      }
    });
  });

  // Modal zum Anlegen eines monatlichen Zwischenziels vorbefüllen (z. B. über "+ Monatsziel" beim 6-Monats-Ziel)
  const createMonthlyBtns = document.querySelectorAll('[data-bs-target="#createMonthlyGoalModal"]');
  const createMgGoalSelect = document.getElementById('create_mg_goal_id');
  const createMgMonthInput = document.getElementById('create_mg_year_month');

  createMonthlyBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (createMgMonthInput && btn.dataset.month) {
        createMgMonthInput.value = btn.dataset.month;
      }
      if (createMgGoalSelect && btn.dataset.goalId) {
        createMgGoalSelect.value = btn.dataset.goalId;
      }
    });
  });

  // Modal zur Bearbeitung eines monatlichen Zwischenziels befüllen
  const editMonthlyBtns = document.querySelectorAll('.edit-monthly-goal-btn');
  const editMgIdInput = document.getElementById('edit_mg_id');
  const editMgGoalSelect = document.getElementById('edit_mg_goal_id');
  const editMgMonthInput = document.getElementById('edit_mg_year_month');
  const editMgHoursInput = document.getElementById('edit_mg_planned_hours');
  const editMgTitleInput = document.getElementById('edit_mg_title');
  const editMgDescInput = document.getElementById('edit_mg_description');

  editMonthlyBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (editMgIdInput) editMgIdInput.value = btn.dataset.id || '';
      if (editMgGoalSelect) editMgGoalSelect.value = btn.dataset.goalId || '';
      if (editMgMonthInput) editMgMonthInput.value = btn.dataset.month || '';
      if (editMgHoursInput) editMgHoursInput.value = btn.dataset.hours || '';
      if (editMgTitleInput) editMgTitleInput.value = btn.dataset.title || '';
      if (editMgDescInput) editMgDescInput.value = btn.dataset.description || '';
    });
  });

  // Modal zur Löschbestätigung eines monatlichen Zwischenziels befüllen
  const deleteMonthlyBtns = document.querySelectorAll('.delete-monthly-goal-btn');
  const deleteMgIdInput = document.getElementById('delete_mg_id');
  const deleteMgTitleSpan = document.getElementById('delete_mg_title');

  deleteMonthlyBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (deleteMgIdInput) deleteMgIdInput.value = btn.dataset.id || '';
      if (deleteMgTitleSpan) deleteMgTitleSpan.textContent = btn.dataset.title || '';
    });
  });
});

