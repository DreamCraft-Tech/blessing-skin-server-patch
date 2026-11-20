<?php

namespace Tests;

use App\Services\Unzip;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;

class UnzipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/bs-unzip-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem())->deleteDirectory($this->root));
    }

    private function archive(array $files): string
    {
        $path = $this->root.'/archive.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) $zip->addFromString($name, $contents);
        $zip->close();

        return $path;
    }

    public function testExtract(): void
    {
        $path = $this->archive(['plugin/package.json' => '{}', 'plugin/src/test.txt' => 'valid']);
        app(Unzip::class)->extract($path, $this->root.'/plugins');
        $this->assertSame('valid', file_get_contents($this->root.'/plugins/plugin/src/test.txt'));
        $this->assertFileDoesNotExist($path);
    }

    public function testRejectsTraversalBeforeExtractingAnyFile(): void
    {
        foreach (['../escaped.txt', '/absolute.txt', 'plugin/../../escaped.txt', 'plugin\\escaped.txt', 'plugin/file:stream'] as $name) {
            $path = $this->archive(['plugin/safe.txt' => 'safe', $name => 'invalid']);
            try {
                app(Unzip::class)->extract($path, $this->root.'/plugins');
                $this->fail('Dangerous archive accepted');
            } catch (RuntimeException) {
                $this->assertFileDoesNotExist($this->root.'/plugins/plugin/safe.txt');
            }
        }
    }

    public function testRejectsExistingSymlinkDestination(): void
    {
        mkdir($this->root.'/plugins');
        mkdir($this->root.'/outside');
        symlink($this->root.'/outside', $this->root.'/plugins/plugin');
        $path = $this->archive(['plugin/escaped.txt' => 'invalid']);
        try {
            app(Unzip::class)->extract($path, $this->root.'/plugins');
            $this->fail('Symlink destination accepted');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($this->root.'/outside/escaped.txt');
        } finally {
            unlink($this->root.'/plugins/plugin');
        }
    }

    public function testRejectsSymlinkEntries(): void
    {
        $path = $this->archive(['plugin/link' => '../../outside']);
        $zip = new ZipArchive();$zip->open($path);
        $zip->setExternalAttributesName('plugin/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);$zip->close();
        $this->expectException(RuntimeException::class);
        app(Unzip::class)->extract($path, $this->root.'/plugins');
    }
}
