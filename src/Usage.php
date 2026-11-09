<?php

declare(strict_types=1);

namespace PdfConversionApi;

/**
 * The key's plan, its limits and this month's count, from GET /v2/usage.
 */
final class Usage
{
    public function __construct(
        /** The plan's name, for example `Free`. */
        public readonly string $plan,
        /** Successful conversions included each calendar month. */
        public readonly int $included,
        /** Successful conversions so far this month. */
        public readonly int $used,
        /** What is left of $included this month, or null when the API doesn't say. */
        public readonly ?int $remaining,
        /** Whether the plan keeps converting past $included. */
        public readonly bool $overage,
        /** Conversions running for the account right now. */
        public readonly int $running,
        /** How many conversions the plan runs at the same time. */
        public readonly int $concurrentLimit,
        /** The largest request the plan accepts, all files together, in MB. */
        public readonly int $maxUploadMb,
        /** Start of this month's count, in UTC. */
        public readonly \DateTimeImmutable $periodStart,
        /** End of this month's count, in UTC; the count starts again then. */
        public readonly \DateTimeImmutable $periodEnd,
    ) {
    }
}
