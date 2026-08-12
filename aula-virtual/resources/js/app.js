import './global.js';

document.addEventListener('DOMContentLoaded', () => {

  const userToggle = document.getElementById('userToggle');
  const userDropdown = document.getElementById('userDropdown');
  const mobileToggle = document.getElementById('mobileToggle');
  const mobileNav = document.getElementById('mobileNav');
  const mobileClose = document.getElementById('mobileClose');
  const mobileBackdrop = document.getElementById('mobileBackdrop');

  const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
  ].join(',');

  const setInteractiveVisibility = ({ panel, trigger, open, openClass }) => {
    if (!panel) return;

    if (open) {
      panel.inert = false;
      panel.removeAttribute('inert');
      panel.setAttribute('aria-hidden', 'false');
      panel.classList.add(openClass);
    } else {
      if (panel.contains(document.activeElement)) {
        trigger?.focus();
      }
      panel.classList.remove(openClass);
      panel.setAttribute('aria-hidden', 'true');
      panel.inert = true;
      panel.setAttribute('inert', '');
    }

    trigger?.setAttribute('aria-expanded', open ? 'true' : 'false');
  };

  const trapFocus = (event, container) => {
    if (event.key !== 'Tab' || !container) return;

    const focusable = [...container.querySelectorAll(focusableSelector)]
      .filter((element) => !element.hidden && element.getClientRects().length > 0);
    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  function toggleDropdown(){
    const isShown = !userDropdown?.classList.contains('show');
    setInteractiveVisibility({
      panel: userDropdown,
      trigger: userToggle,
      open: isShown,
      openClass: 'show',
    });
  }

  function closeDropdown(){
    setInteractiveVisibility({
      panel: userDropdown,
      trigger: userToggle,
      open: false,
      openClass: 'show',
    });
  }

  if (userToggle) {
    userToggle.addEventListener('click', function(e){
      e.stopPropagation();
      toggleDropdown();
    });
  }

  document.addEventListener('click', function(e){
    if (userDropdown && userToggle && !userDropdown.contains(e.target) && !userToggle.contains(e.target)) {
      closeDropdown();
    }
  });

  const isMobileMenuOpen = () => mobileNav?.classList.contains('open') ?? false;

  const setMobileMenuOpen = (open, restoreFocus = true) => {
    if (!mobileNav || !mobileToggle) return;

    setInteractiveVisibility({
      panel: mobileNav,
      trigger: mobileToggle,
      open,
      openClass: 'open',
    });
    mobileBackdrop?.classList.toggle('visible', open);
    document.body.classList.toggle('mobile-menu-open', open);

    if (open) {
      requestAnimationFrame(() => mobileClose?.focus());
    } else if (restoreFocus) {
      mobileToggle.focus();
    }
  };

  document.addEventListener('keydown', function(e){
    if (isMobileMenuOpen()) {
      trapFocus(e, mobileNav);
    }

    if (e.key !== 'Escape') return;

    closeDropdown();
    if (isMobileMenuOpen()) {
      setMobileMenuOpen(false);
    }
  });

  if (mobileToggle && mobileNav) {
    mobileToggle.addEventListener('click', (event) => {
      event.stopPropagation();
      setMobileMenuOpen(!isMobileMenuOpen(), false);
    });

    mobileClose?.addEventListener('click', () => setMobileMenuOpen(false));
    mobileBackdrop?.addEventListener('click', () => setMobileMenuOpen(false));

    mobileNav.querySelectorAll('a[href]').forEach((link) => {
      link.addEventListener('click', () => setMobileMenuOpen(false, false));
    });

    document.addEventListener('click', (event) => {
      if (!isMobileMenuOpen()) return;
      if (mobileNav.contains(event.target) || mobileToggle.contains(event.target)) return;

      setMobileMenuOpen(false);
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth >= 768 && isMobileMenuOpen()) {
        setMobileMenuOpen(false, false);
      }
    });
  }

  const loader = document.querySelector('[data-page-loader]');
  const sameOrigin = (href) => {
    try {
      const url = new URL(href, window.location.href);
      return url.origin === window.location.origin;
    } catch {
      return false;
    }
  };

  const showLoader = () => {
    if (!loader) return;
    loader.removeAttribute('hidden');
    requestAnimationFrame(() => loader.classList.add('is-active'));
  };

  const loadingMessageFor = (form, submitter) => {
    const configuredMessage = submitter?.dataset.loadingMessage || form.dataset.loadingMessage;
    if (configuredMessage) return configuredMessage;

    const isSearch = form.getAttribute('role') === 'search'
      || form.method.toLowerCase() === 'get'
      || form.querySelector('input[type="search"]');

    return isSearch ? 'Buscando...' : 'Procesando...';
  };

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.matches('[data-no-global-loader]')) return;

    // AJAX handlers can still cancel the submission later in this event dispatch.
    queueMicrotask(() => {
      if (event.defaultPrevented) return;
      window.showGlobalLoader?.(loadingMessageFor(form, event.submitter));
    });
  });

  document.addEventListener('click', (event) => {
    const copyMeeting = event.target.closest('[data-copy-meeting]');
    if (copyMeeting) {
      const feedback = copyMeeting.parentElement?.querySelector('[data-copy-meeting-feedback]');
      navigator.clipboard?.writeText(copyMeeting.dataset.copyMeeting || '')
        .then(() => {
          if (feedback) feedback.textContent = 'Acceso copiado.';
          window.setTimeout(() => {
            if (feedback) feedback.textContent = '';
          }, 2500);
        })
        .catch(() => {
          if (feedback) feedback.textContent = 'No se pudo copiar. Selecciona el ID manualmente.';
        });
      return;
    }

    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented) return;
    if (link.target || link.hasAttribute('download')) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!sameOrigin(link.href)) return;

    const url = new URL(link.href, window.location.href);
    if (url.pathname === window.location.pathname && url.search === window.location.search) return;

    showLoader();
  });

  window.addEventListener('pageshow', () => {
    window.hideGlobalLoader?.();
    loader?.classList.remove('is-active');
    loader?.setAttribute('hidden', '');
  });

});
