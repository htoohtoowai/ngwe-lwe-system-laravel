<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

class CompanyLogoController extends Controller
{
    public function __invoke(Company $company): mixed
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
}
