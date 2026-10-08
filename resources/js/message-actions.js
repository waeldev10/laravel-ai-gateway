// Message actions: regenerate, continue, share, and edit-follow.
//
// Every AI generation here goes through fetch to the SSE streaming
// endpoints (shared plumbing in stream-client.js) — never a Livewire
// request. Text persistence stays server-side in MessageService; the DOM
// updates below are optimistic and always reconciled with a Livewire
// list refresh from persisted state.
import {
    buildAssistantNode,
    chatPhase,
    clearSlotAfterListRefresh,
    conversationId,
    isUsageLimitError,
    nearBottom,
    postEventStream,
    refreshMessageList,
    scrollContainer,
    scrollToBottom,
    setChatPhase,
    setComposerStreamingUI,
    setStreamingText,
    shareResponse,
    showUsageLimit,
    streamSlot,
    toast,
} from './stream-client.js';

function messageNode(id) {
    return document.querySelector(`[data-message-id="${CSS.escape(id)}"]`);
}

function removeMessageNodes(ids) {
    (ids || []).forEach((id) => {
        const node = messageNode(String(id));
        if (node) node.remove();
    });
}

// Remove the target node and every message node rendered after it.
// Used optimistically while the server truncates the same range.
function removeNodeAndFollowing(id) {
    const nodes = Array.from(document.querySelectorAll('[data-message-id]'));
    const index = nodes.findIndex((n) => n.dataset.messageId === String(id));

    if (index === -1) return null;

    let anchor = null;

    for (let i = index - 1; i >= 0; i--) {
        anchor = nodes[i];
        break;
    }

    nodes.slice(index).forEach((n) => n.remove());

    return anchor;
}

function guardIdle() {
    if (chatPhase() === 'streaming') {
        toast('warning', 'توليد جارٍ', 'انتظر انتهاء التوليد الحالي أو أوقفه أولاً.');
        return false;
    }

    return true;
}

function regenerateUrlFor(messageId) {
    const conv = conversationId();
    if (!conv) return '';
    return `${window.location.origin}/conversations/${conv}/messages/${messageId}/regenerate`;
}

function continueUrl() {
    const conv = conversationId();
    if (!conv) return '';
    return `${window.location.origin}/conversations/${conv}/messages/continue`;
}

