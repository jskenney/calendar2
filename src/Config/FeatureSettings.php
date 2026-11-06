<?php

declare(strict_types=1);

namespace Cal5\Config;

final class FeatureSettings
{
    public const LEGACY_JS = ['jquery', 'datatables', 'chartjs'];

    /** @param list<string> $legacyJs */
    public function __construct(
        /** off (content always shown, v4 without $REVEALHOUR) | end (after the meeting) | HH:MM */
        public readonly string $postmeeting,
        public readonly ?string $accessLog,
        public readonly bool $legacyCss,
        public readonly array $legacyJs,
        public readonly bool $debug,
    ) {
    }
}
