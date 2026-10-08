// Shared streaming client: SSE helpers used by the composer flow
// (chat-stream.js) and the message-action flows (message-actions.js).
//
// Everything here is transport + transient UI state only. Persisted
// messages stay server-authoritative; provider specifics never leak here —
// the browser only speaks the application-level event format:
//
//   {"type":"meta", ...}  — ids for reconciliation
//   {"type":"delta","text":"..."} — incremental assistant text (real
//     upstream chunks, never fabricated client-side)
//   {"type":"done", ...}  — persisted; refresh the Livewire list
//   {"type":"error","message":"..."} — safe user-facing failure

// Explicit generation phase: idle | streaming | stopped.
// Persisted message status (complete/partial) on the server covers what is
// stored; this covers what the browser is doing right now. Only one
// generation runs at a time.
window.__chatPhase = window.__chatPhase || 'idle';
window.__abortActiveGeneration = window.__abortActiveGeneration || null;

export function chatPhase() {
    return window.__chatPhase || 'idle';
}

export function setChatPhase(phase) {
    window.__chatPhase = phase;

    try {
        document.body.dataset.chatPhase = phase;
    } catch (e) { /* document unavailable */ }

    try {
        document.dispatchEvent(new CustomEvent('chat-phase', { detail: { phase } }));
    } catch (e) { /* noop */ }
}

// Abort whatever generation is currently streaming, whether it was
// started by the composer or by a message action. The owner clears the
// pointer when its stream settles.
export function abortActiveGeneration() {
    try {
        if (typeof window.__abortActiveGeneration === 'function') {
            window.__abortActiveGeneration();
        }
    } catch (e) { /* already settled */ }
}

// Mirror the streaming state onto every composer (send/stop swap + input
// lock) so there is one visible Stop control for all generation flows.
// An active application usage limit always wins: while limited, the
// composer stays disabled no matter what the streaming phase is.
export function setComposerStreamingUI(active) {
    document.querySelectorAll('[data-chat-composer]').forEach((root) => {
        const input = root.querySelector('[data-composer-input]');
        const sendBtn = root.querySelector('[data-send-btn]');
        const stopBtn = root.querySelector('[data-stop-btn]');

        if (input) input.disabled = active || usageLimited;
        if (sendBtn) {
            sendBtn.classList.toggle('hidden', active);
            if (!active && input) sendBtn.disabled = input.value.trim() === '' || usageLimited;
        }
        if (stopBtn) stopBtn.classList.toggle('hidden', !active);
    });
}

export function toast(type, title, message) {
    window.dispatchEvent(new CustomEvent('toast', { detail: { type, title, message } }));
}

// ---- Application usage-limit (AiUsageService) UI state ----
//
// Display only: the backend stays authoritative. The banner renders
// server-side on first paint (Livewire usageLimited props); this module
// only mirrors it after SSE `usage_limit` errors and drives the
// client-side countdown. Re-enabling always re-checks the status
// endpoint first and syncs the Livewire component when available —
// reaching zero locally never grants permission by itself. Fail closed:
// any doubt keeps the composer disabled.
//
// Only the application usage limit uses this state. Provider failures
// (including upstream HTTP 429), timeouts, and network errors never
// carry the `usage_limit` code and keep the generic error path.

let usageLimited = false;
let countdownTimer = null;

export function isUsageLimited() {
    return usageLimited;
}

export function isUsageLimitError(event) {
    return !!event && event.type === 'error' && event.code === 'usage_limit';
}

function usageComposerRoot() {
    return document.querySelector('[data-chat-composer]');
}

function arabicUnit(count, one, two, few) {
    if (count === 1) return one;
    if (count === 2) return two;
    return count <= 10 ? few : one;
}

