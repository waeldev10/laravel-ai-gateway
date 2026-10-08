// Incremental conversation-history loading: cursor-based pagination owned
// entirely by the browser, never by Livewire or the backend.
//
// The initial paint renders only the latest page (at most 30 messages).
// When the user scrolls near the top of `[data-chat-scroll]`, this module
// fetches the next older page from the history endpoint and prepends it
// into `[data-chat-history]` — a sibling OUTSIDE the Livewire message list
// — so list re-renders (new sends, edits) never wipe or morph loaded
// history, and no scroll event ever costs a Livewire round trip.
//
// Client state (module scope, one chat page at a time):
// - loadingOlderMessages: single-flight guard, no concurrent duplicate
//   requests for the same cursor.
// - oldestMessageCursor: id of the oldest currently loaded message,
//   re-derived from the DOM at request time so Livewire re-renders of the
//   latest page can never leave it stale.
// - hasMoreMessages: false once the backend reports exhaustion (or a page
//   yields nothing new), after which no further requests are made.
// - seenIds: every inserted message id, so the same message is never
//   inserted twice even across repeated scroll events.

// Item-level Alpine scope for history-loaded messages. The history endpoint
// renders the SAME `livewire.chat.message-item` partial as the initial
// paint, which expects these helpers from its surrounding scope. This scope
// mirrors the message-list scope's item API (copy/tooltips/inline edit)
// without duplicating server state: persistence still goes through the
// Livewire component's `updateMessage` action via `Livewire.find`, exactly
// like the initial paint. Assistant regenerate/continue/share buttons need
// nothing here: they are document-delegated in message-actions.js.
window.chatHistoryItems = function () {
    return {
        editingId: null,
        busy: false,
        copiedId: null,
        copiedTimer: null,
        tip: false,
        tipTitle: '',
        tipTop: 0,
        tipLeft: 0,
        tipAbove: false,
        showTip(title, el) {
            this.tipTitle = title || '';
            try {
                const r = el.getBoundingClientRect();
                const w = Math.min(260, window.innerWidth - 16);
                this.tipLeft = Math.round(Math.min(Math.max(8, r.left), Math.max(8, window.innerWidth - w - 8)));
                const below = Math.round(r.bottom + 6);
                this.tipAbove = (below + 110 > window.innerHeight - 8) && (r.top > window.innerHeight / 2);
                this.tipTop = this.tipAbove ? Math.round(r.top - 6) : below;
            } catch (e) { this.tipTop = 0; this.tipLeft = 8; this.tipAbove = false; }
            this.tip = true;
        },
        hideTip() { this.tip = false; },
        messageText(btn) {
            const block = btn.closest('[data-message-id]');
            const node = block ? block.querySelector('[data-full-text]') : null;
            return node ? node.innerText : '';
        },
        copyMsg(btn) {
            const block = btn.closest('[data-message-id]');
            const id = block ? block.dataset.messageId : '';
            const text = this.messageText(btn);
            const fail = () => window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', title: 'تعذر النسخ', message: 'تعذر الوصول إلى الحافظة.' } }));
            if (!text || !navigator.clipboard || !navigator.clipboard.writeText) { fail(); return; }
            navigator.clipboard.writeText(text).then(() => {
                this.copiedId = id;
                if (this.copiedTimer) clearTimeout(this.copiedTimer);
                this.copiedTimer = setTimeout(() => { if (this.copiedId === id) this.copiedId = null; }, 2000);
                window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', title: 'تم النسخ', message: 'تم نسخ الرسالة.' } }));
            }).catch(fail);
        },
        startEdit(btn) {
            if (this.busy) return;
            const block = btn.closest('[data-message-id]');
            if (!block) return;
            const node = block.querySelector('[data-full-text]');
            const text = node ? node.innerText : '';
            this.editingId = block.dataset.messageId;
            this.$nextTick(() => {
                const editor = block.querySelector('input[data-edit-input]');
                if (editor) { editor.value = text; editor.focus(); }
            });
        },
        cancelEdit() { if (this.busy) return; this.editingId = null; },
        saveEdit(btn) {
            if (this.busy) return; this.busy = true;
            const block = btn.closest('[data-message-id]');
            const editor = block ? block.querySelector('input[data-edit-input]') : null;
            const done = () => { this.busy = false; };
            // Same server action as the initial paint (`updateMessage` on
            // the message-list component); success closes the editor via
            // the `close-edit-message` window event the component emits.
            let component = null;
            try {
                const root = document.querySelector('[data-message-list-root]');
                const id = root ? root.getAttribute('wire:id') : null;
                if (id && window.Livewire && typeof window.Livewire.find === 'function') {
                    component = window.Livewire.find(id);
                }
            } catch (e) { component = null; }
            if (!component || typeof component.call !== 'function') { done(); return; }
            try {
                const result = component.call('updateMessage', block ? block.dataset.messageId : '', editor ? editor.value : '');
                if (result && typeof result.finally === 'function') result.finally(done);
                else done();
            } catch (e) { done(); }
        },
    };
};

