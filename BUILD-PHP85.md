# Blessing Skin Server：PHP 8.5 构建说明

- 源仓库：https://github.com/bs-community/blessing-skin-server
- 分支：dev；提交：`52f6fefed08e091c08e409c67e31d00b00ff00ee`。
- 基础版本：6.0.2 开发分支快照，并非官方稳定发行包。
- 构建日期：2026-10-23（Asia/Shanghai）。
- 验证环境：macOS arm64，PHP 8.5.12，Composer 2.10.3，Node.js 24.22.1，Yarn 1.22.22。
- Composer `config.platform.php` 固定为 `8.5.0`，未忽略 PHP 或扩展版本要求。

## 产物内容

`blessing-skin-server-php85-52f6fef.zip` 为可部署代码包，包含 PHP 源码、生产 Composer 依赖、Webpack 生产构建资源及模板。PHP 通常由服务器解释执行，因此这里的“编译产物”是部署包，不是原生二进制或可移植 OPcache 字节码。运行服务器仍需安装 PHP 8.5 与所需扩展。

`blessing-skin-server-php85-source.zip` 为修改后的源码，包含前端源码、测试、完整锁文件和本说明，不含 `.git`、`vendor`、`node_modules`、构建工具运行时、部署数据库、部署密钥或测试产生的数据。

`php85.patch` 记录对上游提交的全部改动。

## 兼容修改

1. Composer 依赖解析目标固定为 PHP 8.5.0。
2. 更新原锁文件拒绝 PHP 8.5 的 Nette 依赖，并在原有版本约束内更新 Carbon、React Promise、Guzzle、Symfony、Twig 等相关依赖。
3. MySQL SSL 配置在 PHP 8.5 使用 `Pdo\Mysql::ATTR_SSL_CA`，同时保留较旧 PHP 的条件兼容。
4. 按上游 CI 流程编译前端，复制背景与 favicon，安装 `--no-dev --optimize-autoloader` 生产依赖。

## 验证

验证日志见 `verification.zip`。

- PHP 8.5 代码语法检查。
- Composer 配置及锁文件验证。
- 生产依赖真实扩展及 PHP 平台要求检查。
- Twig 模板：101/101 通过。
- 路由列表命令成功返回 JSON。
- HTTP 冒烟验证：全新未安装状态下 `/setup`、`/setup/info` 均返回 200。
- 最终 PHPUnit：**228 项测试、1766 个断言全部通过，无测试失败或弃用提示**（PHP 8.5.12）。
- 前端生产构建成功，有 2 条非阻断子编译警告及旧 Browserslist 数据提示。

## 部署

1. 解压部署包，将 Web 根目录指向 `public/`，配置 Nginx 或 Apache 的 Laravel URL 重写规则。
2. 安装 PHP 8.5，以及 ctype、curl、dom、fileinfo、filter、gd、hash、imagick、json、libxml、mbstring、openssl、pcre、PDO、session、tokenizer、xml、zip，并为所选数据库启用对应 PDO 驱动。
3. 在项目根目录复制 `.env.example` 为 `.env`，填写数据库与邮件配置，运行 `php artisan key:generate`。
4. 确保 PHP 进程可以写入 `storage/` 和 `bootstrap/cache/`，访问 `/setup` 完成安装。
5. 此包未带预置应用密钥、用户数据、安装锁或数据库凭据。插件需自行验证 PHP 8.5 兼容性。

## 已知限制

完整更新被 Composer 的安全公告检查阻止：上游 Laravel 10 / Passport 11 的版本范围无法解析出全部无已知漏洞的依赖。本构建未关闭安全拦截，也未跨框架主版本升级；通过局部更新减少旧依赖的公告数量。

最终生产依赖审计仍有 **6 条公告，涉及 2 个包**：firebase/php-jwt, laravel/framework。详情在 `verification.zip` 中的 `audit-final.json`。这不是已完成安全升级的发行版；公开生产部署前仍需处理这些上游安全问题。

源码保留上游 `.env.testing` 及其公开测试密钥，仅供测试，不得作为部署配置。

测试使用 SQLite 内存数据库；HTTP 验证覆盖安装页面。未验证实际 MySQL/PostgreSQL 服务、邮件投递、第三方插件或生产 Web 服务器集成。

## 从源码复现

准备 PHP 8.5、Composer、Node.js（含 npm）及 Yarn 1.22.22，在源码根目录执行：

