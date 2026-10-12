<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GroupNotFoundException;
use App\Exceptions\UnauthorizedGroupAccessException;
use App\Exceptions\UserNotFoundException;
use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupBalance;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * All group and membership business rules.
 *
 * Controllers only validate input, call these methods and format the response.
 */
final class GroupService
{
    /**
     * Groups the user is a member of, newest first.
     *
     * Pagination is done by hand (count + skip + limit) instead of paginate().
     * paginate() runs a second, internal count query through the aggregation
     * pipeline, and the explicit version keeps both queries visible and
     * predictable. The same pattern is used for expense and settlement history.
     *
     * @return array{items: Collection<int, Group>, total: int, page: int, per_page: int, last_page: int}
     */
    public function listForUser(string $userId, int $perPage, int $page): array
    {
        // member_ids carries a multikey index, so this equality match against
        // an array field is an index seek, not a collection scan.
        $total = Group::where('member_ids', $userId)->count();

        $items = Group::where('member_ids', $userId)
            ->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Resolve a group id coming from the URL, or fail with a 404.
     */
    public function findOrFail(string $groupId): Group
    {
        $group = Group::find($groupId);

        if ($group === null) {
            throw new GroupNotFoundException();
        }

        return $group;
    }

    public function create(string $ownerId, string $name, ?string $description): Group
    {
        $group = Group::create([
            'name'        => $name,
            'description' => $description,
            'owner_id'    => $ownerId,
            // The creator is the owner AND the first member, as section 11 requires.
            'member_ids'  => [$ownerId],
        ]);

        Log::info('Group created.', ['group_id' => $group->stringId(), 'owner_id' => $ownerId]);

        return $group;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Group $group, array $attributes): Group
    {
        $group->fill($attributes)->save();

        return $group;
    }

    /**
     * Deleting a group removes everything that belongs to it.
     *
     * Section 19: four collections are touched, so the whole removal runs in a
     * single MongoDB transaction - a half-deleted group can never be observed.
     */
    public function delete(Group $group): void
    {
        $groupId = $group->stringId();

        DB::transaction(function () use ($group, $groupId): void {
            Expense::where('group_id', $groupId)->delete();
            Settlement::where('group_id', $groupId)->delete();
            GroupBalance::where('group_id', $groupId)->delete();
            $group->delete();
        });

        Log::info('Group deleted.', ['group_id' => $groupId]);
    }

    /**
     * @return Collection<int, User>
     */
    public function members(Group $group): Collection
    {
        $memberIds = array_values((array) $group->member_ids);

        // No ObjectId objects are constructed by hand: the query builder
        // converts 24-character hex strings to ObjectId automatically for the
        // _id column (MongoDB\Laravel\Query\Builder::convertKey(), applied in
        // compileWheres() for both where() and whereIn()).
        return User::whereIn('_id', $memberIds)->orderBy('name')->get();
    }

    public function addMember(Group $group, string $userId): User
    {
        $user = User::find($userId);

        if ($user === null) {
            throw new UserNotFoundException();
        }

        if ($group->hasMember($userId)) {
            throw ValidationException::withMessages([
                'user_id' => ['The selected user is already a member of this group.'],
            ]);
        }

        // Atomic array append with $addToSet semantics (third argument = only if
        // absent). No read-modify-write of the whole document, so two members
        // added at the same moment cannot overwrite one another. A single
        // document update needs no transaction.
        Group::where('_id', $group->getKey())->push('member_ids', $userId, true);

        $group->refresh();

        Log::info('Group member added.', ['group_id' => $group->stringId(), 'user_id' => $userId]);

        return $user;
    }

    public function removeMember(Group $group, string $userId): void
    {
        if (! preg_match('/^[a-f0-9]{24}$/i', $userId)) {
            throw ValidationException::withMessages([
                'user_id' => ['The user id must be a valid 24-character MongoDB id.'],
            ]);
        }

        if ($group->isOwnedBy($userId)) {
            throw new UnauthorizedGroupAccessException(
                'The group owner cannot be removed from the group.'
            );
        }

        if (! $group->hasMember($userId)) {
            throw ValidationException::withMessages([
                'user_id' => ['The selected user is not a member of this group.'],
            ]);
        }

        // Removing a member who still owes or is owed money would silently
        // change everyone else's balances, so it is refused until settled.
        $balance = GroupBalance::where('group_id', $group->stringId())
            ->where('user_id', $userId)
            ->first();

        if ($balance !== null && abs((float) $balance->net_balance) > 0.0) {
            throw new UnauthorizedGroupAccessException(
                'This member still has an outstanding balance in the group. '
                .'Settle it before removing them.'
            );
        }

        // Section 19: the membership array and the balance row change together.
        DB::transaction(function () use ($group, $userId): void {
            Group::where('_id', $group->getKey())->pull('member_ids', $userId);
            GroupBalance::where('group_id', $group->stringId())->where('user_id', $userId)->delete();
        });

        $group->refresh();

        Log::info('Group member removed.', ['group_id' => $group->stringId(), 'user_id' => $userId]);
    }
}