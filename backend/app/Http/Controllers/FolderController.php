<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\Folder;
use App\Services\HistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sidebar folders CRUD — every route owner-scoped (forUser → 404 on miss). Deleting a
 * folder un-files its sessions (nullOnDelete); it never deletes sessions.
 */
class FolderController extends Controller
{
    public function __construct(private readonly HistoryService $history) {}

    private function findOwned(Request $request, int $id): Folder
    {
        return Folder::forUser((int) $request->user()->id)
            ->whereKey($id)
            ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));
    }

    public function index(Request $request): JsonResponse
    {
        $folders = Folder::forUser((int) $request->user()->id)
            ->withCount('sessions')
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'name', 'color', 'position']);

        return response()->json(['folders' => $folders]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $userId = (int) $request->user()->id;
        $folder = Folder::create([
            'user_id'  => $userId,
            'name'     => $data['name'],
            'color'    => $data['color'] ?? null,
            'position' => (int) Folder::forUser($userId)->max('position') + 1,
        ]);

        return response()->json(['folder' => $folder], Response::HTTP_CREATED);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:80'],
            'color'    => ['sometimes', 'nullable', 'string', 'max:16'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
        $folder->update($data);

        return response()->json(['folder' => $folder]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        $folder->delete();                                   // nullOnDelete un-files sessions
        $this->history->forgetListCache((int) $request->user()->id);

        return response()->json(['ok' => true]);
    }

    /** Move a session into this folder (or out when folder is null). */
    public function assign(Request $request, string $sessionId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $data = $request->validate(['folder_id' => ['nullable', 'integer']]);

        $session = ChatSession::forUser($userId)->whereKey($sessionId)
            ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));

        if (! empty($data['folder_id'])) {
            // Only allow filing into a folder the caller owns.
            Folder::forUser($userId)->whereKey($data['folder_id'])
                ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));
        }

        $session->update(['folder_id' => $data['folder_id'] ?? null]);
        $this->history->forgetListCache($userId);

        return response()->json(['session' => $session->only(['id', 'folder_id'])]);
    }
}
