<?php

use App\Models\AiPersona;
use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedSystemDefault(string $prompt = 'System default prompt'): AiPersona
{
    return AiPersona::create([
        'user_id' => null,
        'name' => 'System default',
        'system_prompt' => $prompt,
        'is_active' => true,
        'is_default' => true,
        'priority' => 0,
    ]);
}

describe('api personas', function () {
    test('listing shows the system default and own customs only', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $default = seedSystemDefault();
        $mine = $user->aiPersonas()->create([
            'name' => 'Mine',
            'system_prompt' => 'My prompt',
            'is_active' => true,
        ]);
        $theirs = $other->aiPersonas()->create([
            'name' => 'Theirs',
            'system_prompt' => 'Their prompt',
            'is_active' => true,
        ]);

        $response = $this->withToken(apiToken($user))
            ->getJson(route('api.v1.personas.index'))
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'system_prompt', 'is_active', 'is_default', 'is_system']]]);

        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids)->toContain((string) $default->getKey())
            ->and($ids)->toContain((string) $mine->getKey())
            ->and($ids)->not()->toContain((string) $theirs->getKey());
    });

    test('a single persona can be shown, including the system default', function () {
        $user = User::factory()->create();
        $default = seedSystemDefault();

        $this->withToken(apiToken($user))
            ->getJson(route('api.v1.personas.show', $default))
            ->assertOk()
            ->assertJsonPath('data.is_system', true)
            ->assertJsonPath('data.is_default', true);
    });

    test('another users persona resolves as missing', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $other->aiPersonas()->create([
            'name' => 'Theirs',
            'system_prompt' => 'Their prompt',
            'is_active' => true,
        ]);

        $this->withToken(apiToken($user))
            ->getJson(route('api.v1.personas.show', $theirs))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    test('a custom persona can be created', function () {
        $user = User::factory()->create();

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.personas.store'), [
                'name' => 'Translator',
                'system_prompt' => 'Translate everything.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Translator')
            ->assertJsonPath('data.is_system', false);

        expect($user->aiPersonas()->count())->toBe(1);
    });

    test('no more than three custom personas can be created', function () {
        $user = User::factory()->create();

        for ($i = 1; $i <= 3; $i++) {
            $user->aiPersonas()->create([
                'name' => "Persona {$i}",
                'system_prompt' => "Prompt {$i}",
                'is_active' => true,
            ]);
        }

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.personas.store'), [
                'name' => 'Fourth',
                'system_prompt' => 'Too many.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'persona_limit_reached');

        expect($user->aiPersonas()->count())->toBe(3);
    });

    test('creating validates the payload', function () {
        $user = User::factory()->create();

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.personas.store'), ['name' => 'Missing prompt'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    });

    test('an owned persona can be updated', function () {
        $user = User::factory()->create();
        $persona = $user->aiPersonas()->create([
            'name' => 'Old',
            'system_prompt' => 'Old prompt',
            'is_active' => true,
        ]);

        $this->withToken(apiToken($user))
            ->patchJson(route('api.v1.personas.update', $persona), [
                'name' => 'New',
                'priority' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.priority', 5)
            ->assertJsonPath('data.system_prompt', 'Old prompt');
    });

    test('the system default and foreign personas cannot be updated or deleted', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $default = seedSystemDefault();
        $theirs = $other->aiPersonas()->create([
            'name' => 'Theirs',
            'system_prompt' => 'Their prompt',
            'is_active' => true,
        ]);

        $this->withToken(apiToken($user))
            ->patchJson(route('api.v1.personas.update', $default), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->withToken(apiToken($user))
            ->patchJson(route('api.v1.personas.update', $theirs), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.personas.destroy', $default))
            ->assertNotFound();

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.personas.destroy', $theirs))
            ->assertNotFound();

        expect($default->refresh()->name)->toBe('System default')
            ->and($theirs->refresh()->name)->toBe('Theirs');
    });

    test('an owned persona can be deleted', function () {
        $user = User::factory()->create();
        $persona = $user->aiPersonas()->create([
            'name' => 'Gone',
            'system_prompt' => 'Gone prompt',
            'is_active' => true,
        ]);

        $this->withToken(apiToken($user))
            ->deleteJson(route('api.v1.personas.destroy', $persona))
            ->assertOk()
            ->assertJsonStructure(['data', 'message'])
            ->assertJsonPath('data', null);

        expect(AiPersona::whereKey($persona->getKey())->exists())->toBeFalse();
    });

    test('an owned persona can be activated and deactivated', function () {
        $user = User::factory()->create();
        $persona = $user->aiPersonas()->create([
            'name' => 'Toggle',
            'system_prompt' => 'Toggle prompt',
            'is_active' => true,
        ]);

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.personas.deactivate', $persona))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.personas.activate', $persona))
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    });

    test('the system default cannot be deactivated', function () {
        $user = User::factory()->create();
        $default = seedSystemDefault();

        $this->withToken(apiToken($user))
            ->postJson(route('api.v1.personas.deactivate', $default))
            ->assertNotFound();

        expect($default->refresh()->is_active)->toBeTrue();
    });

    test('persona writes require the write ability', function () {
        $user = User::factory()->create();
        $token = apiToken($user, [ApiAbilities::PERSONAS_READ]);

        $this->withToken($token)
            ->postJson(route('api.v1.personas.store'), [
                'name' => 'Nope',
                'system_prompt' => 'Nope.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_ability');
    });
});
