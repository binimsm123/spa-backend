<?php

namespace Database\Seeders;

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Locations
        $locations = collect([
            ['region' => 'Bagmati', 'name' => 'Thamel', 'city' => 'Kathmandu', 'slug' => 'thamel', 'latitude' => 27.7154, 'longitude' => 85.3123],
            ['region' => 'Bagmati', 'name' => 'Patan', 'city' => 'Lalitpur', 'slug' => 'patan', 'latitude' => 27.6787, 'longitude' => 85.3162],
            ['region' => 'Bagmati', 'name' => 'Bhaktapur', 'city' => 'Bhaktapur', 'slug' => 'bhaktapur', 'latitude' => 27.6710, 'longitude' => 85.4298],
        ])->map(fn (array $attrs) => Location::query()->create($attrs + ['is_serviceable' => true]));

        // Categories
        $categories = collect([
            ['name' => 'Massage', 'slug' => 'massage', 'sort_order' => 1],
            ['name' => 'Facial', 'slug' => 'facial', 'sort_order' => 2],
            ['name' => 'Body Treatment', 'slug' => 'body-treatment', 'sort_order' => 3],
            ['name' => 'Hair & Beauty', 'slug' => 'hair-beauty', 'sort_order' => 4],
        ])->map(fn (array $attrs) => Category::query()->create($attrs + ['is_active' => true]));

        // Demo owner
        $owner = User::query()->create([
            'mobile' => '9811111111',
            'display_name' => 'Demo Owner',
            'password' => Hash::make('OwnerPass!1'),
            'is_active' => true,
            'mobile_verified_at' => now(),
        ]);
        $owner->assignRole('owner');

        $businesses = [
            ['name' => 'Serenity Spa — Thamel', 'slug' => 'serenity-spa-thamel', 'branch' => 'Thamel Flagship', 'location' => $locations[0]],
            ['name' => 'Serenity Spa — Patan', 'slug' => 'serenity-spa-patan', 'branch' => 'Patan', 'location' => $locations[1]],
        ];

        foreach ($businesses as $index => $entry) {
            $business = Business::query()->create([
                'name' => $entry['name'],
                'slug' => $entry['slug'],
                'search_name' => strtolower($entry['name']),
                'about' => 'A calm escape in '.$entry['branch'].'. Licensed therapists, premium products.',
                'is_verified' => true,
                'is_insured' => true,
                'is_online' => true,
                'status' => 'active',
                'phone_number' => '977-1-55500'.$index,
            ]);

            $branch = BusinessLocation::query()->create([
                'business_id' => $business->getKey(),
                'location_id' => $entry['location']->getKey(),
                'branch_name' => $entry['branch'],
                'address' => 'Street 12, '.$entry['branch'],
                'city' => $entry['location']->city,
                'latitude' => $entry['location']->latitude,
                'longitude' => $entry['location']->longitude,
                'timezone' => 'Asia/Kathmandu',
                'is_active' => true,
            ]);

            foreach ([1, 2, 3, 4, 5, 6] as $weekday) {
                BusinessHour::query()->create([
                    'business_location_id' => $branch->getKey(),
                    'weekday' => $weekday,
                    'opens_at' => '10:00',
                    'closes_at' => '19:00',
                    'is_closed' => false,
                ]);
            }
            BusinessHour::query()->create([
                'business_location_id' => $branch->getKey(),
                'weekday' => 0,
                'opens_at' => null,
                'closes_at' => null,
                'is_closed' => true,
            ]);

            // Owner membership in both branches
            \App\Models\BusinessUser::query()->create([
                'business_id' => $business->getKey(),
                'user_id' => $owner->getKey(),
                'role' => BusinessUserRole::Owner->value,
                'is_active' => true,
            ]);

            $services = [
                ['name' => 'Aromatherapy Massage', 'slug' => 'aromatherapy-massage', 'category' => 0, 'price' => 350000, 'duration' => 60, 'max_people' => 2],
                ['name' => 'Deep Tissue Massage', 'slug' => 'deep-tissue-massage', 'category' => 0, 'price' => 400000, 'duration' => 90, 'max_people' => 1],
                ['name' => 'Hydrating Facial', 'slug' => 'hydrating-facial', 'category' => 1, 'price' => 280000, 'duration' => 45, 'max_people' => 1],
            ];

            foreach ($services as $serviceData) {
                $service = Service::query()->create([
                    'business_id' => $business->getKey(),
                    'category_id' => $categories[$serviceData['category']]->getKey(),
                    'default_location_id' => $branch->getKey(),
                    'name' => $serviceData['name'],
                    'slug' => $serviceData['slug'],
                    'search_name' => strtolower($serviceData['name']),
                    'description' => $serviceData['name'].' by certified therapists at '.$entry['branch'].'.',
                    'duration_minutes' => $serviceData['duration'],
                    'max_people' => $serviceData['max_people'],
                    'is_bookable' => true,
                    'status' => 'active',
                ]);

                ServicePrice::query()->create([
                    'service_id' => $service->getKey(),
                    'business_location_id' => $branch->getKey(),
                    'price_minor' => $serviceData['price'],
                    'currency' => 'NPR',
                    'is_current' => true,
                ]);
            }

            // One demo offer per business
            \App\Models\Offer::query()->create([
                'business_id' => $business->getKey(),
                'created_by_user_id' => $owner->getKey(),
                'code' => 'WELCOME'.$index + 10,
                'title' => 'Welcome — 10% off',
                'description' => '10% off your first booking.',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'minimum_booking_amount_minor' => 100000,
                'currency' => 'NPR',
                'starts_at' => now()->subDay(),
                'expires_at' => now()->addMonths(3),
                'status' => 'active',
                'per_user_limit' => 1,
                'total_usage_limit' => 100,
            ]);

            // Reward configuration (single active)
            if ($index === 0) {
                \App\Models\RewardConfiguration::query()->create([
                    'points_per_rupee' => 1,
                    'basis' => 'subtotal',
                    'is_active' => true,
                ]);
            }
        }
    }
}
