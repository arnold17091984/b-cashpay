/**
 * B-CashPay Admin — Shared JavaScript
 */

'use strict';

// ── Clipboard copy ────────────────────────────────────────────────────────────
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const icon = btn.querySelector('i');
        const original = icon.className;
        icon.className = 'bi bi-clipboard-check text-success';
        setTimeout(() => { icon.className = original; }, 1500);
    }).catch(() => {
        // Fallback for older browsers
        const el = document.createElement('textarea');
        el.value = text;
        el.style.position = 'absolute';
        el.style.left = '-9999px';
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    });
}

// ── Toggle API key visibility ─────────────────────────────────────────────────
function toggleApiKey(btn) {
    const codeEl = btn.previousElementSibling;
    const fullKey = codeEl.dataset.key;
    const icon = btn.querySelector('i');

    if (codeEl.dataset.revealed === '1') {
        codeEl.textContent = fullKey.substring(0, 8) + '••••••••••••••••';
        codeEl.dataset.revealed = '0';
        icon.className = 'bi bi-eye';
    } else {
        codeEl.textContent = fullKey;
        codeEl.dataset.revealed = '1';
        icon.className = 'bi bi-eye-slash';
    }
}

// ── Auto-dismiss alerts after 5 seconds ───────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.alert.alert-success, .alert.alert-info').forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });
});

// ── Confirm delete with custom message ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            if (!confirm(el.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
});

// ── Toast notification helper ─────────────────────────────────────────────────
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container') || (() => {
        const el = document.createElement('div');
        el.id = 'toast-container';
        el.className = 'position-fixed bottom-0 end-0 p-3';
        el.style.zIndex = '9999';
        document.body.appendChild(el);
        return el;
    })();

    const id = 'toast-' + Date.now();
    const html = `
        <div id="${id}" class="toast align-items-center text-bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`;
    container.insertAdjacentHTML('beforeend', html);
    const toastEl = document.getElementById(id);
    bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 }).show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}
