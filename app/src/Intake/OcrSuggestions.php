<?php
declare(strict_types=1);
namespace Vereinskiste\Intake;

use Vereinskiste\Ledger\DateInput;
use Vereinskiste\Ledger\Money;
use Vereinskiste\Ledger\InvalidAmount;

/** Conservative suggestions only. Never apply OCR data or bank details automatically. */
final class OcrSuggestions
{
    public static function extract(string $text): array
    {
        $fields = [];
        if(preg_match('/\b(EUR|USD|GBP|CHF)\b/u',$text,$currency)) {$fields['currency']=$currency[1];}
        elseif(str_contains($text,'€')) {$fields['currency']='EUR';}
        if (preg_match('/(?:Gesamt(?:betrag)?|Summe|Total|zu zahlen)\s*:?\s*(?:EUR|€)?\s*([0-9][0-9., ]{0,20})/iu', $text, $m)) {
            try { $fields['gross'] = (string) Money::parse(trim($m[1])); } catch (InvalidAmount) {}
        }
        if (preg_match('/\b([0-3][0-9])\.([01][0-9])\.([12][0-9]{3})\b/u', $text, $m)) {
            $date = $m[3] . '-' . $m[2] . '-' . $m[1];
            if (DateInput::valid($date)) { $fields['invoice_date'] = $date; }
        }
        if (preg_match('/(?:Rechnungs(?:nummer|nr\.?|[- ]Nr\.?)|Invoice\s*(?:No\.?|Number))\s*:?\s*([\pL\pN][\pL\pN\/.\-]{0,99})/iu', $text, $m)) { $fields['number'] = $m[1]; }
        return ['fields' => $fields, 'text' => mb_substr($text, 0, 20000), 'warning' => 'intake.ocr.check'];
    }
}
