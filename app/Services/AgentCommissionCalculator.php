<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\AgentCommissionDirection;
use App\Models\Account;
use App\Models\AgentCommissionTier;
use App\Repositories\AgentCommissionTierRepository;
use App\Support\Money;

class AgentCommissionCalculator
{
    public function __construct(
        private readonly AgentCommissionTierRepository $tiers,
        private readonly TierValueCalculator $values,
    ) {}

    /**
     * Resolve commission from the actual principal movement of the agent account.
     * Positive principal delta means money entered the account (IN); negative means money left (OUT).
     *
     * @return array{amount:string,tier:?AgentCommissionTier,direction:?AgentCommissionDirection,configured_value:string}
     */
    public function resolveForMovement(Account $account, float|string $amount, float|string $principalDelta): array
    {
        $accountType = $account->account_type instanceof AccountType
            ? $account->account_type
            : AccountType::tryFrom((string) $account->account_type);

        if (! $account->is_agent || $accountType !== AccountType::Pay || $account->company_id === null) {
            return $this->zero();
        }

        $delta = (float) $principalDelta;
        if ($delta === 0.0) {
            return $this->zero();
        }

        $direction = $delta > 0
            ? AgentCommissionDirection::In
            : AgentCommissionDirection::Out;

        $tier = $this->tiers->findForCompany((int) $account->company_id, $amount);
        if ($tier === null) {
            return $this->zero($direction);
        }

        $configuredValue = $direction === AgentCommissionDirection::In
            ? $tier->in_commission_value
            : $tier->out_commission_value;

        $commission = $this->values->calculate(
            $amount,
            $configuredValue,
            $tier->commission_type,
        );

        return [
            'amount' => Money::normalize($commission),
            'tier' => $tier,
            'direction' => $direction,
            'configured_value' => (string) $configuredValue,
        ];
    }

    /** @return array{amount:string,tier:null,direction:?AgentCommissionDirection,configured_value:string} */
    private function zero(?AgentCommissionDirection $direction = null): array
    {
        return [
            'amount' => Money::normalize(0),
            'tier' => null,
            'direction' => $direction,
            'configured_value' => '0.0000',
        ];
    }
}
