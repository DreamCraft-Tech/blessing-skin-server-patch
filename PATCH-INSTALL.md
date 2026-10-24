# 6.0.3_patch 安装与更新

运行环境：PHP 8.5、MariaDB、1Panel OpenResty。版本基于 Blessing Skin Server 6.0.2（52f6fef），更新内容见 CHANGELOG-PATCH.md。

## 首次部署本补丁

将 `blessing-skin-server_6.0.3_patch_overlay.zip` 解压到网站项目根目录，按压缩包相对路径覆盖程序文件。在网站 PHP 运行环境、项目根目录执行：

```sh
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan twig:clean
```

OPcache 关闭时间戳检查时，重启 PHP 运行环境。访问网站后，版本检查触发现有 `update` 命令，执行数据库迁移、写入版本和刷新缓存。

新建站点使用 `blessing-skin-server_6.0.3_patch_php85.zip` 完整运行包，OpenResty 运行目录为 `public/`。

## 检查更新页面

超级管理员在“检查更新”页面上传完整 ZIP 运行包。页面显示目标版本、文件数和 SHA-256，确认后安装。ZIP 可直接包含程序文件，或包含一层外目录；运行包包含 `vendor/autoload.php` 与已编译的 `public/app/` 资源。ZIP 安装支持同版本重装和更高版本更新。

左侧提供在线更新和 ZIP 更新；右侧“注意事项”下显示“当前版本更新日志”。日志内容由 `config/patch.php` 定义。

ZIP 安装按程序目录覆盖：`app`、`bootstrap`、`config`、`database`、`public`、`resources`、`routes`、`vendor` 及根目录程序清单文件。`.env`、`storage`、`plugins`、`bootstrap/cache` 和 `public/storage`、`public/uploads`、`public/textures`、`public/plugins` 沿用站点现有内容。

任务与文件备份位于 `storage/app/system-updates/<任务编号>/`，其中 `backup/` 保存被覆盖的原程序文件，`installed.json` 记录完成时间。校验任务有效期为一小时；安装写入失败时恢复本次已替换的程序文件。数据库迁移沿用主系统升级命令。

## 上传与执行参数

应用 ZIP 上限为 256 MiB，解压内容上限 1 GiB，条目上限 30000。PHP 与 OpenResty 的请求限制共同决定实际上传大小。可在 1Panel 按运行包大小设置，例如：

```ini
upload_max_filesize=256M
post_max_size=260M
max_execution_time=300
```

OpenResty 对应设置 `client_max_body_size 260m;`。PHP 进程需要程序目录和 `storage/app/system-updates` 的写权限。

## 验证

验证包包含 PHP、Twig、JavaScript 语法检查与 PHPUnit 结果；覆盖管理员界面、在线更新、ZIP 上传、PHP 与版本校验、路径检查、站点数据保留、程序文件备份及写入失败回退。
