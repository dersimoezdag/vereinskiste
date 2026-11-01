<?php
declare(strict_types=1);
namespace Vereinskiste\ClubDocuments;

use HTMLPurifier;
use HTMLPurifier_Config;

final class Content
{
    /** Notizfeld als sicheres HTML; aeltere Notizen ohne Tags bleiben mit ihren Zeilenumbruechen lesbar. */
    public static function note(string $text): string
    {
        return preg_match('/<\/?[a-z!][^>]*>/i', $text) !== 1
            ? nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            : self::clean($text);
    }

    /** Lesbare Fassung fuer den Aenderungsverlauf. */
    public static function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br />', '<br>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function clean(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p[style],br,strong,b,em,i,u,s,strike,sub,sup,h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],blockquote,ul,ol[start|type],li,span[style],div[style],a[href|title],hr[style],table[border|cellpadding|cellspacing|style],thead,tbody,tfoot,tr,th[colspan|rowspan|style],td[colspan|rowspan|style],caption,pre,code');
        // HTML Purifier groups print page breaks with its optional CSS definitions.
        $config->set('CSS.Proprietary', true);
        $config->set('CSS.AllowedProperties', ['color','background-color','font-size','font-family','font-weight','font-style','text-align','text-decoration','margin-left','width','height','border','border-collapse','padding','vertical-align','page-break-before','page-break-after']);
        $config->set('URI.AllowedSchemes', ['https' => true, 'http' => true, 'mailto' => true]);
        return (new HTMLPurifier($config))->purify($html);
    }
}
