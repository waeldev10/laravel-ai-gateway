<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Render the profile page. All interactive saves run through the
     * ProfileForm Livewire component (no reload); this action only serves
     * the initial page.
     */
    public function show(): View
    {
        return view('profile.show');
    }
}