```sh
composer install --prefer-dist --no-interaction
mkdir -p resources/views/overrides
yarn install --frozen-lockfile --non-interactive
yarn build
cp resources/assets/src/images/bg.webp public/app/
cp resources/assets/src/images/favicon.ico public/app/
php vendor/bin/phpunit
APP_ENV=testing php artisan twig:lint
```

全新检出时不要预置 `storage/install.lock`；它会使应用尝试读取尚未配置的数据库。发行时另建干净目录，安装 `composer install --no-dev --prefer-dist --optimize-autoloader`，并排除开发依赖、测试环境和本机缓存。不要把配置或路由的本机缓存带入部署包。

## 锁文件版本变化

| 包 | 原版本 | 构建版本 |
| --- | --- | --- |
| carbonphp/carbon-doctrine-types | 2.1.0 | 2.1.1 |
| doctrine/deprecations | 1.1.4 | 1.1.6 |
| doctrine/lexer | 3.0.1 | 3.0.3 |
| egulias/email-validator | 4.0.3 | 4.0.4 |
| guzzlehttp/guzzle | 7.9.2 | 7.16.0 |
| guzzlehttp/promises | 2.0.4 | 2.6.0 |
| guzzlehttp/psr7 | 2.7.0 | 2.13.2 |
| league/commonmark | 2.6.1 | 2.10.4 |
| league/flysystem | 3.29.1 | 3.36.0 |
| league/flysystem-local | 3.29.0 | 3.35.3 |
| league/mime-type-detection | 1.16.0 | 1.17.0 |
| nesbot/carbon | 2.72.6 | 2.73.0 |
| nette/schema | v1.3.2 | v1.4.0 |
| nette/utils | v4.0.5 | v4.1.6 |
| paragonie/constant_time_encoding | v3.0.0 | v3.1.3 |
| phpseclib/phpseclib | 3.0.43 | 3.0.57 |
| symfony/console | v6.4.17 | v6.4.48 |
| symfony/css-selector | v6.4.13 | v6.4.45 |
| symfony/deprecation-contracts | v3.5.1 | v3.7.1 |
| symfony/error-handler | v6.4.17 | v6.4.47 |
| symfony/event-dispatcher | v7.2.0 | v7.4.17 |
| symfony/event-dispatcher-contracts | v3.5.1 | v3.7.1 |
| symfony/finder | v6.4.17 | v6.4.47 |
| symfony/http-foundation | v6.4.16 | v6.4.48 |
| symfony/http-kernel | v6.4.17 | v6.4.48 |
| symfony/mailer | v6.4.13 | v6.4.46 |
| symfony/mime | v6.4.17 | v6.4.46 |
| symfony/polyfill-ctype | v1.31.0 | v1.37.0 |
| symfony/polyfill-intl-grapheme | v1.31.0 | v1.43.0 |
| symfony/polyfill-intl-idn | v1.31.0 | v1.43.0 |
| symfony/polyfill-intl-normalizer | v1.31.0 | v1.43.0 |
| symfony/polyfill-mbstring | v1.31.0 | v1.43.0 |
| symfony/polyfill-php80 | v1.31.0 | v1.43.0 |
| symfony/polyfill-php83 | v1.31.0 | v1.43.0 |
| symfony/polyfill-uuid | v1.31.0 | v1.43.0 |
| symfony/process | v6.4.15 | v6.4.48 |
| symfony/routing | v6.4.16 | v6.4.47 |
| symfony/service-contracts | v3.5.1 | v3.7.3 |
| symfony/string | v7.2.0 | v7.4.21 |
| symfony/translation | v6.4.13 | v6.4.44 |
| symfony/translation-contracts | v3.5.1 | v3.7.1 |
| symfony/uid | v6.4.13 | v6.4.48 |
| symfony/var-dumper | v6.4.15 | v6.4.48 |
| symfony/yaml | v5.4.45 | v5.4.54 |
| twig/twig | v3.18.0 | v3.30.0 |
| masterminds/html5 | 2.9.0 | 2.11.0 |
| react/promise | v3.2.0 | v3.3.0 |
| symfony/dom-crawler | v6.4.16 | v6.4.44 |
| symfony/filesystem | v7.2.0 | v7.4.21 |
| symfony/options-resolver | v7.2.0 | v7.4.8 |
| symfony/polyfill-php81 | v1.31.0 | v1.38.1 |
| symfony/stopwatch | v7.2.2 | v7.4.8 |
