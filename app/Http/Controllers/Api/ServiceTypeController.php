<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceTypeRequest;
use App\Http\Resources\ServiceTypeResource;
use App\Models\ServiceType;
use App\Repositories\ServiceTypeRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceTypeController extends Controller
{
    public function __construct(private readonly ServiceTypeRepository $serviceTypes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $request->integer('company_id') ?: null;

        return ServiceTypeResource::collection($this->serviceTypes->all($companyId, $request->boolean('include_inactive')));
    }

    public function store(ServiceTypeRequest $request): JsonResponse
    {
        try {
            $serviceType = $this->serviceTypes->create($request->validated());
        } catch (QueryException) {
            return response()->json(['message' => 'Service type already exists.'], 409);
        }

        return (new ServiceTypeResource($serviceType))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ServiceType $serviceType): ServiceTypeResource
    {
        return new ServiceTypeResource($serviceType->load('company'));
    }

    public function update(ServiceTypeRequest $request, ServiceType $serviceType): ServiceTypeResource|JsonResponse
    {
        $data = $request->validated();

        if ($data === []) {
            return response()->json(['message' => 'No fields to update.'], 400);
        }

        try {
            $serviceType = $this->serviceTypes->update($serviceType, $data);
        } catch (QueryException) {
            return response()->json(['message' => 'Service type already exists.'], 409);
        }

        return new ServiceTypeResource($serviceType);
    }

    public function destroy(ServiceType $serviceType): ServiceTypeResource
    {
        return new ServiceTypeResource($this->serviceTypes->deactivate($serviceType));
    }
}
