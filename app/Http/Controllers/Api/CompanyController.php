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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

    public function uploadLogo(Request $request, Company $company): CompanyResource|JsonResponse
    {
        $request->validate(['logo' => ['required', 'file', 'image', 'max:2048']]);
        /** @var UploadedFile $logo */
        $logo = $request->file('logo');
        $path = $logo->store('company-logos', 'public');

        if ($company->logo_path !== null && Storage::disk('public')->exists($company->logo_path)) {
            Storage::disk('public')->delete($company->logo_path);
        }

        $company->update(['logo_path' => $path]);

        return new CompanyResource($company->refresh());
    }

    public function logo(Company $company): mixed
    {
        abort_unless($company->logo_path !== null, 404, 'Logo not found.');

        if (Storage::disk('public')->exists($company->logo_path)) {
            return Storage::disk('public')->response($company->logo_path);
        }

        $seedLogo = database_path('seeders/company-logos/'.basename($company->logo_path));
        abort_unless(
            str_starts_with($company->logo_path, 'company-logos/') && is_file($seedLogo),
            404,
            'Logo not found.',
        );

        return response()->file($seedLogo);
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
