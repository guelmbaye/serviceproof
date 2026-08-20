<?php

namespace App\Services\Verification;

use App\Domain\Evidence\Models\Evidence;
use App\Domain\Shared\Enums\EvidenceSource;
use App\Domain\Shared\Enums\EvidenceStatus;
use App\Domain\Shared\Enums\EvidenceType;
use App\Domain\Verification\Models\VerificationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Anti-hallucination boundary.
 *
 * Only evidence items that look like genuine tool output are persisted:
 * a known capability, a known status, a declared source and a request id.
 * Anything else is dropped and logged — the model does not get to invent
 * a network observation by describing one.
 */
class EvidenceRecorder
{
    /**
     * @return Collection<int, Evidence>
     */
    public function record(VerificationRun $run, array $items): Collection
    {
        $recorded = collect();

        foreach ($items as $item) {
            $evidence = $this->recordOne($run, $item);

            if ($evidence) {
                $recorded->push($evidence);
            }
        }

        return $recorded;
    }

    private function recordOne(VerificationRun $run, array $item): ?Evidence
    {
        $type = EvidenceType::tryFrom($item['type'] ?? '');
        $status = EvidenceStatus::tryFrom($item['status'] ?? '');
        $source = EvidenceSource::tryFrom($item['source'] ?? '');

        if (! $type || ! $status || ! $source) {
            Log::warning('Rejected malformed evidence item from agent runtime', [
                'verification_run_id' => $run->id,
                'type' => $item['type'] ?? null,
                'status' => $item['status'] ?? null,
                'source' => $item['source'] ?? null,
            ]);

            return null;
        }

        // A usable observation must carry provenance. No request id means we
        // cannot prove where it came from, so it cannot count as evidence.
        if ($status->isUsable() && blank($item['request_id'] ?? null)) {
            Log::warning('Rejected evidence without provenance', [
                'verification_run_id' => $run->id,
                'type' => $type->value,
            ]);

            return null;
        }

        $observedAt = $this->parseDate($item['observed_at'] ?? null);
        $receivedAt = $this->parseDate($item['received_at'] ?? null) ?? CarbonImmutable::now();

        $freshnessLimit = (int) ($run->policy?->freshness_seconds ?? config('serviceproof.evidence_max_age_seconds'));
        $ageSeconds = $item['age_seconds'] ?? ($observedAt ? $observedAt->diffInSeconds($receivedAt) : null);

        // Freshness is a deterministic property, not something the agent
        // asserts. Recompute it here.
        if ($status->isUsable() && $ageSeconds !== null && $ageSeconds > $freshnessLimit) {
            $status = EvidenceStatus::STALE;
        }

        $freshness = match (true) {
            $status === EvidenceStatus::STALE => 'STALE',
            $ageSeconds === null => 'UNKNOWN',
            default => 'CURRENT',
        };

        return Evidence::create([
            'organization_id' => $run->organization_id,
            'verification_run_id' => $run->id,
            'claim_id' => $run->claim_id,
            'type' => $type->value,
            'status' => $status->value,
            'source' => $source->value,
            'provider' => $item['provider'] ?? null,
            'api_name' => $item['api'] ?? $type->apiName(),
            'request_id' => $item['request_id'] ?? null,
            'freshness' => $freshness,
            'age_seconds' => $ageSeconds !== null ? (int) $ageSeconds : null,
            'latency_ms' => isset($item['latency_ms']) ? (int) $item['latency_ms'] : null,
            'reliability' => isset($item['reliability']) ? (float) $item['reliability'] : null,
            'observed_at' => $observedAt,
            'received_at' => $receivedAt,
            'summary' => $item['summary'] ?? null,
            'normalized' => $item['normalized'] ?? null,
            'payload_hash' => $item['payload_hash'] ?? null,
            'raw_reference' => $item['raw_reference'] ?? null,
            'failure_reason' => $item['failure_reason'] ?? null,
        ]);
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
