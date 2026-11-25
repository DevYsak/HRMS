<?php

namespace App\Services\Notifications;

use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\NotificationDepartmentRule;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\AttendanceExceptionNotification;
use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\MissedPunchAllowanceExceededNotification;
use App\Notifications\MissedPunchNotification;
use App\Notifications\MissedPunchWeeklyWarningNotification;
use App\Notifications\MissingCheckoutNotification;
use App\Notifications\RegularisationReviewedNotification;
use Illuminate\Support\Collection;

/**
 * Who receives a notification event, as configured on the Notifications &
 * Email page:
 *
 *   recipients  the code's own recipients (or everyone, with "apply to
 *               all"), plus users holding an included role and any
 *               specifically named users — the additions limited to the
 *               chosen departments when any are set — minus users holding
 *               an excluded role and named excluded people, each person
 *               once. An event's rules apply to all its stages; a stage's
 *               own rules ("Leave approved") add to them.
 *   department  the rule for the department of the employee the event is
 *               about: off for that department, extra roles / people,
 *               exceptions, or only the people listed (replacing the
 *               code's own recipients).
 *   exclusion   an excluded role or person never receives the event,
 *               whichever code path sends it (NotificationDeliveryGate).
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
     * "include roles / users / departments", "apply to all" and department
     * rules add people to them. Exclusions, department "off", "mandatory"
     * and personal mutes apply to every event (NotificationDeliveryGate).
     *
     * @var array<int, class-string>
     */
    public const RECIPIENT_AWARE = [
        MissingCheckoutNotification::class,
        MissedPunchNotification::class,
        MissedPunchWeeklyWarningNotification::class,
        MissedPunchAllowanceExceededNotification::class,
        AttendanceRegularisationNotification::class,
        AttendanceExceptionNotification::class,
        LeaveRequestNotification::class,
        RegularisationReviewedNotification::class,
    ];

    /**
     * @param  Collection<int, User>  $default  the recipients the code chose
     * @return Collection<int, User>
     */
    public function resolve(string $eventKey, Collection $default, ?Employee $subject = null): Collection
    {
        $settings = $this->settings($eventKey);
        $rule = NotificationDepartmentRule::for($eventKey, $subject?->department_id);

        if ($rule !== null && ! $rule->is_enabled) {
            return collect();   // switched off for this department
        }

        $applyToAll = $settings->contains(fn (NotificationSetting $s) => $s->apply_to_all);
        $recipients = $rule?->replace_defaults ? collect() : ($applyToAll ? $this->activeUsers() : $default->filter());

        $includeRoles = $this->merged($settings, 'include_roles');
        $includeUsers = $this->merged($settings, 'include_user_ids');
        $departments = array_map('intval', $this->merged($settings, 'department_ids'));

        $additions = collect();
        if ($includeRoles !== []) {
            $additions = $additions->merge($this->activeUsers()->filter(fn (User $u) => in_array($this->roleSlug($u), $includeRoles, true)));
        }
        if ($includeUsers !== []) {
            $additions = $additions->merge(User::whereIn('id', $includeUsers)->whereNull('deleted_at')->get());
        }
        if ($departments !== []) {
            $additions = $additions->filter(fn (User $u) => in_array((int) $u->employee?->department_id, $departments, true));

            if ($applyToAll) {
                $recipients = $recipients->filter(fn (User $u) => in_array((int) $u->employee?->department_id, $departments, true));
            }
        }

        // The department's own additions — never narrowed by the event's departments.
        if ($rule !== null) {
            if (! empty($rule->include_roles)) {
                $additions = $additions->merge($this->activeUsers()->filter(fn (User $u) => in_array($this->roleSlug($u), $rule->include_roles, true)));
            }
            if (! empty($rule->include_user_ids)) {
                $additions = $additions->merge(User::whereIn('id', array_map('intval', $rule->include_user_ids))->whereNull('deleted_at')->get());
            }
        }

        return $recipients->merge($additions)
            ->reject(fn (User $u) => $this->excludes($eventKey, $u) || $this->departmentExcludes($rule, $u))
            ->unique('id')
            ->values();
    }

    /** An excluded role or a named excluded person never receives this event. */
    public function excludes(string $eventKey, User $user): bool
    {
        $settings = $this->settings($eventKey);

        return in_array($this->roleSlug($user), $this->merged($settings, 'exclude_roles'), true)
            || in_array((int) $user->id, array_map('intval', $this->merged($settings, 'exclude_user_ids')), true);
    }

    /**
     * The department rule's verdict for one recipient of an event about
     * $subject: 'department_disabled', 'department_excluded', or null.
     */
    public function departmentDecision(string $eventKey, ?Employee $subject, User $user): ?string
    {
        $rule = NotificationDepartmentRule::for($eventKey, $subject?->department_id);

        return match (true) {
            $rule === null => null,
            ! $rule->is_enabled => 'department_disabled',
            $this->departmentExcludes($rule, $user) => 'department_excluded',
            default => null,
        };
    }

    /**
     * The employee a notification is about (its mailContext), when it says.
     */
    public static function subjectOf(?object $notification): ?Employee
    {
        if ($notification === null || ! method_exists($notification, 'mailContext')) {
            return null;
        }

        return self::subjectFromContext($notification->mailContext());
    }

    /**
     * The employee behind a context ("leave_request:42"-style type and id).
     *
     * @param  array{type?: string, id?: int|string}|null  $context
     */
    public static function subjectFromContext(?array $context): ?Employee
    {
        $id = (int) ($context['id'] ?? 0);

        if ($id === 0) {
            return null;
        }

        try {
            return match ($context['type'] ?? null) {
                'employee' => Employee::find($id),
                'leave_request' => LeaveRequest::with('employee')->find($id)?->employee,
                'attendance_regularisation' => AttendanceRegularisation::with('employee')->find($id)?->employee,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    public function isMandatory(string $eventKey): bool
    {
        return $this->settings($eventKey)->contains(fn (NotificationSetting $s) => $s->is_mandatory);
    }

    /** The person muted this channel of an optional event (the stage or the whole event). */
    public function mutedBy(User $user, string $eventKey, string $channel): bool
    {
        if ($this->isMandatory($eventKey)) {
            return false;
        }

        try {
            $preferences = NotificationPreference::where('user_id', $user->id)
                ->whereIn('notification_key', array_unique([$eventKey, NotificationCatalog::parentKey($eventKey)]))
                ->get();
        } catch (\Throwable) {
            return false; // table not migrated yet: nothing is muted
        }

        return $preferences->contains(fn (NotificationPreference $p) => match ($channel) {
            'mail' => (bool) $p->mail_muted,
            'database' => (bool) $p->database_muted,
            default => false,
        });
    }

    /** The RBAC role slug the include / exclude lists are written in. */
    public function roleSlug(User $user): ?string
    {
        return $user->assignedRole?->slug ?? $user->role?->value;
    }

    /**
     * The settings rows that govern a key: the stage's own and its event's.
     *
     * @return Collection<int, NotificationSetting>
     */
    private function settings(string $eventKey): Collection
    {
        $parent = NotificationCatalog::parentKey($eventKey);

        return collect([NotificationSetting::for($eventKey), $parent !== $eventKey ? NotificationSetting::for($parent) : null])->filter()->values();
    }

    /**
     * @param  Collection<int, NotificationSetting>  $settings
     * @return array<int, mixed>
     */
    private function merged(Collection $settings, string $field): array
    {
        return array_values(array_unique($settings->flatMap(fn (NotificationSetting $s) => (array) ($s->{$field} ?? []))->all()));
    }

    private function departmentExcludes(?NotificationDepartmentRule $rule, User $user): bool
    {
        return $rule !== null && (
            in_array($this->roleSlug($user), (array) ($rule->exclude_roles ?? []), true)
            || in_array((int) $user->id, array_map('intval', (array) ($rule->exclude_user_ids ?? [])), true)
        );
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
