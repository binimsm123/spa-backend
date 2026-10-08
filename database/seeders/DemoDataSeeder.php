<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Business;
use App\Models\BusinessClosure;
use App\Models\BusinessHour;
use App\Models\BusinessUser;
use App\Models\Category;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\Review;
use App\Models\RewardConfiguration;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'SpaDemo#2026';

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        DB::transaction(function (): void {
            $customers = [];
            foreach (['Aarav Sharma', 'Anisha Shrestha', 'Bikash Thapa', 'Prakriti Karki', 'Suman Gurung', 'Nisha Rai', 'Rohan Adhikari', 'Sneha Maharjan', 'Kiran Tamang', 'Pooja Basnet', 'Sagar Pandey', 'Ritika Joshi'] as $index => $name) {
                $customers[] = $this->user($name, '98200000'.sprintf('%02d', $index + 1), 'customer');
            }

            $owners = [
                $this->user('Sujata Shrestha', '9811111111', 'owner'),
                $this->user('Nabin Gurung', '9811111112', 'owner'),
                $this->user('Meera Karki', '9811111113', 'owner'),
            ];

            $businesses = [
                ['Serenity Spa — Thamel', 'serenity-spa-thamel', 'Kathmandu', 'Mandala Street, Thamel', 27.7154, 85.3123, 'active'],
                ['Serenity Spa — Patan', 'serenity-spa-patan', 'Lalitpur', 'Jhamsikhel Road, Jhamsikhel', 27.6787, 85.3162, 'active'],
                ['Himalayan Bliss Wellness Spa', 'himalayan-bliss-wellness-spa', 'Pokhara', 'Lakeside Road, Baidam', 28.2096, 83.9595, 'active'],
                ['Lotus Ayurveda Retreat', 'lotus-ayurveda-retreat', 'Kathmandu', 'Boudha Road, Boudha', 27.7215, 85.3620, 'active'],
                ['Sattva Day Spa', 'sattva-day-spa', 'Bhaktapur', 'Durbar Square Road, Taumadhi', 27.6710, 85.4298, 'pending'],
                ['Tranquil Touch Spa', 'tranquil-touch-spa', 'Lalitpur', 'Jawalakhel Chowk, Jawalakhel', 27.6720, 85.3130, 'suspended'],
            ];

            foreach ($businesses as $index => [$name, $slug, $city, $address, $latitude, $longitude, $status]) {
                $owner = $owners[intdiv($index, 2)];
                $business = Business::query()->updateOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'about' => 'Relax and recharge at '.$name.'. Enjoy therapeutic massages, botanical facials, Ayurvedic rituals, and attentive care in a peaceful setting.',
                    'phone_number' => '98010000'.sprintf('%02d', $index + 1),
                    'address' => $address, 'city' => $city,
                    'latitude' => $latitude, 'longitude' => $longitude,
                    'timezone' => 'Asia/Kathmandu',
                    'status' => $status, 'is_verified' => $status !== 'pending', 'is_online' => $status === 'active',
                ]);
                $manager = $this->user(['Sunita', 'Prabin', 'Sabina', 'Roshan', 'Anju', 'Dipak'][$index].' '.['Rai', 'Shrestha', 'Gurung', 'Thapa', 'Karki', 'Tamang'][$index], '98300000'.sprintf('%02d', $index + 1), 'manager');
                $staff = $this->user(['Maya', 'Amit', 'Laxmi', 'Sujan', 'Rina', 'Manoj'][$index].' '.['Tamang', 'Rai', 'Thapa', 'Maharjan', 'Gurung', 'Sharma'][$index], '98400000'.sprintf('%02d', $index + 1), 'staff');
                foreach ([$owner, $manager, $staff] as $member) {
                    BusinessUser::query()->updateOrCreate(['business_id' => $business->id, 'user_id' => $member->id], [
                        'role' => $member->id === $owner->id ? 'owner' : ($member->id === $manager->id ? 'manager' : 'staff'),
                        'is_active' => true,
                    ]);
                }
                foreach (range(0, 6) as $weekday) {
                    BusinessHour::query()->updateOrCreate(['business_id' => $business->id, 'weekday' => $weekday], [
                        'opens_at' => '09:00', 'closes_at' => '20:00', 'is_closed' => false,
                    ]);
                }
                BusinessClosure::query()->updateOrCreate(['business_id' => $business->id, 'reason' => 'Demo: annual wellness team training'], [
                    'starts_on' => today()->addDays(21), 'ends_on' => today()->addDays(21),
                ]);

                $services = $this->services($business, $index);
                Offer::query()->updateOrCreate(['code' => 'WELCOME'.($index + 10)], [
                    'business_id' => $business->id, 'created_by_user_id' => $owner->id,
                    'title' => 'First Visit Wellness Offer', 'description' => 'Save 10% on your first spa treatment of NPR 2,000 or more.',
                    'discount_type' => 'percentage', 'discount_value' => 10,
                    'minimum_booking_amount_minor' => 200000, 'max_discount_minor' => 100000, 'currency' => 'NPR',
                    'starts_at' => now()->subDay(), 'expires_at' => now()->addMonths(3),
                    'status' => $status === 'active' ? 'active' : 'paused', 'per_user_limit' => 1, 'total_usage_limit' => 100,
                ]);
                if ($status === 'active') {
                    $this->appointments($business, $staff, $services, $customers, $index);
                }
            }

            RewardConfiguration::query()->firstOrCreate(['is_active' => true], [
                'points_per_rupee' => 1, 'basis' => 'subtotal', 'starts_at' => now(),
            ]);
        });

        $this->command?->info('Spa demo data ready. Demo account password: '.self::PASSWORD);
    }

    private function user(string $name, string $mobile, string $role): User
    {
        $user = User::query()->updateOrCreate(['mobile' => $mobile], [
            'display_name' => $name, 'email' => Str::slug($name).'@spa-demo.test',
            'password' => self::PASSWORD, 'timezone' => 'Asia/Kathmandu',
            'address' => 'Wellness Lane, Ward 3', 'city' => 'Kathmandu',
            'is_active' => true, 'mobile_verified_at' => now(), 'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<int, Service> */
    private function services(Business $business, int $businessIndex): array
    {
        $categories = [];
        foreach (['Massage Therapy', 'Facial & Skin Care', 'Body Rituals', 'Ayurvedic Wellness'] as $index => $name) {
            $categories[] = Category::query()->updateOrCreate(['slug' => $business->slug.'-'.Str::slug($name)], [
                'business_id' => $business->id, 'name' => $name, 'sort_order' => $index + 1, 'is_active' => true,
            ]);
        }

        $menu = [
            ['Aromatherapy Massage', 0, 3500, 60, 2, 'A gentle full-body massage with calming lavender and lemongrass oils.'],
            ['Deep Tissue Massage', 0, 4500, 90, 1, 'Focused muscle work to release tension in the back, shoulders, and legs.'],
            ['Himalayan Hot Stone Massage', 0, 5000, 90, 1, 'Warm basalt stones and flowing massage strokes for deep relaxation.'],
            ['Hydrating Botanical Facial', 1, 2800, 45, 1, 'A cleansing facial with botanical masks and a nourishing face massage.'],
            ['Radiance Vitamin C Facial', 1, 3800, 60, 1, 'Brightening skin care with vitamin C serum and a soothing mask.'],
            ['Himalayan Salt Body Scrub', 2, 3200, 45, 1, 'A mineral-rich salt exfoliation followed by a moisturizing body finish.'],
            ['Ayurvedic Abhyanga Oil Massage', 3, 4200, 60, 1, 'A traditional warm herbal oil massage with rhythmic full-body strokes.'],
            ['Shirodhara Relaxation Ritual', 3, 5500, 60, 1, 'A calming forehead oil ritual with a gentle scalp and shoulder massage.'],
        ];
        $services = [];
        foreach ($menu as $index => [$name, $category, $rupees, $minutes, $capacity, $description]) {
            $service = Service::query()->updateOrCreate(['business_id' => $business->id, 'slug' => Str::slug($name)], [
                'category_id' => $categories[$category]->id, 'name' => $name,
                'search_name' => Str::lower($name), 'description' => $description,
                'max_people' => $capacity, 'is_bookable' => true, 'status' => 'active',
            ]);
            $price = ($rupees + $businessIndex * 200) * 100;
            ServicePrice::query()->updateOrCreate(['service_id' => $service->id, 'duration' => sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60), 'is_current' => true], [
                'price_minor' => $price, 'currency' => 'NPR',
            ]);
            if ($index === 0) {
                ServicePrice::query()->updateOrCreate(['service_id' => $service->id, 'duration' => '01:30:00', 'is_current' => true], [
                    'price_minor' => $price + 150000, 'currency' => 'NPR',
                ]);
            }
            $services[] = $service;
        }

        return $services;
    }

    /** @param array<int, Service> $services
     * @param  array<int, User>  $customers
     */
    private function appointments(Business $business, User $staff, array $services, array $customers, int $businessIndex): void
    {
        foreach (['completed', 'completed', 'confirmed', 'awaiting_payment', 'cancelled', 'pending'] as $index => $status) {
            $customer = $customers[($businessIndex * 3 + $index) % count($customers)];
            $service = $services[$index];
            $price = $service->currentPrices()->orderBy('duration')->firstOrFail();
            $start = today()->addDays($index < 2 ? -($index + 1) : $index + 1)->setTime(10 + $index, 0);
            $end = $start->copy()->addMinutes($price->durationMinutes());
            $paid = in_array($status, ['completed', 'confirmed'], true);
            $request = BookingRequest::query()->firstOrCreate([
                'user_id' => $customer->id, 'business_id' => $business->id, 'service_id' => $service->id,
            ], ['idempotency_key' => (string) Str::ulid(), 'requested_date' => $start->toDateString(), 'expires_at' => $start]);
            $request->update([
                'requested_date' => $start->toDateString(), 'timezone' => 'Asia/Kathmandu', 'people_count' => 1,
                'status' => $status === 'pending' ? 'times_proposed' : ($status === 'cancelled' ? 'cancelled' : ($paid ? 'confirmed' : 'accepted')),
                'expires_at' => $start, 'accepted_at' => $status === 'pending' ? null : $start->copy()->subDay(),
                'confirmed_at' => $paid ? $start->copy()->subDay() : null,
            ]);
            $time = $request->times()->first();
            if ($time === null) {
                $request->times()->create(['starts_at' => $start, 'ends_at' => $end, 'is_available' => true]);
            } else {
                $time->update(['starts_at' => $start, 'ends_at' => $end, 'is_available' => true]);
            }
            if ($status === 'pending') {
                continue;
            }
            $booking = Booking::query()->updateOrCreate(['booking_request_id' => $request->id], [
                'user_id' => $customer->id, 'business_id' => $business->id, 'assigned_staff_user_id' => $staff->id,
                'appointment_date' => $start->toDateString(), 'starts_at' => $start, 'ends_at' => $end,
                'timezone' => 'Asia/Kathmandu', 'status' => $status, 'people_count' => 1,
                'subtotal_minor' => $price->price_minor, 'total_minor' => $price->price_minor, 'currency' => 'NPR',
                'confirmed_at' => $paid ? $start->copy()->subDay() : null,
                'completed_at' => $status === 'completed' ? $end : null,
                'cancelled_at' => $status === 'cancelled' ? $start->copy()->subDay() : null,
                'cancel_reason' => $status === 'cancelled' ? 'Customer rescheduled their wellness visit.' : null,
            ]);
            $booking->items()->updateOrCreate(['service_id' => $service->id], [
                'service_name_snapshot' => $service->name, 'duration_minutes_snapshot' => $price->durationMinutes(),
                'max_people_snapshot' => $service->max_people, 'unit_price_minor' => $price->price_minor, 'currency' => 'NPR', 'quantity' => 1,
            ]);
            $booking->statusHistory()->updateOrCreate(['note' => 'Demo spa appointment'], [
                'changed_by_user_id' => $staff->id, 'old_status' => null, 'new_status' => $status,
                'created_at' => $paid ? $start->copy()->subDay() : now(),
            ]);
            if ($paid) {
                Payment::query()->updateOrCreate(['gateway_reference' => 'SPA-DEMO-'.$booking->id], [
                    'booking_id' => $booking->id, 'user_id' => $customer->id, 'gateway' => 'esewa', 'purpose' => 'booking',
                    'amount_minor' => $booking->total_minor, 'currency' => 'NPR', 'status' => 'succeeded',
                    'gateway_payload' => ['demo' => true], 'paid_at' => $booking->confirmed_at,
                ]);
            }
            if ($status === 'completed') {
                Review::query()->updateOrCreate(['booking_id' => $booking->id], [
                    'user_id' => $customer->id, 'business_id' => $business->id, 'service_id' => $service->id,
                    'rating' => $index === 0 ? 5 : 4, 'tag' => 'Relaxing',
                    'comments' => $index === 0 ? 'A wonderfully relaxing treatment. The therapist listened carefully and the spa was spotless.' : 'Excellent deep tissue massage and friendly staff. I will book another visit.',
                    'is_hidden' => false,
                ]);
            }
            Notification::query()->updateOrCreate(['user_id' => $customer->id, 'title' => 'Spa visit: '.$business->name.' — '.$service->name], [
                'type' => 'booking', 'body' => 'Your '.$service->name.' appointment is '.str_replace('_', ' ', $status).'.',
                'data' => ['booking_id' => $booking->id], 'read_at' => $status === 'completed' ? now() : null,
            ]);
        }
        $business->update([
            'rating_average' => $business->reviews()->where('is_hidden', false)->avg('rating') ?? 0,
            'rating_count' => $business->reviews()->where('is_hidden', false)->count(),
        ]);
    }
}
