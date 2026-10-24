<?php

namespace Tests;

use App\Services\UpdatePackage;
use Illuminate\Filesystem\Filesystem;
use ZipArchive;

class UpdatePackageTest extends TestCase
{
    private function archive(array $extra = [], string $prefix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bs-zip-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $files = array_merge([
            'composer.json' => json_encode(['name' => 'bs-community/blessing-skin-server', 'require' => ['php' => '^8.1']]),
            'artisan' => 'new artisan', 'bootstrap/app.php' => 'new bootstrap',
            'config/app.php' => "<?php return ['version'=>'6.0.4_patch'];",
            'public/index.php' => 'new index', 'vendor/autoload.php' => 'new autoload',
            'resources/views/assets/app.twig' => 'compiled entry', 'public/app/app.js' => 'compiled js',
        ], $extra);
        foreach ($files as $name => $content) {
            $zip->addFromString($prefix.$name, $content);
        }
        $zip->close();
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    private function service(bool $fail = false): UpdatePackage
    {
        $root = sys_get_temp_dir().'/bs-install-'.bin2hex(random_bytes(6));
        mkdir($root);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem())->deleteDirectory($root));

        return new class($root, $fail) extends UpdatePackage {
            public function __construct(public string $destination, private bool $fail)
            {
            }

            protected function root(): string
            {
                return $this->destination;
            }

            protected function publish(string $source, string $target): void
            {
                if ($this->fail && str_ends_with($target, '/vendor/autoload.php')) {
                    throw new \RuntimeException('simulated write failure');
                }
                parent::publish($source, $target);
            }
        };
    }

    private function prepare(UpdatePackage $service, string $path): array
    {
        $info = $service->prepare($path);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem())->deleteDirectory(storage_path('app/system-updates/'.$info['id'])));

        return $info;
    }

    public function testWrappedRuntimeInstallsAndPreservesSiteFiles()
    {
        $service = $this->service();
        file_put_contents($service->destination.'/.env', 'existing secret');
        mkdir($service->destination.'/storage');
        file_put_contents($service->destination.'/storage/data', 'existing texture');
        file_put_contents($service->destination.'/artisan', 'old artisan');
        $info = $this->prepare($service, $this->archive(['.env' => 'replacement', 'storage/data' => 'replacement', 'plugins/custom/bootstrap.php' => 'replacement', 'public/plugins/custom.js' => 'replacement'], 'release/'));
        $this->assertSame('6.0.4_patch', $info['version']);
        $this->assertCount(8, $info['files']);
        $service->install($info['id']);
        $this->assertSame('new artisan', file_get_contents($service->destination.'/artisan'));
        $this->assertSame('existing secret', file_get_contents($service->destination.'/.env'));
        $this->assertSame('existing texture', file_get_contents($service->destination.'/storage/data'));
        $this->assertFileDoesNotExist($service->destination.'/plugins/custom/bootstrap.php');
        $this->assertFileDoesNotExist($service->destination.'/public/plugins/custom.js');
        $this->assertSame('old artisan', file_get_contents(storage_path('app/system-updates/'.$info['id'].'/backup/artisan')));
    }

    public function testFailedInstallRestoresPreviousFiles()
    {
        $service = $this->service(true);
        file_put_contents($service->destination.'/artisan', 'original');
        $info = $this->prepare($service, $this->archive());
        try {
            $service->install($info['id']);
            $this->fail('Expected simulated failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated write failure', $e->getMessage());
        }
        $this->assertSame('original', file_get_contents($service->destination.'/artisan'));
        $this->assertFileDoesNotExist($service->destination.'/config/app.php');
    }

    public function testTraversalIsRejectedBeforeExtraction()
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->prepare($this->archive(['../escape.php' => 'escape']));
    }

    public function testSourceArchiveWithoutRuntimeIsRejected()
    {
        $path = $this->archive();
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->deleteName('vendor/autoload.php');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        $this->service()->prepare($path);
    }

    public function testIncompatiblePhpAndOlderVersionsAreRejected()
    {
        foreach ([['composer.json' => json_encode(['name' => 'bs-community/blessing-skin-server', 'require' => ['php' => '>=100']])], ['config/app.php' => "<?php return ['version'=>'5.0.0'];"]] as $extra) {
            try {
                $this->service()->prepare($this->archive($extra));
                $this->fail('Expected version rejection');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testSymlinkEntryAndDestinationAreRejected()
    {
        $path = $this->archive(['app/link' => 'target']);
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->setExternalAttributesName('app/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        try {
            $this->service()->prepare($path);
            $this->fail('Expected symlink rejection');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $service = $this->service();
        $info = $this->prepare($service, $this->archive());
        symlink(sys_get_temp_dir(), $service->destination.'/vendor');
        $this->expectException(\RuntimeException::class);
        $service->install($info['id']);
    }
}
