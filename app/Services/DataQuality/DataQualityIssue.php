<?php

namespace App\Services\DataQuality;

/**
 * One data-quality finding about one record (employee, department, team,
 * user or role). Read-only: it describes what HR should look at, it never
 * changes anything.
 */
final readonly class DataQualityIssue
{
    /**
     * @param  string  $check  key into DataQualityAuditor::CHECKS
     * @param  string  $subjectType  employee | department | team | user | role
     */
    public function __construct(
        public string $check,
        public string $severity,
        public string $subjectType,
        public int $subjectId,
        public string $subjectLabel,
        public string $message,
        public ?int $departmentId = null,
        public ?string $departmentName = null,
        public ?int $employeeId = null,
        public ?string $employeeStatus = null,
    ) {}

    public function label(): string
    {
        return DataQualityAuditor::CHECKS[$this->check]['label'];
    }

    public function area(): string
    {
        return DataQualityAuditor::CHECKS[$this->check]['area'];
    }

    /** data = HR correction; policy = needs a decision; never a code bug. */
    public function nature(): string
    {
        return DataQualityAuditor::CHECKS[$this->check]['nature'];
    }

    public function action(): string
    {
        return DataQualityAuditor::CHECKS[$this->check]['action'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'issue' => $this->label(),
            'severity' => $this->severity,
            'area' => $this->area(),
            'nature' => $this->nature(),
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject' => $this->subjectLabel,
            'department' => $this->departmentName,
            'message' => $this->message,
            'suggested_action' => $this->action(),
        ];
    }
}
