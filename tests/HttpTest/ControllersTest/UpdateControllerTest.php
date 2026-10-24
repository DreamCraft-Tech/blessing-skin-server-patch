<?php

namespace Tests;

use App\Models\User;
use App\Services\Unzip;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

class UpdateControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function testShowUpdatePage()
    {
        Http::fakeSequence()
            ->pushStatus(404)
            ->push($this->fakeUpdateInfo('8.9.3', ['spec' => 0]))
            ->push($this->fakeUpdateInfo('8.9.3', ['php' => '100.0.0']))
            ->push($this->fakeUpdateInfo('8.9.3'));

        // Can't connect to update source
        $this->get('/admin/update')->assertSee(config('app.version'));

        // Missing `spec` field
        $this->get('/admin/update')->assertSee(trans('admin.update.errors.spec'));

        // Low PHP version
        $this->get('/admin/update')->assertSee(trans('admin.update.errors.php', ['version' => '100.0.0']));

        // New version available
        $this->get('/admin/update')->assertSee(config('app.version'))->assertSee('8.9.3');
    }

    public function testDownload()
    {
        Http::fake([
            config('app.update_source') => Http::sequence()
                ->push($this->fakeUpdateInfo('1.2.3'))
                ->whenEmpty($this->fakeUpdateInfo('8.9.3')),
            'https://whatever.test/8.9.3/update.zip' => Http::sequence()
                ->pushStatus(404)
                ->pushStatus(200),
        ]);

        // Already up-to-date
        $this->postJson('/admin/update/download')
            ->assertJson([
                'code' => 1,
                'message' => trans('admin.update.info.up-to-date'),
            ]);

        // Download
        $this->postJson('/admin/update/download')->assertJson(['code' => 1]);
        $this->mock(Unzip::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn();
        });
        $this->mock(\Illuminate\Filesystem\Filesystem::class, function ($mock) {
            $mock->shouldReceive('delete')->with(storage_path('options.php'))->once();
            $mock->shouldReceive('exists')->with(storage_path('install.lock'))->andReturn(true);
        });
        $this->postJson('/admin/update/download')
            ->assertJson(['code' => 0, 'message' => trans('admin.update.complete')]);
    }

    public function testOfflinePageIncludesZipUpdateAndCurrentChangelog()
    {
        $this->actingAs(User::factory()->superAdmin()->create(['locale' => 'zh_CN']));
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('offline'));
        $response = $this->get('/admin/update')->assertOk()->assertSee('ZIP 运行包更新')->assertSee('当前版本更新日志')->assertSee('6.0.3_patch');
        if ($directory = getenv('UPDATE_UI_PREVIEW_DIR')) {
            file_put_contents($directory.'/update.html', $response->getContent());
        }
    }

    public function testUploadRequiresSuperAdministratorAndMatchingSession()
    {
        foreach ([User::NORMAL, User::ADMIN] as $permission) {
            $this->actingAs(User::factory()->create(['permission' => $permission]));
            $this->post('/admin/update/upload')->assertForbidden();
            $this->post('/admin/update/install', ['id' => str_repeat('a', 32)])->assertForbidden();
        }
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->post('/admin/update/install', ['id' => str_repeat('a', 32)])->assertForbidden();
    }

    public function testUploadShowsPackageReviewBeforeInstallation()
    {
        $info = ['id' => str_repeat('a', 32), 'version' => '6.0.4_patch', 'sha256' => str_repeat('b', 64), 'count' => 100, 'files' => [], 'created_at' => time()];
        $this->mock(\App\Services\UpdatePackage::class, fn ($mock) => $mock->shouldReceive('prepare')->once()->andReturn($info));
        $this->post('/admin/update/upload', ['package' => \Illuminate\Http\UploadedFile::fake()->create('runtime.zip', 100, 'application/zip')])->assertRedirect('/admin/update')->assertSessionHas('system_update_package', $info);
        Http::fake([config('app.update_source') => Http::response('', 404)]);
        $this->get('/admin/update')->assertOk()->assertSee('确认安装 6.0.4_patch')->assertSee(str_repeat('b', 64));
    }

    protected function fakeUpdateInfo(string $version, $extra = [])
    {
        return array_merge([
            'spec' => 2,
            'php' => '8.1.0',
            'latest' => $version,
            'url' => "https://whatever.test/$version/update.zip",
        ], $extra);
    }
}
