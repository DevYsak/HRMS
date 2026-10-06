<?php

namespace App\Services\Notifications;

use App\Models\Employee;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\AttendanceExceptionNotification;
use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\MissingCheckoutNotification;
use Illuminate\Support\Collection;

/**
 * Who receives a notification event, as configured on the Notifications &
 * Email page:
 *
 *   recipients  the code's own recipients (or everyone, with "apply to
 *               all"), plus users holding an included role and any
 *               specifically named users — the additions limited to the
 *               chosen departments when any are set — minus users holding
 *               an excluded role, each person once.
 *   exclusion   an excluded role never receives the event, whichever code
 *               path sends it (enforced in NotificationDeliveryGate).
 *   preference  a person may mute an optional event; a mandatory one cannot
 *               be muted. Admin switches still win over "mandatory".
 *
 * Roles here are RBAC roles (the role's slug: hr_admin, manager, a custom
 * role …), never the recipient-relationship role of a role override.
 * Nothing configured = the code's recipients, unchanged (fail open).
 */
class NotificationRecipientPolicy
{
    /**
     * Events whose senders resolve recipients through this policy, so
     * "include roles / users / departments" and "apply to all" add people
     * to them. Exclusions, "mandatory" and personal mutes apply to every
     * event (they are enforced in NotificationDeliveryGate).
     *
     * @var array<int, class-string>
     */
    public const RECIPIENT_AWARE = [
        MissingCheckoutNotification::class,
        AttendanceRegularisationNotification::class,
        AttendanceExceptionNotification::class,
    ];

    /**
     * @param  Collection<int, User>  $default  the recipients the code chose
     * @return Collection<int, User>
     */
    public function resolve(string $eventKey, Collection $default, ?Employee $subject = null): Collection
    {
        $setting = NotificationSetting::for($eventKey);

        if ($setting === null) {
            return $default->filter()->unique('id')->values();
        }

        $recipients = $setting->apply_to_all ? $this->activeUsers() : $default->filter();

        $additions = collect();
        if (! empty($setting->include_roles)) {
            $additions = $additions->merge($this->activeUsers()->filter(fn (User $u) => in_array($this->roleSlug($u), $setting->include_roles, true)));
        }
        if (! empty($setting->include_user_ids)) {
            $additions = $additions->merge(User::whereIn('id', $setting->include_user_ids)->whereNull('deleted_at')->get());
        }
        if (! empty($setting->department_ids)) {
            $departments = array_map('intval', $setting->department_ids);
            $additions = $additions->filter(fn (User $u) => in_array((int) $u->employee?->department_id, $departments, true));

            if ($setting->apply_to_all) {
                $recipients = $recipients->filter(fn (User $u) => in_array((int) $u->employee?->department_id, $departments, true));
            }
        }

        return $recipients->merge($additions)
            ->reject(fn (User $u) => $this->excludes($eventKey, $u))
            ->unique('id')
            ->values();
    }

    /** An excluded role never receives this event. */
    public function excludes(string $eventKey, User $user): bool
    {
        $excluded = NotificationSetting::for($eventKey)?->exclude_roles;

        return ! empty($excluded) && in_array($this->roleSlug($user), $excluded, true);
    }

    public function isMandatory(string $eventKey): bool
    {
        return (bool) NotificationSetting::for($eventKey)?->is_mandatory;
    }

    /** The person muted this channel of an optional event. */
    public function mutedBy(User $user, string $eventKey, string $channel): bool
    {
        if ($this->isMandatory($eventKey)) {
            return false;
        }

        try {
            $preference = NotificationPreference::where('user_id', $user->id)->where('notification_key', $eventKey)->first();
        } catch (\Throwable) {
            return false; // table not migrated yet: nothing is muted
        }

        return match ($channel) {
            'mail' => (bool) $preference?->mail_muted,
            'database' => (bool) $preference?->database_muted,
            default => false,
        };
    }

    /** The RBAC role slug the include / exclude lists are written in. */
    public function roleSlug(User $user): ?string
    {
        return $user->assignedRole?->slug ?? $user->role?->value;
    }

    /** @return Collection<int, User> */
    private function activeUsers(): Collection
    {
        return User::with(['assignedRole', 'employee'])
            ->whereNull('deleted_at')
            ->whereDoesntHave('employee', fn ($q) => $q->whereIn('status', ['inactive', 'archived']))
            ->get();
    }
}
