<?php

namespace App\Console\Commands;

use App\Models\BiometricDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Set who may push to /iclock as a given device.
 *
 *   php artisan biometric:adms-device TBDD253900118                  show current settings
 *   php artisan biometric:adms-device TBDD253900118 --allow-ip=203.0.113.10 --allow-ip=10.0.0.0/24
 *   php artisan biometric:adms-device TBDD253900118 --issue-token    print a new token once
 *   php artisan biometric:adms-device TBDD253900118 --clear-token
 *
 * The token is printed once and stored only as a SHA-256 hash.
 */
class ConfigureAdmsDevice extends Command
{
    protected $signature = 'biometric:adms-device
        {serial : The device serial number (SN)}
        {--allow-ip=* : IP or CIDR the device pushes from (replaces the list)}
        {--clear-ips : Remove the allow-list (falls back to the registered ip_address)}
        {--issue-token : Issue a new token, printed once}
        {--clear-token : Stop requiring a token}';

    protected $description = 'Configure ADMS push authentication (allowed addresses and token) for a biometric device';

    public function handle(): int
    {
        $device = BiometricDevice::where('serial_number', $this->argument('serial'))->first();

        if ($device === null) {
            $this->error('No biometric device has that serial number.');

            return self::FAILURE;
        }

        $ips = array_values(array_filter(array_map('trim', (array) $this->option('allow-ip'))));
        foreach ($ips as $ip) {
            if (! $this->validAddress($ip)) {
                $this->error("Not an IP address or CIDR range: {$ip}");

                return self::FAILURE;
            }
        }

        if ($ips !== []) {
            $device->forceFill(['adms_allowed_ips' => implode(',', $ips)])->save();
        } elseif ($this->option('clear-ips')) {
            $device->forceFill(['adms_allowed_ips' => null])->save();
        }

        if ($this->option('issue-token') && $this->option('clear-token')) {
            $this->error('Choose either --issue-token or --clear-token.');

            return self::FAILURE;
        }

        if ($this->option('issue-token')) {
            $token = Str::random(48);
            $device->forceFill(['adms_token_hash' => hash('sha256', $token)])->save();
            $this->warn('New token (shown once — the device or relay must send it as ?token= or X-Device-Token):');
            $this->line($token);
        } elseif ($this->option('clear-token')) {
            $device->forceFill(['adms_token_hash' => null])->save();
        }

        $device->refresh();
        $this->table(['Device', 'Active', 'Allowed addresses', 'Token required'], [[
            $device->name.' ('.$device->serial_number.')',
            $device->is_active ? 'yes' : 'no',
            implode(', ', $device->admsAllowedAddresses()) ?: '— none: every push is refused —',
            $device->requiresAdmsToken() ? 'yes' : 'no',
        ]]);

        return self::SUCCESS;
    }

    private function validAddress(string $value): bool
    {
        [$address, $mask] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return $mask === null || (ctype_digit($mask) && IpUtils::checkIp($address, $value));
    }
}
