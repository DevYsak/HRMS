<?php

use App\Enums\PunchMethod;
use App\Support\PunchMethodResolver;

beforeEach(function () {
    // AIFACE-MAGNUM (confirmed live): 15 = Face, 4 = Card, 1 = Fingerprint.
    config(['biometric.verify_methods' => [1 => 'fingerprint', 4 => 'id_card', 15 => 'face']]);
});

test('numeric device codes use the authoritative config map', function () {
    expect(PunchMethodResolver::resolve(15))->toBe(PunchMethod::Face);
    expect(PunchMethodResolver::resolve(4))->toBe(PunchMethod::IdCard);     // 4 = Card on this device
    expect(PunchMethodResolver::resolve(1))->toBe(PunchMethod::Fingerprint);
    expect(PunchMethodResolver::resolve('15'))->toBe(PunchMethod::Face);    // numeric string too
});

test('unmapped numeric codes resolve to null when a device map is set', function () {
    expect(PunchMethodResolver::resolve(2))->toBeNull();  // PIN
    expect(PunchMethodResolver::resolve(3))->toBeNull();  // unused on this device
    expect(PunchMethodResolver::resolve(null))->toBeNull();
});

test('textual verify values fall through to the alias mapper', function () {
    expect(PunchMethodResolver::resolve('face'))->toBe(PunchMethod::Face);
    expect(PunchMethodResolver::resolve('card'))->toBe(PunchMethod::IdCard);
    expect(PunchMethodResolver::resolve('physical_card'))->toBe(PunchMethod::PhysicalCard);
    expect(PunchMethodResolver::value('face'))->toBe('face');
});

test('with no device map, numeric codes use the generic ZK aliases', function () {
    config(['biometric.verify_methods' => []]);

    expect(PunchMethodResolver::resolve(15))->toBe(PunchMethod::Face); // ZK generic: 15 = face
    expect(PunchMethodResolver::resolve(1))->toBe(PunchMethod::Fingerprint);
});
