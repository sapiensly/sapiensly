<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Http\Requests\Folder\StoreFolderRequest;
use App\Http\Requests\Folder\UpdateFolderRequest;
use App\Models\Folder;
use App\Services\FolderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function __construct(
        protected FolderService $folderService
    ) {}

    public function index(Request $request)
    {
        $folders = $this->folderService->getFolderTree($request->user());

        return response()->json([
            'folders' => $folders,
        ]);
    }

    public function store(StoreFolderRequest $request): RedirectResponse
    {
        $visibility = Visibility::tryFrom($request->visibility) ?? Visibility::Private;

        $folder = $this->folderService->create(
            user: $request->user(),
            name: $request->name,
            visibility: $visibility,
            parentId: $request->parent_id
        );

        return to_route('documents.index', ['folder' => $folder->id]);
    }

    public function update(UpdateFolderRequest $request, Folder $folder): RedirectResponse
    {
        $this->authorize('update', $folder);

        $visibility = $request->has('visibility')
            ? Visibility::from($request->visibility)
            : null;

        $this->folderService->update(
            folder: $folder,
            name: $request->name,
            parentId: $request->has('parent_id') ? ($request->parent_id ?? '') : null,
            visibility: $visibility,
            user: $request->user()
        );

        return back();
    }

    public function destroy(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorize('delete', $folder);

        $parentId = $folder->parent_id;

        // Move contents to parent by default
        $this->folderService->delete($folder, moveContentsToParent: true);

        return to_route('documents.index', $parentId ? ['folder' => $parentId] : []);
    }
}
