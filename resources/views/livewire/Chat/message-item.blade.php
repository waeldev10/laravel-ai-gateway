{{-- Single conversation message (user or assistant branch).
     Rendered by the initial paint (conversation-messages loop) and by the
     history endpoint (MessageController@index) for scroll-loaded older
     pages, so both look identical. Expects `$m` (Message). Runs inside an
     Alpine scope that provides the item-level UI helpers (editingId/busy,
     copyMsg/copiedId, showTip/hideTip, startEdit/cancelEdit/saveEdit) —
     either the message-list scope or the history scope (chatHistoryItems).
     Assistant regenerate/continue/share buttons are document-delegated
     plain-JS actions and work in both scopes. --}}
            @if($m->role === \App\Enums\MessageRole::User)
                <div class="flex justify-end" data-message-id="{{ $m->id }}">
                    <div class="w-full max-w-[95%]">
                        <div x-show="editingId !== '{{ $m->id }}'" class="flex justify-end">
                            <div
                                class="max-w-[82%] sm:max-w-[74%] bg-[#f0f0f0] dark:bg-[#2a2a2a] rounded-2xl rounded-bl-md px-4 py-3 shadow-sm chat-user-accent"
                                data-preview="{{ \Illuminate\Support\Str::limit($m->content, 80, '…') }}"
                                @mouseenter="showTip($el.dataset.preview, $el)" @mouseleave="hideTip()">
                                <div class="text-sm leading-relaxed whitespace-pre-wrap break-words" dir="auto" data-full-text>{{ $m->content }}</div>
                                <div class="mt-1.5 text-[11px] text-zinc-400 dark:text-zinc-500 text-end"><time datetime="{{ $m->created_at->toIso8601String() }}">{{ \App\Support\ArabicDateTime::forMessage($m->created_at) }}</time></div>
                            </div>
                        </div>
                        <div x-show="editingId !== '{{ $m->id }}'" class="mt-1 flex items-center justify-end gap-1">
                            <button type="button" @click="copyMsg($el)" aria-label="نسخ الرسالة" :aria-label="copiedId === '{{ $m->id }}' ? 'تم النسخ' : 'نسخ الرسالة'"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors">
                                <svg x-show="copiedId !== '{{ $m->id }}'" x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                                <svg x-show="copiedId === '{{ $m->id }}'" x-cloak x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-3.5 h-3.5" style="color: var(--color-primary)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                                <span x-text="copiedId === '{{ $m->id }}' ? 'تم النسخ' : 'نسخ'">نسخ</span>
                            </button>
                            <button type="button" @click="startEdit($el)" aria-label="تعديل الرسالة"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></svg>
                                <span>تعديل</span>
                            </button>
                        </div>
                        <div x-show="editingId === '{{ $m->id }}'" x-cloak class="mt-1">
                            <input type="text" data-edit-input dir="auto" maxlength="10000" aria-label="تعديل نص الرسالة"
                                @keydown.escape="cancelEdit()" @keydown.enter="saveEdit($el)"
                                class="w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-[#212121] px-3.5 py-6 text-sm leading-relaxed focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current" />
                            <div class="mt-1.5 flex items-center justify-end gap-1.5">
                                <button type="button" @click="cancelEdit()" :disabled="busy"
                                    class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-300 hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current disabled:opacity-50 disabled:cursor-not-allowed transition-colors">إلغاء</button>
                                <button type="button" @click="saveEdit($el)" :disabled="busy"
                                    class="inline-flex items-center gap-1 rounded-full ui-primary-btn px-3 py-1.5 text-[11px] font-medium disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                    <span x-show="busy" x-cloak>
                                        <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                                    </span>
                                    <span x-text="busy ? 'جارٍ الحفظ...' : 'تعديل'">تعديل</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="flex gap-3 justify-start" data-message-id="{{ $m->id }}">
                    <div
                        class="w-7 h-7 rounded-full ui-primary-surface grid place-items-center text-xs shrink-0 mt-0.5">
                        ✦</div>
                    <div class="flex-1 min-w-0 max-w-[85%] sm:max-w-[78%]">
                        <div class="rounded-2xl px-4 py-3 text-sm leading-relaxed break-words"
                            dir="auto" data-full-text
                            data-preview="{{ \Illuminate\Support\Str::limit($m->content, 80, '…') }}"
                            @mouseenter="showTip($el.dataset.preview, $el)" @mouseleave="hideTip()"><div class="ai-markdown">{!! \App\Support\SafeMarkdown::render($m->content) !!}</div></div>
                        <div class="mt-1 px-4 text-[11px] text-zinc-400 dark:text-zinc-500 text-start"><time datetime="{{ $m->created_at->toIso8601String() }}">{{ \App\Support\ArabicDateTime::forMessage($m->created_at) }}</time></div>
                        <div class="mt-1 flex items-center justify-start gap-1 px-4">
                            <button type="button" @click="copyMsg($el)" aria-label="نسخ الرسالة" :aria-label="copiedId === '{{ $m->id }}' ? 'تم النسخ' : 'نسخ الرسالة'"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors">
                                <svg x-show="copiedId !== '{{ $m->id }}'" x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                                <svg x-show="copiedId === '{{ $m->id }}'" x-cloak x-transition:enter="transition-opacity duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-3.5 h-3.5" style="color: var(--color-primary)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                                <span x-text="copiedId === '{{ $m->id }}' ? 'تم النسخ' : 'نسخ'">نسخ</span>
                            </button>
                            {{-- Assistant response actions (pure fetch streaming, no Livewire):
                                 completed → Regenerate + Share; interrupted
                                 (partial) → Continue + Share. --}}
                            @if($m->status === \App\Enums\MessageStatus::Partial)
                                <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] text-amber-600 dark:text-amber-300">توقف التوليد قبل الاكتمال</span>
                                <button type="button" data-action="continue" data-message-id="{{ $m->id }}" data-url="{{ route('conversations.messages.continue', $m->conversation_id) }}" aria-label="مواصلة التوليد"
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 4 14 8-14 8V4Z" /></svg>
                                    <span>مواصلة التوليد</span>
                                </button>
                            @else
                                <button type="button" data-action="regenerate" data-message-id="{{ $m->id }}" data-url="{{ route('conversations.messages.regenerate', [$m->conversation_id, $m]) }}" aria-label="إعادة توليد الرد"
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36" /><path d="M21 3v6h-6" /></svg>
                                    <span>إعادة التوليد</span>
                                </button>
                            @endif
                            <button type="button" data-action="share" data-message-id="{{ $m->id }}" aria-label="مشاركة الرد"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><path d="m8.59 13.51 6.83 3.98" /><path d="m15.41 6.51-6.83 3.98" /></svg>
                                <span>مشاركة</span>
                            </button>
                        </div>
                    </div>
                </div>
            @endif
