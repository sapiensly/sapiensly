<?php

namespace App\Services\Admin;

use App\Enums\MembershipRole;
use App\Models\Agent;
use App\Models\AgentTeam;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\CloudProvider;
use App\Models\Document;
use App\Models\Flow;
use App\Models\Folder;
use App\Models\Integration;
use App\Models\KnowledgeBase;
use App\Models\OrganizationMembership;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an admin-managed user. Two branches per the decision in the
 * admin-v2 handoff:
 *
 *   - The user belongs to an organization → reassign every resource they
 *     own to that organization's owner, detach memberships, then delete the
 *     user record. No content is lost.
 *   - The user has no organization → there's no one to inherit, so cascade-
 *     delete every resource they own alongside the user.
 *
 * Resources with a `user_id` FK covered here: Document, Chatbot, Agent,
 * AgentTeam, KnowledgeBase, Flow, Integration, CloudProvider, Channel,
 * Folder, Tool. Adding a new user-owned model? Extend the $ownedModels list.
 */
class UserDeletionService
{
    /**
     * Ordered list of models that carry a `user_id` column. When a user is
     * deleted we either retarget `user_id` to the inheriting owner or delete
     * the rows outright.
     *
     * @var array<int, class-string<Model>>
     */
    protected array $ownedModels = [
        Document::class,
        Chatbot::class,
        Agent::class,
        AgentTeam::class,
        KnowledgeBase::class,
        Flow::class,
        Integration::class,
        CloudProvider::class,
        Channel::class,
        Folder::class,
        Tool::class,
    ];

    /**
     * Delete a user. Returns the branch that ran so callers can report it.
     *
     * @return 'transferred' | 'cascaded'
     */
    public function delete(User $user): string
    {
        return DB::transaction(function () use ($user) {
            if ($user->organization_id !== null) {
                $inheritor = $this->findOrganizationOwner($user);

                // If the user was the sole owner and no other owner exists,
                // we have no one to transfer to — fall through to cascade.
                if ($inheritor !== null) {
                    $this->transferOwnership($user, $inheritor);
                    $this->detachMemberships($user);
                    $user->forceDelete();

                    return 'transferred';
                }
            }

            $this->cascadeOwnedResources($user);
            $this->detachMemberships($user);
            $user->forceDelete();

            return 'cascaded';
        });
    }

    /**
     * Return the user who should inherit the departing user's resources.
     * The org's `Owner` membership — excluding the user being deleted — wins.
     * Returns null when no such user exists (sole-owner edge case).
     */
    protected function findOrganizationOwner(User $user): ?User
    {
        $ownerMembership = OrganizationMembership::query()
            ->where('organization_id', $user->organization_id)
            ->where('role', MembershipRole::Owner)
            ->where('user_id', '!=', $user->id)
            ->with('user')
            ->first();

        return $ownerMembership?->user;
    }

    /**
     * Point every `user_id` on the covered models at the new owner.
     */
    protected function transferOwnership(User $from, User $to): void
    {
        foreach ($this->ownedModels as $model) {
            $model::query()
                ->where('user_id', $from->id)
                ->update(['user_id' => $to->id]);
        }
    }

    /**
     * Delete every row the user owns. Uses the Eloquent facade so models
     * with SoftDeletes / HasPrefixedUlid / observers get their hooks called.
     */
    protected function cascadeOwnedResources(User $user): void
    {
        foreach ($this->ownedModels as $model) {
            $model::query()->where('user_id', $user->id)->get()
                ->each(function (Model $row) {
                    // Force delete so soft-deletable rows are actually removed;
                    // the user is being hard-deleted, so their data goes too.
                    if (method_exists($row, 'forceDelete')) {
                        $row->forceDelete();
                    } else {
                        $row->delete();
                    }
                });
        }
    }

    protected function detachMemberships(User $user): void
    {
        $user->memberships()->delete();
    }
}
