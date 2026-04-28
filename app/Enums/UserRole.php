<?php

namespace App\Enums;

enum UserRole: string
{
    case Superadmin = 'superadmin';
    case TenantAdmin = 'tenant_admin';
}
