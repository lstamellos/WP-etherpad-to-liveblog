/* Admin-only AJAX controls for one Liveblog/pad author mapping. */
(function () {
  'use strict';

  function context(group) {
    return {
      url: window.wpelbAdmin.ajaxUrl,
      postId: group.getAttribute('data-post-id'),
      nonce: group.getAttribute('data-nonce')
    };
  }

  async function ajax(params) {
    const response = await window.fetch(window.wpelbAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: params.toString()
    });
    const json = await response.json();
    if (!response.ok || !json || json.success !== true) {
      throw new Error((json && json.data && json.data.message) || 'Unable to contact WordPress.');
    }
    return json.data;
  }

  // One outstanding search per field; older or detached responses are ignored.
  const searches = new WeakMap();
  document.addEventListener('input', function (event) {
    const input = event.target.closest('.wpelb-author-search');
    if (!input) return;
    const group = input.closest('.wpelb-action-group');
    const select = group && group.querySelector('.wpelb-author-select');
    if (!group || !select || !window.wpelbAdmin?.ajaxUrl) return;

    const last = searches.get(input) || { sequence: 0, timer: null };
    window.clearTimeout(last.timer);
    const sequence = ++last.sequence;
    searches.set(input, last);
    const term = input.value.trim();
    if (term.length < 2) return;

    last.timer = window.setTimeout(async function () {
      const {postId, nonce} = context(group);
      const params = new URLSearchParams({
        action: 'wpelb_author_search', post_id: postId, nonce, term
      });
      try {
        const data = await ajax(params);
        if (last.sequence !== sequence || !input.isConnected) return;

        const selectedValue = select.value;
        const selectedLabel = select.selectedOptions[0]?.textContent || 'Current author';
        select.replaceChildren();
        if (selectedValue) {
          select.add(new Option(selectedLabel, selectedValue, true, true));
        }
        (data.users || []).forEach(function (user) {
          if (String(user.id) === selectedValue) return;
          select.add(new Option(user.label, String(user.id)));
        });
        if (!selectedValue && select.options.length) {
          select.selectedIndex = 0;
        }
      } catch (error) {
        const feedback = group.querySelector('.wpelb-feedback');
        if (feedback && last.sequence === sequence) {
          feedback.hidden = false;
          feedback.textContent = error.message || 'Author search failed.';
          feedback.style.color = '#b32d2e';
        }
      }
    }, 250);
  });

  document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-wpelb-operation]');
    if (!button) return;
    const group = button.closest('.wpelb-action-group');
    if (!group || !window.wpelbAdmin?.ajaxUrl) return;
    event.preventDefault();

    const operation = button.getAttribute('data-wpelb-operation');
    if (!['connect', 'pause', 'resume', 'disconnect', 'save_author'].includes(operation)) return;
    if (operation === 'disconnect' &&
        !window.confirm('Disconnect this pad? Published entries are preserved.')) return;

    const {postId, nonce} = context(group);
    const params = new URLSearchParams({
      action: 'wpelb_action', post_id: postId, nonce, wpelb_operation: operation
    });

    if (operation === 'connect' || operation === 'save_author') {
      const author = group.querySelector('[name="wpelb_author_id"]');
      if (!author || !author.reportValidity() || !author.value) return;
      params.set('wpelb_author_id', author.value);
    }

    if (operation === 'connect') {
      const url = group.querySelector('[name="pad_url"]');
      const ack = group.querySelector('[name="pad_acl_ack"]');
      if (!url || !ack || !url.reportValidity() || !ack.reportValidity()) return;
      params.set('pad_url', url.value);
      params.set('pad_acl_ack', ack.checked ? '1' : '');
    }

    const buttons = Array.from(group.querySelectorAll('[data-wpelb-operation]'));
    const feedback = group.querySelector('.wpelb-feedback');
    buttons.forEach((item) => { item.disabled = true; });
    if (feedback) {
      feedback.hidden = false;
      feedback.textContent = 'Saving…';
      feedback.style.color = '';
    }
    try {
      const data = await ajax(params);
      const panel = group.closest('#wpelb-connection .inside');
      if (panel && typeof data.html === 'string') {
        panel.innerHTML = data.html;
      } else if (feedback) {
        feedback.textContent = 'Saved. Reload the editor to refresh the connection.';
      }
    } catch (error) {
      if (feedback) {
        feedback.hidden = false;
        feedback.style.color = '#b32d2e';
        feedback.textContent = error.message || 'Could not contact WordPress AJAX.';
      }
    } finally {
      buttons.forEach((item) => { item.disabled = false; });
    }
  });
})();
