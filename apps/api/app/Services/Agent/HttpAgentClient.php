<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Laravel -> FastAPI internal channel.
 *
 * The bundle is pre-loaded from PostgreSQL and sent in the initial POST, so
 * the agent runtime never calls back into the product core mid-verification.
 * That keeps the Laravel -> FastAPI -> Laravel cycle to a single round trip
 * and makes the timeout budget easy to reason about.
 */
class HttpAgentClient implements AgentGateway
{
    public function verify(array $bundle): AgentResult
    {
        $timeout = (int) config('services.agent.timeout', 45);

        try {
            $response = Http::baseUrl(rtrim((string) config('services.agent.base_url'), '/'))
                ->withHeaders([
                    'X-Internal-Token' => (string) config('services.agent.token'),
                    'X-Request-Id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
                    'Accept' => 'application/json',
                ])
                ->timeout($timeout)
                ->connectTimeout(min(5, $timeout))
                ->retry(1, 250, throw: false)
                ->post('/agent/verify', $bundle);

            if ($response->failed()) {
                Log::warning('Agent runtime returned an error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return AgentResult::failed(sprintf(
                    'Agent runtime responded with HTTP %d.',
                    $response->status()
                ));
            }

            return AgentResult::fromResponse($response->json() ?? []);
        } catch (Throwable $e) {
            Log::error('Agent runtime unreachable', ['exception' => $e->getMessage()]);

            return AgentResult::failed('Agent runtime unreachable: '.$e->getMessage());
        }
    }

    public function health(): array
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('services.agent.base_url'), '/'))
                ->timeout(5)
                ->get('/health');

            return [
                'reachable' => $response->successful(),
                'status' => $response->status(),
                'detail' => $response->json() ?? [],
            ];
        } catch (Throwable $e) {
            return ['reachable' => false, 'status' => null, 'detail' => ['error' => $e->getMessage()]];
        }
    }
}
