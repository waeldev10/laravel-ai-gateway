<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PromptController extends Controller
{
    /**
     * Render the prompt/persona settings page. All management runs through
     * the PromptSettings Livewire component (no reload); this action only
     * serves the initial page. Memory has no UI by design.
     */
    public function index(): View
    {
        return view('prompts.index');
    }
}
