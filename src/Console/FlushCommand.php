<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Console;

use Illuminate\Console\Command;
use Infinitypaul\Idempotency\IdempotencyManager;

final class FlushCommand extends Command
{
    protected $signature = 'idempotency:flush
        {key? : The idempotency key to flush}
        {--scope= : Scope prefix the key was stored under}';

    protected $description = 'Flush cached idempotency entries for a given key.';

    public function handle(IdempotencyManager $manager): int
    {
        $key = $this->argument('key');

        if (! is_string($key) || $key === '') {
            $this->error('Provide an idempotency key to flush. Bulk wipe is intentionally not supported.');

            return self::FAILURE;
        }

        $scope = $this->option('scope');
        $manager->flush($key, is_string($scope) ? $scope : null);

        $this->info('Flushed idempotency entries for '.$key.'.');

        return self::SUCCESS;
    }
}
