<?php

namespace ClarionApp\WizlightBackend\Commands;

use Illuminate\Console\Command;
use ClarionApp\WizlightBackend\Jobs\BulbDiscovery;

class WizlightDiscover extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wizlight:discover';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add bulb discovery job to queue';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        dispatch(new BulbDiscovery());
    }
}
