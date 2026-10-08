<?php

namespace App\Livewire\Prompts;

use App\Services\AI\AiPromptService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Component;
use Throwable;

/**
 * Prompt/persona settings: the system default (always active, read-only)
 * plus the user's own personas (create up to 3 / edit / toggle /
 * delete). Every mutation resolves its row through the acting user's own
 * relation, so forged ids resolve as missing, and AiPromptService
 * re-enforces ownership and the creation limit.
 *
 * Only primitives ride in public state (ids, drafts) — never models.
 * Memory has no UI here by design (backend/context only).
 */
class PromptSettings extends Component
{
    public string $name = '';

    public string $prompt = '';

    public ?string $editingId = null;

    public string $editingName = '';

    public string $editingPrompt = '';

    public ?string $confirmingId = null;

    public function create(AiPromptService $prompts): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'prompt' => ['required', 'string', 'max:8000'],
        ]);

        try {
            $prompts->createFor(Auth::user(), [
                'name' => $validated['name'],
                'system_prompt' => $validated['prompt'],
            ]);
        } catch (InvalidArgumentException $e) {
            report($e);

            $this->dispatch('toast', type: 'error', title: 'تعذر الحفظ', message: $e->getMessage());

            return;
        }

        $this->reset(['name', 'prompt']);

        $this->dispatch('toast', type: 'success', title: 'البرومبت', message: 'تم إنشاء البرومبت بنجاح.');
    }

    public function startEdit(string $id): void
    {
        $persona = Auth::user()->aiPersonas()->findOrFail($id);

        $this->editingId = (string) $persona->getKey();
        $this->editingName = (string) $persona->name;
        $this->editingPrompt = (string) $persona->system_prompt;
        $this->confirmingId = null;
    }

    public function update(AiPromptService $prompts): void
    {
        if ($this->editingId === null) {
            return;
        }

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:100'],
            'editingPrompt' => ['required', 'string', 'max:8000'],
        ]);

        try {
            $persona = Auth::user()->aiPersonas()->findOrFail($this->editingId);

            $prompts->updateFor(Auth::user(), $persona, [
                'name' => $validated['editingName'],
                'system_prompt' => $validated['editingPrompt'],
            ]);
        } catch (AuthorizationException $e) {
            report($e);

            $this->cancelEdit();
            $this->dispatch('toast', type: 'error', title: 'غير مصرح', message: 'لا يمكنك تعديل هذا البرومبت.');

            return;
        }

        $this->cancelEdit();

        $this->dispatch('toast', type: 'success', title: 'البرومبت', message: 'تم حفظ التعديلات بنجاح.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editingName', 'editingPrompt']);
        $this->resetValidation();
    }

    /**
     * Flip one custom persona's activation. Each custom toggles
     * independently — any combination may be active at once. The system
     * default is always active and never reaches this path with a valid
     * owned row (scoped lookup 404s ownerless rows first).
     */
    public function toggle(string $id, AiPromptService $prompts): void
    {
        try {
            $persona = Auth::user()->aiPersonas()->findOrFail($id);

            $active = ! (bool) $persona->is_active;

            $prompts->setActiveFor(Auth::user(), $persona, $active);
        } catch (AuthorizationException $e) {
            report($e);

            $this->dispatch('toast', type: 'error', title: 'غير مصرح', message: 'لا يمكنك تعديل هذا البرومبت.');

            return;
        }

        $this->confirmingId = null;

        $this->dispatch('toast',
            type: 'success',
            title: 'البرومبت',
            message: $active ? 'تم تفعيل البرومبت.' : 'تم إلغاء تفعيل البرومبت.'
        );
    }

    /**
     * Two-step delete: the first call arms confirmation on the row, the
     * second (same id) performs it. Forged ids fail at the scoped lookup
     * before anything is touched.
     */
    public function remove(string $id, AiPromptService $prompts): void
    {
        if ($this->confirmingId !== $id) {
            $this->confirmingId = $id;

            return;
        }

        try {
            $persona = Auth::user()->aiPersonas()->findOrFail($id);

            $prompts->deleteFor(Auth::user(), $persona);
        } catch (AuthorizationException $e) {
            report($e);

            $this->confirmingId = null;
            $this->dispatch('toast', type: 'error', title: 'غير مصرح', message: 'لا يمكنك حذف هذا البرومبت.');

            return;
        } catch (ModelNotFoundException $e) {
            report($e);

            $this->confirmingId = null;

            return;
        } catch (Throwable $e) {
            report($e);

            $this->confirmingId = null;
            $this->dispatch('toast', type: 'error', title: 'تعذر الحذف', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');

            return;
        }

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }

        $this->confirmingId = null;

        $this->dispatch('toast', type: 'success', title: 'البرومبت', message: 'تم حذف البرومبت نهائياً.');
    }

    public function render(AiPromptService $prompts)
    {
        $user = Auth::user();

        $personas = $user->aiPersonas()
            ->orderByDesc('is_active')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return view('livewire.prompts.prompt-settings', [
            'systemDefault' => $prompts->systemDefault(),
            'personas' => $personas,
            'maxCustom' => AiPromptService::MAX_CUSTOM_PERSONAS,
            'canCreate' => $personas->count() < AiPromptService::MAX_CUSTOM_PERSONAS,
        ]);
    }
}
