<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Storage;

use DateTimeInterface;
use League\Flysystem\FilesystemOperator;
use Milzer\HttpLogger\Core\Contracts\BodyStore;
use Milzer\HttpLogger\Core\Serialization\BodyContext;
use Milzer\HttpLogger\Core\Serialization\StoredBody;

/**
 * Stores large bodies on any Flysystem filesystem (every Laravel disk is one), one file per
 * body, in one folder per day:
 *
 *   {root}/2026/09/24/150116-outgoing-21200c57ff98fa8e-response.json
 *
 * Day folders make retention cheap: pruning deletes whole folders instead of inspecting files.
 */
final readonly class FilesystemBodyStore implements BodyStore
{
    private string $root;

    /**
     * @param  string  $disk  Name reported in log entries, e.g. the Laravel disk name.
     * @param  string  $root  Folder inside the filesystem that holds the day folders.
     */
    public function __construct(
        private FilesystemOperator $filesystem,
        private string $disk = 'default',
        string $root = 'http-logs',
    ) {
        $this->root = trim($root, '/');
    }

    public function store(string $contents, string $extension, BodyContext $context): StoredBody
    {
        $path = $this->path(sprintf(
            '%s/%s-%s-%s-%s.%s',
            $context->occurredAt->format('Y/m/d'),
            $context->occurredAt->format('His'),
            $context->direction->value,
            $context->correlationId,
            $context->part,
            $extension,
        ));

        $this->filesystem->write($path, $contents);

        return new StoredBody($this->disk, $path, strlen($contents));
    }

    public function prune(DateTimeInterface $before): int
    {
        $cutoff = $before->format('Ymd');
        $deleted = 0;

        foreach ($this->folders($this->root, 4) as $year) {
            foreach ($this->folders($year, 2) as $month) {
                foreach ($this->folders($month, 2) as $day) {
                    if (basename($year).basename($month).basename($day) < $cutoff) {
                        $this->filesystem->deleteDirectory($day);
                        $deleted++;
                    }
                }

                $this->deleteIfEmpty($month);
            }

            $this->deleteIfEmpty($year);
        }

        return $deleted;
    }

    /**
     * Sub-folders whose name is a number with the given amount of digits (year, month or day).
     *
     * @return list<string>
     */
    private function folders(string $path, int $digits): array
    {
        $folders = [];

        foreach ($this->filesystem->listContents($path, false) as $item) {
            if ($item->isDir() && preg_match('/^\d{'.$digits.'}$/', basename($item->path())) === 1) {
                $folders[] = $item->path();
            }
        }

        sort($folders);

        return $folders;
    }

    private function deleteIfEmpty(string $path): void
    {
        if ($this->filesystem->listContents($path, false)->toArray() === []) {
            $this->filesystem->deleteDirectory($path);
        }
    }

    private function path(string $relative): string
    {
        return $this->root === '' ? $relative : $this->root.'/'.$relative;
    }
}
