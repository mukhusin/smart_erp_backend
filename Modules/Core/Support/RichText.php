<?php

namespace Modules\Core\Support;

use Mews\Purifier\Facades\Purifier;

/** HTML from the rich text editor, cleaned to a safe set of formatting (no scripts, links, images or styles). */
final class RichText
{
    private const CONFIG = [
        'HTML.Allowed' => 'p[style],br,strong,b,em,i,u,s,ul,ol,li,h2[style],h3[style],blockquote',
        'CSS.AllowedProperties' => 'text-align',
        'AutoFormat.RemoveEmpty' => true,
        'HTML.TargetBlank' => false,
    ];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        $html = preg_replace(['/<!--.*?-->/s', '/<(xml|style|script|o:p)\b[^>]*>.*?<\/\1>/is'], '', $html);
        $clean = trim((string) Purifier::clean($html, self::CONFIG));

        return self::text($clean) === '' ? null : $clean;
    }

    /** Plain text of the HTML (for length checks and summaries). */
    public static function text(?string $html): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h[23]|\/blockquote)\b[^>]*>/i', "\n", (string) $html)), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text)));
    }
}
