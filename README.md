# AliyunMinioStorage — 阿里云 OSS & MinIO 存储扩展

**AliyunMinioStorage** 是一个专为 MediaWiki 1.45+ 设计的文件存储后端扩展。它允许 MediaWiki 使用阿里云 OSS、MinIO 或任何兼容 S3 协议的对象存储服务来替代本地文件系统。

本扩展基于 **AWS SDK for PHP v3** 构建，继承 MediaWiki 原生 `FileBackendStore` 框架，提供生产可用的兼容性与性能表现。

---

## 功能特性

*   **S3 协议兼容**: 基于 AWS SDK，原生支持 Amazon S3、阿里云 OSS 和 MinIO。自动识别 Path Style (MinIO) 与 Virtual Hosted Style (OSS) URL 模式。
*   **多租户架构 (WikiFarm)**: 通过 `containerPaths` 映射，在同一 Bucket 内用路径前缀隔离不同站点文件（Images、Thumbnails、Temp 等）。
*   **高性能 Stat 缓存**: 内置文件元数据缓存 (7 天 TTL + 不存在文件 60 秒短缓存)，显著减少 HeadObject API 调用。
*   **安全加固**:
    *   流式输出 Content-Type 白名单过滤，防止 XSS 攻击
    *   `X-Content-Type-Options: nosniff` 安全头
    *   S3 异常信息脱敏，敏感信息仅写入服务端日志
    *   路径遍历 (`../`) 防护
    *   凭证完整性校验
*   **维护工具**: 附带 `verify_storage.php` 维护脚本，支持 CRUD 全流程健康检查。

---

## 安装

1.  克隆到 `extensions/` 目录：
    ```bash
    cd extensions/
    git clone https://github.com/litanwei-mediawiki-extension/AliyunMinioStorage.git
    ```

2.  安装 Composer 依赖：
    ```bash
    cd AliyunMinioStorage/
    composer install --no-dev
    ```

3.  在 `LocalSettings.php` 或 `FarmSettings.php` 中启用：
    ```php
    wfLoadExtension( 'AliyunMinioStorage' );
    ```

---

## 配置

本扩展通过 `$wgFileBackends` 数组进行配置。所有连接参数均在后端配置中声明。

### 基础示例 (单站点)

```php
$wgFileBackends[] = [
    'name'           => 'my-s3-backend',
    'class'          => 'AliyunMinioStorage\AliyunMinioFileBackend',
    'endpoint'       => 'https://oss-cn-hangzhou-internal.aliyuncs.com',
    'region'         => 'oss-cn-hangzhou',
    'serviceType'    => 'aliyun',  // 'aliyun' | 'minio' (决定 URL 样式)
    'credentials'    => [
        'key'    => '您的 AccessKey ID',
        'secret' => '您的 AccessKey Secret',
    ],
    'containerPaths' => [
        'local-public'  => 'my-bucket/images',
        'local-thumb'   => 'my-bucket/thumb',
        'local-temp'    => 'my-bucket/temp',
        'local-deleted' => 'my-bucket/deleted',
    ],
];
```

### 多租户 WikiFarm 示例

```php
// 为每个站点动态生成 containerPaths 映射
$wikiId = 'wiki_' . $wgDBname;  // 如 wiki_huatuo, wiki_huawen 等

$wgFileBackends[] = [
    'name'           => 'farm-backend',
    'class'          => 'AliyunMinioStorage\AliyunMinioFileBackend',
    'endpoint'       => 'http://minio:9000',
    'region'         => 'us-east-1',
    'serviceType'    => 'minio',
    'credentials'    => [
        'key'    => getenv('MINIO_ACCESS_KEY'),
        'secret' => getenv('MINIO_SECRET_KEY'),
    ],
    'containerPaths' => [
        "{$wikiId}-local-public"  => "wikifarm-storage/{$wikiId}/images",
        "{$wikiId}-local-thumb"   => "wikifarm-storage/{$wikiId}/thumb",
        "{$wikiId}-local-temp"    => "wikifarm-storage/{$wikiId}/temp",
        "{$wikiId}-local-deleted" => "wikifarm-storage/{$wikiId}/deleted",
    ],
];
```

### 配置参数说明

| 参数             | 类型   | 必填 | 说明                                               |
| ---------------- | ------ | ---- | -------------------------------------------------- |
| `name`           | string | ✅    | 后端名称，供 `$wgLocalFileRepo` 引用               |
| `class`          | string | ✅    | 固定为 `AliyunMinioStorage\AliyunMinioFileBackend` |
| `endpoint`       | string | ✅    | S3 服务地址                                        |
| `region`         | string | ❌    | 存储区域，默认 `us-east-1`                         |
| `serviceType`    | string | ❌    | `'aliyun'` / `'oss'` / `'minio'` (默认 `minio`)    |
| `credentials`    | array  | ✅    | 必须包含 `key` 和 `secret`，不可为空               |
| `containerPaths` | array  | ❌    | 逻辑容器→物理路径映射 (推荐配置)                   |

---

## 维护工具

运行以下命令验证存储后端健康状态：

```bash
php extensions/AliyunMinioStorage/maintenance/verify_storage.php
```

验证流程：
1.  **Backend 加载** — 检查 MediaWiki 能否识别该后端
2.  **写入测试 (Create)** — 上传测试文件到 S3
3.  **状态测试 (Stat)** — 核对文件大小和元数据
4.  **列表测试 (List)** — 验证文件遍历功能
5.  **删除测试 (Delete)** — 确认文件可被正确清理

---

## 技术信息

| 项目       | 值                          |
| ---------- | --------------------------- |
| **版本**   | 1.0.0                       |
| **兼容性** | MediaWiki ≥ 1.45, PHP ≥ 8.1 |
| **依赖**   | `aws/aws-sdk-php` ^3.0      |
| **许可证** | GPL-2.0-or-later            |
| **作者**   | Litanwei <web@litanwei.com> |
