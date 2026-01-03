# AliyunMinioStorage Extension / 阿里云 OSS & MinIO 存储扩展

**AliyunMinioStorage** 是一个专为 MediaWiki 1.45+ 设计的高性能文件存储扩展。它允许 MediaWiki 使用阿里云 OSS、MinIO 或任何兼容 S3 协议的对象存储服务。

本扩展基于 **AWS SDK for PHP v3** 构建，结合了 MediaWiki 的原生文件后端框架，提供了极致的兼容性与性能表现。

---

## 主要功能特性 / Key Features

*   **全协议兼容**: 原生支持 Amazon S3 协议，完美适配阿里云 OSS (Aliyun) 和本地 MinIO 环境。
*   **多租户架构 (WikiFarm)**: 支持通过 `containerPaths` 映射，在同一个 Bucket 中利用路径前缀隔离不同站点的文件（Images, Thumbnails, Temp 等）。
*   **标准化全局配置**: 集成了 MediaWiki 1.45+ 的标准配置系统，可通过 `$wg` 前缀的全局变量轻松控制连接参数。
*   **高性能 Stat 缓存**: 内置文件元数据缓存机制，能够显著减少昂贵的 S3 API 调用（如 HeadObject），大幅提升页面加载速度。
*   **智能路径解析**: 能够自动将 MediaWiki 的逻辑存储路径（mwstore://）解析为物理对象存储键名，支持虚拟目录操作。
*   **完善的维护工具**: 附带专用维护脚本，支持一键健康检查和增删改查全流程验证。

---

## 安装方法 / Installation

1.  将本扩展下载或克隆到 `extensions/` 目录：
    ```bash
    cd extensions/
    git clone https://github.com/litanwei/AliyunMinioStorage.git AliyunMinioStorage
    ```

2.  在 `LocalSettings.php` 或 `FarmSettings.php` 中启用：
    ```php
    wfLoadExtension( 'AliyunMinioStorage' );
    ```

---

## 配置指南 / Configuration

本扩展支持两种配置方式：

### 1. 标准全局配置 (Recommended)

您可以在 `LocalSettings.php` 中直接设置以下全局变量：

```php
// 连接凭证
$wgAliyunMinioStorageCredentials = [
    'key'    => '您的 AccessKey ID',
    'secret' => '您的 AccessKey Secret'
];

// 存储区域 (OSS: 如 oss-cn-hangzhou; MinIO: 默认 us-east-1)
$wgAliyunMinioStorageRegion = 'oss-cn-hangzhou';

// 终端地址 (Endpoint)
$wgAliyunMinioStorageEndpoint = 'https://oss-cn-hangzhou.aliyuncs.com';

// 服务类型 ('aliyun' 或 'minio')
$wgAliyunMinioStorageServiceType = 'aliyun';

// Stat 缓存有效期 (默认 7 天)
$wgAliyunMinioStorageStatCacheExpiry = 604800;
```

### 2. FileBackend 后端配置 (针对多租户/WikiFarm)

如果您需要更精细的控制（如 WikiFarm 分站点隔离），可以在 `$wgFileBackends` 中进行配置：

```php
$wgFileBackends[] = [
    'name'           => 'farm-backend',
    'class'          => 'AliyunMinioStorage\AliyunMinioFileBackend',
    'endpoint'       => 'http://minio:9000',
    'region'         => 'us-east-1',
    'credentials'    => [
        'key'    => 'minioadmin',
        'secret' => 'minioadmin'
    ],
    // 关键配置：逻辑容器 -> 物理路径映射
    'containerPaths' => [
        'wiki1-local-public'  => 'shared-bucket/wiki1/images',
        'wiki1-local-thumb'   => 'shared-bucket/wiki1/thumb',
        'wiki2-local-public'  => 'shared-bucket/wiki2/images',
    ],
    // 可选：绑定静态资源域名 (CDN)
    'publicUrl'      => 'https://cdn.example.com',
];
```

---

## 维护工具 / Maintenance

运行以下命令验证存储后端是否配置正确并能够正常工作：

```bash
php maintenance/verify_storage.php
```

该工具将按顺序执行：
1.  **Backend 加载**：验证 MediaWiki 是否能正确识别该存储后端。
2.  **写测试 (Create)**：上传测试文件。
3.  **状态测试 (Stat)**：核对文件大小和元数据。
4.  **列表测试 (List)**：测试文件遍历功能。
5.  **删测试 (Delete)**：确保文件可被正确清理。

---

## 开发与贡献

*   **作者**: Litanwei <web@litanwei.com>
*   **版本**: 0.0.1
*   **许可证**: GPL-2.0-or-later
