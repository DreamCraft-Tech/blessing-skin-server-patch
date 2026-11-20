<?php

namespace App\Http\Controllers;

use App\Services\Unzip;
use App\Services\UpdatePackage;
use App\Services\UpdateTasks;
use Composer\CaBundle\CaBundle;
use Composer\Semver\Comparator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateController extends Controller
{
    public const SPEC = 2;

    public function showUpdatePage()
    {
        $info = $this->getUpdateInfo();
        $canUpdate = $this->canUpdate(Arr::get($info, 'info'));

        return view('admin.update', [
            'info' => [
                'latest' => Arr::get($info, 'info.latest'),
                'current' => config('app.version'),
            ],
            'error' => Arr::get($info, 'error', $canUpdate['reason']),
            'can_update' => $canUpdate['can'],
            'package' => app(UpdateTasks::class)->pending((int) auth()->id()) ?? session('system_update_package'),
            'patch_releases' => collect(config('patch.releases', []))->where('version', config('app.version'))->take(1)->values()->all(),
            'upload_limits' => app(\App\Services\UploadLimits::class)->current(),
        ]);
    }

    public function download(Request $request, Unzip $unzip, Filesystem $filesystem, UpdatePackage $packages)
    {
        if (str_contains($request->header('Accept', ''), 'application/x-ndjson')) {
            return response()->stream(function () use ($request, $unzip, $filesystem, $packages) {
                $emit = function (array $event) {
                    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
                    if (!app()->runningUnitTests()) {
                        if (ob_get_level() > 0) {
                            @ob_flush();
                        }
                        flush();
                    }
                };
                $result = $this->performDownload($request, $unzip, $filesystem, $packages, $emit);
                $emit(['type' => 'result'] + $result->getData(true));
            }, 200, ['Content-Type' => 'application/x-ndjson; charset=UTF-8', 'Cache-Control' => 'no-cache, no-store, no-transform', 'X-Accel-Buffering' => 'no']);
        }

        return $this->performDownload($request, $unzip, $filesystem, $packages, function (array $event) {});
    }

    private function performDownload(Request $request, Unzip $unzip, Filesystem $filesystem, UpdatePackage $packages, callable $emit)
    {
        $path = null;
        try {
            $emit(['type' => 'status', 'message' => '正在连接更新源…']);
            $result = $this->getUpdateInfo();
            if (!$result['ok']) {
                return json($result['error'], 1);
            }
            $available = $this->canUpdate($result['info']);
            if (!$available['can']) {
                return json($available['reason'] ?: trans('admin.update.info.up-to-date'), 1);
            }
            $info = $result['info'];
            if (!is_string($info['url'] ?? null) || !filter_var($info['url'], FILTER_VALIDATE_URL) || !in_array(parse_url($info['url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new \RuntimeException('更新源未提供有效的 HTTP / HTTPS 下载地址。');
            }
            $path = tempnam(sys_get_temp_dir(), 'bs');
            if (!$path) {
                throw new \RuntimeException('无法创建下载临时文件，请检查临时目录权限和可用空间。');
            }
            $emit(['type' => 'status', 'message' => '正在下载运行包…']);
            $last = 0;
            $response = Http::connectTimeout(10)->timeout(600)->withOptions([
                'sink' => $path,
                'verify' => CaBundle::getSystemCaRootBundlePath(),
                'progress' => function ($total, $downloaded) use ($emit, &$last) {
                    if (microtime(true) - $last < 0.25 && (!$total || $downloaded < $total)) {
                        return;
                    }
                    $last = microtime(true);
                    $emit(['type' => 'progress', 'downloaded' => $downloaded, 'total' => $total]);
                },
            ])->get($info['url']);
            if (!$response->ok()) {
                return json('运行包下载失败：HTTP '.$response->status().'。请检查更新源，或使用 ZIP 运行包更新。', 1);
            }
            $emit(['type' => 'status', 'message' => '下载完成，正在校验并解压运行包…']);
            if (Arr::get($info, 'package') === 'full') {
                $checksum = Arr::get($info, 'sha256', '');
                if (!is_string($checksum) || !preg_match('/\A[a-f0-9]{64}\z/D', $checksum) || !hash_equals($checksum, hash_file('sha256', $path))) {
                    throw new \RuntimeException('运行包 SHA-256 校验失败，请重新下载。');
                }
                $pending = $packages->prepare($path);
                if ($pending['version'] !== $info['latest']) {
                    throw new \RuntimeException('运行包版本与更新信息不一致。');
                }
                $pending = app(UpdateTasks::class)->remember((int) $request->user()->uid, $pending);
                $request->session()->put('system_update_package', $pending);

                return json('运行包已下载并校验，请确认后安装。', 0, ['review' => true]);
            }
            $unzip->extract($path, base_path());
            $filesystem->delete(storage_path('options.php'));

            return json(trans('admin.update.complete'), 0);
        } catch (ConnectionException $e) {
            Log::warning('System update download connection failed', ['error' => $e->getMessage()]);

            return json($this->connectionError($e), 1);
        } catch (\Throwable $e) {
            Log::error('System update download failed', ['exception' => $e]);

            return json($e instanceof \RuntimeException ? $e->getMessage() : '运行包处理失败，请检查目录权限、磁盘空间和站点运行日志。', 1);
        } finally {
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function connectionError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (preg_match('/cURL error (\d+)/', $message, $match)) {
            $reason = [6 => '无法解析下载域名', 7 => '无法连接下载服务器', 18 => '下载未完成，连接提前关闭', 23 => '下载文件写入失败，请检查磁盘空间和临时目录权限', 28 => '连接或下载超时', 35 => 'TLS 握手失败', 60 => 'TLS 证书校验失败'][$match[1]] ?? '网络连接中断';

            return '更新源连接失败：'.$reason.'（cURL '.$match[1].'）。请检查服务端网络或使用 ZIP 运行包更新。';
        }

        return '更新源连接失败：网络连接中断。请检查服务端 DNS、网络及站点运行日志。';
    }

    public function upload(Request $request, UpdatePackage $packages)
    {
        $max = (int) ceil(app(\App\Services\UploadLimits::class)->applicationBytes() / 1024);
        $request->validate(['package' => 'required|file|max:'.$max]);
        try {
            $file = $request->file('package');
            if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
                throw new \RuntimeException('请选择 ZIP 运行包。');
            }
            $info = $packages->prepare($file->getPathname());
            $info = app(UpdateTasks::class)->remember((int) $request->user()->uid, $info);
            $request->session()->put('system_update_package', $info);

            return redirect()->route('admin.update.view')->with('update_notice', '运行包校验完成，请确认版本与校验值后安装。');
        } catch (\Throwable $e) {
            return redirect()->route('admin.update.view')->with('update_error', $e instanceof \RuntimeException ? $e->getMessage() : '运行包处理失败，请检查文件和目录权限。');
        }
    }

    public function install(Request $request, UpdatePackage $packages, Filesystem $filesystem)
    {
        $input = $request->validate(['id' => 'required|string|size:32']);
        $pending = app(UpdateTasks::class)->pending((int) $request->user()->uid);
        $session = $request->session()->get('system_update_package');
        $matches = fn ($task) => is_array($task) && isset($task['id']) && (!isset($task['owner_id']) || (int) $task['owner_id'] === (int) $request->user()->uid) && hash_equals($task['id'], $input['id']);
        if (!$matches($pending) && !$matches($session)) {
            return redirect()->route('admin.update.view')->with('update_error', '更新任务已失效，请重新下载或上传运行包后确认安装。');
        }
        try {
            $packages->install($input['id']);
            $filesystem->delete(storage_path('options.php'));
            $filesystem->delete(app()->getCachedConfigPath());
            $filesystem->delete(app()->getCachedRoutesPath());
            Artisan::call('view:clear');
            Artisan::call('twig:clean');
            $request->session()->forget('system_update_package');
            app(UpdateTasks::class)->forget((int) $request->user()->uid);

            return redirect()->route('admin.update.view')->with('update_notice', '系统程序已更新，版本检查与数据库升级将在后续请求中完成。');
        } catch (\Throwable $e) {
            return redirect()->route('admin.update.view')->with('update_error', $e instanceof \RuntimeException ? $e->getMessage() : '安装失败，请检查站点目录权限与运行日志。');
        }
    }

    protected function getUpdateInfo()
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withOptions([
                'verify' => CaBundle::getSystemCaRootBundlePath(),
            ])->get(option('update_source') ?: config('app.update_source'));

            if ($response->ok()) {
                $info = $response->json();
                if (Arr::get($info, 'spec') === self::SPEC) {
                    return ['ok' => true, 'info' => $info];
                }

                return ['ok' => false, 'error' => trans('admin.update.errors.spec')];
            }

            return ['ok' => false, 'error' => 'HTTP status code: '.$response->status()];
        } catch (\Throwable $e) {
            Log::warning('System update source failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $this->connectionError($e)];
        }
    }

    protected function canUpdate($info = [])
    {
        if (!is_array($info) || !isset($info['php'], $info['latest'])) {
            return ['can' => false, 'reason' => ''];
        }
        $php = Arr::get($info, 'php');
        preg_match('/(\d+\.\d+\.\d+)/', PHP_VERSION, $matches);
        $version = $matches[1];
        if (Comparator::lessThan($version, $php)) {
            return [
                'can' => false,
                'reason' => trans('admin.update.errors.php', ['version' => $php]),
            ];
        }

        $can = Comparator::greaterThan(Arr::get($info, 'latest'), config('app.version'));

        return ['can' => $can, 'reason' => ''];
    }
}
