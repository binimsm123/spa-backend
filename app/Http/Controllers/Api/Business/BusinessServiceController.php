<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpsertServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Business;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessServiceController extends Controller
{
    use ApiResponse;

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business);

        $services = $business->services()->with('category')->get();

        // Attach the primary branch price for display.
        $primary = $business->locations()->first();
        $prices = $primary
            ? ServicePrice::query()->where('business_location_id', $primary->getKey())->where('is_current', true)->get()->keyBy('service_id')
            : collect();

        $services->each(function (Service $service) use ($prices): void {
            $price = $prices->get($service->getKey());

            if ($price !== null) {
                $service->price = $price->price_minor;
                $service->currency = $price->currency;
            }
        });

        return $this->success(['items' => ServiceResource::collection($services)->resolve()]);
    }

    public function store(UpsertServiceRequest $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);

        $data = $request->validated();
        $priceMinor = (int) ($data['price_minor'] ?? 0);
        $currency = $data['currency'] ?? 'NPR';
        unset($data['price_minor'], $data['currency']);

        $service = DB::transaction(function () use ($business, $data, $priceMinor, $currency): Service {
            /** @var Service $service */
            $service = $business->services()->create(
                $data + ['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)), 'search_name' => strtolower($data['name'])],
            );

            $primary = $business->locations()->first();

            if ($primary !== null) {
                ServicePrice::query()->create([
                    'service_id' => $service->getKey(),
                    'business_location_id' => $primary->getKey(),
                    'price_minor' => $priceMinor,
                    'currency' => $currency,
                    'is_current' => true,
                ]);
            }

            return $service;
        });

        return $this->resource(ServiceResource::make($service->load('category')), 'Service created.', 201);
    }

    public function update(UpsertServiceRequest $request, Business $business, Service $service): JsonResponse
    {
        $this->assertMember($request, $business, ['owner', 'manager']);
        abort_unless($service->business_id === $business->getKey(), 404);

        $data = $request->validated();
        $priceMinor = $data['price_minor'] ?? null;
        $currency = $data['currency'] ?? 'NPR';
        unset($data['price_minor'], $data['currency']);

        DB::transaction(function () use ($service, $data, $priceMinor, $currency, $business): void {
            $service->fill($data)->save();

            if ($priceMinor !== null) {
                $this->insertNewPrice($service, $business, (int) $priceMinor, $currency);
            }
        });

        return $this->resource(ServiceResource::make($service->refresh()->load('category')), 'Service updated.');
    }

    /**
     * Historical price rows are preserved: close the current row, insert a new one.
     * Existing bookings keep their own snapshots, so receipts never change.
     */
    private function insertNewPrice(Service $service, Business $business, int $priceMinor, string $currency): void
    {
        $primary = $business->locations()->first();

        if ($primary === null) {
            return;
        }

        ServicePrice::query()
            ->where('service_id', $service->getKey())
            ->where('business_location_id', $primary->getKey())
            ->where('is_current', true)
            ->update(['is_current' => false, 'ends_at' => now()]);

        ServicePrice::query()->create([
            'service_id' => $service->getKey(),
            'business_location_id' => $primary->getKey(),
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'is_current' => true,
            'starts_at' => now(),
        ]);
    }

    private function assertMember(Request $request, Business $business, ?array $roles = null): void
    {
        $user = $request->user();

        abort_unless($user->belongsToBusiness($business), 403, 'You do not manage this business.');

        if ($roles !== null && ! $user->hasBusinessRole($business, ...array_map(
            fn (string $r) => \App\Enums\BusinessUserRole::from($r),
            $roles,
        ))) {
            abort(403, 'Your role cannot perform this action.');
        }
    }
}
