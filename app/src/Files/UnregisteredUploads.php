<?php
declare(strict_types=1);

namespace Vereinskiste\Files;

use PDO;

/** Nur alte unregistrierte Dokument-Uploads; bestaetigte Dateien nie automatisch entfernen. */
final class UnregisteredUploads
{
    public function __construct(private PDO $pdo, private string $directory) {}

    public function clean(int $limit = 200): int
    {
        if (!is_dir($this->directory)) { return 0; }
        $removed = 0; $visited = 0;
        $stmt = $this->pdo->prepare("SELECT 1 FROM stored_files WHERE root='documents' AND storage_name=?");
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if (++$visited > max(1, min(1000, $limit))) { break; }
            if (!$file->isFile() || $file->isLink() || $file->getMTime() > time() - 86400
                || !preg_match('/^[a-f0-9]{32}\.(png|jpg|pdf|xml|csv)\z/', $file->getFilename())) { continue; }
            $stmt->execute([$file->getFilename()]);
            if ($stmt->fetchColumn() === false && unlink($file->getPathname())) { $removed++; }
        }
        return $removed;
    }
}
