# 自动检查与发布

推送 main、向 main 提交 Pull Request，以及手动运行 Actions 时执行 PHP 8.5 语法检查、发布工具测试、后端测试、前端类型检查和测试，并构建完整生产运行包。生产依赖通过 composer.lock 安装，前端资源通过 Yarn 构建。

检查通过后，main 的运行自动发布当前版本。版本号同时维护在 package.json 与 config/app.php，构建时校验两者一致。Release 标题为 Release <版本号>，首次发布创建 v<版本号> 标签；同版本的正式 Release 已存在时保持原发布内容。后续升级时提高这两个文件中的版本号，并在 CHANGELOG-PATCH.md 增加更新内容。

每次发布包含一个 blessing-skin-server\_<版本>\_php85.zip，以及 manifest.json、SHA256SUMS。ZIP 含生产 vendor 与前端资源，可在管理后台“检查更新”上传。流水线使用站点实际的 UpdatePackage::prepare 对 ZIP 进行暂存验证。

发布先创建草稿，上传全部附件并核对附件名称和大小后转为正式 Release；中断后可重新运行相同提交的失败任务，补齐该草稿。正式 Release 保持不变。PR 运行仅检查和生成 Actions 构建产物，发布任务只对 main 开放。

工作流使用 GitHub 自动提供的 GITHUB_TOKEN；检查任务仅有 contents:read，发布任务有 contents:write。无需配置个人访问令牌或上传本机 SSH 私钥。第三方 Actions 固定到提交 SHA。
