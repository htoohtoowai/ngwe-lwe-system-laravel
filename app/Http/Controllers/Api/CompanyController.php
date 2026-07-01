<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Repositories\CompanyRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyRepository $companies) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CompanyResource::collection($this->companies->all($request->boolean('include_inactive')));
    }

    public function store(CompanyRequest $request): JsonResponse
    {
        try {
            $company = $this->companies->create($request->validated());
        } catch (QueryException) {
            return response()->json(['message' => 'Company already exists.'], 409);
        }

        return (new CompanyResource($company))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company);
    }

    public function update(CompanyRequest $request, Company $company): CompanyResource|JsonResponse
    {
        $data = $request->validated();

        if ($data === []) {
            return response()->json(['message' => 'No fields to update.'], 400);
        }

        try {
            $company = $this->companies->update($company, $data);
        } catch (QueryException) {
            return response()->json(['message' => 'Company already exists.'], 409);
        }

        return new CompanyResource($company);
    }

    public function destroy(Company $company): CompanyResource
    {
        return new CompanyResource($this->companies->deactivate($company));
    }
}
