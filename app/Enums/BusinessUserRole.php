<?php

namespace App\Enums;

/**
 * Role a user holds inside one business (branch-scoped, stored on business_users).
 * Global roles (superadmin, customer) live in Spatie's model_has_roles.
 */
enum BusinessUserRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';
}
