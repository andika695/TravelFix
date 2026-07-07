// js/profile-modal.js — Profile Modal Toggle & Actions

window.initProfileModal = function () {
  'use strict';

  const pill    = document.getElementById('user-pill');
  const modal   = document.getElementById('profile-modal');

  if (!pill || !modal) return;  // guard: only run on pages with the modal

  /* ── Toggle open / close ─────────────────────────────── */
  function openModal() {
    modal.hidden = false;
    modal.classList.add('pm-visible');
    pill.setAttribute('aria-expanded', 'true');
  }

  function closeModal() {
    modal.classList.remove('pm-visible');
    // Wait for CSS transition then hide
    modal.addEventListener('transitionend', function handler() {
      modal.hidden = true;
      modal.removeEventListener('transitionend', handler);
    });
    pill.setAttribute('aria-expanded', 'false');
  }

  function toggleModal() {
    if (modal.hidden) {
      openModal();
    } else {
      closeModal();
    }
  }

  /* ── Click on pill ────────────────────────────────────── */
  pill.addEventListener('click', function (e) {
    e.stopPropagation();

    // Reset to current user and unhide actions (if they were hidden by another view)
    if (typeof window.updateProfileUI === 'function') {
      window.updateProfileUI();
    }
    const actionsContainer = modal.querySelector('.pm-actions');
    if (actionsContainer) {
      actionsContainer.style.display = '';
    }

    toggleModal();
  });

  /* ── Keyboard on pill (Enter / Space) ─────────────────── */
  pill.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      toggleModal();
    }
  });

  /* ── Click outside → close ────────────────────────────── */
  document.addEventListener('click', function (e) {
    if (!modal.hidden && !modal.contains(e.target) && e.target !== pill) {
      closeModal();
    }
  });

  /* ── Escape key → close ───────────────────────────────── */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) {
      closeModal();
      pill.focus();
    }
  });

  /* ── Stop clicks inside modal from bubbling out ────────── */
  modal.addEventListener('click', function (e) {
    e.stopPropagation();
  });

  /* ── Action buttons ───────────────────────────────────── */
  const btnLogout  = document.getElementById('pm-logout');
  const btnAiRec   = document.getElementById('pm-ai-rec');
  const btnSettings  = document.getElementById('pm-settings') || document.getElementById('pm-update-skill');

  if (btnLogout) {
    btnLogout.addEventListener('click', function () {
      // Sebelumnya tombol ini cuma pindah halaman TANPA menghapus sessionStorage
      // ATAU session PHP di server -- "logout" jadi tidak benar-benar keluar.
      const API_BASE = (window.db && window.db.API_BASE) || 'api';
      fetch(`${API_BASE}/logout.php`, { method: 'POST' })
        .catch(function () { /* tetap lanjut logout di sisi klien walau request gagal */ })
        .finally(function () {
          if (window.db && typeof window.db.logout === 'function') {
            window.db.logout();
          } else {
            sessionStorage.removeItem('currentUser');
          }
          window.location.href = 'login.html';
        });
    });
  }

  if (btnAiRec) {
    btnAiRec.addEventListener('click', function () {
      window.location.href = 'ai-match.html';
    });
  }

  if (btnSettings) {
    btnSettings.addEventListener('click', function () {
      closeModal();
      window.location.href = 'profile.html';
    });
  }

};
