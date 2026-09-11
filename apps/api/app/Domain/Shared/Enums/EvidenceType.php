<?php

namespace App\Domain\Shared\Enums;

enum EvidenceType: string
{
    case LOCATION_VERIFICATION = 'LOCATION_VERIFICATION';
    case DEVICE_SWAP = 'DEVICE_SWAP';
    case DEVICE_STATUS = 'DEVICE_STATUS';
    case DEVICE_REACHABILITY = 'DEVICE_REACHABILITY';
    case DEVICE_ROAMING_STATUS = 'DEVICE_ROAMING_STATUS';
    case LOCATION_RETRIEVAL = 'LOCATION_RETRIEVAL';

    /** The agent tool that produces this evidence type. */
    public function tool(): string
    {
        return match ($this) {
            self::LOCATION_VERIFICATION => 'verify_location',
            self::DEVICE_SWAP => 'check_device_swap',
            self::DEVICE_STATUS => 'get_device_status',
            self::DEVICE_REACHABILITY => 'check_reachability',
            self::DEVICE_ROAMING_STATUS => 'get_roaming_status',
            self::LOCATION_RETRIEVAL => 'retrieve_location',
        };
    }

    public function apiName(): string
    {
        return match ($this) {
            self::LOCATION_VERIFICATION => 'Location Verification',
            self::DEVICE_STATUS => 'Device Status',
            self::DEVICE_REACHABILITY => 'Device Reachability Status',
            self::DEVICE_ROAMING_STATUS => 'Device Roaming Status',
            self::LOCATION_RETRIEVAL => 'Location Retrieval',
        };
    }

    /** The business question this capability answers. */
    public function businessQuestion(): string
    {
        return match ($this) {
            self::LOCATION_VERIFICATION => 'Was the device consistent with the expected service site?',
            self::DEVICE_STATUS => 'Was the device active on the network?',
            self::DEVICE_REACHABILITY => 'Could the device be reached?',
            self::DEVICE_ROAMING_STATUS => 'Was the device roaming outside the expected country?',
            self::LOCATION_RETRIEVAL => 'Where did the network last observe the device?',
        };
    }

    public static function fromTool(string $tool): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->tool() === $tool) {
                return $case;
            }
        }

        return null;
    }
}
