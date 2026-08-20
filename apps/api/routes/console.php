<?php

use App\Console\Commands\VerifyClaimCommand;
use Illuminate\Support\Facades\Artisan;

Artisan::command('serviceproof:about', function () {
    $this->info('ServiceProof AI — Network-Powered Operational Assurance');
    $this->line('Laravel owns the product. FastAPI owns the agent.');
    $this->line('CAMARA provides the network evidence. PostgreSQL owns the truth.');
    $this->newLine();
    $this->table(['Setting', 'Value'], [
        ['Agent runtime', config('services.agent.base_url')],
        ['Agent fake mode', config('services.agent.fake') ? 'yes' : 'no'],
        ['Default policy', config('serviceproof.default_policy')],
        ['Enabled tools', implode(', ', config('serviceproof.enabled_tools'))],
    ]);
})->purpose('Show the ServiceProof runtime configuration');