const chatHistoryState = {
    loadingOlderMessages: false,
    oldestMessageCursor: null,
    hasMoreMessages: true,
    seenIds: new Set(),
    emptyPagesInRow: 0,
    // Promise of the currently running page, when any: shared so rapid
    // scroll events never run concurrent requests for the same cursor.
    activeLoad: null,
};

const HISTORY_PAGE_LIMIT = 30;
const HISTORY_TOP_THRESHOLD_PX = 240;

function historyScroller() {
    return document.querySelector('[data-chat-scroll]');
}

function historyContainer() {
    return document.querySelector('[data-chat-history]');
}

function historyLoader() {
    return document.querySelector('[data-history-loader]');
}

function setHistoryLoaderVisible(visible) {
    const loader = historyLoader();
    if (!loader) return;
    loader.classList.toggle('hidden', !visible);
}

// The oldest currently loaded message id, read live from the DOM: the first
// `[data-message-id]` node across the history container, the Livewire list,
// and the stream slot, in document order. Falls back to the server-rendered
// cursor when no message node exists yet (empty conversation).
function oldestLoadedMessageId(scroller) {
    try {
        const nodes = scroller.querySelectorAll('[data-message-id]');
        if (nodes.length > 0) return nodes[0].dataset.messageId || null;
    } catch (e) { /* fall through to the attribute */ }
    const container = historyContainer();
    const cursor = container ? container.dataset.oldestCursor : '';
    return cursor && cursor !== '' ? cursor : null;
}

function seedSeenIds(scroller) {
    try {
        scroller.querySelectorAll('[data-message-id]').forEach((node) => {
            if (node.dataset.messageId) chatHistoryState.seenIds.add(node.dataset.messageId);
        });
    } catch (e) { /* non-fatal */ }
}

// Single-flight entry point for one older page. A caller that arrives
// while a page is already running shares the in-flight promise instead of
// starting a duplicate request for the same cursor.
function loadOlderMessages() {
    if (chatHistoryState.loadingOlderMessages) return chatHistoryState.activeLoad || Promise.resolve();
    if (!chatHistoryState.hasMoreMessages) return Promise.resolve();

    chatHistoryState.activeLoad = runOlderMessagesLoad().finally(() => {
        chatHistoryState.activeLoad = null;
    });

    return chatHistoryState.activeLoad;
}

async function runOlderMessagesLoad() {
    const scroller = historyScroller();
    const container = historyContainer();

    if (!scroller || !container) return;
    if (chatHistoryState.loadingOlderMessages) return;
    if (!chatHistoryState.hasMoreMessages) return;

    const url = container.dataset.historyUrl || '';
    // The cursor is re-derived from the DOM on every request so a
    // Livewire re-render of the latest page (new send, edit) between two
    // scroll events can never make us re-request a stale cursor.
    const oldestMessageCursor = oldestLoadedMessageId(scroller);

    if (!url || !oldestMessageCursor) {
        // No cursor (empty conversation) or no endpoint: nothing older
        // exists, so stop without ever requesting.
        chatHistoryState.hasMoreMessages = false;
        chatHistoryState.oldestMessageCursor = oldestMessageCursor;
        return;
    }

    chatHistoryState.loadingOlderMessages = true;
    chatHistoryState.oldestMessageCursor = oldestMessageCursor;
    setHistoryLoaderVisible(true);

    // Scroll preservation: remember the geometry BEFORE prepending, then
    // shift scrollTop by exactly the added height so the previously visible
    // message stays pixel-stable (no viewport jump).
    const previousHeight = scroller.scrollHeight;
    const previousTop = scroller.scrollTop;

    try {
        const endpoint = `${url}?before=${encodeURIComponent(oldestMessageCursor)}&limit=${HISTORY_PAGE_LIMIT}`;
        const response = await fetch(endpoint, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) throw new Error(`history request failed: ${response.status}`);

        const payload = await response.json();
        const items = payload && Array.isArray(payload.data) ? payload.data : [];

        // Dedupe: skip anything already on screen or previously inserted,
        // so repeated scroll events can never duplicate a message.
        const onScreen = new Set();
        try {
            scroller.querySelectorAll('[data-message-id]').forEach((node) => {
                if (node.dataset.messageId) onScreen.add(node.dataset.messageId);
            });
        } catch (e) { /* treat as empty */ }

        const fresh = items.filter((item) => item
            && typeof item.id === 'string'
            && typeof item.html === 'string'
            && item.html !== ''
            && !onScreen.has(item.id)
            && !chatHistoryState.seenIds.has(item.id));

        if (fresh.length > 0) {
            // Older than everything already shown: prepend at the very top
            // of the history container (payload is oldest-first already).
            container.insertAdjacentHTML('afterbegin', fresh.map((item) => item.html).join(''));
            fresh.forEach((item) => chatHistoryState.seenIds.add(item.id));
            chatHistoryState.emptyPagesInRow = 0;
            scroller.scrollTop = previousTop + (scroller.scrollHeight - previousHeight);
        } else {
            chatHistoryState.emptyPagesInRow += 1;
        }

        const serverHasMore = payload && typeof payload.has_more === 'boolean' ? payload.has_more : false;
        // Stop when the backend reports exhaustion, or when consecutive
        // pages yield nothing new (stale cursor safety net): either way no
        // further requests are made for this conversation view.
        chatHistoryState.hasMoreMessages = serverHasMore && chatHistoryState.emptyPagesInRow === 0;
        if (chatHistoryState.hasMoreMessages && typeof payload.oldest_cursor === 'string' && payload.oldest_cursor !== '') {
            container.dataset.oldestCursor = payload.oldest_cursor;
        }
        container.dataset.hasMore = chatHistoryState.hasMoreMessages ? '1' : '0';
    } catch (e) {
        // Visible but recoverable: state is untouched (hasMore stays as it
        // was), so the next scroll-up retries. Uses the app's standard
        // toast event, never a new loading state.
        window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', title: 'تعذر تحميل الرسائل السابقة', message: 'تحقق من الاتصال ثم مرر للأعلى مرة أخرى.' } }));
    } finally {
        chatHistoryState.loadingOlderMessages = false;
        setHistoryLoaderVisible(false);
    }
}

