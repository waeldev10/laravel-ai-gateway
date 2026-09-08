<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Policies\ConversationPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

class DeniesConversationDeletion
{
    public function delete(User $user, Conversation $conversation): bool
    {
        return false;
    }
}

describe('conversation listing', function () {
    test('authenticated users can view their conversations', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
            'title' => 'محادثة الاختبار',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee('محادثة الاختبار');
    });

    test('users only see their own conversations', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'محادثتي']);
        Conversation::factory()->create(['user_id' => $other->id, 'title' => 'محادثة سرية']);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee('محادثتي')
            ->assertDontSee('محادثة سرية');
    });

    test('conversations are ordered newest first', function () {
        $user = User::factory()->create();

        Conversation::factory()->create([
            'user_id' => $user->id,
            'title' => 'الأقدم',
            'created_at' => now()->subDay(),
        ]);

        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'الأحدث']);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertSeeInOrder(['الأحدث', 'الأقدم']);
    });
});

describe('conversation creation', function () {
    test('the conversation creation screen can be rendered', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('conversations.create'))
            ->assertOk()
            ->assertSee('محادثة جديدة');
    });

    test('authenticated users can create a conversation', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('conversations.store'), ['title' => 'محادثة جديدة'])
            ->assertRedirect();

        $conversation = Conversation::first();

        expect($conversation)->not()->toBeNull()
            ->and($conversation->title)->toBe('محادثة جديدة')
            ->and($conversation->user_id)->toBe($user->id);
    });

    test('a created conversation belongs to the authenticated user', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('conversations.store'), ['title' => 'محادثة أخرى'])
            ->assertRedirect();

        expect(Conversation::first()->user_id)->toBe($user->id);
    });

    test('a user cannot assign a conversation to another user', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->post(route('conversations.store'), [
                'title' => 'محادثة مملوكة',
                'user_id' => $other->id,
            ])
            ->assertRedirect();

        $conversation = Conversation::first();

        expect($conversation->user_id)->toBe($user->id)
            ->and($conversation->user_id)->not()->toBe($other->id);
    });

    test('a conversation requires a title', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('conversations.store'), ['title' => ''])
            ->assertSessionHasErrors('title');

        expect(Conversation::count())->toBe(0);
    });
});

describe('conversation viewing and security', function () {
    test('users can view their own conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
            'title' => 'محادثة خاصة',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('محادثة خاصة');
    });

    test('users cannot view another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $other->id,
            'title' => 'محادثة سرية',
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertForbidden();
    });

    test('direct access to another users conversation id is blocked', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $other->id,
            'title' => 'محادثة سرية',
        ]);

        $response = $this->actingAs($user)
            ->get(route('conversations.show', $conversation->id));

        $response->assertForbidden();
        $response->assertDontSee('محادثة سرية');
    });

    test('guests cannot access conversation pages', function () {
        $conversation = Conversation::factory()->create();

        $this->get(route('conversations.index'))->assertRedirect(route('login'));
        $this->get(route('conversations.create'))->assertRedirect(route('login'));
        $this->post(route('conversations.store'), ['title' => 'محادثة'])->assertRedirect(route('login'));
        $this->get(route('conversations.show', $conversation))->assertRedirect(route('login'));
    });
});

describe('conversation search', function () {
    test('users can search their conversations by title', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'مشروع العمل']);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'خطة السفر']);

        $this->actingAs($user)
            ->get(route('conversations.index', ['search' => 'مشروع']))
            ->assertOk()
            ->assertSee('مشروع العمل')
            ->assertDontSee('خطة السفر');
    });

    test('search returns matching titles', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'الذكاء الاصطناعي']);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'تصميم واجهات']);

        $this->actingAs($user)
            ->get(route('conversations.index', ['search' => 'الذكاء']))
            ->assertOk()
            ->assertSee('الذكاء الاصطناعي')
            ->assertDontSee('تصميم واجهات');
    });

    test('search is limited to the authenticated users conversations', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Conversation::factory()->create(['user_id' => $other->id, 'title' => 'محادثة سرية للآخرين']);

        $this->actingAs($user)
            ->get(route('conversations.index', ['search' => 'سرية']))
            ->assertOk()
            ->assertDontSee('محادثة سرية للآخرين');
    });

    test('a users search cannot return another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'مشروعي']);
        Conversation::factory()->create(['user_id' => $other->id, 'title' => 'مشروع سري']);

        $this->actingAs($user)
            ->get(route('conversations.index', ['search' => 'مشروع']))
            ->assertOk()
            ->assertSee('مشروعي')
            ->assertDontSee('مشروع سري');
    });

    test('empty search returns the normal conversation list', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'الأولى']);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'الثانية']);

        $this->actingAs($user)
            ->get(route('conversations.index', ['search' => '']))
            ->assertOk()
            ->assertSee('الأولى')
            ->assertSee('الثانية');
    });

    test('search with no matches shows an empty state', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'محادثة عن البرمجة']);

        $this->actingAs($user)
            ->get(route('conversations.index', ['search' => 'غير موجود']))
            ->assertOk()
            ->assertSee('غير موجود')
            ->assertDontSee('البرمجة');
    });

    test('clearing the search returns the unfiltered conversation list', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'مشروع العمل']);
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'خطة السفر']);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertDontSee('search=', false)
            ->assertSee('مشروع العمل')
            ->assertSee('خطة السفر');
    });
});

