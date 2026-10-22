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
});

