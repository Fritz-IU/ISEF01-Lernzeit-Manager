// assets/js/profile.js

document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('profile-form');
  if (!form) return;

  const submitBtn = document.getElementById('submit-btn');

  form.addEventListener('submit', async (event) => {
    // Standard-Formularabsendung via Browser verhindern
    event.preventDefault();

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
        if (typeof window.showToast === 'function') {
          window.showToast('success', result.message || 'Profildaten erfolgreich gespeichert.');
        }

        // Live-Update des Anzeigenamens in der Navigationsleiste (Server liefert bei leerem Namen die E-Mail)
        if (result.data && result.data.nav_name) {
          const navUserName = document.getElementById('nav-user-name');
          if (navUserName) {
            navUserName.textContent = result.data.nav_short_name || result.data.nav_name;
          }

          const dropdownUserName = document.getElementById('nav-dropdown-user-name');
          if (dropdownUserName) {
            dropdownUserName.textContent = result.data.nav_name;
          }

          const dropdownUserEmail = document.getElementById('nav-dropdown-user-email');
          if (dropdownUserEmail) {
            const hasCustomDisplayName = Boolean(result.data.display_name);
            dropdownUserEmail.classList.toggle('d-none', !hasCustomDisplayName);
          }
        }
      } else if (typeof window.showToast === 'function') {
        window.showToast('danger', result.message || 'Fehler beim Speichern der Profildaten.');
      }
    } catch {
      if (typeof window.showToast === 'function') {
        window.showToast('danger', 'Netzwerkfehler: Profil konnte nicht gespeichert werden.');
      }
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
    }
  });
});
