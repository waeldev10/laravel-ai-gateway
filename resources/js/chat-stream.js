// Composer flow: submit a user message, stream the assistant reply.
//
// Plain fetch + ReadableStream SSE, never a Livewire request for AI
// generation. Shared SSE plumbing lives in stream-client.js; this file owns
// only the composer form (new + existing conversations).
import {
    abortActiveGeneration,
    buildAssistantNode,
    buildUserNode,
    chatPhase,
    clearSlotAfterListRefresh,
    initUsageLimitUI,
    isUsageLimited,
    isUsageLimitError,
    nearBottom,
    postEventStream,
    recheckUsageStatus,
    refreshMessageList,
    scrollContainer,
    scrollToBottom,
    setChatPhase,
    setStreamingText,
    showUsageLimit,
    streamSlot,
    toast,
} from './stream-client.js';

// First send on the new-chat page: switch from the centered empty state
// to a normal top-aligned chat with the composer at the bottom. Called
// ONLY from the submit handler after validation (the actual first send):
// typing, textarea growth, Alpine/Livewire init never reach here. Only
// layout classes change on the existing parent wrappers — the composer
// node itself is never moved, cloned, or appended into the message
// container. No reload, no extra Livewire request.
function activateCreateChat() {
    const hero = document.querySelector('[data-create-hero]');
    if (hero) hero.classList.add('hidden');

    const root = document.querySelector('[data-create-root]');
    if (root) root.dataset.chatStarted = '1';

    // Let the content column fill the viewport so the composer sits at
    // the bottom even with a single short message; the stream slot
    // absorbs the free space above it.
    const content = document.querySelector('[data-create-content]');
    if (content) content.classList.add('min-h-full', 'flex', 'flex-col');

    const stream = streamSlot();
    if (stream) stream.classList.add('flex-1');

    // Pin the (never moved, never duplicated) composer slot as a sticky
    // bottom bar so it stays visible while long streams scroll above it.
    const slot = document.querySelector('[data-create-composer-slot]');
    if (slot) {
        slot.classList.add(
            'sticky', 'bottom-0', 'z-10',
            '-mx-4', 'px-4', 'pt-3', 'pb-4',
            'bg-white', 'dark:bg-[#141414]',
            'border-t', 'border-black/5', 'dark:border-white/10'
        );
    }

    const scroller = scrollContainer();
    if (scroller) scroller.scrollTop = 0;
}

// SPA-style transition to a persisted conversation: Livewire navigation
// morphs the new page in place (layout/sidebar/theme stay mounted) instead
// of a full browser reload that would flash the whole UI. The plain reload
// below runs only when Livewire navigation is unavailable.
function spaNavigate(url) {
    try {
        if (window.Livewire && typeof window.Livewire.navigate === 'function') {
            window.Livewire.navigate(url);
            return;
        }
    } catch (err) { /* fall through to the plain reload */ }
    window.location.href = url;
}

