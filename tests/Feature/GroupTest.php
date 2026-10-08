<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use Tests\TestCase;

/**
 * Sections 11, 13, 14 - group creation, membership management, and the rules
 * that keep non-members and plain members out of owner-only actions.
 */
final class GroupTest extends TestCase
{
    public function test_the_creator_becomes_the_owner_and_the_first_member(): void
    {
        $owner = $this->makeUser('owner@example.com', 'Owner');
        $groupId = $this->makeGroup($owner);

        $group = Group::find($groupId);

        $this->assertNotNull($group);
        $this->assertSame($owner['id'], (string) $group->owner_id);
        $this->assertContains($owner['id'], array_map('strval', (array) $group->member_ids));
    }

    public function test_a_member_can_fetch_the_group(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $groupId = $this->makeGroup($owner);

        $this->withToken($owner['token'])
            ->getJson("/api/groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Test Group')
            ->assertJsonStructure(['success', 'message', 'data']);
    }

    public function test_a_non_member_cannot_fetch_the_group(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $groupId = $this->makeGroup($owner);
        $outsider = $this->makeUser('outsider@example.com');

        $this->withToken($outsider['token'])
            ->getJson("/api/groups/{$groupId}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_an_unknown_group_id_returns_404(): void
    {
        $owner = $this->makeUser('owner@example.com');

        $this->withToken($owner['token'])
            ->getJson('/api/groups/000000000000000000000000')
            ->assertNotFound();

        // A malformed id must be a 404 as well, never a 500.
        $this->withToken($owner['token'])
            ->getJson('/api/groups/not-an-object-id')
            ->assertNotFound();
    }

    public function test_the_owner_can_add_a_member(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $groupId = $this->makeGroup($owner, [$member]);

        $this->assertContains(
            $member['id'],
            array_map('strval', (array) Group::find($groupId)->member_ids)
        );

        $response = $this->withToken($owner['token'])->getJson("/api/groups/{$groupId}/members");

        $response->assertOk();
        $this->assertStringContainsString('member@example.com', $response->getContent());
    }

    public function test_adding_the_same_member_twice_is_rejected(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $groupId = $this->makeGroup($owner, [$member]);

        $this->withToken($owner['token'])
            ->postJson("/api/groups/{$groupId}/members", ['user_id' => $member['id']])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors' => ['user_id']]);
    }

    public function test_adding_an_unknown_user_returns_404(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $groupId = $this->makeGroup($owner);

        // GroupService::addMember throws UserNotFoundException.
        $this->withToken($owner['token'])
            ->postJson("/api/groups/{$groupId}/members", ['user_id' => '000000000000000000000000'])
            ->assertNotFound();
    }

    public function test_a_plain_member_cannot_add_another_member(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $other = $this->makeUser('other@example.com');
        $groupId = $this->makeGroup($owner, [$member]);

        $this->withToken($member['token'])
            ->postJson("/api/groups/{$groupId}/members", ['user_id' => $other['id']])
            ->assertForbidden();
    }

    public function test_the_owner_can_remove_a_member(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $groupId = $this->makeGroup($owner, [$member]);

        $this->withToken($owner['token'])
            ->deleteJson("/api/groups/{$groupId}/members/{$member['id']}")
            ->assertSuccessful();

        $this->assertNotContains(
            $member['id'],
            array_map('strval', (array) Group::find($groupId)->member_ids)
        );
    }

    public function test_the_owner_cannot_be_removed(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $groupId = $this->makeGroup($owner);

        $this->withToken($owner['token'])
            ->deleteJson("/api/groups/{$groupId}/members/{$owner['id']}")
            ->assertForbidden();
    }

    public function test_a_member_with_an_outstanding_balance_cannot_be_removed(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $groupId = $this->makeGroup($owner, [$member]);

        $this->withToken($owner['token'])->postJson("/api/groups/{$groupId}/expenses", [
            'description' => 'Lunch',
            'amount' => 100,
            'paid_by' => $owner['id'],
            'split_type' => 'equal',
            'participants' => [
                ['user_id' => $owner['id']],
                ['user_id' => $member['id']],
            ],
        ])->assertStatus(201);

        $this->withToken($owner['token'])
            ->deleteJson("/api/groups/{$groupId}/members/{$member['id']}")
            ->assertForbidden();
    }

    public function test_only_the_owner_can_update_the_group(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $groupId = $this->makeGroup($owner, [$member]);

        $this->withToken($member['token'])
            ->patchJson("/api/groups/{$groupId}", ['name' => 'Renamed by member'])
            ->assertForbidden();

        $this->withToken($owner['token'])
            ->patchJson("/api/groups/{$groupId}", ['name' => 'Renamed by owner'])
            ->assertOk();

        $this->assertSame('Renamed by owner', (string) Group::find($groupId)->name);
    }

    public function test_only_the_owner_can_delete_the_group(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $groupId = $this->makeGroup($owner, [$member]);

        $this->withToken($member['token'])
            ->deleteJson("/api/groups/{$groupId}")
            ->assertForbidden();

        $this->withToken($owner['token'])
            ->deleteJson("/api/groups/{$groupId}")
            ->assertOk();

        $this->assertNull(Group::find($groupId));
    }

    public function test_deleting_a_group_removes_its_expenses(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $groupId = $this->makeGroup($owner);

        $this->withToken($owner['token'])->postJson("/api/groups/{$groupId}/expenses", [
            'description' => 'Solo dinner',
            'amount' => 100,
            'paid_by' => $owner['id'],
            'split_type' => 'equal',
            'participants' => [['user_id' => $owner['id']]],
        ])->assertStatus(201);

        $this->assertSame(1, Expense::where('group_id', $groupId)->count());

        $this->withToken($owner['token'])->deleteJson("/api/groups/{$groupId}")->assertOk();

        $this->assertSame(0, Expense::where('group_id', $groupId)->count());
    }

    public function test_the_group_list_only_contains_the_users_groups(): void
    {
        $alice = $this->makeUser('alice@example.com');
        $bob = $this->makeUser('bob@example.com');

        $aliceGroup = $this->makeGroup($alice, [], 'Alice Group');
        $bobGroup = $this->makeGroup($bob, [], 'Bob Group');

        $response = $this->withToken($alice['token'])->getJson('/api/groups');

        $response->assertOk();
        $this->assertStringContainsString($aliceGroup, $response->getContent());
        $this->assertStringNotContainsString($bobGroup, $response->getContent());
    }

    public function test_creating_a_group_requires_a_name(): void
    {
        $owner = $this->makeUser('owner@example.com');

        $this->withToken($owner['token'])
            ->postJson('/api/groups', ['description' => 'No name given'])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['name']]);
    }

    public function test_group_routes_require_authentication(): void
    {
        $this->getJson('/api/groups')->assertStatus(401);
        $this->postJson('/api/groups', ['name' => 'Nope'])->assertStatus(401);
    }
}