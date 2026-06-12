// Shared client-side handling for every form on the site (any <form data-form>):
// required/email validation with inline notifications, then a JSON POST to the
// form's `action` (built for Formspree — paste the real endpoint per form).
//
// States surfaced to the visitor, in a .form-note line under the form:
//   • "<Field> is required."            (empty required field — field underlined gold)
//   • "Please enter a valid email…"     (format check beyond `required`)
//   • "Sending…"                        (request in flight, button disabled)
//   • the form's data-success message   (2xx response; form resets)
//   • denied / network error messages   (non-2xx or fetch failure)

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function initForms() {
  document.querySelectorAll('form[data-form]').forEach((form) => {
    form.setAttribute('novalidate', ''); // our messages, not the browser bubbles

    // Notification line lives right after the form so it never breaks the
    // form's own layout (several forms are flex rows).
    let note = form.nextElementSibling;
    if (!note || !note.classList.contains('form-note')) {
      note = document.createElement('p');
      note.className = 'form-note';
      note.hidden = true;
      note.setAttribute('role', 'status');
      note.setAttribute('aria-live', 'polite');
      form.insertAdjacentElement('afterend', note);
    }

    const show = (msg, type) => {
      note.textContent = msg;
      note.classList.remove('error', 'success', 'pending');
      note.classList.add(type);
      note.hidden = false;
    };
    const hide = () => { note.hidden = true; };

    const fields = () =>
      [...form.querySelectorAll('input[name], select[name], textarea[name]')]
        .filter((el) => el.type !== 'hidden' && el.name !== '_gotcha');

    const mark = (el) => (el.closest('.field') || el).classList.add('invalid');
    const unmark = (el) => (el.closest('.field') || el).classList.remove('invalid');

    // Typing/choosing clears that field's error state.
    ['input', 'change'].forEach((evt) =>
      form.addEventListener(evt, (e) => { unmark(e.target); hide(); }, true)
    );

    // Clicking into a field clears its highlight; clicking anywhere outside the
    // form reverts all highlights (success notes stay visible, errors hide).
    form.addEventListener('focusin', (e) => unmark(e.target));
    document.addEventListener('pointerdown', (e) => {
      if (form.contains(e.target) || e.target === note) return;
      fields().forEach(unmark);
      if (note.classList.contains('error')) hide();
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      // --- validate -------------------------------------------------------
      for (const el of fields()) {
        const label = (el.dataset.label || el.getAttribute('placeholder') ||
          el.getAttribute('aria-label') || 'This field').replace(/\s*\(optional\)/i, '');
        const val = (el.value || '').trim();
        if (el.required && !val) {
          mark(el); show(`${label} is required.`, 'error'); el.focus();
          return;
        }
        if (el.type === 'email' && val && !EMAIL_RE.test(val)) {
          mark(el); show('Please enter a valid email address.', 'error'); el.focus();
          return;
        }
      }

      // --- submit ---------------------------------------------------------
      const action = form.getAttribute('action') || '';
      if (!action || action.includes('YOUR_FORM_ID')) {
        show('This form isn’t connected yet — add the Formspree endpoint to its action attribute.', 'error');
        return;
      }

      const btn = form.querySelector('[type="submit"]');
      btn?.setAttribute('disabled', '');
      show('Sending…', 'pending');

      try {
        const res = await fetch(action, {
          method: 'POST',
          body: new FormData(form),
          headers: { Accept: 'application/json' },
        });
        if (res.ok) {
          form.reset();
          fields().forEach(unmark);
          // Re-sync enhanced fields (select placeholder tint, textarea height).
          form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change')));
          form.querySelectorAll('textarea').forEach((t) => t.dispatchEvent(new Event('input')));
          show(form.dataset.success || 'Thank you — your message has been sent.', 'success');
        } else {
          const data = await res.json().catch(() => null);
          const msg = data?.errors?.map((er) => er.message).join(', ');
          show(msg || 'Your submission was denied. Please try again later.', 'error');
        }
      } catch {
        show('Network error — please check your connection and try again.', 'error');
      } finally {
        btn?.removeAttribute('disabled');
      }
    });
  });
}