// Mirrors MessageComposer::formatCooldown exactly (e.g. "4 دقائق و32 ثانية").
export function formatCooldown(totalSeconds) {
    const total = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const parts = [];
    const minutes = Math.floor(total / 60);
    const rest = total % 60;

    if (minutes > 0) parts.push(`${minutes} ${arabicUnit(minutes, 'دقيقة', 'دقيقتان', 'دقائق')}`);
    if (rest > 0) parts.push(`${rest} ${arabicUnit(rest, 'ثانية', 'ثانيتان', 'ثوانٍ')}`);

    return parts.join(' و');
}

function statusUrl() {
    const root = usageComposerRoot();
    return (root && root.dataset.usageStatusUrl) || '';
}

function composerBannerWidthClass(root) {
    const form = root ? root.querySelector('[data-composer-form]') : null;
    return form && form.classList.contains('w-full') ? 'w-full' : 'max-w-3xl mx-auto';
}

function ensureUsageBanner(root, retryAfter) {
    let banner = root.querySelector('[data-usage-limit-banner]');

    if (!banner) {
        banner = document.createElement('div');
        banner.setAttribute('data-usage-limit-banner', '');
        banner.setAttribute('role', 'status');
        banner.className = `${composerBannerWidthClass(root)} mb-3 flex gap-2.5 rounded-2xl border border-amber-200 dark:border-amber-400/30 bg-amber-50 dark:bg-amber-400/10 px-4 py-3`;
        banner.innerHTML = '<svg class="w-4 h-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" /></svg>'
            + '<div class="min-w-0"><p class="text-xs font-semibold leading-relaxed text-amber-800 dark:text-amber-200">لقد وصلت إلى الحد المسموح من طلبات الذكاء الاصطناعي. يرجى الانتظار حتى انتهاء فترة التهدئة قبل إرسال طلب جديد.</p>'
            + '<p class="mt-1 text-[11px] leading-relaxed text-amber-700 dark:text-amber-300">يمكنك إرسال طلب جديد بعد <span data-usage-countdown-text>.</span>.</p></div>';

        const form = root.querySelector('[data-composer-form]');
        if (form && form.parentNode) form.parentNode.insertBefore(banner, form);
        else root.prepend(banner);
    }

    const countdown = banner.querySelector('[data-usage-countdown-text]');

    if (countdown) {
        if (typeof retryAfter === 'number' && retryAfter > 0) {
            countdown.dataset.retryAfter = String(retryAfter);
            countdown.textContent = formatCooldown(retryAfter);
        } else {
            delete countdown.dataset.retryAfter;
            countdown.textContent = '';
        }
    }

    return banner;
}

function stopCountdown() {
    if (countdownTimer) {
        clearInterval(countdownTimer);
        countdownTimer = null;
    }
}

function startCountdown(totalSeconds) {
    stopCountdown();

    const el = document.querySelector('[data-usage-countdown-text]');

    if (!el || !(totalSeconds > 0)) return;

    let remaining = Math.floor(totalSeconds);
    el.dataset.retryAfter = String(remaining);
    el.textContent = formatCooldown(remaining);

    countdownTimer = setInterval(() => {
        remaining -= 1;

        if (remaining <= 0) {
            stopCountdown();
            recheckUsageStatus();
            return;
        }

        el.dataset.retryAfter = String(remaining);
        el.textContent = formatCooldown(remaining);
    }, 1000);
}

// Ask the Livewire composer to re-read the authoritative state so the
// server-rendered props never drift from this display state. Best
// effort: the endpoint check above already decided.
function syncLivewireUsageState() {
    try {
        const root = usageComposerRoot();
        const id = root ? root.getAttribute('wire:id') : null;

        if (!id || !window.Livewire || typeof window.Livewire.find !== 'function') return;

        const component = window.Livewire.find(id);

        if (component && typeof component.call === 'function') component.call('refreshUsageState');
    } catch (e) { /* display sync only; backend already decided */ }
}

