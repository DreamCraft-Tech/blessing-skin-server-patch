<?php

namespace Tests;

use App\Models\User;
use App\Services\Facades\Option;

class UpdateSourcesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->superAdmin()->create(['locale' => 'zh_CN']));
    }

    public function testSourcesRenderBelowRecaptchaAndSave()
    {
        $response = $this->get('/admin/options?lang=zh_CN')->assertOk()->assertSee('主站更新检测 URL')->assertSee('插件仓库 URL')->assertSee('card card-danger');
        $this->assertLessThan(strpos($response->getContent(), 'submit_updates'), strpos($response->getContent(), 'submit_recaptcha'));
        if ($directory = getenv('OPTIONS_UI_PREVIEW_DIR')) {
            file_put_contents($directory.'/options.html', $response->getContent());
        }
        $this->post('/admin/options', ['option' => 'updates', 'update_source' => 'https://updates.example.test/update.json', 'plugins_registry' => 'https://plugins.example.test/registry_{lang}.json'])->assertOk()->assertSee('https://updates.example.test/update.json');
        $this->assertSame('https://updates.example.test/update.json', option('update_source'));
        $this->assertSame('https://plugins.example.test/registry_{lang}.json', option('plugins_registry'));
    }

    public function testInvalidSourceDoesNotChangeEitherSetting()
    {
        Option::set('update_source', 'https://old.example.test/update.json');
        $this->from('/admin/options')->post('/admin/options', ['option' => 'updates', 'update_source' => 'file:///tmp/update.json', 'plugins_registry' => 'https://plugins.example.test/registry.json'])->assertRedirect('/admin/options')->assertSessionHasErrors('update_source');
        $this->assertSame('https://old.example.test/update.json', option('update_source'));
    }

    public function testEmptySourcesUseConfiguredDefaults()
    {
        $this->post('/admin/options', ['option' => 'updates', 'update_source' => '', 'plugins_registry' => ''])->assertOk()->assertSee(config('app.update_source'))->assertSee(config('plugins.registry'));
    }
}
