<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeRateRequest;
use App\Http\Resources\ExchangeRateResource;
use App\Models\ExchangeRate;
use App\Repositories\ExchangeRateRepository;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExchangeRateController extends Controller
{
    public function __construct(private readonly ExchangeRateRepository $rates) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ExchangeRateResource::collection(
            $this->rates->recent($request->integer('limit') ?: 50)
        );
    }

    public function latest(Request $request): JsonResponse
    {
        $base = strtoupper(trim((string) ($request->query('base') ?? 'THB')));
        $quote = strtoupper(trim((string) ($request->query('quote') ?? 'MMK')));

        $rate = $this->rates->getLatest($base, $quote);

        if ($rate === null) {
            return response()->json([
                'data' => [
                    'base_currency' => $base,
                    'quote_currency' => $quote,
                    'base_amount' => Money::normalize(1),
                    'buy_rate' => Money::normalize(0, 4),
                    'sell_rate' => Money::normalize(0, 4),
                ],
            ]);
        }

        return (new ExchangeRateResource($rate))->response();
    }

    public function show(ExchangeRate $exchangeRate): ExchangeRateResource
    {
        return new ExchangeRateResource($exchangeRate);
    }

    public function store(ExchangeRateRequest $request): JsonResponse
    {
        $rate = $this->rates->create($this->normalizePayload($request->validated()));

        return (new ExchangeRateResource($rate))->response()->setStatusCode(201);
    }

    public function update(ExchangeRateRequest $request, ExchangeRate $exchangeRate): ExchangeRateResource|JsonResponse
    {
        $data = $request->validated();

        if ($data === []) {
            return response()->json(['message' => 'No fields to update.'], 400);
        }

        return new ExchangeRateResource(
            $this->rates->update($exchangeRate, $this->normalizePayload($data))
        );
    }

    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        $this->rates->delete($exchangeRate);

        return response()->json(['message' => 'Rate deleted']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data): array
    {
        foreach (['base_amount', 'buy_rate', 'sell_rate'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = Money::normalize($data[$field], $field === 'base_amount' ? 2 : 4);
            }
        }

        return $data;
    }
}
