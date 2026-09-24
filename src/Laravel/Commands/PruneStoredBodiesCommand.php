<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Laravel\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Milzer\HttpLogger\Core\Contracts\BodyStore;
use Milzer\HttpLogger\Core\HttpLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Deletes stored bodies older than the retention period, for disks that cannot expire files
 * on their own (unlike e.g. a GCS/S3 bucket lifecycle rule). Schedule it daily.
 */
#[AsCommand(name: 'http-logger:prune', description: 'Delete stored HTTP log bodies older than the retention period')]
final class PruneStoredBodiesCommand extends Command
{
    public function handle(HttpLogger $logger, Repository $config): int
    {
        $store = $logger->store();

        if (! $store instanceof BodyStore) {
            $this->components->info('Body storage is disabled; nothing to prune.');

            return self::SUCCESS;
        }

        $days = $this->option('days') ?? $config->get('http-logger.storage.retention_days');

        if (! is_numeric($days) || (int) $days < 1) {
            $this->components->error('The retention must be at least 1 day. Set http-logger.storage.retention_days or pass --days.');

            return self::FAILURE;
        }

        $deleted = $store->prune(new DateTimeImmutable(sprintf('-%d days', (int) $days)));

        $this->components->info(sprintf('Deleted %d day folder(s) older than %d days.', $deleted, (int) $days));

        return self::SUCCESS;
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('days', null, InputOption::VALUE_REQUIRED, 'Delete bodies older than this many days (default: http-logger.storage.retention_days)'),
        ];
    }
}
