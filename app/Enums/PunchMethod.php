<?php

namespace App\Enums;

/**
 * How a biometric punch was verified at the device.
 *
 * Only the methods the business tracks are modelled — Face, ID Card and
 * Physical Card. Any other verify mode (fingerprint, password, unknown) maps
 * to null so it simply renders no chip rather than a misleading one.
 */
enum PunchMethod: string
{
    case Face = 'face';
    case IdCard = 'id_card';
    case PhysicalCard = 'physical_card';

    public function label(): string
    {
        return match ($this) {
            self::Face => 'Face',
            self::IdCard => 'ID Card',
            self::PhysicalCard => 'Physical Card',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Face => 'face-smile',
            self::IdCard => 'identification',
            self::PhysicalCard => 'credit-card',
        };
    }

    public function chipClass(): string
    {
        return match ($this) {
            self::Face => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-300',
            self::IdCard => 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
            self::PhysicalCard => 'bg-teal-50 text-teal-600 dark:bg-teal-950/40 dark:text-teal-300',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }

    /**
     * Normalise a raw verify mode reported by a device/engine (string alias or
     * numeric ZKTeco code) into a tracked method, or null when unsupported.
     */
    public static function fromDevice(int|string|null $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $key = strtolower(trim((string) $raw));

        return match ($key) {
            'face', 'facial', 'face_recognition', '15', '11' => self::Face,
            'id_card', 'id', 'rfid', 'card', 'proximity', 'prox', '3', '4' => self::IdCard,
            'physical_card', 'physical', 'swipe', 'mag', 'magnetic', 'mifare' => self::PhysicalCard,
            default => self::tryFrom($key),
        };
    }
}
