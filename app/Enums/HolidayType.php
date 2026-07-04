<?php

namespace App\Enums;

/**
 * The six holiday classifications for the Holiday Management module. Each
 * carries a label and a default colour used for calendar colour-coding when a
 * holiday has no explicit colour set.
 */
enum HolidayType: string
{
    case National = 'national';
    case State = 'state';
    case Festival = 'festival';
    case Company = 'company';
    case Optional = 'optional';
    case Branch = 'branch';

    public function label(): string
    {
        return match ($this) {
            self::National => 'National Holiday',
            self::State => 'State Holiday',
            self::Festival => 'Festival Holiday',
            self::Company => 'Company Holiday',
            self::Optional => 'Optional Holiday',
            self::Branch => 'Branch Holiday',
        };
    }

    /** Default hex colour for calendar chips when none is set on the holiday. */
    public function color(): string
    {
        return match ($this) {
            self::National => '#EF4444',   // red
            self::State => '#F59E0B',      // amber
            self::Festival => '#8B5CF6',   // violet
            self::Company => '#F97316',    // orange
            self::Optional => '#0EA5E9',   // sky
            self::Branch => '#14B8A6',     // teal
        };
    }

    /** @return array<int, array{value:string, label:string, color:string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'color' => $t->color(),
        ], self::cases());
    }
}
