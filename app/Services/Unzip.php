<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;

class Unzip
{
    public function __construct(protected Filesystem $filesystem, protected ZipArchive $zipper)
    {
    }

    public function extract(string $file, string $destination): void
    {
        $zip = $this->zipper;
        if ($zip->open($file) !== true) {
            throw new RuntimeException(trans('admin.download.errors.unzip'));
        }
        try {
            if ($zip->numFiles > 30000) {
                throw new RuntimeException('ZIP 文件数超过限制。');
            }
            $this->filesystem->ensureDirectoryExists($destination);
            $root = realpath($destination);
            if ($root === false) {
                throw new RuntimeException(trans('admin.download.errors.unzip'));
            }
            $bytes = 0;
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_contains($name, ':') || str_starts_with($name, '/') || preg_match('#(^|/)(\.\.?)(/|$)#', $name)) {
                    throw new RuntimeException('ZIP 文件路径格式错误。');
                }
                $key = strtolower(rtrim($name, '/'));
                if (isset($seen[$key])) {
                    throw new RuntimeException('ZIP 包含重复路径。');
                }
                $seen[$key] = true;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if (!in_array($type, [0, 0100000, 0040000], true)) {
                    throw new RuntimeException('ZIP 包含特殊文件。');
                }
                $bytes += $stat['size'];
                if ($bytes > 1073741824) {
                    throw new RuntimeException('ZIP 解压大小超过 1 GiB。');
                }
                $target = $root;
                foreach (explode('/', rtrim($name, '/')) as $part) {
                    $target .= '/'.$part;
                    if (is_link($target)) {
                        throw new RuntimeException('ZIP 解压目标包含符号链接。');
                    }
                }
            }
            if (!$zip->extractTo($root)) {
                throw new RuntimeException(trans('admin.download.errors.unzip'));
            }
        } finally {
            $zip->close();
        }
        $this->filesystem->delete($file);
    }
}
