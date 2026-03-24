<?php

namespace App\Services;

use App\Enums\Visibility;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class FolderService
{
    /**
     * Create a new folder.
     */
    public function create(
        User $user,
        string $name,
        Visibility $visibility = Visibility::Private,
        ?string $parentId = null
    ): Folder {
        // Validate parent folder if provided
        if ($parentId) {
            $parent = Folder::find($parentId);
            if (! $parent || ! $parent->isVisibleTo($user)) {
                throw new \RuntimeException('Parent folder not found or not accessible.');
            }
        }

        return Folder::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'parent_id' => $parentId,
            'name' => $name,
            'visibility' => $visibility,
        ]);
    }

    /**
     * Update a folder.
     */
    public function update(
        Folder $folder,
        ?string $name = null,
        ?string $parentId = null,
        ?Visibility $visibility = null,
        ?User $user = null
    ): Folder {
        $data = [];

        if ($name !== null) {
            $data['name'] = $name;
        }

        // Handle parent change with circular reference check
        if ($parentId !== null) {
            if ($parentId === '') {
                // Move to root
                $data['parent_id'] = null;
            } else {
                $newParent = Folder::find($parentId);

                if (! $newParent) {
                    throw new \RuntimeException('Parent folder not found.');
                }

                // Prevent moving folder into itself or its descendants
                if ($newParent->id === $folder->id || $newParent->isDescendantOf($folder)) {
                    throw new \RuntimeException('Cannot move folder into itself or its descendants.');
                }

                $data['parent_id'] = $parentId;
            }
        }

        // Handle visibility change
        if ($visibility !== null && $user !== null) {
            $organizationId = null;

            if ($visibility === Visibility::Organization) {
                if (! $user->organization_id) {
                    throw new \RuntimeException('User must belong to an organization to share folders.');
                }
                $organizationId = $user->organization_id;
            }

            $data['visibility'] = $visibility;
            $data['organization_id'] = $organizationId;
        }

        if (! empty($data)) {
            $folder->update($data);
        }

        return $folder->fresh();
    }

    /**
     * Delete a folder.
     *
     * @param  bool  $moveContentsToParent  If true, move contents to parent folder. If false, delete all contents.
     */
    public function delete(Folder $folder, bool $moveContentsToParent = true): void
    {
        if ($moveContentsToParent) {
            // Move documents to parent folder
            $folder->documents()->update(['folder_id' => $folder->parent_id]);

            // Move child folders to parent folder
            $folder->children()->update(['parent_id' => $folder->parent_id]);
        } else {
            // Delete all documents in this folder
            $documentService = app(DocumentService::class);
            foreach ($folder->documents as $document) {
                $documentService->delete($document);
            }

            // Recursively delete child folders
            foreach ($folder->children as $child) {
                $this->delete($child, false);
            }
        }

        // Soft delete the folder
        $folder->delete();
    }

    /**
     * Get folder tree for user's current account context.
     *
     * @return Collection<Folder>
     */
    public function getFolderTree(User $user): Collection
    {
        return Folder::forAccountContext($user)
            ->root()
            ->with(['children' => function ($query) use ($user) {
                $query->forAccountContext($user)
                    ->with(['children' => function ($q) use ($user) {
                        $q->forAccountContext($user);
                    }]);
            }])
            ->orderBy('name')
            ->get();
    }

    /**
     * Get flat list of all folders for user's current account context.
     *
     * @return Collection<Folder>
     */
    public function getAllFolders(User $user): Collection
    {
        return Folder::forAccountContext($user)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get folders grouped by ownership (My Folders / Organization Folders).
     *
     * Fully isolated by account context:
     * - Personal: only user's own folders with no organization.
     * - Business: user's own folders in this org + shared org folders from others.
     *
     * @return array{my: Collection<Folder>, organization: Collection<Folder>}
     */
    public function getGroupedFolders(User $user): array
    {
        if ($user->organization_id === null) {
            // Personal context: only user's own folders with no org
            $myFolders = Folder::where('user_id', $user->id)
                ->whereNull('organization_id')
                ->root()
                ->with(['children' => function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->whereNull('organization_id');
                }])
                ->orderBy('name')
                ->get();

            return [
                'my' => $myFolders,
                'organization' => collect(),
            ];
        }

        // Business context: user's own folders in this org
        $myFolders = Folder::where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->root()
            ->with(['children' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('organization_id', $user->organization_id);
            }])
            ->orderBy('name')
            ->get();

        // Shared org folders from other members
        $organizationFolders = Folder::where('organization_id', $user->organization_id)
            ->where('visibility', Visibility::Organization)
            ->where('user_id', '!=', $user->id)
            ->root()
            ->with(['children' => function ($query) use ($user) {
                $query->where('organization_id', $user->organization_id)
                    ->where('visibility', Visibility::Organization);
            }])
            ->orderBy('name')
            ->get();

        return [
            'my' => $myFolders,
            'organization' => $organizationFolders,
        ];
    }
}
