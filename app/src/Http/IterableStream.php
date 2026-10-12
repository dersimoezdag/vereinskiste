<?php
declare(strict_types=1);

namespace Vereinskiste\Http;

use Iterator;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Nur lesbarer, nicht rueckspulbarer PSR-7-Stream ueber einen Iterator von Strings.
 * Er erlaubt Antworten, die nie komplett im Speicher stehen (z. B. CSV-Export).
 */
final class IterableStream implements StreamInterface
{
    private string $buffer = '';
    private int $position = 0;
    private bool $closed = false;

    /** @param Iterator<int,string> $chunks */
    public function __construct(private Iterator $chunks)
    {
    }

    public function __toString(): string
    {
        $all = '';
        while (!$this->eof()) {
            $all .= $this->read(8192);
        }
        return $all;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->buffer = '';
    }

    public function detach()
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->closed || ($this->buffer === '' && !$this->chunks->valid());
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Der Stream ist nicht rueckspulbar.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Der Stream ist nicht rueckspulbar.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Der Stream ist nur lesbar.');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function read(int $length): string
    {
        while (!$this->closed && strlen($this->buffer) < $length && $this->chunks->valid()) {
            $this->buffer .= (string) $this->chunks->current();
            $this->chunks->next();
        }
        $out = substr($this->buffer, 0, $length);
        $this->buffer = (string) substr($this->buffer, strlen($out));
        $this->position += strlen($out);
        return $out;
    }

    public function getContents(): string
    {
        return $this->__toString();
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
