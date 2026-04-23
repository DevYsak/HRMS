<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case HrAdmin    = 'hr_admin';
    case Director   = 'director';
    case Manager    = 'manager';
    case Finance    = 'finance';
    case Employee   = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::HrAdmin    => 'HR Admin',
            self::Director   => 'Director',
            self::Manager    => 'Manager',
            self::Finance    => 'Finance',
            self::Employee   => 'Employee',
        };
    }

    public function canManageEmployees(): bool
    {
        return in_array($this, [
            self::SuperAdmin,
            self::HrAdmin,
            self::Director,
        ]);
    }

    public function canApproveLeave(): bool
    {
        return in_array($this, [
            self::SuperAdmin,
            self::HrAdmin,
            self::Director,
            self::Manager,
        ]);
    }

    public function canApproveOt(): bool
    {
        return in_array($this, [
            self::SuperAdmin,
            self::HrAdmin,
            self::Director,
            self::Manager,
        ]);
    }

    public function canRunPayroll(): bool
    {
        return in_array($this, [
            self::SuperAdmin,
            self::HrAdmin,
            self::Finance,
        ]);
    }

    public function canApproveFinance(): bool
    {
        return in_array($this, [
            self::SuperAdmin,
            self::Finance,
            self::Director,
        ]);
    }

    public function canManageSettings(): bool
    {
        return in_array($this, [
            self::SuperAdmin,
            self::HrAdmin,
        ]);
    }

    public function canManageDocuments(): bool
    {
        return in_array($this, [
            self::SuperAdmin,
            self::HrAdmin,
        ]);
    }
}
