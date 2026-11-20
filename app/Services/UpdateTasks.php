<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class UpdateTasks
{
    public function remember(int $userId, array $package): array
    {
        $package = Arr::only($package, ['id', 'version', 'sha256', 'count', 'created_at']);
        $package['owner_id'] = $userId;
        $package['created_at'] = $package['created_at'] ?? time();
        if (!Storage::disk('local')->put($this->key($userId), json_encode($package, JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('无法保存更新任务，请检查 storage 目录权限和磁盘空间。');
        }

        return $package;
    }

    public function pending(int $userId): ?array
    {
        $contents = Storage::disk('local')->get($this->key($userId));
        $package = $contents ? json_decode($contents, true) : null;
        if (!is_array($package) || !preg_match('/\A[a-f0-9]{32}\z/D', $package['id'] ?? '') || ($package['created_at'] ?? 0) < time() - 3600) {
            return null;
        }

        return $package;
    }

    public function forget(int $userId): void
    {
        Storage::disk('local')->delete($this->key($userId));
    }

    private function key(int $userId): string
    {
        return 'system-updates/owners/'.$userId.'.json';
    }
}
