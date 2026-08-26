<?php

namespace App\Http\Controllers;

use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Repositories\CashFloatRepository;
use App\Repositories\TransactionRepository;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TellerController extends Controller
{
    public function __construct(
        private readonly CashFloatRepository $floats,
        private readonly TransactionRepository $transactions,
    ) {}

    public function counter(Request $request): Response
    {
        return Inertia::render('teller/Counter', [
            'float' => $this->floatProp($request),
            'denominations' => $this->denominationRows($request),
            'today' => $this->today($request),
            'recent' => $this->recent($request),
        ]);
    }

    public function floatPage(Request $request): Response
    {
        return $this->floatResponse($request, 'current');
    }

    public function floatReceive(Request $request): Response
    {
        return $this->floatResponse($request, 'receive');
    }

    public function floatReturn(Request $request): Response
    {
        return $this->floatResponse($request, 'return');
    }

    public function floatHistory(Request $request): Response
    {
        return $this->floatResponse($request, 'history');
    }

    private function floatResponse(Request $request, string $view): Response
    {
        return Inertia::render('teller/Float', [
            'view' => $view,
            'float' => $this->floatProp($request),
            'floats' => $this->floatRows($request),
            'floatIssues' => $this->floatIssueRows($request),
            'notes' => $this->notes(),
            'issued' => $this->issued($request),
            'onHand' => $this->onHand($request),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function notes(): array
    {
        $notes = Money::supportedDenominations();
        rsort($notes);

        return $notes;
    }

    private function selectedFloat(Request $request): ?CashFloatAssignment
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $floats = $this->floats->list($user->id);

        return $floats->firstWhere('status', 'ACTIVE')
            ?? $floats->firstWhere('status', 'PENDING_RECEIPT')
            ?? $floats->firstWhere('status', 'PENDING_RECONCILIATION')
            ?? $floats->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function floatProp(Request $request): ?array
    {
        $float = $this->selectedFloat($request);

        if ($float === null) {
            return null;
        }

        return [
            'id' => $float->id,
            'status' => $float->status,
            'current_balance' => $float->current_balance ?? '0.00',
            'issued_amount' => $float->total_amount,
            'total_amount' => $float->total_amount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function floatRows(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return $this->floats->list($user->id)
            ->take(50)
            ->map(fn (CashFloatAssignment $float): array => [
                'id' => $float->id,
                'status' => $float->status,
                'current_balance' => $float->current_balance ?? '0.00',
                'issued_amount' => $float->total_amount,
                'total_amount' => $float->total_amount,
                'closing_total' => $float->closing_total,
                'issued_by_name' => $float->issuer?->full_name,
                'created_at' => $float->created_at?->toISOString(),
                'received_at' => $float->received_at?->toISOString(),
                'closed_at' => $float->closed_at?->toISOString(),
                'note' => $float->note,
                'denominations' => $float->denominations
                    ->map(fn ($row): array => [
                        'denomination' => (int) $row->denomination,
                        'quantity' => (int) $row->quantity,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    private function floatIssueRows(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return $this->floats->issuesForEmployee($user->id)
            ->where('issue_type', 'ADDITIONAL')
            ->take(100)
            ->map(fn ($issue): array => [
                'id' => $issue->id,
                'float_id' => $issue->float_id,
                'status' => $issue->status,
                'amount' => $issue->amount,
                'issued_by_name' => $issue->issuer?->full_name ?? $issue->issuer?->username,
                'created_at' => $issue->created_at?->toISOString(),
                'received_at' => $issue->received_at?->toISOString(),
                'rejected_at' => $issue->rejected_at?->toISOString(),
                'note' => $issue->note,
                'denominations' => collect($issue->denominations_json ?? [])
                    ->map(fn ($quantity, $denomination): array => [
                        'denomination' => (int) $denomination,
                        'quantity' => (int) $quantity,
                    ])
                    ->filter(fn (array $row): bool => $row['quantity'] > 0)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function issued(Request $request): array
    {
        $float = $this->selectedFloat($request);

        if ($float === null) {
            return [];
        }

        return $float->denominations
            ->mapWithKeys(fn ($row): array => [(int) $row->denomination => (int) $row->quantity])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function onHand(Request $request): array
    {
        $float = $this->selectedFloat($request);

        if ($float === null) {
            return [];
        }

        return $this->floats->getDenominationBalance($float->id);
    }

    /**
     * @return array<int, array{note:int,quantity:int}>
     */
    private function denominationRows(Request $request): array
    {
        $stock = $this->onHand($request) ?: $this->issued($request);

        return collect($this->notes())
            ->map(fn (int $note): array => [
                'note' => $note,
                'quantity' => (int) ($stock[$note] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recent(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return $this->transactions->recentForUser($user, 20)
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->transaction_type,
                'amount' => $transaction->amount,
                'fee_amount' => $transaction->customer_fee ?? '0.00',
                'status' => $transaction->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{cash_in:string,cash_out:string,send_money:string,receive_money:string,transfer:string,exchange:string,count:int}
     */
    private function today(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [
                'cash_in' => '0.00',
                'cash_out' => '0.00',
                'send_money' => '0.00',
                'receive_money' => '0.00',
                'transfer' => '0.00',
                'exchange' => '0.00',
                'count' => 0,
            ];
        }

        $today = now()->toDateString();
        $rows = $this->transactions->recentForUser($user, 1000)
            ->filter(fn (Transaction $transaction): bool => $transaction->created_at?->toDateString() === $today);

        return [
            'cash_in' => Money::normalize($rows->where('transaction_type', 'cash_in')->sum(fn ($row) => (float) $row->amount)),
            'cash_out' => Money::normalize($rows->where('transaction_type', 'cash_out')->sum(fn ($row) => (float) $row->amount)),
            'send_money' => Money::normalize($rows->where('transaction_type', 'send_money')->sum(fn ($row) => (float) $row->amount)),
            'receive_money' => Money::normalize($rows->where('transaction_type', 'receive_money')->sum(fn ($row) => (float) $row->amount)),
            'transfer' => Money::normalize($rows->where('transaction_type', 'transfer')->sum(fn ($row) => (float) $row->amount)),
            'exchange' => Money::normalize($rows->where('transaction_type', 'exchange')->sum(fn ($row) => (float) $row->amount)),
            'count' => $rows->count(),
        ];
    }


}
