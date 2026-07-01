<?php

namespace App\Enums;

/**
 * The single set of attendance modes supported across the one attendance module:
 * Office, WFH, Hybrid, Client Visit, On Duty, Training, Business Travel.
 *
 * Class strings are returned as full literals (not interpolated) so Tailwind's
 * content scanner includes them.
 */
enum AttendanceMode: string
{
    case Office = 'office';
    case Wfh = 'wfh';
    case Hybrid = 'hybrid';
    case ClientVisit = 'client_visit';
    case OnDuty = 'on_duty';
    case Training = 'training';
    case BusinessTravel = 'business_travel';

    public function label(): string
    {
        return match ($this) {
            self::Office => 'Office',
            self::Wfh => 'Work From Home',
            self::Hybrid => 'Hybrid',
            self::ClientVisit => 'Client Visit',
            self::OnDuty => 'On Duty',
            self::Training => 'Training',
            self::BusinessTravel => 'Business Travel',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Office => 'Office',
            self::Wfh => 'WFH',
            self::Hybrid => 'Hybrid',
            self::ClientVisit => 'Client',
            self::OnDuty => 'On Duty',
            self::Training => 'Training',
            self::BusinessTravel => 'Travel',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Office => 'building-office',
            self::Wfh => 'home',
            self::Hybrid => 'arrows-right-left',
            self::ClientVisit => 'briefcase',
            self::OnDuty => 'map-pin',
            self::Training => 'academic-cap',
            self::BusinessTravel => 'paper-airplane',
        };
    }

    /** Solid dot/bar background class. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Office => 'bg-emerald-500',
            self::Wfh => 'bg-blue-500',
            self::Hybrid => 'bg-violet-500',
            self::ClientVisit => 'bg-orange-500',
            self::OnDuty => 'bg-amber-500',
            self::Training => 'bg-teal-500',
            self::BusinessTravel => 'bg-indigo-500',
        };
    }

    /** Soft chip (bg + text, light & dark). */
    public function chipClass(): string
    {
        return match ($this) {
            self::Office => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
            self::Wfh => 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
            self::Hybrid => 'bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-400',
            self::ClientVisit => 'bg-orange-50 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
            self::OnDuty => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
            self::Training => 'bg-teal-50 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400',
            self::BusinessTravel => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400',
        };
    }

    /** Hex colour for charts. */
    public function hex(): string
    {
        return match ($this) {
            self::Office => '#10B981',
            self::Wfh => '#3B82F6',
            self::Hybrid => '#8B5CF6',
            self::ClientVisit => '#F97316',
            self::OnDuty => '#F59E0B',
            self::Training => '#14B8A6',
            self::BusinessTravel => '#6366F1',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public static function tryFromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Office;
    }
}
