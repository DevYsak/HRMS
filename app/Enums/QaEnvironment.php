<?php

namespace App\Enums;

/**
 * Where a QA check ran. Production is read-only: destructive QA (seeding,
 * resets, test data) is never run there — results recorded against it are
 * observations only.
 */
enum QaEnvironment: string
{
    case Local = 'local';
    case LocalTestDb = 'local_test_db';
    case E2e = 'e2e';
    case Staging = 'staging';
    case ProductionReadOnly = 'production_read_only';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local',
            self::LocalTestDb => 'Local Test DB',
            self::E2e => 'E2E',
            self::Staging => 'Staging',
            self::ProductionReadOnly => 'Production (read-only)',
        };
    }
}
