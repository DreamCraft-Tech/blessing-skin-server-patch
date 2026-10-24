<?php

namespace Tests;

use App\Models\Communication;
use App\Models\User;
use App\Services\CommunicationRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CommunicationsControllerTest extends TestCase
{
    private function fields(array $extra = []): array
    {
        return array_merge(['title' => '测试公告', 'kind' => 'both', 'editor' => 'blocks', 'blocks' => [
            ['type' => 'alert', 'tone' => 'info', 'content' => '欢迎 **玩家**'],
            ['type' => 'collapse', 'title' => '使用帮助', 'content' => '折叠正文', 'tone' => 'danger', 'expanded' => false],
        ], 'receiver' => 'all', 'operation' => 'publish'], $extra);
    }

    public function testDraftPublishEditReadAndWithdraw()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->actingAs($admin)->post('/admin/communications', $this->fields(['operation' => 'draft']))->assertRedirect();
        $message = Communication::where('title', '测试公告')->firstOrFail();
        $this->assertSame(0, DB::table('notifications')->count());
        $this->actingAs($user)->get('/user')->assertOk()->assertDontSee('折叠正文');
        $this->actingAs($admin)->put('/admin/communications/'.$message->id, $this->fields())->assertRedirect();
        $this->assertSame(2, DB::table('notifications')->where('data->communication_id', $message->id)->count());
        $mail = $user->notifications()->firstOrFail();
        $this->actingAs($user)->get('/user/inbox')->assertOk()->assertSee('测试公告')->assertSee('未读');
        $this->get('/user/inbox/'.$mail->id)->assertOk()->assertSee('折叠正文')->assertSee('data-toggle="collapse"', false);
        $read = $mail->fresh()->read_at;
        $this->assertNotNull($read);
        $this->actingAs($admin)->put('/admin/communications/'.$message->id, $this->fields(['title' => '更新后的公告', 'editor' => 'source', 'source' => '更新后的正文']))->assertRedirect();
        $this->assertEquals($read, $mail->fresh()->read_at);
        $this->actingAs($user)->get('/user/inbox/'.$mail->id)->assertOk()->assertSee('更新后的公告')->assertSee('更新后的正文');
        $this->get('/user')->assertOk()->assertSee('更新后的正文');
        $this->actingAs($admin)->post('/admin/communications/'.$message->id.'/withdraw')->assertRedirect();
        $this->actingAs($user)->get('/user/inbox/'.$mail->id)->assertNotFound();
        $this->get('/user')->assertOk()->assertDontSee('更新后的正文');
        $this->assertSame('withdrawn', $message->fresh()->status);
    }

    public function testUserScopeAndAdminPermissions()
    {
        $admin = User::factory()->admin()->create();
        $first = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($first)->get('/admin/communications')->assertForbidden();
        $this->post('/admin/communications', $this->fields())->assertForbidden();
        $this->postJson('/admin/communications/preview', $this->fields())->assertForbidden();
        $this->actingAs($admin)->post('/admin/communications', $this->fields(['kind' => 'message', 'receiver' => 'uid', 'uid' => $first->uid]))->assertRedirect();
        $mail = $first->notifications()->firstOrFail();
        $this->actingAs($other)->get('/user/inbox/'.$mail->id)->assertNotFound();
        $this->get('/user/inbox')->assertOk()->assertDontSee('测试公告');
    }

    public function testPreviewSanitizesAndImportsExistingBootstrapCards()
    {
        $admin = User::factory()->admin()->create();
        $source = '### 欢迎'."\n\n".'<div class="alert alert-danger">提示</div><div id="announcements"><div class="card bg-red"><div class="card-header" data-toggle="collapse" data-target="#details">折叠标题</div><div id="details" class="card-body collapse">正文<script>alert(1)</script><img src="/ok.png" onerror="alert(1)"></div></div></div>';
        $this->actingAs($admin)->postJson('/admin/communications/import', ['source' => $source])->assertOk()->assertJsonPath('blocks.1.type', 'alert')->assertJsonPath('blocks.2.type', 'collapse');
        $response = $this->postJson('/admin/communications/preview', ['editor' => 'source', 'source' => $source])->assertOk();
        $html = $response->json('html');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('data-toggle="collapse"', $html);
        preg_match('/data-target="#([^"]+)"/', $html, $match);
        $this->assertStringContainsString('id="'.$match[1].'"', $html);
        $response = $this->get('/admin/communications?new=1')->assertOk()->assertSee('返回列表')->assertSee('内容设计器')->assertSee('实时预览');
        if ($path = getenv('COMMUNICATIONS_UI_PREVIEW_DIR')) {
            file_put_contents($path.'/communications.html', $response->getContent());
        }
    }

    public function testReferenceComponentsRetainIndependentCollapseTargets()
    {
        $renderer=app(CommunicationRenderer::class);
        $source=file_get_contents(base_path('tests/fixtures/announcement-components.md'));
        $html=$renderer->render(['editor'=>'source','source'=>$source]);
        preg_match_all('/ id="([^"]+)"/',$html,$ids);
        $this->assertSame(count($ids[1]),count(array_unique($ids[1])));
        preg_match_all('/data-target="#([^"]+)"/',$html,$targets);
        $this->assertCount(2,$targets[1]);
        foreach($targets[1] as $target){$this->assertContains($target,$ids[1]);}
        $blocks=$renderer->importBlocks($source);
        $this->assertCount(2,array_filter($blocks,fn($block)=>$block['type']==='collapse'));
        $this->assertStringContainsString('bg-cyan',$html);
    }

    public function testGlobalAnnouncementsAlsoAppearWithLocalizedLegacyContent()
    {
        Communication::where('legacy_key','option:announcement')->update(['source'=>'默认旧公告']);
        Communication::create(['legacy_key'=>'option:announcement_zh_CN','title'=>'中文','kind'=>'announcement','status'=>'published','editor'=>'source','locale'=>'zh_CN','source'=>'中文旧公告']);
        Communication::create(['title'=>'全部语言','kind'=>'announcement','status'=>'published','editor'=>'source','locale'=>'','source'=>'全体新公告']);
        app()->setLocale('zh_CN');$html=app(\App\Services\Communications::class)->announcements();
        $this->assertStringContainsString('中文旧公告',$html);$this->assertStringContainsString('全体新公告',$html);$this->assertStringNotContainsString('默认旧公告',$html);
        app()->setLocale('en');$this->assertStringContainsString('默认旧公告',app(\App\Services\Communications::class)->announcements());
    }

    public function testBodySurvivesValidationFailureAndBlocksUseFormJson()
    {
        $admin = User::factory()->admin()->create();
        $fields = $this->fields(['operation' => 'draft', 'editor' => 'blocks', 'blocks_json' => json_encode([['type' => 'text', 'content' => '表单正文']])]);
        unset($fields['blocks']);
        $this->actingAs($admin)->post('/admin/communications', $fields)->assertRedirect();
        $this->assertSame('表单正文', Communication::where('title', '测试公告')->firstOrFail()->blocks[0]['content']);
        $fields['title'] = '';
        $this->post('/admin/communications', $fields)->assertSessionHasErrors('title')->assertSessionHasInput('blocks');
    }

    public function testLegacyMigrationKeepsReadStateAndLocalizedAnnouncement()
    {
        $user = User::factory()->create();
        $user->notifyNow(new \App\Notifications\SiteMessage('历史站内信', '历史正文'));
        $mail = $user->notifications()->firstOrFail();
        $mail->markAsRead();
        $read = $mail->fresh()->read_at;
        DB::table('options')->updateOrInsert(['option_name' => 'announcement_zh_CN'], ['option_value' => '旧中文公告']);
        Schema::drop('communications');
        $migration = require database_path('migrations/2026_10_24_000001_create_communications.php');
        $migration->up();
        $this->assertEquals($read, $mail->fresh()->read_at);
        $this->assertNotEmpty($mail->fresh()->data['communication_id']);
        $this->assertDatabaseHas('communications', ['source' => '旧中文公告', 'locale' => 'zh_CN', 'status' => 'published']);
        $this->actingAs($user)->get('/user/inbox/'.$mail->id)->assertOk()->assertSee('历史正文');
    }

    public function testStaleQueuedNotificationIsHiddenAfterWithdrawal()
    {
        $user = User::factory()->create();
        $message = Communication::create(['title' => '撤回', 'kind' => 'message', 'status' => 'withdrawn', 'editor' => 'source', 'source' => '内容']);
        $user->notifyNow(new \App\Notifications\SiteMessage('迟到消息', '内容', $message->id));
        $this->actingAs($user)->get('/user/inbox')->assertOk()->assertDontSee('迟到消息');
        \Laravel\Passport\Passport::actingAs($user, ['Notification.Read'], 'oauth');
        $this->getJson('/api/user/notifications')->assertOk()->assertExactJson([]);
    }
}
