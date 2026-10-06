<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        // Business-scoped
        'business.view',
        'business.update',
        'business.staff.view',
        'business.staff.manage',
        'business.services.view',
        'business.services.manage',
        'business.bookings.view',
        'business.bookings.manage',
        'business.offers.manage',
        'business.reports.view',
        'booking.complete',

        // Customer-scoped
        'booking.create',
        'booking.view-own',
        'booking.cancel-own',
        'payment.create',
        'review.create-own',
        'offer.redeem',
        'referral.use',
        'notification.manage-own',
        'reward.view-own',

        // Platform / admin
        'admin.dashboard.view',
        'admin.users.manage',
        'admin.businesses.manage',
        'admin.businesses.verify',
        'admin.catalog.manage',
        'admin.bookings.manage',
        'admin.payments.manage',
        'admin.offers.manage',
        'admin.rewards.manage',
        'admin.reviews.moderate',
        'admin.notifications.manage',
        'admin.reports.view',
        'admin.audit.view',
        'admin.roles.manage',
    ];

    private const ROLES = [
        'superadmin' => '*',
        'owner' => [
            'business.view', 'business.update', 'business.staff.view', 'business.staff.manage',
            'business.services.view', 'business.services.manage', 'business.bookings.view',
            'business.bookings.manage', 'business.offers.manage', 'business.reports.view',
            'booking.complete', 'booking.view-own', 'booking.cancel-own',
        ],
        'manager' => [
            'business.view', 'business.update', 'business.staff.view',
            'business.services.view', 'business.services.manage', 'business.bookings.view',
            'business.bookings.manage', 'business.offers.manage', 'business.reports.view',
            'booking.complete', 'booking.view-own', 'booking.cancel-own',
        ],
        'staff' => [
            'business.view', 'business.services.view', 'business.bookings.view',
            'business.bookings.manage', 'booking.complete', 'booking.view-own', 'booking.cancel-own',
        ],
        'customer' => [
            'booking.create', 'booking.view-own', 'booking.cancel-own', 'payment.create',
            'review.create-own', 'offer.redeem', 'referral.use', 'notification.manage-own', 'reward.view-own',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Roles/permissions live on the default guard (web); Sanctum only
        // handles authentication, Spatie handles authorization on the model.
        $guard = config('auth.defaults.guard', 'web');

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        foreach (self::ROLES as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, $guard);

            if ($permissions === '*') {
                $role->givePermissionTo(Permission::all());
            } else {
                $role->syncPermissions($permissions);
            }
        }
    }
}
