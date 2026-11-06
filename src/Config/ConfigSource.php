<?php

declare(strict_types=1);

namespace Cal5\Config;

/** Where a course's configuration comes from (see docs/design/configuration.md). */
enum ConfigSource: string
{
    /** calendar.ini.php in the course directory */
    case Ini = 'ini';
    /** variables set by a v4 calendar.php */
    case Legacy = 'legacy';
    /** nothing found: show the setup page */
    case None = 'none';
}
