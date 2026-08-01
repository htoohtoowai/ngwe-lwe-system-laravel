<?php

namespace App\Repositories;

use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Collection;

class ServiceTypeRepository
{
    public function all(?int $companyId = null, bool $includeInactive = false): Collection
    {
        return ServiceType::query()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when(! $includeInactive, fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true)))
            ->with('company')
            ->orderBy('name')
            ->get();
    }

    public function activeForCompany(int $companyId): Collection
    {
        return ServiceType::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?ServiceType
    {
        return ServiceType::query()->find($id);
    }

    /**
     * @param  array{company_id:int,name:string,operation:string,is_active?:bool}  $data
     */
    public function create(array $data): ServiceType
    {
        return ServiceType::query()->create($data)->load('company');
    }

    /**
     * @param  array{company_id?:int,name?:string,operation?:string,is_active?:bool}  $data
     */
    public function update(ServiceType $serviceType, array $data): ServiceType
    {
        $serviceType->fill($data);
        $serviceType->save();

        return $serviceType->refresh()->load('company');
    }

    public function deactivate(ServiceType $serviceType): ServiceType
    {
        $serviceType->is_active = false;
        $serviceType->save();

        return $serviceType->refresh()->load('company');
    }
}
