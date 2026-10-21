// assets/js/profile.js

document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('profile-form');
  if (!form) return;

  const alertBox = document.getElementById('profile-alert');
  const submitBtn = document.getElementById('submit-btn');

  /**
   * Zeigt eine Rückmeldung im Alert-Container an.
   * @param {'success'|'danger'|''} type
   * @param {string} message
   */
  function showAlert(type, message) {
    if (!alertBox) return;

    if (!message) {
      alertBox.className = 'd-none';
      alertBox.textContent = '';
      return;
    }

    alertBox.className = `alert alert-${type} mt-3 mb-0`;
    alertBox.textContent = message;
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  form.addEventListener('submit', async (event) => {
    // Standard-Formularabsendung via Browser verhindern
    event.preventDefault();

    showAlert('', '');

    const displayNameInput = document.getElementById('display_name');
    const studyProgramInput = document.getElementById('study_program');
    const semesterInput = document.getElementById('semester');

    const payload = {
      display_name: displayNameInput ? displayNameInput.value.trim() : '',
      study_program: studyProgramInput ? studyProgramInput.value.trim() : '',
      semester: semesterInput ? semesterInput.value.trim() : '',
    };

    // Button-Zustand während der Anfrage
    const originalBtnText = submitBtn ? submitBtn.innerHTML : 'Speichern';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Speichern…';
    }

    try {
      const response = await fetch('api/profile.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(payload),
      });

      const result = await response.json();

      if (response.ok && result.success) {
        showAlert('success', result.message || 'Profildaten erfolgreich gespeichert.');

        // Live-Update des Anzeigenamens in der Navigationsleiste (Server liefert bei leerem Namen die E-Mail)
        const navUserName = document.getElementById('nav-user-name');
        if (navUserName && result.data && result.data.nav_name) {
          navUserName.textContent = result.data.nav_name;
        }
      } else {
        showAlert('danger', result.message || 'Fehler beim Speichern der Profildaten.');
      }
    } catch (error) {
      showAlert('danger', 'Netzwerkfehler: Profil konnte nicht gespeichert werden.');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
    }
  });
});
