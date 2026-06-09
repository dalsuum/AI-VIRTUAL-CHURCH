<?php

namespace App\Http\Controllers;

use App\Models\Testimony;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestimonyController extends Controller
{
    /** The public wall: approved testimonies only, newest first. */
    public function index(): JsonResponse
    {
        $testimonies = Testimony::where('approved', true)
            ->latest()
            ->limit(50)
            ->get(['id', 'content', 'created_at']);

        return response()->json(['testimonies' => $testimonies]);
    }

    /**
     * A worshipper shares their own testimony. It is held unapproved until a
     * moderator clears it, so nothing user-written reaches the public wall unseen.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $testimony = Testimony::create([
            'user_id'  => $request->user()->id,
            'content'  => $data['content'],
            'source'   => 'user_submitted',
            'approved' => false,
        ]);

        return response()->json([
            'id'      => $testimony->id,
            'pending' => true,
            'message' => 'Thank you for sharing. Your testimony will appear once reviewed.',
        ], 201);
    }
}