export function showUsageLimit(retryAfter) {
    const root = usageComposerRoot();
    if (!root) return;

    usageLimited = true;
    root.dataset.usageLimited = '1';

    const seconds = typeof retryAfter === 'number' && retryAfter > 0 ? Math.floor(retryAfter) : null;

    if (seconds !== null) root.dataset.retryAfter = String(seconds);
    else delete root.dataset.retryAfter;

    const input = root.querySelector('[data-composer-input]');
    const sendBtn = root.querySelector('[data-send-btn]');

    if (input) input.disabled = true;
    if (sendBtn) sendBtn.disabled = true;

    ensureUsageBanner(root, seconds);
    startCountdown(seconds || 0);
    syncLivewireUsageState();
}

export function hideUsageLimit() {
    usageLimited = false;
    stopCountdown();

    const root = usageComposerRoot();

    if (root) {
        root.dataset.usageLimited = '0';
        delete root.dataset.retryAfter;

        const banner = root.querySelector('[data-usage-limit-banner]');
        if (banner) banner.remove();
    }

    // Restore the exact normal-state behavior (send enabled only with text).
    const input = root ? root.querySelector('[data-composer-input]') : null;
    const sendBtn = root ? root.querySelector('[data-send-btn]') : null;

    if (input) input.disabled = false;
    if (sendBtn && input) sendBtn.disabled = input.value.trim() === '';

    syncLivewireUsageState();
}

// Re-check the authoritative state: only a clear answer re-enables the
// composer. Fail closed on network or server failure.
export async function recheckUsageStatus() {
    const url = statusUrl();
    if (!url) return;

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) return;

        const data = await response.json();

        if (data && data.limited) {
            const retry = typeof data.retry_after === 'number' ? data.retry_after : null;
            showUsageLimit(retry);
        } else if (data) {
            hideUsageLimit();
        }
    } catch (e) { /* stay limited: fail closed */ }
}

// First-paint handoff: the server already rendered the banner when
// limited; pick up disabling + countdown from its data attributes.
export function initUsageLimitUI() {
    stopCountdown();

    const root = usageComposerRoot();
    if (!root) return;

    if (root.dataset.usageLimited === '1') {
        const retry = parseInt(root.dataset.retryAfter || '', 10);
        showUsageLimit(Number.isFinite(retry) && retry > 0 ? retry : null);
    } else {
        usageLimited = false;
    }
}

export function scrollContainer() {
    return document.querySelector('[data-chat-scroll]');
}

export function streamSlot() {
    return document.querySelector('[data-chat-stream-slot]');
}

function composerCsrf() {
    const composer = document.querySelector('[data-chat-composer]');
    return (composer && composer.dataset.csrf) || '';
}

export function conversationId() {
    const composer = document.querySelector('[data-chat-composer]');
    if (composer && composer.dataset.conversationId) return composer.dataset.conversationId;

    const match = window.location.pathname.match(/conversations\/([A-Za-z0-9]+)/);
    return match ? match[1] : '';
}

export function nearBottom(el) {
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
}

export function scrollToBottom(el) {
    if (!el) return;
    el.scrollTop = el.scrollHeight;
}

export function buildUserNode(text) {
    const wrap = document.createElement('div');
    wrap.className = 'flex justify-end';
    wrap.setAttribute('data-stream-node', 'user');

    const inner = document.createElement('div');
    inner.className = 'w-full max-w-[95%] flex justify-end';

    const bubble = document.createElement('div');
    bubble.className = 'max-w-[82%] sm:max-w-[74%] bg-[#f0f0f0] dark:bg-[#2a2a2a] rounded-2xl rounded-bl-md px-4 py-3 shadow-sm chat-user-accent';

    const content = document.createElement('div');
    content.className = 'text-sm leading-relaxed whitespace-pre-wrap break-words';
    content.setAttribute('dir', 'auto');
    content.textContent = text;

    bubble.appendChild(content);
    inner.appendChild(bubble);
    wrap.appendChild(inner);

    return wrap;
}