describe('conversation deletion', function () {
    test('users can delete their own conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('conversations.destroy', $conversation))
            ->assertRedirect(route('conversations.index'));

        expect(Conversation::find($conversation->id))->toBeNull();
    });

    test('deleting a conversation deletes its messages through cascade', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id]);

        $this->actingAs($user)
            ->delete(route('conversations.destroy', $conversation))
            ->assertRedirect(route('conversations.index'));

        expect(Message::find($message->id))->toBeNull();
    });

    test('users cannot delete another users conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)
            ->delete(route('conversations.destroy', $conversation))
            ->assertForbidden();

        expect(Conversation::find($conversation->id))->not()->toBeNull();
    });

    test('direct url manipulation cannot bypass deletion ownership', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $other->id,
            'title' => 'محادثة سرية',
        ]);

        $this->actingAs($user)
            ->delete(route('conversations.destroy', $conversation->id))
            ->assertForbidden();

        expect(Conversation::find($conversation->id))->not()->toBeNull();
    });

    test('guests cannot delete conversations', function () {
        $conversation = Conversation::factory()->create();

        $this->delete(route('conversations.destroy', $conversation))
            ->assertRedirect(route('login'));

        expect(Conversation::find($conversation->id))->not()->toBeNull();
    });

    test('individual delete consults the conversation delete policy', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        Gate::policy(Conversation::class, DeniesConversationDeletion::class);

        try {
            $this->actingAs($user)
                ->delete(route('conversations.destroy', $conversation))
                ->assertForbidden();
        } finally {
            Gate::policy(Conversation::class, ConversationPolicy::class);
        }

        expect(Conversation::find($conversation->id))->not()->toBeNull();
    });
});

describe('conversation bulk deletion', function () {
    test('users can delete multiple of their conversations at once', function () {
        $user = User::factory()->create();
        $first = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'الأولى']);
        $second = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'الثانية']);
        $kept = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'متبقية']);

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect(route('conversations.index'));

        expect(Conversation::find($first->id))->toBeNull()
            ->and(Conversation::find($second->id))->toBeNull()
            ->and(Conversation::find($kept->id))->not()->toBeNull();
    });

    test('bulk deletion deletes the messages of all selected conversations', function () {
        $user = User::factory()->create();
        $first = Conversation::factory()->create(['user_id' => $user->id]);
        $second = Conversation::factory()->create(['user_id' => $user->id]);
        $kept = Conversation::factory()->create(['user_id' => $user->id]);
        $firstMessage = Message::factory()->create(['conversation_id' => $first->id]);
        $secondMessage = Message::factory()->create(['conversation_id' => $second->id]);
        $keptMessage = Message::factory()->create(['conversation_id' => $kept->id]);

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect(route('conversations.index'));

        expect(Message::find($firstMessage->id))->toBeNull()
            ->and(Message::find($secondMessage->id))->toBeNull()
            ->and(Message::find($keptMessage->id))->not()->toBeNull();
    });

    test('non-owner conversations cannot be bulk deleted', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherConversation = Conversation::factory()->create(['user_id' => $other->id, 'title' => 'ليست لك']);
        $otherMessage = Message::factory()->create(['conversation_id' => $otherConversation->id]);

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => [$otherConversation->id]])
            ->assertForbidden();

        expect(Conversation::find($otherConversation->id))->not()->toBeNull()
            ->and(Message::find($otherMessage->id))->not()->toBeNull();
    });

    test('a mixed owned and non-owned selection results in zero deletions', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'محادثتي']);
        $foreign = Conversation::factory()->create(['user_id' => $other->id, 'title' => 'محادثة الآخر']);

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => [$own->id, $foreign->id]])
            ->assertForbidden();

        expect(Conversation::find($own->id))->not()->toBeNull()
            ->and(Conversation::find($foreign->id))->not()->toBeNull();
    });

    test('bulk deletion with an empty selection changes nothing', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => []])
            ->assertRedirect(route('conversations.index'));

        expect(Conversation::find($conversation->id))->not()->toBeNull();
    });

    test('bulk deletion with duplicate ids deletes each conversation only once', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => [$conversation->id, $conversation->id]])
            ->assertRedirect(route('conversations.index'));

        expect(Conversation::find($conversation->id))->toBeNull();
    });

    test('bulk deletion with non-existent ids fails safely', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => [9999, 8888]])
            ->assertForbidden();

        expect(Conversation::find($conversation->id))->not()->toBeNull();
    });

    test('guests cannot bulk delete conversations', function () {
        $conversation = Conversation::factory()->create();

        $this->delete(route('conversations.destroyMany'), ['ids' => [$conversation->id]])
            ->assertRedirect(route('login'));

        expect(Conversation::find($conversation->id))->not()->toBeNull();
    });

    test('bulk deletion requires an array of integer ids', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => 'not-an-array'])
            ->assertSessionHasErrors('ids');

        $this->actingAs($user)
            ->delete(route('conversations.destroyMany'), ['ids' => ['abc']])
            ->assertSessionHasErrors('ids.0');
    });

    test('bulk deletion consults the conversation delete policy', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        Gate::policy(Conversation::class, DeniesConversationDeletion::class);

        try {
            $this->actingAs($user)
                ->delete(route('conversations.destroyMany'), ['ids' => [$conversation->id]])
                ->assertForbidden();
        } finally {
            Gate::policy(Conversation::class, ConversationPolicy::class);
        }

        expect(Conversation::find($conversation->id))->not()->toBeNull();
    });
});

