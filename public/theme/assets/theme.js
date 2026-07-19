/* Scout Portal — Theme JS */
(function () {
  'use strict';

  // ── Sidebar collapse ──────────────────────────────────────
  const sidebar  = document.getElementById('sidebar');
  const collapseBtn = document.getElementById('collapseBtn');

  if (collapseBtn && sidebar) {
    collapseBtn.addEventListener('click', () => {
      sidebar.classList.toggle('collapsed');
      localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
    });
  }

  // Restore state
  if (sidebar && localStorage.getItem('sidebarCollapsed') === 'true') {
    sidebar.classList.add('collapsed');
  }

  // ── Submenu accordion ─────────────────────────────────────
  document.querySelectorAll('.nav-has-sub > .nav-link').forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      const item = link.closest('.nav-has-sub');
      // close siblings
      item.parentElement.querySelectorAll('.nav-has-sub.open').forEach(el => {
        if (el !== item) el.classList.remove('open');
      });
      item.classList.toggle('open');
    });
  });

  // ── Active nav highlight ──────────────────────────────────
  const current = location.pathname.split('/').pop();
  document.querySelectorAll('.nav-list .nav-link').forEach(link => {
    if (link.getAttribute('href') === current) {
      link.closest('.nav-item')?.classList.add('active');
    }
  });

  // ── Mobile sidebar toggle ─────────────────────────────────
  const mobileToggle = document.getElementById('mobileMenuToggle');
  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', () => sidebar.classList.toggle('mobile-open'));
  }

})();
