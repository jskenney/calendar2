<?php

declare(strict_types=1);

namespace Cal5\Render\Tags;

use Cal5\Auth\Csrf;
use Cal5\Auth\LockCodes;
use Cal5\Render\PageContext;
use Cal5\Support\Html;

/**
 * <lock code="a,b">: shown once a matching code was entered (remembered in the
 * session, v4); otherwise students get a form to enter the code.  The
 * instructor always sees the content.
 */
final class LockTag implements Tag
{
    public function apply(string $html, PageContext $page): string
    {
        foreach (Attributes::tags($html, 'lock') as $tag) {
            $codes = Attributes::quoted($tag, 'code');
            if ($codes !== null && LockCodes::opens($page->session, $codes)) {
                $html = str_ireplace($tag, '<unlock>', $html);
            }
        }
        if ($page->instructor) {
            return $html;
        }
        $form = '<form method="post" class="cal5-unlock"><label>Enter the password to see this content '
            . '<input type="password" name="lock" autocomplete="off"></label>'
            . '<input type="hidden" name="' . Csrf::FIELD . '" value="' . Html::escape(Csrf::token($page->session)) . '">'
            . '<button type="submit">Unlock</button></form>';
        return BlockTag::remove($html, 'lock', $form);
    }
}
