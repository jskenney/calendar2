<?php

declare(strict_types=1);

namespace Cal5\Config;

final class FeatureSettings
{
    /** Libraries features.legacy_js can load; "auto" loads each one on the pages whose scripts use it. */
    public const LEGACY_JS = ['jquery', 'datatables', 'chartjs', 'auto'];

    /** @param list<string> $legacyJs libraries loaded on every page, and/or "auto" */
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
