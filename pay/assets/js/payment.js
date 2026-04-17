/**
 * B-CashPay Payment Page — Client-side logic
 *
 * Responsibilities:
 *   1. Copy button functionality (clipboard API + textarea fallback)
 *   2. Toast notification system
 *   3. Status polling (GET /api/v1/pay/{token}/status every 30s)
 *   4. Auto-transition to confirmed state on payment confirmation
 *   5. Skeleton loading management for bank info (if dynamically injected)
 */
(function () {
  'use strict';

  // ── Configuration ─────────────────────────────────────────
  var POLL_INTERVAL_MS = 30000; // 30 seconds
  var TOAST_DURATION_MS = 2200;
  var COPIED_RESET_MS = 1800;

  // Read token from a data attribute on <body> or a meta tag
  // The PHP template sets: <body data-token="{{TOKEN}}" data-status-url="{{STATUS_URL}}">
  var body = document.body;
  var TOKEN = body.getAttribute('data-token') || '';
  var STATUS_URL = body.getAttribute('data-status-url') || '';

  // ── Toast system ──────────────────────────────────────────
  var toastEl = document.getElementById('bcp-toast');
  var toastTextEl = document.getElementById('bcp-toast-text');
  var toastTimer = null;

  function showToast(message) {
    if (!toastEl) return;
    if (toastTimer) clearTimeout(toastTimer);
    if (toastTextEl) toastTextEl.textContent = message;
    toastEl.classList.add('is-visible');
    toastTimer = setTimeout(function () {
      toastEl.classList.remove('is-visible');
    }, TOAST_DURATION_MS);
  }

  // ── Clipboard copy ────────────────────────────────────────
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
    try {
      ok = document.execCommand('copy');
    } catch (e) {
      ok = false;
    }
    document.body.removeChild(ta);
    return ok;
  }

  // Delegate all [data-copy] button clicks
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();
    var text = btn.getAttribute('data-copy');
    if (!text) return;

    copyToClipboard(text).then(function (ok) {
      if (ok) {
        showToast('コピーしました');
        btn.classList.add('is-copied');
        var timer = setTimeout(function () {
          btn.classList.remove('is-copied');
        }, COPIED_RESET_MS);
        btn._copyTimer = timer;
      } else {
        showToast('コピーに失敗しました。手動でコピーしてください。');
      }
    });
  });

  // ── Bank info skeleton helpers ────────────────────────────
  // Called when bank info is injected by server but still needs
  // to add copy buttons. Also handles dynamic hydration cases.
  function hydrateBankField(key, value) {
    var el = document.querySelector('[data-bcp-bank="' + key + '"]');
    if (!el) return;

    // Clear skeleton placeholder
    el.innerHTML = '';
    el.appendChild(document.createTextNode(value || '—'));

    // Attach inline copy button for copyable fields
    if (value && key !== 'account_type') {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'bcp-copy-btn bcp-copy-btn--small';
      btn.setAttribute('data-copy', value);
      btn.setAttribute('aria-label', key + 'をコピー');
      btn.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<rect x="9" y="9" width="13" height="13" rx="2"/>' +
        '<path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>' +
        '</svg><span>コピー</span>';
      el.appendChild(btn);
    }
  }

  // If bank info was pre-rendered by server, just attach copy buttons
  // to existing values (skeletons were never needed).
  function attachCopyButtonsToRenderedBankFields() {
    var fields = document.querySelectorAll('[data-bcp-bank]');
    fields.forEach(function (el) {
      var hasSkeleton = el.querySelector('.bcp-skeleton');
      if (hasSkeleton) return; // still loading, skip
      var key = el.getAttribute('data-bcp-bank');
      var text = el.textContent.trim();
      if (text && text !== '—' && key !== 'account_type') {
        if (!el.querySelector('.bcp-copy-btn')) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'bcp-copy-btn bcp-copy-btn--small';
          btn.setAttribute('data-copy', text);
          btn.setAttribute('aria-label', 'コピー');
          btn.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            '<rect x="9" y="9" width="13" height="13" rx="2"/>' +
            '<path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>' +
            '</svg><span>コピー</span>';
          el.appendChild(btn);
        }
      }
    });
  }

  // ── Confirmed state transition ────────────────────────────
  function showConfirmedState(data) {
    // Option 1: full-page overlay (for the main payment page)
    var overlay = document.getElementById('bcp-confirmed-overlay');
    if (overlay) {
      // Update overlay content if placeholders present
      var amountEl = overlay.querySelector('[data-confirmed-amount]');
      var refEl = overlay.querySelector('[data-confirmed-ref]');
      if (amountEl && data && data.amount) {
        amountEl.textContent = Number(data.amount).toLocaleString('ja-JP') + '円';
      }
      if (refEl && data && data.reference_number) {
        refEl.textContent = data.reference_number;
      }
      overlay.classList.add('is-active');
      // Stop polling once confirmed
      stopPolling();
      return;
    }

    // Option 2: redirect to confirmed page
    if (TOKEN) {
      window.location.href = '/pay/' + TOKEN + '/confirmed';
    }
  }

  // ── Status polling ────────────────────────────────────────
  var pollTimer = null;
  var pollCount = 0;
  var MAX_POLL_COUNT = 120; // 120 * 30s = 1 hour max polling

  function startPolling() {
    if (!STATUS_URL && !TOKEN) return;
    var url = STATUS_URL || ('/api/v1/pay/' + TOKEN + '/status');
    pollTimer = setInterval(function () {
      pollCount += 1;
      if (pollCount > MAX_POLL_COUNT) {
        stopPolling();
        return;
      }
      fetchStatus(url);
    }, POLL_INTERVAL_MS);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function fetchStatus(url) {
    fetch(url, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (resp) {
        if (resp.status === 404) {
          stopPolling();
          return null;
        }
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

    switch (status) {
      case 'confirmed':
      case 'completed':
        showConfirmedState(json.data || json);
        updateStatusBadge('confirmed');
        break;

      case 'expired':
      case 'cancelled':
        stopPolling();
        // Redirect to expired page
        if (TOKEN) {
          window.location.href = '/pay/' + TOKEN + '/expired';
        }
        break;

      case 'pending':
      default:
        // Still waiting — update last-checked display if present
        var lastCheckedEl = document.getElementById('bcp-last-checked');
        if (lastCheckedEl) {
          lastCheckedEl.textContent = new Date().toLocaleTimeString('ja-JP');
        }
        break;
    }
  }

  function updateStatusBadge(status) {
    var badge = document.querySelector('.bcp-status');
    if (!badge) return;
    badge.className = 'bcp-status bcp-status--' + status;
    var dot = badge.querySelector('.bcp-status-dot');
    var text = badge.querySelector('.bcp-status-text');
    if (status === 'confirmed') {
      if (dot) dot.style.animation = 'none';
      if (text) text.textContent = '入金確認済み';
    }
  }

  // ── Countdown timer display (for deadline) ────────────────
  function initDeadlineCountdown() {
    var el = document.getElementById('bcp-deadline-countdown');
    if (!el) return;
    var deadline = el.getAttribute('data-deadline');
    if (!deadline) return;

    var deadlineMs = new Date(deadline).getTime();

    function update() {
      var now = Date.now();
      var diff = deadlineMs - now;
      if (diff <= 0) {
        el.textContent = '期限切れ';
        return;
      }
      var hours = Math.floor(diff / 3600000);
      var minutes = Math.floor((diff % 3600000) / 60000);
      el.textContent = hours + '時間' + minutes + '分後';
    }

    update();
    setInterval(update, 60000);
  }

  // ── Init ──────────────────────────────────────────────────
  function init() {
    // Attach copy buttons to any pre-rendered bank fields
    attachCopyButtonsToRenderedBankFields();

    // Start deadline countdown if present
    initDeadlineCountdown();

    // Only start polling on pending payment pages
    var isPending = document.querySelector('[data-payment-status="pending"]');
    if (isPending || (TOKEN && !document.querySelector('[data-no-poll]'))) {
      startPolling();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose for external use (e.g. PHP-rendered inline scripts)
  window.BCashPay = {
    hydrateBankField: hydrateBankField,
    showToast: showToast,
    showConfirmedState: showConfirmedState,
    stopPolling: stopPolling,
  };
})();
