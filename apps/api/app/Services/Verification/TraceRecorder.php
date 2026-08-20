<?php

namespace App\Services\Verification;

use App\Domain\Shared\Enums\TraceEventType;
use App\Domain\Verification\Models\AgentTraceEvent;
use App\Domain\Verification\Models\VerificationRun;
use Carbon\CarbonImmutable;

class TraceRecorder
{
    public function record(VerificationRun $run, array $events): int
    {
        $sequence = (int) $run->traceEvents()->max('sequence');
        $written = 0;

        foreach ($events as $event) {
            $type = TraceEventType::tryFrom($event['event_type'] ?? '');

            if (! $type) {
                continue;
            }

            AgentTraceEvent::create([
                'organization_id' => $run->organization_id,
                'verification_run_id' => $run->id,
                'sequence' => ++$sequence,
                'event_type' => $type->value,
                'label' => mb_substr((string) ($event['label'] ?? $type->value), 0, 255),
                'detail' => $event['detail'] ?? null,
                'occurred_at' => $this->parse($event['occurred_at'] ?? null),
            ]);

            $written++;
        }

        return $written;
    }

    public function append(VerificationRun $run, TraceEventType $type, string $label, array $detail = []): AgentTraceEvent
    {
        return AgentTraceEvent::create([
            'organization_id' => $run->organization_id,
            'verification_run_id' => $run->id,
            'sequence' => ((int) $run->traceEvents()->max('sequence')) + 1,
            'event_type' => $type->value,
            'label' => $label,
            'detail' => $detail ?: null,
            'occurred_at' => now(),
        ]);
    }

    private function parse(?string $value): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::parse($value) : CarbonImmutable::now();
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }
}
