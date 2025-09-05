<?php

namespace App\Service\Ports;

interface SegmentBuildQueue
{
    /** @return string|int entry id */
    public function enqueue(array $job): string|int|null;

    /** For debugging/observability */
    public function getStream(): string;
}

interface DistributedLock
{
    /** Acquire lock (ttl seconds). Return lock token if acquired, null otherwise. */
    public function acquire(string $key, int $ttlSec): ?string;

    /** Release lock (must use the same token to avoid releasing others’ locks). */
    public function release(string $key, string $token): void;
}
