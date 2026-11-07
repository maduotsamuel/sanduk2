'use strict';
(() => {
  const headerMenu = document.querySelector('.menu-toggle');
  const headerNav = document.querySelector('#top-navigation');
  const sideToggle = document.querySelector('.side-toggle');
  const sideNav = document.querySelector('#group-navigation');
  function toggle(button, panel) {
    if (!button || !panel) return;
    const open = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(open));
    panel.classList.toggle('open', open);
  }
  headerMenu?.addEventListener('click', () => toggle(headerMenu, headerNav));
  sideToggle?.addEventListener('click', () => toggle(sideToggle, sideNav));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      for (const [button, panel] of [[headerMenu, headerNav], [sideToggle, sideNav]]) {
        button?.setAttribute('aria-expanded', 'false');
        panel?.classList.remove('open');
      }
    }
  });
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (event) => {
      if (!window.confirm(el.dataset.confirm)) event.preventDefault();
    });
  });
  document.querySelectorAll('form[method="post"]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (event.defaultPrevented || !form.checkValidity()) return;
      if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
      form.dataset.submitting = 'true';
      const submit = form.querySelector('button[type="submit"],button:not([type])');
      if (submit) {
        submit.setAttribute('aria-busy', 'true');
        submit.classList.add('working');
        // Do not disable the button before submitting: this can interfere with keyboard and browser handling.
      }
    });
  });
  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.getElementById(button.dataset.copyTarget);
      if (!target) return;
      const value = target.textContent.trim();
      try {
        await navigator.clipboard.writeText(value);
        button.textContent = 'Copied!';
      } catch (_) {
        // Insecure localhost variants might not support Clipboard API.
        button.textContent = 'Select the code above to copy';
      }
    });
  });
  document.querySelectorAll('input[type="password"]').forEach((input) => {
    const holder = document.createElement('div');
    holder.className = 'password-row';
    input.parentElement.insertBefore(holder, input);
    holder.appendChild(input);
    const togglePassword = document.createElement('button');
    togglePassword.type = 'button';
    togglePassword.className = 'show-password';
    togglePassword.textContent = 'Show';
    togglePassword.setAttribute('aria-label', 'Show password');
    togglePassword.addEventListener('click', () => {
      const reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      togglePassword.textContent = reveal ? 'Hide' : 'Show';
      togglePassword.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    });
    holder.appendChild(togglePassword);
  });
  const interest = document.querySelector('input[name="interest_rate"]');
  if (interest) {
    const info = document.createElement('p');
    info.className = 'tiny';
    info.textContent = 'Flat interest is charged once on the original loan amount.';
    interest.parentElement.appendChild(info);
  }
})();
