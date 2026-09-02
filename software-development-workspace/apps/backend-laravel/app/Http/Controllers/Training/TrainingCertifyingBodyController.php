<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Training\TrainingCertifyingBody;
use App\Models\Training\TrainingItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A small, global lookup list — no organisation scoping (see the model),
 * no dedicated policy. Reading it only requires being signed in, the same
 * bar as the rest of the training catalogue; adding to it reuses the
 * training-catalogue "create" ability rather than a new permission for a
 * five-row reference table.
 */
class TrainingCertifyingBodyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TrainingCertifyingBody::query()->orderBy('name');
        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get(['id', 'uuid', 'name', 'is_active'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TrainingItem::class);

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:training_certifying_bodies,name',
        ]);

        $body = TrainingCertifyingBody::create([
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
            'is_active' => true,
        ]);

        return response()->json(['data' => $body], 201);
    }
}
