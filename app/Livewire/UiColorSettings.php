<?php

namespace App\Livewire;

use App\Services\Theme\UiColorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

class UiColorSettings extends Component
{
    /**
     * Persist the user's UI colors (single Livewire request on Save only).
     *
     * Color picking/preview stays purely in Alpine (no server traffic per
     * keystroke); the Alpine draft arrives here once when the user saves.
     * Validation mirrors UiColorService rules so invalid values are rejected
     * with an error toast and nothing is persisted. On success the saved
     * palette becomes authoritative: the UI already previews it, the next
     * full/SPA load renders it server-side, and a success toast confirms.
     * The modal closes, matching the existing rename/confirm conventions.
     *
     * @param  array<string, mixed>  $colors
     */
    public function save(array $colors, UiColorService $service): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', title: 'تعذر الحفظ', message: 'سجل الدخول أولاً.');

            return;
        }

        $validator = Validator::make($colors, [
            'primary' => ['required', 'string', UiColorService::HEX_RULE],
            'primary_text' => ['required', 'string', UiColorService::HEX_RULE],
            'accent' => ['required', 'string', UiColorService::HEX_RULE],
            'link' => ['required', 'string', UiColorService::HEX_RULE],
        ]);

        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', title: 'تعذر الحفظ', message: (string) $validator->errors()->first());

            return;
        }

        $user = $service->saveFor($user, $validator->validated());
        $saved = $service->customFor($user);

        // Fall back to light defaults only if storage somehow yields null;
        // normally every token is a saved #RRGGBB here.
        $fallback = $service->defaults()['light'];

        foreach ($saved as $token => $value) {
            $saved[$token] = $value ?? $fallback[$token];
        }

        $this->dispatch('ui-colors-saved', colors: $saved);
        $this->dispatch('close-settings-modal');
        $this->dispatch('toast', type: 'success', title: 'الإعدادات', message: 'تم حفظ ألوان الواجهة بنجاح.');
    }

    /**
     * Clear customizations so both schemes fall back to config defaults.
     *
     * One request, no reload; the UI updates from the dispatched palette
     * and a success toast confirms, matching the save flow.
     */
    public function resetToDefaults(UiColorService $service): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', title: 'تعذر الحفظ', message: 'سجل الدخول أولاً.');

            return;
        }

        $service->resetFor($user);

        $this->dispatch('ui-colors-saved', colors: $service->defaults()['light']);
        $this->dispatch('close-settings-modal');
        $this->dispatch('toast', type: 'success', title: 'الإعدادات', message: 'تمت استعادة الألوان الافتراضية.');
    }

    public function render(UiColorService $service)
    {
        $user = Auth::user();
        $effective = $service->effectiveFor($user);

        // Single editable palette: the saved customization when present,
        // otherwise the light-scheme defaults. It applies to both schemes
        // once saved, so editing one palette keeps light/dark consistent.
        $custom = $effective['custom'];
        $light = $effective['light'];

        $initial = [];

        foreach ($service->tokens() as $token) {
            $initial[$token] = $custom[$token] ?? $light[$token];
        }

        return view('livewire.ui-color-settings', [
            'initial' => $initial,
            'lightDefaults' => $service->defaults()['light'],
        ]);
    }
}
