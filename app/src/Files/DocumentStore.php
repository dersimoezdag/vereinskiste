<?php
declare(strict_types=1);

namespace Vereinskiste\Files;

use PDO;
use Throwable;

final class DocumentStore
{
    public function __construct(private PDO $pdo, private array $roots) {}

    public function addImage(string $path, string $name, int $actor): int
    {
        $dir = $this->roots['documents'];
        $file = (new FileStorage($dir, 2097152))->ingest($path, ['image/jpeg', 'image/png']);
        try {
            return (new StoredFileRepository($this->pdo))->register($file, 'documents',
                FileStorage::cleanName($name, $file['ext']), $actor);
        } catch (Throwable $e) {
            @unlink($dir . '/' . $file['storage_name']);
            throw $e;
        }
    }

    public function open(int $id): ?array
    {
        $file = (new StoredFileRepository($this->pdo))->find($id);
        if ($file === null || !isset($this->roots[$file['root']])
            || !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|xml|csv)\z/', $file['storage_name'])) { return null; }
        $path = $this->roots[$file['root']] . '/' . $file['storage_name'];
        if (!is_file($path) || is_link($path)) { return null; }
        return $file + ['path' => $path];
    }
}
