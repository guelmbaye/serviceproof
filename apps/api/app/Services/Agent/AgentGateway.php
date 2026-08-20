<?php

namespace App\Services\Agent;

interface AgentGateway
{
    /**
     * Run one verification through the AI agent runtime.
     *
     * @param  array  $bundle  Fully pre-loaded context: claim, work order,
     *                         policy, device descriptor, budget, tools.
     */
    public function verify(array $bundle): AgentResult;

    public function health(): array;
}
