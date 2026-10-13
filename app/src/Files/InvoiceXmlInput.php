<?php
declare(strict_types=1);

namespace Vereinskiste\Files;

use Vereinskiste\Ledger\ValidationError;

/** Sichere XML-Grundpruefung. Fachliche Profilvalidierung erfolgt im Rechnungsimport. */
final class InvoiceXmlInput
{
    public function validate(string $path): void
    {
        if (!class_exists(\XMLReader::class)) { throw new ValidationError(['receipt' => 'validation.receipt.type']); }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader(); $root = null; $count = 0;
        try {
            if (!$reader->open($path, null, LIBXML_NONET)) { throw new ValidationError(['receipt' => 'validation.receipt.type']); }
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            $reader->setParserProperty(\XMLReader::VALIDATE, false);
            while ($reader->read()) {
                if (++$count > 200000 || $reader->depth > 64 || $reader->nodeType === \XMLReader::DOC_TYPE
                    || $reader->nodeType === \XMLReader::ENTITY_REF
                    || $reader->namespaceURI === 'http://www.w3.org/2001/XInclude') {
                    throw new ValidationError(['receipt' => 'validation.receipt.type']);
                }
                if ($root === null && $reader->nodeType === \XMLReader::ELEMENT) {
                    $root = [$reader->localName, $reader->namespaceURI];
                }
            }
            $allowed = [
                ['Invoice', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2'],
                ['CreditNote', 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2'],
                ['CrossIndustryInvoice', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100'],
            ];
            if (!in_array($root, $allowed, true) || libxml_get_errors() !== []) {
                throw new ValidationError(['receipt' => 'validation.receipt.type']);
            }
        } finally {
            $reader->close(); libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
    }
}
