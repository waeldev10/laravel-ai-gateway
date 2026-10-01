<?php

use App\Livewire\Auth\LoginForm;
use App\Livewire\Auth\RegisterForm;
use App\Livewire\Chat\MessageComposer;
use App\Livewire\Profile\ProfileForm;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('new conversation validation', function () {
    test('empty content fails with an Arabic message and creates nothing', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(MessageComposer::class)
            ->set('content', '')
            ->call('send')
            ->assertHasErrors(['content']);

        expect($component->errors()->get('content'))->toBe(['حقل الرسالة مطلوب.'])
            ->and(Conversation::count())->toBe(0)
            ->and(Message::count())->toBe(0);
    });

    test('whitespace-only content fails with an Arabic message and creates nothing', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(MessageComposer::class)
            ->set('content', '   ')
            ->call('send')
            ->assertHasErrors(['content']);

        expect($component->errors()->get('content'))->toBe(['حقل الرسالة مطلوب.'])
            ->and(Conversation::count())->toBe(0)
            ->and(Message::count())->toBe(0);
    });

    test('valid content reaches the component and persists exactly', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(MessageComposer::class)
            ->set('content', 'مرحبا')
            ->assertSet('content', 'مرحبا')
            ->call('send')
            ->assertHasNoErrors();

        $conversation = Conversation::first();
        $message = Message::first();

        expect($conversation)->not()->toBeNull()
            ->and($message)->not()->toBeNull()
            ->and($message->content)->toBe('مرحبا')
            ->and($message->role->value)->toBe('user');

        $component->assertRedirect(route('conversations.show', $conversation));
    });

    test('message posting through HTTP validates content in Arabic', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('conversations.messages.store', $conversation), ['content' => ''])
            ->assertSessionHasErrors(['content']);

        expect(session('errors')->get('content'))->toBe(['حقل الرسالة مطلوب.'])
            ->and(Message::count())->toBe(0);
    });
});

describe('authentication validation in Arabic', function () {
    test('login required fields show Arabic messages', function () {
        $component = Livewire::test(LoginForm::class)
            ->set('email', '')
            ->set('password', '')
            ->call('login')
            ->assertHasErrors(['email', 'password']);

        expect($component->errors()->get('email'))->toBe(['حقل البريد الإلكتروني مطلوب.'])
            ->and($component->errors()->get('password'))->toBe(['حقل كلمة المرور مطلوب.']);
    });

    test('register required fields show Arabic messages', function () {
        $component = Livewire::test(RegisterForm::class)
            ->set('name', '')
            ->set('email', '')
            ->set('password', '')
            ->set('password_confirmation', '')
            ->call('register')
            ->assertHasErrors(['name', 'email', 'password']);

        expect($component->errors()->get('name'))->toBe(['حقل الاسم مطلوب.'])
            ->and($component->errors()->get('email'))->toBe(['حقل البريد الإلكتروني مطلوب.'])
            ->and($component->errors()->get('password'))->toContain('حقل كلمة المرور مطلوب.');
    });

    test('register password mismatch shows an Arabic confirmation message', function () {
        $component = Livewire::test(RegisterForm::class)
            ->set('name', 'ليلى')
            ->set('email', 'laila@example.com')
            ->set('password', 'long-secure-password')
            ->set('password_confirmation', 'different-password')
            ->call('register')
            ->assertHasErrors(['password']);

        expect($component->errors()->get('password'))->toContain('تأكيد كلمة المرور غير متطابق.')
            ->and(User::where('email', 'laila@example.com')->count())->toBe(0);
    });
});

describe('profile validation in Arabic', function () {
    test('profile fields show Arabic messages', function () {
        $user = User::factory()->create(['name' => 'Keep', 'email' => 'keep@example.com']);
        $this->actingAs($user);

        $component = Livewire::test(ProfileForm::class)
            ->set('name', '')
            ->set('email', 'not-an-email')
            ->call('saveProfile')
            ->assertHasErrors(['name', 'email']);

        expect($component->errors()->get('name'))->toBe(['حقل الاسم مطلوب.'])
            ->and($component->errors()->get('email'))->toBe(['يجب أن يكون البريد الإلكتروني بريداً إلكترونياً صالحاً.'])
            ->and($user->refresh()->name)->toBe('Keep');
    });

    test('incorrect current password shows an Arabic message without sensitive details', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(ProfileForm::class)
            ->set('currentPassword', 'wrong-password')
            ->set('newPassword', 'new-secure-password')
            ->set('newPassword_confirmation', 'new-secure-password')
            ->call('savePassword')
            ->assertHasErrors(['currentPassword']);

        $message = (string) $component->errors()->first('currentPassword');

        expect($message)->toContain('الحالية')
            ->and($message)->not()->toContain('currentPassword');
    });

    test('weak new password shows an Arabic message', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(ProfileForm::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'short')
            ->set('newPassword_confirmation', 'short')
            ->call('savePassword')
            ->assertHasErrors(['newPassword']);

        $message = (string) $component->errors()->first('newPassword');

        expect($message)->toContain('كلمة المرور الجديدة')
            ->and($message)->not()->toContain('newPassword');
    });
});

describe('settings validation in Arabic', function () {
    test('invalid color values produce Arabic messages', function () {
        $message = (string) Validator::make(
            ['primary' => 'red'],
            ['primary' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']]
        )->errors()->first();

        expect($message)->toBe('صيغة اللون الرئيسي غير صالحة.');

        $missing = (string) Validator::make(
            [],
            ['primary' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']]
        )->errors()->first();

        expect($missing)->toBe('حقل اللون الرئيسي مطلوب.');
    });

    test('invalid colors never reach the server and the format stays strict', function () {
        // UI colors are browser-local: no component class, no service, and
        // no users.ui_colors column exist, so there is nothing server-side
        // to persist. The shared #RRGGBB format still rejects hostile values
        // wherever it is referenced.
        expect(class_exists('App\Livewire\Settings\UiColorSettings'))->toBeFalse()
            ->and(class_exists('App\Services\Theme\UiColorService'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'ui_colors'))->toBeFalse();

        $validator = Validator::make(
            ['primary' => 'url(evil)'],
            ['primary' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']]
        );

        expect($validator->fails())->toBeTrue();

        $user = User::factory()->create();
        $this->actingAs($user);

        expect(array_key_exists('ui_colors', $user->getAttributes()))->toBeFalse();
    });
});

describe('no raw field names leak to the user', function () {
    test('all user-facing messages use Arabic attribute names', function () {
        $messages = [
            (string) Validator::make(['content' => ''], ['content' => ['required']])->errors()->first(),
            (string) Validator::make(['password_confirmation' => 'x'], ['password' => ['required', 'confirmed']])->errors()->first('password'),
            (string) Validator::make(['primary' => 'red'], ['primary' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']])->errors()->first(),
            (string) Validator::make(['title' => ''], ['title' => ['required']])->errors()->first(),
        ];

        foreach ($messages as $message) {
            expect($message)->not()->toContain('content')
                ->and($message)->not()->toContain('password_confirmation')
                ->and($message)->not()->toContain('primary');
        }

        expect($messages[0])->toBe('حقل الرسالة مطلوب.')
            ->and($messages[3])->toBe('حقل العنوان مطلوب.');
    });
});
