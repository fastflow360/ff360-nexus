<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 100), 1), 100);

        return response()->json(
            Tenant::query()
                ->orderBy('name')
                ->paginate($perPage)
                ->withQueryString(),
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = Tenant::query()->create($request->validated());

        return response()->json($tenant, Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json($tenant);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $tenant->update($request->validated());

        return response()->json($tenant->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tenant $tenant): Response
    {
        $tenant->delete();

        return response()->noContent();
    }
}