function initComposer(root) {    if (!root || root.dataset.chatInit === '1') return;
    root.dataset.chatInit = '1';

    const form = root.querySelector('[data-composer-form]');
    const input = root.querySelector('[data-composer-input]');
    const sendBtn = root.querySelector('[data-send-btn]');
    const stopBtn = root.querySelector('[data-stop-btn]');
    const errorBox = root.querySelector('[data-composer-error]');

    if (!form || !input) return;

    const streamUrl = root.dataset.streamUrl || '';
    const newStreamUrl = root.dataset.newStreamUrl || '';
    const isNew = !streamUrl && !!newStreamUrl;

    let controller = null;
    let streaming = false;

    const setError = (message) => {
        if (!errorBox) return;
        if (!message) {
            errorBox.textContent = '';
            errorBox.classList.add('hidden');
            return;
        }
        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
    };

    const setStreaming = (active) => {
        streaming = active;
        input.disabled = active || isUsageLimited();
        if (sendBtn) sendBtn.classList.toggle('hidden', active);
        if (stopBtn) stopBtn.classList.toggle('hidden', !active);
        setChatPhase(active ? 'streaming' : chatPhase() === 'streaming' ? 'idle' : chatPhase());
    };

    if (stopBtn) {
        stopBtn.addEventListener('click', () => {
            abortActiveGeneration();
        });
    }

    // Auto-resize the textarea upward only: the composer wrapper stays
    // pinned to the bottom of the chat column (shrink-0 flex child), the
    // form keeps items-end, and only the textarea height grows — capped at
    // max-h-32 (128px) with internal scroll beyond it. Never touches the
    // chat scroller, transforms, or positioning here.
    const MAX_INPUT_H = 128;
    const autoResize = () => {
        input.style.height = 'auto';
        const next = Math.min(input.scrollHeight, MAX_INPUT_H);
        input.style.height = `${next}px`;
        input.style.overflowY = input.scrollHeight > MAX_INPUT_H ? 'auto' : 'hidden';
    };

    input.addEventListener('input', () => {
        if (sendBtn) sendBtn.disabled = input.value.trim() === '' || streaming || isUsageLimited();
        setError(null);
        autoResize();
    });

    autoResize();

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (input.value.trim() && !streaming && !isUsageLimited()) form.requestSubmit();
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        // Application usage limit: never submit while limited. Re-check
        // the authoritative state so a stale banner cannot block forever.
        if (isUsageLimited()) {
            recheckUsageStatus();
            return;
        }

        if (streaming || chatPhase() === 'streaming') return;

        const text = input.value.trim();

        if (!text) {
            setError('حقل الرسالة مطلوب.');
            return;
        }

        if (text.length > 10000) {
            setError('يجب ألا تتجاوز الرسالة 10000 حرف.');
            return;
        }

        const url = isNew ? newStreamUrl : streamUrl;

        if (!url) return;

        setError(null);
        setStreaming(true);
        if (sendBtn) sendBtn.disabled = true;

        // New-chat first send: this submit — and only this submit, never
        // typing or textarea growth — switches the centered empty state
        // to a normal top-aligned chat. The same composer stays mounted
        // (only its parent layout classes change); no reload, no duplicate.
        if (isNew) activateCreateChat();

        const scroller = scrollContainer();
        const slot = streamSlot();
        const stick = isNew ? false : nearBottom(scroller);

        // Immediate optimistic UI: user bubble + empty assistant bubble.
        const userNode = buildUserNode(text);
        const assistant = buildAssistantNode();

        if (slot) {
            slot.appendChild(userNode);
            slot.appendChild(assistant.wrap);
        }

        if (stick) scrollToBottom(scroller);

        input.value = '';
        autoResize();
        if (sendBtn) sendBtn.disabled = true;

        controller = new AbortController();
        window.__abortActiveGeneration = () => { try { controller.abort(); } catch (e) { /* noop */ } };
        let aborted = false;
        let assistantText = '';
        let meta = {};
        let streamError = null;
        let usageLimitHit = false;
        let usageLimitRetryAfter = null;
        let donePayload = null;

        try {
            const body = new FormData();
            body.append('content', text);

            const outcome = await postEventStream({
                url,
                formData: body,
                signal: controller.signal,
                onEvent: (event) => {
                    if (!event || typeof event.type !== 'string') return;

                    if (event.type === 'meta') {
                        meta = { ...meta, ...event };
                    } else if (event.type === 'delta') {
                        const chunk = typeof event.text === 'string' ? event.text : '';
                        if (!chunk) return;
                        assistantText += chunk;
                        // First chunk swaps the typing dots for real text;
                        // later chunks just extend it.
                        setStreamingText(assistant.bubble, assistantText);
                        if (nearBottom(scroller) || stick) scrollToBottom(scroller);
                    } else if (event.type === 'done') {
                        donePayload = event;
                    } else if (isUsageLimitError(event)) {
                        // Application usage limit: dedicated banner state,
                        // never the generic error toast.
                        usageLimitHit = true;
                        usageLimitRetryAfter = typeof event.retry_after === 'number' ? event.retry_after : null;
                    } else if (event.type === 'error') {
                        streamError = typeof event.message === 'string' && event.message.trim() !== ''
                            ? event.message
                            : 'تعذر الحصول على رد المساعد. حاول مرة أخرى.';
                    }
                },
            });

            aborted = outcome.aborted;
        } catch (err) {
            streamError = err && err.message ? err.message : 'تعذر الحصول على رد المساعد. حاول مرة أخرى.';
        } finally {
            controller = null;
            window.__abortActiveGeneration = null;
            setStreaming(false);
            if (sendBtn) sendBtn.disabled = input.value.trim() === '' || isUsageLimited();
            input.disabled = isUsageLimited();
            try { if (!isUsageLimited()) input.focus(); } catch (err) { /* noop */ }
        }

        if (aborted) {
            // The server preserves the received text as a partial reply on
            // abort; refresh twice (now + delayed) so the persisted partial
            // — with its Continue action — appears even if the server needs
            // a moment to detect the abort.
            setChatPhase('stopped');
            toast('warning', 'تم الإيقاف', 'تم حفظ الجزء المولّد. يمكنك المواصلة بزر مواصلة التوليد.');

            if (isNew && meta.conversation_id) {
                spaNavigate(`${window.location.origin}/conversations/${meta.conversation_id}`);
                return;
            }

            refreshMessageList();
            setTimeout(refreshMessageList, 2000);
            clearSlotAfterListRefresh(2600);
            return;
        }

        if (usageLimitHit) {
            // Application usage limit: dedicated banner state above the
            // composer (with countdown), never the generic error toast.
            setChatPhase('idle');
            showUsageLimit(usageLimitRetryAfter);

            // New-chat mode already persisted the conversation: navigate to
            // it so the user message is not lost (its banner renders
            // server-side there too).
            if (isNew && meta.conversation_id) {
                spaNavigate(`${window.location.origin}/conversations/${meta.conversation_id}`);
                return;
            }

            refreshMessageList();
            clearSlotAfterListRefresh();
            return;
        }

        if (streamError) {
            setChatPhase('idle');
            toast('error', 'تعذر الحصول على رد المساعد', streamError);

            // New-chat mode already persisted the conversation: navigate to
            // it so the user message is not lost.
            if (isNew && meta.conversation_id) {
                spaNavigate(`${window.location.origin}/conversations/${meta.conversation_id}`);
                return;
            }

            refreshMessageList();
            clearSlotAfterListRefresh();
            return;
        }

        if (isNew) {
            const redirect = (donePayload && donePayload.redirect_url) || (meta.conversation_id
                ? `${window.location.origin}/conversations/${meta.conversation_id}`
                : null);

            if (redirect) {
                spaNavigate(redirect);
                return;
            }
        }

        setChatPhase('idle');
        scrollToBottom(scroller);
        refreshMessageList();
        clearSlotAfterListRefresh();
    });
}

function initAllComposers() {
    document.querySelectorAll('[data-chat-composer]').forEach(initComposer);
    initUsageLimitUI();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllComposers);
} else {
    initAllComposers();
}

// NOTE: only `livewire:navigated` re-runs initialization after SPA
// navigation (per-root chatInit guard keeps it idempotent). There is no
// `livewire:load` document event in Livewire v4, so no such listener.
document.addEventListener('livewire:navigated', initAllComposers);