export function buildAssistantNode() {
    const wrap = document.createElement('div');
    wrap.className = 'flex gap-3 justify-start';
    wrap.setAttribute('data-stream-node', 'assistant');

    const avatar = document.createElement('div');
    avatar.className = 'w-7 h-7 rounded-full ui-primary-surface grid place-items-center text-xs shrink-0 mt-0.5';
    avatar.textContent = '✦';

    const inner = document.createElement('div');
    inner.className = 'flex-1 min-w-0 max-w-[85%] sm:max-w-[78%]';

    const bubble = document.createElement('div');
    bubble.className = 'rounded-2xl px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap break-words';
    bubble.setAttribute('dir', 'auto');

    // Pre-first-token state: three animated dots (see .typing-dots in
    // app.css). No rectangular placeholder, no block cursor. The first
    // real delta replaces the dots via setStreamingText().
    const dots = document.createElement('span');
    dots.className = 'typing-dots';
    dots.setAttribute('data-typing-dots', '1');
    dots.setAttribute('role', 'status');
    dots.setAttribute('aria-label', 'جارٍ توليد الرد');

    for (let i = 0; i < 3; i++) {
        const dot = document.createElement('span');
        dot.className = 'typing-dot';
        dot.setAttribute('aria-hidden', 'true');
        dots.appendChild(dot);
    }

    bubble.appendChild(dots);
    inner.appendChild(bubble);
    wrap.appendChild(avatar);
    wrap.appendChild(inner);

    return { wrap, bubble };
}

// Render streamed assistant text: the first real chunk removes the typing
// indicator, subsequent chunks append. Dots never coexist with text.
export function setStreamingText(bubble, text) {
    if (!bubble) return;

    const dots = bubble.querySelector('[data-typing-dots]');

    if (dots) dots.remove();

    bubble.textContent = text;
}

// Handoff ownership: set when WE ask Livewire to re-render the message
// list; the observer below clears the optimistic stream slot on the exact
// morph that brings the persisted [data-message-id] nodes — so the
// temporary streaming representation and the canonical persisted message
// never coexist. Module-scoped (registered once), never a global flag.
let pendingSlotClear = false;
let slotObserver = null;

function hasMessageNode(node) {
    if (!node || node.nodeType !== 1) return false;
    try {
        if (node.hasAttribute('data-message-id')) return true;
        return !!node.querySelector('[data-message-id]');
    } catch (e) { return false; }
}

function ensureSlotObserver() {
    if (slotObserver) return;
    const target = document.documentElement;
    if (!target || typeof MutationObserver === 'undefined') return;
    try {
        slotObserver = new MutationObserver((mutations) => {
            if (!pendingSlotClear) return;
            for (const m of mutations) {
                for (const n of m.addedNodes) {
                    // Optimistic stream nodes carry data-stream-node (never
                    // data-message-id), so only the persisted Livewire
                    // render trips this handoff.
                    if (hasMessageNode(n)) {
                        pendingSlotClear = false;
                        clearStreamSlot();
                        return;
                    }
                }
            }
        });
        // Observed on the root element so it survives Livewire navigations
        // (the <html> node itself is never replaced).
        slotObserver.observe(target, { childList: true, subtree: true });
    } catch (e) { slotObserver = null; }
}

export function refreshMessageList() {
    pendingSlotClear = true;
    ensureSlotObserver();
    try {
        if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
            window.Livewire.dispatch('message-sent');
            return;
        }
    } catch (e) { /* fall through to the DOM event */ }

    document.dispatchEvent(new CustomEvent('message-sent'));
}

export function clearStreamSlot() {
    const slot = streamSlot();
    if (slot) slot.replaceChildren();
}