async function runActionStream({ url, anchorNode, mode }) {
    // mode: 'replace' (fresh placeholder after anchor) or 'append' (grow
    // the existing partial bubble in place).
    if (!url) {
        toast('error', 'تعذر التنفيذ', 'تعذر تحديد المحادثة.');
        return;
    }

    setChatPhase('streaming');

    const scroller = scrollContainer();
    const slot = streamSlot();
    const stick = nearBottom(scroller);

    let bubble = null;
    let baseText = '';

    if (mode === 'append' && anchorNode) {
        bubble = anchorNode.querySelector('[data-full-text]');
        if (bubble) baseText = bubble.innerText;
    }

    if (!bubble) {
        const assistant = buildAssistantNode();
        bubble = assistant.bubble;

        if (mode === 'append' && anchorNode && anchorNode.parentNode) {
            anchorNode.parentNode.insertBefore(assistant.wrap, anchorNode.nextSibling);
        } else if (slot && anchorNode && anchorNode.parentNode === slot) {
            slot.insertBefore(assistant.wrap, anchorNode.nextSibling);
        } else if (slot) {
            slot.appendChild(assistant.wrap);
        } else if (anchorNode && anchorNode.parentNode) {
            anchorNode.parentNode.insertBefore(assistant.wrap, anchorNode.nextSibling);
        }
    }

    let text = baseText;
    let streamError = null;
    let usageLimitHit = false;
    let usageLimitRetryAfter = null;
    let donePayload = null;
    let aborted = false;
    const controller = new AbortController();
    window.__abortActiveGeneration = () => { try { controller.abort(); } catch (e) { /* noop */ } };
    setComposerStreamingUI(true);

    toast('info', 'جارٍ التوليد', 'اضغط إيقاف في شريط الإرسال لإيقاف التوليد.');

    try {
        const outcome = await postEventStream({
            url,
            formData: new FormData(),
            signal: controller.signal,
            onEvent: (event) => {
                if (!event || typeof event.type !== 'string') return;

                if (event.type === 'meta' && Array.isArray(event.removed_message_ids)) {
                    removeMessageNodes(event.removed_message_ids);
                } else if (event.type === 'delta') {
                    const chunk = typeof event.text === 'string' ? event.text : '';
                    if (!chunk) return;
                    text += chunk;
                    // First chunk swaps the typing dots for real text (or
                    // extends existing text in append mode); no block cursor.
                    setStreamingText(bubble, text);
                    if (nearBottom(scroller) || stick) scrollToBottom(scroller);
                } else if (event.type === 'done') {
                    donePayload = event;
                } else if (isUsageLimitError(event)) {
                    // Application usage limit: dedicated banner state above
                    // the composer, never the generic error toast.
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
        window.__abortActiveGeneration = null;
        setComposerStreamingUI(false);
    }

    if (aborted) {
        setChatPhase('stopped');
        toast('warning', 'تم الإيقاف', 'تم حفظ الجزء المولّد. يمكنك المواصلة بزر مواصلة التوليد.');
        refreshMessageList();
        setTimeout(refreshMessageList, 2000);
        clearSlotAfterListRefresh(2600);
        return;
    }

    if (streamError || !donePayload) {
        setChatPhase('idle');

        if (usageLimitHit) {
            showUsageLimit(usageLimitRetryAfter);
        } else {
            toast('error', 'تعذر الحصول على رد المساعد', streamError || 'تعذر الحصول على رد المساعد. حاول مرة أخرى.');
        }

        refreshMessageList();
        clearSlotAfterListRefresh();
        return;
    }

    setChatPhase('idle');
    scrollToBottom(scroller);
    refreshMessageList();
    clearSlotAfterListRefresh();
}

async function handleRegenerate(btn) {
    if (!guardIdle()) return;

    const messageId = btn.dataset.messageId;
    const url = btn.dataset.url || regenerateUrlFor(messageId);

    if (!messageId) return;

    // Optimistic: drop the stale reply (and anything after it) now; the
    // server truncates the identical range before streaming the fresh one.
    const anchor = removeNodeAndFollowing(messageId);

    await runActionStream({ url, anchorNode: anchor, mode: 'replace' });
}

async function handleContinue(btn) {
    if (!guardIdle()) return;

    const messageId = btn.dataset.messageId;
    const url = btn.dataset.url || continueUrl();
    const node = messageId ? messageNode(messageId) : null;

    await runActionStream({ url, anchorNode: node, mode: 'append' });
}

function handleShare(btn) {
    const block = btn.closest('[data-message-id]');
    const node = block ? block.querySelector('[data-full-text]') : null;
    const text = node ? node.innerText : '';

    if (!text) {
        toast('error', 'تعذر المشاركة', 'لا يوجد نص للمشاركة.');
        return;
    }

    shareResponse(document.title || 'رد المساعد', text);
}

document.addEventListener('click', (e) => {
    const btn = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
    if (!btn) return;

    const action = btn.dataset.action;

    if (action === 'share') {
        e.preventDefault();
        handleShare(btn);
    } else if (action === 'regenerate') {
        e.preventDefault();
        handleRegenerate(btn);
    } else if (action === 'continue') {
        e.preventDefault();
        handleContinue(btn);
    }
});

// After a user message edit is persisted through Livewire
// (`close-edit-message` carries the edited message id), regenerate its
// reply through the streaming endpoint using the edited content.
// Livewire dispatches browser events on window, so listen there.
window.addEventListener('close-edit-message', (e) => {
    const messageId = e && e.detail && e.detail.messageId;

    if (!messageId) return;
    if (!guardIdle()) return;

    const node = messageNode(String(messageId));

    // Drop dependent replies optimistically; the endpoint truncates them
    // server-side before streaming the fresh reply.
    let anchor = node;

    if (node) {
        const nodes = Array.from(document.querySelectorAll('[data-message-id]'));
        const index = nodes.findIndex((n) => n.dataset.messageId === String(messageId));
        if (index !== -1) nodes.slice(index + 1).forEach((n) => n.remove());
    }

    runActionStream({ url: regenerateUrlFor(String(messageId)), anchorNode: anchor, mode: 'replace' });
});
