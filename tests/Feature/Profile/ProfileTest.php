<?php

use App\Livewire\ProfileForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('profile authentication', function () {
    test('guests cannot access the profile page', function () {
        $this->get(route('profile.show'))->assertRedirect(route('login'));
    });

    test('authenticated users see their own database values and both tabs', function () {
        $user = User::factory()->create(['name' => 'ليلى حسن', 'email' => 'laila@example.com']);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('الملف الشخصي')
            ->assertSee('كلمة السر')
            ->assertSee('ليلى حسن', false)
            ->assertSee('laila@example.com', false)
            ->assertSeeLivewire('profile-form');
    });
});

describe('profile update through livewire', function () {
    test('valid data updates the user without redirect and dispatches toast', function () {
        $user = User::factory()->create(['name' => 'Old', 'email' => 'old@example.com']);

        $this->actingAs($user);

        $component = Livewire::test(ProfileForm::class)
            ->set('name', 'New Name')
            ->set('email', 'new@example.com')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success')
            ->assertDispatched('profile-updated', name: 'New Name');

        expect($component->effects['redirect'] ?? null)->toBeNull();
        expect($user->refresh())
            ->name->toBe('New Name')
            ->email->toBe('new@example.com');
    });

    test('invalid data shows field errors and persists nothing', function () {
        $user = User::factory()->create(['name' => 'Keep', 'email' => 'keep@example.com']);

        $this->actingAs($user);
        Livewire::test(ProfileForm::class)
            ->set('name', '')
            ->set('email', 'not-an-email')
            ->call('saveProfile')
            ->assertHasErrors(['name', 'email'])
            ->assertSet('name', '')
            ->assertSet('email', 'not-an-email');

        expect($user->refresh())
            ->name->toBe('Keep')
            ->email->toBe('keep@example.com');
    });

    test('an unchanged email is accepted', function () {
        $user = User::factory()->create(['name' => 'Old', 'email' => 'same@example.com']);

        $this->actingAs($user);
        Livewire::test(ProfileForm::class)
            ->set('name', 'New')
            ->set('email', 'same@example.com')
            ->call('saveProfile')
            ->assertHasNoErrors();

        expect($user->refresh()->name)->toBe('New');
    });

    test('another users email cannot be taken', function () {
        $user = User::factory()->create(['email' => 'mine@example.com']);
        User::factory()->create(['email' => 'theirs@example.com']);

        $this->actingAs($user);
        Livewire::test(ProfileForm::class)
            ->set('name', 'Mine')
            ->set('email', 'theirs@example.com')
            ->call('saveProfile')
            ->assertHasErrors(['email']);

        expect($user->refresh()->email)->toBe('mine@example.com');
    });

    test('no browser-provided id can redirect the update to another user', function () {
        $user = User::factory()->create(['name' => 'Mine', 'email' => 'mine@example.com']);
        $other = User::factory()->create(['name' => 'Other', 'email' => 'other@example.com']);

        $this->actingAs($user);
        Livewire::test(ProfileForm::class)
            ->set('name', 'Changed Mine')
            ->set('email', 'mine@example.com')
            ->call('saveProfile')
            ->assertHasNoErrors();

        expect($user->refresh()->name)->toBe('Changed Mine')
            ->and($other->refresh()->name)->toBe('Other')
            ->and($other->refresh()->email)->toBe('other@example.com');
    });

    test('changing email resets verification while unchanged email keeps it', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user);
        Livewire::test(ProfileForm::class)
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->call('saveProfile')
            ->assertHasNoErrors();

        expect($user->refresh()->email_verified_at)->not()->toBeNull();

        Livewire::test(ProfileForm::class)
            ->set('name', $user->name)
            ->set('email', 'changed@example.com')
            ->call('saveProfile')
            ->assertHasNoErrors();

        expect($user->refresh()->email_verified_at)->toBeNull();
    });
});

