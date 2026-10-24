# 6.0.4_patch 安装与更新

运行环境：PHP 8.5、MariaDB、1Panel OpenResty。完整运行包包含 Composer 依赖和已构建的前端资源，网站运行目录为 public/。

## 更新现有网站

已安装 6.0.3_patch 的站点：在“检查更新”的 ZIP 更新区域上传 blessing-skin-server_6.0.4_patch_php85.zip，核对版本后确认安装。

手动更新：将 overlay.zip 解压到网站项目根目录覆盖程序文件，在网站 PHP 运行环境、项目根目录执行：

```sh
php artisan config:clear
php artisan route:clear
php artisan migrate --force
php artisan view:clear
php artisan twig:clean
```

随后重启 PHP 运行环境以刷新 OPcache。ZIP 更新沿用站点的 .env、storage、plugins 和上传资源；安装备份位于 storage/app/system-updates/。

## 通知与公告

管理菜单位于“仪表板”和“用户管理”之间。发布位置可选站点公告、站内信或两者。编辑器提供标题、文本、提示框、折叠卡片和分隔线，可拖动排序、调整属性并实时预览；源码模式支持 Markdown 和 HTML，并可导入为可视化组件。

公告显示在用户仪表盘，站内信进入用户消息列表。支持草稿、编辑、已读统计、撤回与重新发布；撤回前显示确认弹窗。编辑已有站内信保留原收件人和已读状态，重新发布生成新一轮投递。编辑页面顶部和底部均提供返回列表。“当前版本更新日志”采用折叠卡片展示。

升级迁移将原站点公告及历史站内信导入管理中心，保留历史消息的已读状态和时间。数据表使用 InnoDB。

## 上传上限

ZIP 更新页面读取当前 PHP 的 upload_max_filesize、post_max_size，并结合应用上限显示可上传大小。应用上限由 .env 的 SYSTEM_UPDATE_MAX_MB 指定，默认 256 MiB。OpenResty 的 client_max_body_size 在 1Panel 网站配置中设置。

## 文件

php85.zip 为完整运行包，overlay.zip 为程序覆盖包。verification.zip 包含测试、语法检查和版本信息，SHA256SUMS 提供文件校验值。版本更新内容见运行包内 CHANGELOG-PATCH.md。
