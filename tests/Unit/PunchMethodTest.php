<?php

use App\Enums\PunchMethod;

test('it maps device aliases and ZK verify codes to tracked methods', function (int|string $raw, ?PunchMethod $expected) {
    expect(PunchMethod::fromDevice($raw))->toBe($expected);
})->with([
    'face word' => ['face', PunchMethod::Face],
    'facial' => ['FACIAL', PunchMethod::Face],
    'zk face code 15' => ['15', PunchMethod::Face],
    'id_card' => ['id_card', PunchMethod::IdCard],
    'rfid alias' => ['rfid', PunchMethod::IdCard],
    'generic card' => ['card', PunchMethod::IdCard],
    'zk card code 3' => ['3', PunchMethod::IdCard],
    'physical_card' => ['physical_card', PunchMethod::PhysicalCard],
    'swipe alias' => ['swipe', PunchMethod::PhysicalCard],
    'fingerprint word' => ['fingerprint', PunchMethod::Fingerprint],
    'zk fingerprint code 1' => ['1', PunchMethod::Fingerprint],
    'password unsupported' => ['password', null],
]);

test('it returns null for empty input', function () {
    expect(PunchMethod::fromDevice(null))->toBeNull();
    expect(PunchMethod::fromDevice(''))->toBeNull();
});

test('every case exposes display metadata', function () {
    foreach (PunchMethod::cases() as $method) {
        expect($method->label())->not->toBe('');
        expect($method->icon())->not->toBe('');
        expect($method->chipClass())->toContain('bg-');
    }

    expect(PunchMethod::values())->toBe(['face', 'fingerprint', 'id_card', 'physical_card']);
});
