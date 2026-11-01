<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

/** Seitenzustand fuer Listen, gerendert von components/pagination.twig. */
final class Pagination
{
    /** @param list<int> $sizes erlaubte Eintraege pro Seite, der erste ist die Vorgabe */
    private function __construct(
        public readonly int $page,
        public readonly int $pages,
        public readonly int $perPage,
        public readonly int $total,
        private readonly array $sizes,
        private readonly string $path,
        private readonly array $params,
    ) {}

    /**
     * @param array<string,mixed> $query rohe Query-Parameter (page, per_page)
     * @param array<string,mixed> $params Filter, die in jedem Link erhalten bleiben
     * @param list<int> $sizes
     */
    public static function from(array $query, int $total, string $path, array $params = [], array $sizes = [25, 50, 100]): self
    {
        $rawSize = $query['per_page'] ?? null;
        $perPage = is_string($rawSize) && in_array((int) $rawSize, $sizes, true) ? (int) $rawSize : $sizes[0];
        $pages = max(1, (int) ceil($total / $perPage));
        $rawPage = $query['page'] ?? '1';
        $page = is_string($rawPage) && preg_match('/^\d{1,9}\z/', $rawPage) === 1 ? (int) $rawPage : 1;
        return new self(min(max(1, $page), $pages), $pages, $perPage, $total, $sizes, $path, $params);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** Eintrag fuer ein verstecktes Feld im Filterformular, damit die Seitengroesse beim Filtern bleibt. */
    public function perPageParam(): ?int
    {
        return $this->perPage === $this->sizes[0] ? null : $this->perPage;
    }

    /** @return array<string,mixed> */
    public function view(): array
    {
        $numbers = [];
        foreach ($this->visiblePages() as $number) {
            $numbers[] = $number === null ? ['gap' => true]
                : ['gap' => false, 'number' => $number, 'url' => $this->url($number, $this->perPage), 'current' => $number === $this->page];
        }
        $sizes = [];
        foreach ($this->sizes as $size) {
            $sizes[] = ['size' => $size, 'current' => $size === $this->perPage,
                // Erster sichtbarer Eintrag bleibt beim Wechsel der Seitengroesse sichtbar.
                'url' => $this->url((int) floor($this->offset() / $size) + 1, $size)];
        }
        return [
            'total' => $this->total,
            'from' => $this->total === 0 ? 0 : $this->offset() + 1,
            'to' => min($this->offset() + $this->perPage, $this->total),
            'prev_url' => $this->page > 1 ? $this->url($this->page - 1, $this->perPage) : null,
            'next_url' => $this->page < $this->pages ? $this->url($this->page + 1, $this->perPage) : null,
            'numbers' => $this->pages > 1 ? $numbers : [],
            'sizes' => count($this->sizes) > 1 && $this->total > $this->sizes[0] ? $sizes : [],
        ];
    }

    /** Erste, letzte und die Nachbarn der aktuellen Seite; null steht fuer eine Luecke. @return list<?int> */
    private function visiblePages(): array
    {
        $out = [];
        $previous = 0;
        for ($n = 1; $n <= $this->pages; $n++) {
            if ($n !== 1 && $n !== $this->pages && abs($n - $this->page) > 1) { continue; }
            if ($n - $previous === 2) { $out[] = $n - 1; }
            elseif ($n - $previous > 2) { $out[] = null; }
            $out[] = $n;
            $previous = $n;
        }
        return $out;
    }

    private function url(int $page, int $size): string
    {
        $params = $this->params;
        if ($size !== $this->sizes[0]) { $params['per_page'] = $size; }
        $params['page'] = $page;
        return $this->path . '?' . http_build_query($params);
    }
}