export function clearSlotAfterListRefresh(delay = 1200) {
    // The Livewire message list re-renders from persisted state; the
    // MutationObserver handoff above drops the optimistic nodes on that
    // exact morph. The timeout below is a safety fallback only (e.g.
    // Livewire unavailable in tests): it never drives the normal handoff.
    // NOTE: there is no `livewire:updated` document event in Livewire v4
    // (it fires init/initialized/initializing/navigate/navigated/navigating
    // only), so listening for it left duplicates on screen until the timer.
    pendingSlotClear = true;
    ensureSlotObserver();
    let cleared = false;
    const clear = () => {
        if (cleared) return;
        cleared = true;
        pendingSlotClear = false;
        clearStreamSlot();
    };

    setTimeout(clear, delay);
}

async function consumeStream(response, onEvent) {
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { done, value } = await reader.read();

        if (done) break;

        buffer += decoder.decode(value, { stream: true });

        let boundary = buffer.indexOf('\n\n');

        while (boundary !== -1) {
            const raw = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);
            boundary = buffer.indexOf('\n\n');

            const lines = raw.split('\n').map((l) => l.trim()).filter(Boolean);
            const dataLine = lines.find((l) => l.startsWith('data:'));

            if (!dataLine) continue;

            const payload = dataLine.slice(5).trim();

            if (!payload) continue;

            let event = null;

            try {
                event = JSON.parse(payload);
            } catch (e) {
                continue;
            }

            onEvent(event);
        }
    }

    const tail = buffer.trim();

    if (tail.startsWith('data:')) {
        try {
            onEvent(JSON.parse(tail.slice(5).trim()));
        } catch (e) { /* ignore trailing partial */ }
    }
}

export function httpErrorMessage(status) {
    if (status === 419) return 'انتهت الجلسة. حدّث الصفحة وحاول مرة أخرى.';
    if (status === 401 || status === 403) return 'غير مصرح لك بالوصول إلى هذه المحادثة.';
    if (status === 429) return 'تم تجاوز الحد المسموح مؤقتاً. حاول مرة أخرى بعد قليل.';
    if (status === 422) return 'الرسالة غير صالحة. تحقق من النص وحاول مرة أخرى.';
    return 'تعذر الحصول على رد المساعد. حاول مرة أخرى.';
}

// POST to an SSE endpoint and forward parsed application-level events.
// Returns { aborted: true } when the caller aborted, otherwise
// { aborted: false }. HTTP failures throw an Error with a safe message;
// every parsed event is delivered to onEvent incrementally as it arrives.
export async function postEventStream({ url, formData, signal, onEvent }) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': composerCsrf(),
            Accept: 'text/event-stream',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: formData || new FormData(),
        signal,
    });

    if (!response.ok) {
        let message = httpErrorMessage(response.status);

        try {
            const data = await response.clone().json();
            if (data && typeof data.message === 'string' && data.message.trim() !== '') {
                message = data.message;
            }
        } catch (e) { /* keep the default message */ }

        throw new Error(message);
    }

    if (!response.body) {
        throw new Error('المتصفح لا يدعم قراءة الرد المباشر.');
    }

    try {
        await consumeStream(response, onEvent);
    } catch (err) {
        if (err && err.name === 'AbortError') return { aborted: true };
        throw err;
    }

    if (signal && signal.aborted) return { aborted: true };

    return { aborted: false };
}

// Share an assistant response through the OS share sheet when available,
// otherwise copy a shareable representation (page link + text) to the
// clipboard. The link itself requires authentication: no private content
// is ever exposed through an unauthenticated URL.
export async function shareResponse(title, text) {
    const url = window.location.href;
    const content = `${text}\n\n${url}`;

    if (navigator.share) {
        try {
            await navigator.share({ title, text, url });
            return;
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            // Fall through to the clipboard fallback.
        }
    }

    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(content);
            toast('success', 'تم النسخ', 'تم نسخ الرد مع رابط المحادثة.');
            return;
        }
    } catch (e) { /* fall through to the error toast */ }

    toast('error', 'تعذر المشاركة', 'المشاركة غير مدعومة على هذا المتصفح.');
}
