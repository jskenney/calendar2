<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Access\PostMeeting;
use Cal5\Render\PageContext;

/** <postmeeting>: shown to students once the meeting is over (features.postmeeting); always to the instructor. */
final class PostMeetingTag implements Tag
{
    public function apply(string $html, PageContext $page): string
    {
        if ($page->instructor || $page->config->features->postmeeting === 'off') {
            return $html; // v4 leaves the tags (and so the content) alone
        }
        $visible = $page->eventContext !== null && PostMeeting::visible($page->config, $page->eventContext, $page->now);
        return $visible ? BlockTag::unwrap($html, 'postmeeting') : BlockTag::remove($html, 'postmeeting');
    }
}