// Scroll the conversation to the latest (bottom) message. Used exactly
// once per conversation open, after the server-rendered message DOM is
// already in place: both the full-load path (DOMContentLoaded) and the
// Livewire SPA path (livewire:navigated) fire after the new DOM exists,
// so this runs synchronously with no timers and no smooth animation.
// Never used when prepending older pages: that path preserves the
// viewport instead (see runOlderMessagesLoad) and must not jump.
function scrollChatToBottom(scroller) {
    try {
        scroller.scrollTop = scroller.scrollHeight;
    } catch (e) { /* non-fatal */ }
}

function onChatHistoryScroll() {
    const scroller = historyScroller();
    if (!scroller) return;
    if (chatHistoryState.loadingOlderMessages) return;
    if (!chatHistoryState.hasMoreMessages) return;
    // Only when there is scrollable content above: when everything fits on
    // screen there is nothing to reveal, and this guard also prevents a
    // load-everything cascade on tall viewports.
    if (scroller.scrollHeight <= scroller.clientHeight + 1) return;
    if (scroller.scrollTop > HISTORY_TOP_THRESHOLD_PX) return;
    loadOlderMessages();
}

function initChatHistory() {
    const scroller = historyScroller();
    const container = historyContainer();

    if (!scroller || !container) return;

    // Server-provided initial state: `hasMoreMessages` starts false for
    // short conversations so no request is ever made for them. Reset on
    // every navigation: Livewire SPA navigation may reuse the same
    // scroller node (morph), so carried-over cursors/flags/ids would
    // otherwise go stale and older pages would never load.
    chatHistoryState.hasMoreMessages = container.dataset.hasMore !== '0';
    chatHistoryState.oldestMessageCursor = container.dataset.oldestCursor || null;
    chatHistoryState.loadingOlderMessages = false;
    chatHistoryState.activeLoad = null;
    chatHistoryState.emptyPagesInRow = 0;
    // Rebuilt from the current DOM: after SPA navigation the document no
    // longer contains previously loaded pages, so carried-over ids would
    // only grow stale state.
    chatHistoryState.seenIds = new Set();
    seedSeenIds(scroller);

    // Single listener per scroller node: re-init resets state above,
    // positions the fresh conversation at the bottom, then re-runs the
    // top check — but never attaches a duplicate scroll handler.
    if (scroller.dataset.chatHistoryInit === '1') {
        // Initial open always shows the latest message: the top check
        // below no-ops at the bottom, so no older page is fetched here.
        // Older pages load only when the user manually scrolls upward.
        scrollChatToBottom(scroller);
        onChatHistoryScroll();
        return;
    }
    scroller.dataset.chatHistoryInit = '1';

    scroller.addEventListener('scroll', onChatHistoryScroll, { passive: true });

    // Initial open always shows the latest message: the top check below
    // no-ops at the bottom, so no older page is fetched here. Older pages
    // load only when the user manually scrolls upward.
    scrollChatToBottom(scroller);
    onChatHistoryScroll();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initChatHistory);
} else {
    initChatHistory();
}

// Re-initialize after SPA navigation (same single-listener pattern as the
// composer module: per-scroller guard keeps it idempotent).
document.addEventListener('livewire:navigated', initChatHistory);