describe('conversation deletion policy', function () {
    test('the delete ability only allows the owner to delete a conversation', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = Conversation::factory()->create(['user_id' => $user->id]);
        $foreign = Conversation::factory()->create(['user_id' => $other->id]);

        expect(Gate::forUser($user)->allows('delete', $own))->toBeTrue()
            ->and(Gate::forUser($user)->allows('delete', $foreign))->toBeFalse();
    });

    test('the delete ability authorizes the owner and rejects others', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        Gate::forUser($user)->authorize('delete', $conversation);

        expect(fn () => Gate::forUser($other)->authorize('delete', $conversation))
            ->toThrow(AuthorizationException::class);
    });

    test('the conversation policy is registered for the conversation model', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $foreign = Conversation::factory()->create(['user_id' => $other->id]);

        expect(Gate::getPolicyFor(Conversation::class))->toBeInstanceOf(ConversationPolicy::class)
            ->and(Gate::forUser($user)->allows('delete', $conversation))->toBeTrue()
            ->and(Gate::forUser($user)->allows('delete', $foreign))->toBeFalse();
    });
});

describe('conversation selection interface', function () {
    test('the index shows a delete button for every conversation', function () {
        $user = User::factory()->create();
        $first = Conversation::factory()->create(['user_id' => $user->id]);
        $second = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee('x-ref="singleDelete'.$first->id.'"', false)
            ->assertSee('x-ref="singleDelete'.$second->id.'"', false);
    });

    test('the index shows a select all checkbox', function () {
        $user = User::factory()->create();
        Conversation::factory()->count(2)->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee('aria-label="تحديد الكل"', false);
    });

    test('the index shows a select checkbox for every conversation', function () {
        $user = User::factory()->create();
        $first = Conversation::factory()->create(['user_id' => $user->id]);
        $second = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee(':value="'.$first->id.'"', false)
            ->assertSee(':value="'.$second->id.'"', false);
    });

    test('the bulk delete form points to the destroy many route', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee('action="'.route('conversations.destroyMany').'"', false);
    });

    test('the bulk delete bar is only shown when conversations are selected', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee('x-show="selected.length > 0"', false);
    });

    test('the clear search button appears when a search term is present', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'مشروع العمل']);

        $this->actingAs($user)
            ->get(route('conversations.index', ['search' => 'مشروع']))
            ->assertOk()
            ->assertSee('aria-label="مسح البحث"', false);
    });

    test('the clear search button navigates to the index without the search parameter', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'مشروع العمل']);

        $this->actingAs($user)
            ->get(route('conversations.index', ['search' => 'مشروع']))
            ->assertOk()
            ->assertSee('href="'.route('conversations.index').'"', false);
    });

    test('the clear search button is hidden without a search term', function () {
        $user = User::factory()->create();
        Conversation::factory()->create(['user_id' => $user->id, 'title' => 'مشروع العمل']);

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertDontSee('aria-label="مسح البحث"', false);
    });
});
