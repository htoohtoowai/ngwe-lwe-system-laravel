<?php

namespace App\Repositories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Collection;

class CompanyRepository
{
    public function all(bool $includeInactive = false): Collection
    {
        return Company::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    public function active(): Collection
    {
        return Company::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?Company
    {
        return Company::query()->find($id);
    }

    /**
     * @param  array{name:string,logo_path?:string|null,category:string,is_active?:bool}  $data
     */
    public function create(array $data): Company
    {
        return Company::query()->create($data);
    }

    /**
     * @param  array{name?:string,logo_path?:string|null,category?:string,is_active?:bool}  $data
     */
    public function update(Company $company, array $data): Company
    {
        $company->fill($data);
        $company->save();

        return $company->refresh();
    }

    public function deactivate(Company $company): Company
    {
        $company->is_active = false;
        $company->save();

        return $company->refresh();
    }
}
