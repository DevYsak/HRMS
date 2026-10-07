<?php

namespace App\Enums;

/**
 * How a biometric punch was verified at the device.
 *
 * Tracks Face, Fingerprint, ID Card and Physical Card. Any other verify mode
 * (password, unknown) maps to null so it renders no chip rather than a
 * misleading one.
 */
enum PunchMethod: string
{
    case Face = 'face';
    case Fingerprint = 'fingerprint';
    case IdCard = 'id_card';
    case PhysicalCard = 'physical_card';

    public function label(): string
    {
        return match ($this) {
            self::Face => 'Face',
            self::Fingerprint => 'Fingerprint',
            self::IdCard => 'ID Card',
            self::PhysicalCard => 'Physical Card',
        };
    }

    /**
     * The plain-language edge a punch records — "Biometric IN" or "Biometric
     * OUT". Display guidance only: a direction the punch was actually resolved
     * to (the device's own explicit tag, a regularised OUT) always wins over
     * the method's default (Face = IN, ID Card = OUT on this deployment).
     */
    public function guidance(?string $direction = null): ?string
    {
        $direction = strtolower((string) $direction);
        if (! in_array($direction, ['in', 'out'], true)) {
            $direction = config('biometric.method_direction.'.$this->value);
        }

        return match ($direction) {
            'in' => 'Biometric IN',
            'out' => 'Biometric OUT',
            default => null,
        };
    }

    /** "Face · Biometric IN" — the method with its guidance, when it has one. */
    public function labelWithGuidance(?string $direction = null): string
    {
        $guidance = $this->guidance($direction);

        return $guidance ? $this->label().' · '.$guidance : $this->label();
    }

    public function icon(): string
    {
        return match ($this) {
            self::Face => 'face-smile',
            self::Fingerprint => 'finger-print',
            self::IdCard => 'identification',
            self::PhysicalCard => 'credit-card',
        };
    }

    public function chipClass(): string
    {
        return match ($this) {
            self::Face => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-300',
            self::Fingerprint => 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
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
            'fingerprint', 'finger', 'fp', 'finger_print', '1' => self::Fingerprint,
            'id_card', 'id', 'rfid', 'card', 'proximity', 'prox', '3', '4' => self::IdCard,
            'physical_card', 'physical', 'swipe', 'mag', 'magnetic', 'mifare' => self::PhysicalCard,
            default => self::tryFrom($key),
        };
    }
}
