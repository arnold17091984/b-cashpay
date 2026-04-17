/**
 * B-CashPay Payment Page — Client-side logic
 *
 * Responsibilities:
 *   1. Copy button functionality (clipboard API + textarea fallback)
 *   2. Toast notification system
 *   3. Status polling (GET /api/v1/pay/{token}/status every 30s)
 *   4. Auto-transition to confirmed state on payment confirmation
 *   5. Deadline countdown timer
 *   6. Bank field copy button hydration (dynamic injection support)
 */
(function () {
  'use strict';

  // ── Configuration ──────────────────────────────────────────────────────────
  var POLL_INTERVAL_MS  = 30000; // 30 seconds
  var TOAST_DURATION_MS = 2200;
  var COPIED_RESET_MS   = 1800;

  // Read token + status URL from <body> data attributes.
  // PHP sets: <body data-token="{{TOKEN}}" data-status-url="{{STATUS_URL}}">
  var body       = document.body;
  var TOKEN      = body.getAttribute('data-token')     || '';
  var STATUS_URL = body.getAttribute('data-status-url') || '';

  // ── Toast system ───────────────────────────────────────────────────────────
  var toastEl     = document.getElementById('bcp-toast');
  var toastTextEl = document.getElementById('bcp-toast-text');
  var toastTimer  = null;

  function showToast(message) {
    if (!toastEl) return;
    if (toastTimer) clearTimeout(toastTimer);
    if (toastTextEl) toastTextEl.textContent = message;
    // Force reflow so slide-up animation restarts on repeat copies
    toastEl.classList.remove('is-visible');
    // eslint-disable-next-line no-unused-expressions
    toastEl.offsetHeight; // trigger reflow
    toastEl.classList.add('is-visible');
    toastTimer = setTimeout(function () {
      toastEl.classList.remove('is-visible');
    }, TOAST_DURATION_MS);
  }

  // ── Clipboard copy ─────────────────────────────────────────────────────────
  function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(
        function () { return true; },
        function () { return fallbackCopy(text); }
      );
    }
    return Promise.resolve(fallbackCopy(text));
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;';
    ta.setAttribute('readonly', '');
    ta.setAttribute('aria-hidden', 'true');
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }

  // Delegate all [data-copy] clicks (buttons and any element)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();
    var text = btn.getAttribute('data-copy');
    if (!text) return;

    copyToClipboard(text).then(function (ok) {
      if (ok) {
        showToast('コピーしました');
        // Visual feedback on the button
        if (btn._copyTimer) clearTimeout(btn._copyTimer);
        btn.classList.add('is-copied');
        btn._copyTimer = setTimeout(function () {
          btn.classList.remove('is-copied');
        }, COPIED_RESET_MS);
      } else {
        showToast('コピーに失敗しました。手動でコピーしてください。');
      }
    });
  });

  // ── Bank info copy button hydration ───────────────────────────────────────
  // Attaches inline copy buttons to pre-rendered [data-bcp-bank] value cells
  // that don't already have one. Skips skeleton placeholders and account_type.
  function attachCopyButtonsToRenderedBankFields() {
    var COPY_ICON_SVG =
      '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<rect x="5.5" y="5.5" width="8.5" height="8.5" rx="1.5"/>' +
      '<path d="M3 10.5H2a1.5 1.5 0 01-1.5-1.5V2A1.5 1.5 0 012 .5h7A1.5 1.5 0 0110.5 2v1"/>' +
      '</svg>';

    var fields = document.querySelectorAll('[data-bcp-bank]');
    fields.forEach(function (el) {
      if (el.querySelector('.bcp-skeleton')) return; // still loading
      var key  = el.getAttribute('data-bcp-bank');
      if (key === 'account_type') return;           // not copyable
      var text = el.textContent.trim();
      if (!text || text === '\u2014') return;       // empty

      // Find the sibling actions cell (next element with .bcp-bank-row__actions)
      var row     = el.closest('.bcp-bank-row');
      var actions = row && row.querySelector('.bcp-bank-row__actions');
      if (!actions || actions.querySelector('.bcp-copy-mini')) return; // already has one

      var btn = document.createElement('button');
      btn.type      = 'button';
      btn.className = 'bcp-copy-mini';
      btn.setAttribute('data-copy', text);
      btn.setAttribute('aria-label', 'コピー');
      btn.innerHTML = COPY_ICON_SVG + '<span>コピー</span>';
      actions.appendChild(btn);
    });
  }

  // Dynamic hydration (called by external code if bank info is lazy-loaded)
  function hydrateBankField(key, value) {
    var COPY_ICON_SVG =
      '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<rect x="5.5" y="5.5" width="8.5" height="8.5" rx="1.5"/>' +
      '<path d="M3 10.5H2a1.5 1.5 0 01-1.5-1.5V2A1.5 1.5 0 012 .5h7A1.5 1.5 0 0110.5 2v1"/>' +
      '</svg>';

    var el = document.querySelector('[data-bcp-bank="' + key + '"]');
    if (!el) return;

    el.innerHTML = '';
    el.appendChild(document.createTextNode(value || '\u2014'));

    if (value && key !== 'account_type') {
      var row     = el.closest('.bcp-bank-row');
      var actions = row && row.querySelector('.bcp-bank-row__actions');
      if (actions && !actions.querySelector('.bcp-copy-mini')) {
        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'bcp-copy-mini';
        btn.setAttribute('data-copy', value);
        btn.setAttribute('aria-label', 'コピー');
        btn.innerHTML = COPY_ICON_SVG + '<span>コピー</span>';
        actions.appendChild(btn);
      }
    }
  }

  // ── Status badge update ────────────────────────────────────────────────────
  function updateStatusBadge(status) {
    var badge = document.getElementById('bcp-status-badge');
    if (!badge) return;

    // Animate out → swap content → animate in
    badge.style.transition = 'opacity 0.2s, transform 0.2s';
    badge.style.opacity    = '0';
    badge.style.transform  = 'scale(0.92)';

    setTimeout(function () {
      badge.className = 'bcp-status bcp-status--' + status;

      var dot  = badge.querySelector('.bcp-status-dot');
      var text = badge.querySelector('.bcp-status-text');

      if (status === 'confirmed') {
        if (dot) dot.style.animation = 'none';
        if (text) text.textContent = '入金確認済み';
      } else if (status === 'expired') {
        if (dot) dot.style.animation = 'none';
        if (text) text.textContent = '期限切れ';
      }

      badge.style.opacity   = '1';
      badge.style.transform = 'scale(1)';
    }, 200);
  }

  // ── Confirmed state transition ─────────────────────────────────────────────
  function showConfirmedState(data) {
    var overlay = document.getElementById('bcp-confirmed-overlay');
    if (overlay) {
      // Update dynamic placeholders if present
      var amountEl = overlay.querySelector('[data-confirmed-amount]');
      var refEl    = overlay.querySelector('[data-confirmed-ref]');
      if (amountEl && data && data.amount) {
        amountEl.textContent = '\xA5' + Number(data.amount).toLocaleString('ja-JP');
      }
      if (refEl && data && data.reference_number) {
        refEl.textContent = data.reference_number;
      }

      // Use native <dialog>.showModal() when available
      if (typeof overlay.showModal === 'function') {
        overlay.showModal();
      } else {
        overlay.classList.add('is-active');
        overlay.setAttribute('open', '');
      }

      stopPolling();
      return;
    }

    // Fallback: redirect to confirmed page
    if (TOKEN) {
      window.location.href = '/pay/' + TOKEN + '/confirmed';
    }
  }

  // ── Status polling ─────────────────────────────────────────────────────────
  var pollTimer     = null;
  var pollCount     = 0;
  var MAX_POLL_COUNT = 120; // 120 * 30s = 1 hour max

  function startPolling() {
    if (!STATUS_URL && !TOKEN) return;
    var url = STATUS_URL || ('/api/v1/pay/' + TOKEN + '/status');
    pollTimer = setInterval(function () {
      pollCount += 1;
      if (pollCount > MAX_POLL_COUNT) { stopPolling(); return; }
      fetchStatus(url);
    }, POLL_INTERVAL_MS);
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    var bar = document.getElementById('bcp-poll-bar');
    if (bar) { bar.style.display = 'none'; }
  }

  function fetchStatus(url) {
    fetch(url, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (resp) {
        if (resp.status === 404) { stopPolling(); return null; }
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        return resp.json();
      })
      .then(function (json) {
        if (!json) return;
        handleStatusResponse(json);
      })
      .catch(function (err) {
        // Silently ignore transient network errors; polling continues
        console.warn('[BCP] status poll error:', err.message);
      });
  }

  function handleStatusResponse(json) {
    var status = json.status || (json.data && json.data.status) || '';

    // Update last-checked timestamp
    var lastCheckedEl = document.getElementById('bcp-last-checked');
    if (lastCheckedEl) {
      lastCheckedEl.textContent = new Date().toLocaleTimeString('ja-JP', {
        hour: '2-digit', minute: '2-digit',
      });
    }

    switch (status) {
      case 'confirmed':
      case 'completed':
        updateStatusBadge('confirmed');
        showConfirmedState(json.data || json);
        break;

      case 'expired':
      case 'cancelled':
        stopPolling();
        updateStatusBadge('expired');
        // Redirect to expired page after a brief pause so the badge update is visible
        setTimeout(function () {
          if (TOKEN) window.location.href = '/pay/' + TOKEN + '/expired';
        }, 1200);
        break;

      case 'pending':
      default:
        // Nothing to update — poll bar timestamp is already refreshed above
        break;
    }
  }

  // ── Deadline countdown ─────────────────────────────────────────────────────
  function initDeadlineCountdown() {
    var el = document.getElementById('bcp-deadline-countdown');
    if (!el) return;
    var deadline = el.getAttribute('data-deadline');
    if (!deadline) return;

    var deadlineMs = new Date(deadline).getTime();
    if (isNaN(deadlineMs)) return;

    function formatRemaining() {
      var diff = deadlineMs - Date.now();
      if (diff <= 0) { el.textContent = '期限切れ'; return; }

      var totalMinutes = Math.floor(diff / 60000);
      var hours        = Math.floor(totalMinutes / 60);
      var minutes      = totalMinutes % 60;

      if (hours > 0) {
        el.textContent = hours + '\u6642\u9593' + minutes + '\u5206\u5F8C';
      } else if (minutes > 0) {
        el.textContent = minutes + '\u5206\u5F8C';
      } else {
        el.textContent = '1分以内';
      }
    }

    formatRemaining();
    // Update every minute
    setInterval(formatRemaining, 60000);
  }

  // ── Init ───────────────────────────────────────────────────────────────────
  function init() {
    attachCopyButtonsToRenderedBankFields();
    initDeadlineCountdown();

    // Only poll on pending pages
    var isPendingPage = body.getAttribute('data-payment-status') === 'pending';
    var hasNoPoll     = !!document.querySelector('[data-no-poll]');
    if (isPendingPage && !hasNoPoll && (TOKEN || STATUS_URL)) {
      startPolling();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Public API — accessible from PHP-rendered inline scripts
  window.BCashPay = {
    hydrateBankField:   hydrateBankField,
    showToast:          showToast,
    showConfirmedState: showConfirmedState,
    stopPolling:        stopPolling,
    updateStatusBadge:  updateStatusBadge,
  };

}());
