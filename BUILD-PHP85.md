# DreamCraft Blessing Skin Server Patch

基于 Blessing Skin Server 6.0.2 开发快照 `52f6fef` 的 PHP 8.5 / Laravel 10 补丁分支，当前版本 **6.0.8_patch**。

## 修改历史

保留上游完整历史。后续签名提交按 PHP 8.5 适配、6.0.3_patch、6.0.4_patch、6.0.5_patch、6.0.6_patch 顺序重建自已交付版本包，每次在原文件路径更新。打开文件 History 或查看 commit diff 可追踪具体改动。提交日期记录本次重建时间，版本日期见 CHANGELOG-PATCH.md。版本标签留待后续 Actions 发布流程生成。

主要功能包含管理员仪表板入口、ZIP 运行包更新、通知与公告管理、组件式编辑器、站内信及统一分页。上游来源和历史说明见 docs/UPSTREAM.md。

## 开发

环境：PHP 8.5、Composer、Node.js 24、Yarn 1.x。PHP 扩展见 composer.json。

```sh
composer install
yarn install --frozen-lockfile
php artisan test
yarn build
```

测试配置使用仓库内 .env.testing 的 SQLite 内存数据库。生产环境使用 .env.example 生成 .env 并设置 MariaDB 连接，PHP 数据库扩展为 pdo_mysql。1Panel OpenResty 规则见 docs/1panel-openresty.conf，网站运行目录 public/。

## 构建运行包

```sh
python3 tools/build-php85.py --output /absolute/path/to/new-output
```

可通过 --php、--composer、--yarn 指定可执行文件路径。构建脚本安装锁定依赖、构建前端、复制通讯编辑器资源，并生成 PHP 8.5 ZIP 和 SHA256SUMS。使用已安装依赖时可加 --skip-install。

更新说明见 PATCH-INSTALL.md，各版本更新内容见 CHANGELOG-PATCH.md。原项目作者与 MIT 许可保留在下方和 LICENSE 中。
