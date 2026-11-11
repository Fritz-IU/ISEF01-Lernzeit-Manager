// assets/js/toast.js
// Zentrale Bootstrap-5-Toast-Benachrichtigungen (Floating Alerts, Issue #22)

(() => {
  const TOAST_CONFIG = {
    success: {
      bgClass: 'text-bg-success',
      closeClass: 'btn-close-white',
      role: 'status',
      ariaLive: 'polite',
      defaultDelay: 4500,
      icon: '<path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>',
    },
    danger: {
      bgClass: 'text-bg-danger',
      closeClass: 'btn-close-white',
      role: 'alert',
      ariaLive: 'assertive',
      defaultDelay: 6000,
      icon: '<path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M8 4a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 4m.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2"/>',
    },
    info: {
      bgClass: 'text-bg-primary',
      closeClass: 'btn-close-white',
      role: 'status',
      ariaLive: 'polite',
      defaultDelay: 4500,
      icon: '<path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/>',
    },
    warning: {
      bgClass: 'text-bg-warning',
      closeClass: '',
      role: 'alert',
      ariaLive: 'polite',
      defaultDelay: 5500,
      icon: '<path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>',
    },
  };

  /**
   * Liefert den zentralen Toast-Container oder legt ihn bei Bedarf an.
   * @returns {HTMLElement}
   */
  function getOrCreateToastContainer() {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      container.className = 'toast-container position-fixed bottom-0 end-0 p-3 app-toast-container';
      container.setAttribute('aria-live', 'polite');
      container.setAttribute('aria-atomic', 'true');
      document.body.appendChild(container);
    }
    return container;
  }

  /**
   * Zeigt eine schwebende Bootstrap-5-Toast-Benachrichtigung an.
   *
   * @param {'success'|'danger'|'error'|'info'|'warning'} type Status-Typ der Meldung
   * @param {string} message Anzuzeigender Nachrichtentext
   * @param {{ delay?: number, autohide?: boolean }} [options] Optionale Steuerung
   * @returns {HTMLElement|null}
   */
  function showToast(type, message, options = {}) {
    const text = typeof message === 'string' ? message.trim() : '';
    if (!text) {
      return null;
    }

    const normalizedType = type === 'error' ? 'danger' : type;
    const config = TOAST_CONFIG[normalizedType] || TOAST_CONFIG.info;
    const autohide = options.autohide !== undefined ? Boolean(options.autohide) : true;
    const delay =
      typeof options.delay === 'number' && options.delay > 0 ? options.delay : config.defaultDelay;

    const container = getOrCreateToastContainer();

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center border-0 shadow app-toast ${config.bgClass}`;
    toastEl.setAttribute('role', config.role);
    toastEl.setAttribute('aria-live', config.ariaLive);
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.setAttribute('data-bs-autohide', autohide ? 'true' : 'false');
    toastEl.setAttribute('data-bs-delay', String(delay));

    const inner = document.createElement('div');
    inner.className = 'd-flex align-items-center';

    const body = document.createElement('div');
    body.className = 'toast-body d-flex align-items-center gap-2 flex-grow-1';

    const iconSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    iconSvg.setAttribute('width', '18');
    iconSvg.setAttribute('height', '18');
    iconSvg.setAttribute('fill', 'currentColor');
    iconSvg.setAttribute('viewBox', '0 0 16 16');
    iconSvg.setAttribute('aria-hidden', 'true');
    iconSvg.setAttribute('class', 'flex-shrink-0');
    iconSvg.innerHTML = config.icon;

    const messageSpan = document.createElement('span');
    messageSpan.className = 'text-break';
    messageSpan.textContent = text;

    body.appendChild(iconSvg);
    body.appendChild(messageSpan);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = `btn-close ${config.closeClass} me-2 m-auto flex-shrink-0`.trim();
    closeBtn.setAttribute('data-bs-dismiss', 'toast');
    closeBtn.setAttribute('aria-label', 'Schließen');

    inner.appendChild(body);
    inner.appendChild(closeBtn);
    toastEl.appendChild(inner);
    container.appendChild(toastEl);

    toastEl.addEventListener('hidden.bs.toast', () => {
      toastEl.remove();
    });

    if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
      const instance = bootstrap.Toast.getOrCreateInstance(toastEl, { autohide, delay });
      instance.show();
    } else {
      toastEl.classList.add('show');
      closeBtn.addEventListener('click', () => toastEl.remove());
      if (autohide) {
        window.setTimeout(() => toastEl.remove(), delay);
      }
    }

    return toastEl;
  }

  window.showToast = showToast;

  document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('toast-container');
    if (!container) {
      return;
    }

    const rawInitial = container.getAttribute('data-initial-toasts');
    if (!rawInitial) {
      return;
    }

    container.removeAttribute('data-initial-toasts');

    try {
      const items = JSON.parse(rawInitial);
      if (Array.isArray(items)) {
        items.forEach((item) => {
          if (item && typeof item.message === 'string' && item.message.trim() !== '') {
            showToast(item.type || 'info', item.message);
          }
        });
      }
    } catch {
      // Ungültige JSON-Daten still verwerfen
    }
  });
})();
