<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Render\PageContext;

/** <host server="a,b">: only shown when the site is served by one of those names or addresses. */
final class HostTag implements Tag
{
    public function __construct(private readonly string $serverName, private readonly string $serverAddress)
    {
    }

    public function apply(string $html, PageContext $page): string
    {
        foreach (Attributes::tags($html, 'host') as $tag) {
            $servers = Attributes::quoted($tag, 'server');
            if ($servers === null) {
                continue;
            }
            foreach (explode(',', $servers) as $server) {
                $server = trim($server);
                if (($this->serverName !== '' && $this->serverName === $server) || ($this->serverAddress !== '' && $this->serverAddress === $server)) {
                    $html = str_ireplace($tag, '<unhost>', $html);
                }
            }
        }
        return BlockTag::remove($html, 'host');
    }
}