describe('password change through livewire', function () {
    test('correct current password changes the password and clears the fields', function () {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Livewire::test(ProfileForm::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'new-secure-password')
            ->set('newPassword_confirmation', 'new-secure-password')
            ->call('savePassword')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success')
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPassword_confirmation', '');

        expect($component->effects['redirect'] ?? null)->toBeNull();
        expect(Hash::check('new-secure-password', $user->refresh()->password))->toBeTrue()
            ->and(Hash::check('password', $user->refresh()->password))->toBeFalse();
    });

    test('incorrect current password is rejected and the old password keeps working', function () {
        $user = User::factory()->create();

        $this->actingAs($user);
        Livewire::test(ProfileForm::class)
            ->set('currentPassword', 'wrong-password')
            ->set('newPassword', 'new-secure-password')
            ->set('newPassword_confirmation', 'new-secure-password')
            ->call('savePassword')
            ->assertHasErrors(['currentPassword']);

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });

    test('weak new password is rejected', function () {
        $user = User::factory()->create();

        $this->actingAs($user);
        Livewire::test(ProfileForm::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'short')
            ->set('newPassword_confirmation', 'short')
            ->call('savePassword')
            ->assertHasErrors(['newPassword']);

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });

    test('mismatched confirmation is rejected', function () {
        $user = User::factory()->create();

        $this->actingAs($user);
        Livewire::test(ProfileForm::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'new-secure-password')
            ->set('newPassword_confirmation', 'different-password')
            ->call('savePassword')
            ->assertHasErrors(['newPassword']);

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });

    test('one user cannot change another users password', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user);
        Livewire::test(ProfileForm::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'new-secure-password')
            ->set('newPassword_confirmation', 'new-secure-password')
            ->call('savePassword')
            ->assertHasNoErrors();

        // Only the authenticated user's password changed.
        expect(Hash::check('new-secure-password', $user->refresh()->password))->toBeTrue()
            ->and(Hash::check('password', $other->refresh()->password))->toBeTrue();
    });
});

describe('user menu profile integration', function () {
    test('the user menu links to profile and opens settings without navigating', function () {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->getContent();

        expect($html)->toContain(route('profile.show'))
            ->and($html)->toContain('الملف الشخصي')
            ->and($html)->toContain('الإعدادات')
            ->and($html)->toContain('تسجيل الخروج')
            // Settings is a browser-only action: no navigation, no server request to open.
            ->and($html)->toContain("CustomEvent('open-settings-modal')")
            // Order: Profile, then Settings, then Logout.
            ->and(strpos($html, 'الملف الشخصي') < strpos($html, 'الإعدادات'))->toBeTrue()
            ->and(strpos($html, 'الإعدادات') < strpos($html, 'تسجيل الخروج'))->toBeTrue();
    });

    test('the user menu carries sync hooks for profile updates', function () {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('data-profile-name')
            ->and($html)->toContain('data-profile-email')
            ->and($html)->toContain('@profile-updated.window');
    });
});

describe('theme persistence infrastructure', function () {
    test('both layouts share one pre-paint theme initializer', function () {
        $authHtml = $this->get(route('login'))->assertOk()->getContent();

        $user = User::factory()->create();
        $appHtml = $this->actingAs($user)->get(route('conversations.index'))->assertOk()->getContent();

        foreach ([$appHtml, $authHtml] as $html) {
            // Single shared partial output: persisted key read before first paint.
            expect(substr_count($html, "localStorage.getItem('theme')"))->toBeGreaterThanOrEqual(1);
        }

        // Exactly one authoritative Alpine theme state on the app shell,
        // delegating writes to the single implementation in app.js.
        expect(substr_count($appHtml, 'theme:'))->toBe(1)
            ->and($appHtml)->toContain('window.__setTheme');
    });

    test('the shipped theme module owns apply, navigate, system and cross-tab sync', function () {
        $source = file_get_contents(resource_path('js/app.js'));

        expect($source)->toContain('localStorage.getItem')
            ->and($source)->toContain('livewire:navigated')
            ->and($source)->toContain('prefers-color-scheme')
            ->and($source)->toContain("addEventListener('change'")
            ->and($source)->toContain("addEventListener('storage'")
            ->and($source)->not()->toContain('setTimeout');
    });
});
