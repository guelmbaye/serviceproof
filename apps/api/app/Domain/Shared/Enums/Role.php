<?php

namespace App\Domain\Shared\Enums;

enum Role: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case ORG_ADMIN = 'ORG_ADMIN';
    case OPERATIONS_MANAGER = 'OPERATIONS_MANAGER';
    case REVIEWER = 'REVIEWER';
    case FIELD_WORKER = 'FIELD_WORKER';

    /** Roles that may see the operations web app. */
    public function isBackOffice(): bool
    {
        return $this !== self::FIELD_WORKER;
    }

    public function canReview(): bool
    {
        return in_array($this, [self::SUPER_ADMIN, self::ORG_ADMIN, self::OPERATIONS_MANAGER, self::REVIEWER], true);
    }

    public function canAdministerOrganization(): bool
    {
        return in_array($this, [self::SUPER_ADMIN, self::ORG_ADMIN], true);
    }

    public function canTriggerVerification(): bool
    {
        return $this !== self::REVIEWER;
    }

    /** Roles this role is allowed to create. */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::SUPER_ADMIN => array_map(fn (self $r) => $r->value, self::cases()),
            self::ORG_ADMIN => [
                self::ORG_ADMIN->value,
                self::OPERATIONS_MANAGER->value,
                self::REVIEWER->value,
                self::FIELD_WORKER->value,
            ],
            default => [],
        };
    }
}
