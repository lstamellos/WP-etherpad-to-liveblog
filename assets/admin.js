/* Admin-only Ajax actions for Etherpad-to-Liveblog, independent of post editor form. */
(function () {
  'use strict';

  document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-wpelb-operation]');
    if (!button) return;

    const group = button.closest('.wpelb-action-group');
    if (!group || !window.wpelbAdmin || !window.wpelbAdmin.ajaxUrl) return;
    event.preventDefault();

    const operation = button.getAttribute('data-wpelb-operation');
    if (!['connect', 'pause', 'resume', 'disconnect'].includes(operation)) return;
    if (operation === 'disconnect' && !window.confirm('Disconnect this pad? Published entries are preserved.')) return;

    const feedback = group.querySelector('.wpelb-feedback');
    const params = new URLSearchParams({
      action: 'wpelb_action',
      post_id: group.getAttribute('data-post-id'),
      nonce: group.getAttribute('data-nonce'),
      wpelb_operation: operation
    });

    if (operation === 'connect') {
      const urlInput = group.querySelector('[name="pad_url"]');
      const ack = group.querySelector('[name="pad_acl_ack"]');
      if (!urlInput || !ack || !urlInput.reportValidity() || !ack.reportValidity()) return;
      params.set('pad_url', urlInput.value);
      params.set('pad_acl_ack', ack.checked ? '1' : '');
    }

    const buttons = Array.from(group.querySelectorAll('[data-wpelb-operation]'));
    buttons.forEach((item) => { item.disabled = true; });
    if (feedback) {
      feedback.hidden = false;
      feedback.textContent = 'Saving connection…';
      feedback.style.color = '';
    }

    try {
      const response = await window.fetch(window.wpelbAdmin.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      });

      const json = await response.json();
      if (!response.ok || !json || json.success !== true) {
        throw new Error((json && json.data && json.data.message) || 'Unable to save connection; please check your permissions and try again.');
      }

      const panel = group.closest('#wpelb-connection .inside');
      if (panel && json.data && typeof json.data.html === 'string') {
        // Trusted HTML rendered by the WordPress admin meta box callback.
        panel.innerHTML = json.data.html;
      } else if (feedback) {
        feedback.textContent = 'Connection saved. Reload the editor to refresh its status.';
      }
    } catch (error) {
      if (feedback) {
        feedback.hidden = false;
        feedback.style.color = '#b32d2e';
        feedback.textContent = error && error.message ? error.message : 'Could not contact WordPress AJAX.';
      }
    } finally {
      // If the panel was replaced, these buttons are no longer attached.
      buttons.forEach((item) => { item.disabled = false; });
    }
  });
})();
