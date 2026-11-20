<?php

namespace App\Services;

class AuthLink
{
    public static function absolute(string $relative): string
    {
        $root = rtrim((string) (option('site_url') ?: config('app.url')), '/');
        $parts = parse_url($root);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('站点地址配置无效。');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($path !== '' && str_starts_with($relative, $path.'/')) {
            return substr($root, 0, -strlen($path)).$relative;
        }

        return $root.'/'.ltrim($relative, '/');
    }
}
