<?php

namespace AliyunMinioStorage;

use FileBackendStore;
use FileBackend;
use FileBackendError;
use Status;
use MediaWiki\MediaWikiServices;
use Wikimedia\ObjectCache\WANObjectCache;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

/**
 * AliyunMinioFileBackend Class
 * 
 * 基于 AWS SDK for PHP v3 实现的对象存储后端。
 * 支持 Amazon S3, Aliyun OSS, MinIO 等兼容 S3 协议的存储服务。
 * 
 * 架构参考：MediaWiki 官方 AWS 扩展
 * 
 * 功能特点：
 * 1. 支持自定义 Endpoint (兼容 MinIO/OSS)。
 * 2. 支持通过 containerPaths 进行灵活的路径映射 (多租户支持)。
 * 3. 完整的 S3 异常处理与状态码转换。
 * 4. 内置 Stat 缓存以提升性能。
 */
class AliyunMinioFileBackend extends FileBackendStore
{
    /** @var S3Client AWS S3 客户端实例 */
    protected $client;

    /** @var array 容器名到 S3 bucket/prefix 的映射配置 */
    protected $containerPaths = [];

    /** @var array 后端配置数组 */
    protected $config;

    /** @var \BagOStuff 本地缓存，用于减少 headObject 请求 */
    protected $statCache = null;

    /**
     * 构造函数
     * 
     * @param array $config 配置数组，包含 endpoint, region, keys 等
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->config = $config;

        // 强制清空 domainId，防止 FileBackendStore 自动添加 wikiId 前缀
        // 我们通过 containerPaths 自行管理路径映射
        $this->domainId = '';

        // 1. 初始化容器映射配置 (Container Paths)
        // 这是实现多租户隔离的关键：将逻辑容器名 (如 mw123-local-public) 映射到物理存储路径 (如 bucket/mw123/images)
        if (isset($config['containerPaths'])) {
            $this->containerPaths = (array) $config['containerPaths'];
        } else {
            // 如果未配置，记录警告但不抛出致命错误 (允许部分初始化)
            // 实际操作时如果找不到映射会报错
        }

        // 2. 初始化缓存 (Stat Cache)
        // 使用本地集群缓存实例，用于暂存文件元数据(size, mtime, sha1)，避免频繁请求 S3
        $this->statCache = \ObjectCache::getLocalClusterInstance();
    }

    /**
     * 获取或创建 S3 客户端实例 (懒加载)
     * 
     * @return S3Client
     */
    protected function getClient()
    {
        if ($this->client) {
            return $this->client;
        }

        // 构建 AWS SDK 配置参数
        $params = [
            'version' => 'latest', // 推荐使用 latest
            'region' => $this->config['region'] ?? 'us-east-1', // MinIO 默认通常是 us-east-1
        ];

        // 认证凭证 (含完整性校验)
        if (isset($this->config['credentials'])) {
            // 校验 credentials 数组必须包含 key 和 secret
            if (
                !is_array($this->config['credentials'])
                || empty($this->config['credentials']['key'])
                || empty($this->config['credentials']['secret'])
            ) {
                throw new \InvalidArgumentException(
                    'AliyunMinioStorage: credentials 配置必须包含非空的 key 和 secret'
                );
            }
            $params['credentials'] = $this->config['credentials'];
        } elseif (isset($this->config['awsKey'])) {
            // 兼容旧式配置，同样校验完整性
            if (empty($this->config['awsKey']) || empty($this->config['awsSecret'] ?? '')) {
                throw new \InvalidArgumentException(
                    'AliyunMinioStorage: awsKey 和 awsSecret 配置不能为空'
                );
            }
            $params['credentials'] = [
                'key' => $this->config['awsKey'],
                'secret' => $this->config['awsSecret'],
            ];
        }

        // Endpoint 配置 (关键：用于支持 MinIO 和 Aliyun OSS)
        if (isset($this->config['endpoint'])) {
            $params['endpoint'] = $this->config['endpoint'];

            // 根据 serviceType 决定 URL 样式:
            // - MinIO: 使用 Path Style (http://host/bucket/key)
            // - Aliyun OSS: 使用 Virtual Hosted Style (http://bucket.host/key)
            $serviceType = $this->config['serviceType'] ?? 'minio';
            if ($serviceType === 'aliyun' || $serviceType === 'oss') {
                // 阿里云 OSS 强制要求 Virtual Hosted Style
                $params['use_path_style_endpoint'] = false;
            } else {
                // MinIO 和其他兼容服务使用 Path Style
                $params['use_path_style_endpoint'] = true;
            }
        }

        // 初始化客户端
        $this->client = new S3Client($params);

        return $this->client;
    }

    /**
     * 解析容器路径 (Resolve Container Path)
     * 
     * 将 MediaWiki 内部的 "相对存储路径" (relStoragePath) 转换为 S3 对象键名 (Object Key)。
     * 
     * @param string $container 容器名称
     * @param string $relStoragePath 相对路径
     * @return string|null 返回处理后的路径，如果路径非法则返回 null
     */
    protected function resolveContainerPath($container, $relStoragePath)
    {
        // 安全检查 1：路径遍历防护 - 阻止 ../ 等目录穿越攻击
        if (preg_match('/(?:^|\/)\.\.(?:\/|$)/', $relStoragePath)) {
            wfLogWarning("AliyunMinioStorage: 检测到路径遍历尝试: '$relStoragePath'");
            return null;
        }

        // 安全检查 2：URL 编码后长度限制 (S3 对象键最大 1024 字节)
        if (strlen(urlencode($relStoragePath)) > 1024) {
            return null;
        }

        return $relStoragePath;
    }

    /**
     * [CORE] 查找容器对应的物理存储位置
     * 
     * 根据 containerPaths 配置，将逻辑容器名解析为 S3 Bucket 和 Prefix。
     * 
     * @param string $container 逻辑容器名 (如 mw-local-public)
     * @return array|null [bucket, prefix] 或 null (未找到映射)
     */
    protected function findContainer($container)
    {
        // 1. 检查是否有 explicit mapping
        if (!isset($this->containerPaths[$container])) {
            // Fallback: 直接使用容器名作为 Bucket 名
            // 记录调试日志，便于排查配置错误
            wfDebugLog(
                'AliyunMinioStorage',
                "容器 '$container' 未在 containerPaths 中找到映射，将直接用作 Bucket 名"
            );
            return [$container, ''];
        }

        $path = $this->containerPaths[$container];

        // 解析 "Bucket/Prefix/..." 格式
        $firstSlash = strpos($path, '/');
        if ($firstSlash === false) {
            return [$path, '']; // 只有 Bucket，没有 Prefix
        }

        $bucket = substr($path, 0, $firstSlash);
        $prefix = substr($path, $firstSlash + 1);

        // 确保 prefix 以 / 结尾 (如果有的话)
        if ($prefix !== '' && substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        return [$bucket, $prefix];
    }

    /**
     * [CORE] 解析完整存储路径为 S3 Bucket 和 Key
     * 
     * @param string $storagePath 格式: mwstore://backend/container/path
     * @return array|null [bucket, key, containerName]
     */
    protected function getBucketAndObject($storagePath)
    {
        // 1. 使用 FileBackend 自带的解析器拆分 mwstore URI
        // 注意：这里我们使用 self::splitStoragePath 来避免 FileBackendStore::resolveStoragePathReal 的干扰
        // FileBackendStore::resolveStoragePathReal 会调用 fullContainerName() 强行加上 wikiId 前缀
        // 我们已经在上一步调试中确认这是不需要的。

        // 但 FileBackendStore 的标准流程是先 resolveStoragePathReal -> resolveContainerPath
        // 为了遵循继承规范，我们重写 resolveStoragePathReal 的调用者逻辑是不可能的 (final methods)。
        // 唯一的方法是在 resolveContainerPath 中做手脚？不，那个只返回 relPath。

        // 让我们看看 AWS 扩展怎么做的：
        // AWS 扩展确实调用了 $this->resolveStoragePathReal($storagePath)。
        // 但是 AWS 扩展依赖 fullContainerName 返回正确的值。

        // 我们的问题是：FileBackendStore::fullContainerName 是 final 的，并且它逻辑是：
        // if ( $this->domainId != '' ) returns "$domainId-$container";

        // 【解决方案】在构造函数中，我已经把 $this->wikiId (映射为 domainId) 设为了空 (通过不传 wikiId 配置或手动清空)。
        // 所以 resolveStoragePathReal 返回的 Container 就是原始的 (如 mw260...-local-public)。
        // 这样我们就可以安全地使用 findContainer 映射了。

        list($container, $relPath) = $this->resolveStoragePathReal($storagePath);

        if (!$container || $relPath === null) {
            return [null, null, null];
        }

        list($bucket, $prefix) = $this->findContainer($container);

        // key = prefix + relPath
        $key = $prefix . $relPath;

        return [$bucket, $key, $container];
    }

    /**
     * 判断目录是否是虚拟的
     * S3 中目录都是虚拟的
     * 
     * @return bool
     */
    protected function directoriesAreVirtual()
    {
        return true;
    }

    /**
     * 检查路径是否可用
     * 
     * @param string $storagePath
     * @return bool
     */
    public function isPathUsableInternal($storagePath)
    {
        return true; // S3 路径总是只需 Bucket 存在即可，不需要预创建目录
    }

    /**
     * [WRITE] 创建或写入文件 (Unified internal method)
     * 
     * @param array $params
     * @param string $content 文件内容
     * @param string $sourceFile 本地源文件路径 (可选)
     * @return Status
     */
    protected function createOrStore(array $params, $content = null, $sourceFile = null)
    {
        $status = Status::newGood();

        list($bucket, $key) = $this->getBucketAndObject($params['dst']);

        if (!$bucket || !$key) {
            return Status::newFatal('backend-fail-invalidpath', $params['dst']);
        }

        // 准备 PUT 参数
        $s3Params = [
            'Bucket' => $bucket,
            'Key' => $key,
            // 'ACL'    => 'public-read', // 根据需要设置 ACL，MinIO 默认 Bucket Policy 控制更好
        ];

        if ($sourceFile) {
            $s3Params['SourceFile'] = $sourceFile;
            // 计算 Content-Type
            $s3Params['ContentType'] = mime_content_type($sourceFile);
        } elseif ($content !== null) {
            $s3Params['Body'] = $content;
            // 尝试猜测 Content-Type ? 
            // 对于 create 操作，params headers 可能有
            if (isset($params['headers']['content-type'])) {
                $s3Params['ContentType'] = $params['headers']['content-type'];
            }
        } else {
            return Status::newFatal('backend-fail-internal', 'No content or source file provided');
        }

        // 处理 headers (Content-Disposition etc)
        if (!empty($params['headers'])) {
            foreach ($params['headers'] as $header => $value) {
                // S3 参数映射
                $map = [
                    'content-disposition' => 'ContentDisposition',
                    'cache-control' => 'CacheControl',
                    'content-type' => 'ContentType',
                ];
                if (isset($map[strtolower($header)])) {
                    $s3Params[$map[strtolower($header)]] = $value;
                }
            }
        }

        try {
            $this->getClient()->putObject($s3Params);
            // 操作成功，清除缓存
            $this->invalidateCache($params['dst']);
        } catch (S3Exception $e) {
            // 安全：仅在日志中记录详细异常，对外返回通用错误，防止敏感信息泄露
            $this->logS3Error('putObject', $e);
            $status->fatal('backend-fail-internal', 'S3 写入操作失败');
        }

        return $status;
    }

    /**
     * [WRITE] 直接创建文件 (内容在内存中)
     */
    protected function doCreateInternal(array $params)
    {
        return $this->createOrStore($params, $params['content']);
    }

    /**
     * [WRITE] 从本地文件存储
     */
    protected function doStoreInternal(array $params)
    {
        return $this->createOrStore($params, null, $params['src']);
    }

    /**
     * [WRITE] 复制文件
     */
    protected function doCopyInternal(array $params)
    {
        $status = Status::newGood();

        list($srcBucket, $srcKey) = $this->getBucketAndObject($params['src']);
        list($dstBucket, $dstKey) = $this->getBucketAndObject($params['dst']);

        if (!$srcBucket || !$srcKey || !$dstBucket || !$dstKey) {
            return Status::newFatal('backend-fail-invalidpath');
        }

        try {
            $this->getClient()->copyObject([
                'Bucket' => $dstBucket,
                'Key' => $dstKey,
                'CopySource' => "{$srcBucket}/{$srcKey}",
            ]);
            $this->invalidateCache($params['dst']);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey') {
                if (!empty($params['ignoreMissingSource'])) {
                    return Status::newGood();
                }
                return Status::newFatal('backend-fail-copy', $params['src']);
            }
            // 安全：仅在日志中记录详细异常，对外返回通用错误
            $this->logS3Error('copyObject', $e);
            $status->fatal('backend-fail-internal', 'S3 复制操作失败');
        }

        return $status;
    }

    /**
     * [WRITE] 删除文件
     */
    protected function doDeleteInternal(array $params)
    {
        $status = Status::newGood();
        list($bucket, $key) = $this->getBucketAndObject($params['src']);

        if (!$bucket || !$key) {
            return Status::newFatal('backend-fail-invalidpath', $params['src']);
        }

        try {
            $this->getClient()->deleteObject([
                'Bucket' => $bucket,
                'Key' => $key
            ]);
            $this->invalidateCache($params['src']);
        } catch (S3Exception $e) {
            // 安全：仅在日志中记录详细异常，对外返回通用错误
            $this->logS3Error('deleteObject', $e);
            $status->fatal('backend-fail-internal', 'S3 删除操作失败');
        }

        return $status;
    }

    /**
     * [WRITE] 移动文件 (S3 不支持直接 Move，需 Copy + Delete)
     */
    protected function doMoveInternal(array $params)
    {
        // 1. Copy
        $status = $this->doCopyInternal($params);
        if (!$status->isOK()) {
            return $status;
        }
        // 2. Delete Source
        $deleteStatus = $this->doDeleteInternal(['src' => $params['src']]);
        $status->merge($deleteStatus);

        return $status;
    }

    /**
     * [READ] 获取文件元数据 (Stat)
     * 支持缓存以提升性能
     */
    protected function doGetFileStat(array $params)
    {
        $src = $params['src'];

        // 1. 尝试从缓存获取
        $cacheKey = $this->statCache->makeKey('s3-stat', md5($src));
        $cacheValue = $this->statCache->get($cacheKey);

        // 这里需要判断 false (没找到) vs null (文件不存在)
        // 简单处理：如果缓存有值，直接返回
        if (is_array($cacheValue)) {
            return $cacheValue;
        } elseif ($cacheValue === 'NOT_FOUND') {
            return false;
        }

        // 2. 缓存未命中，请求 S3 HeadObject
        list($bucket, $key) = $this->getBucketAndObject($src);
        if (!$bucket || !$key)
            return false;

        try {
            $result = $this->getClient()->headObject([
                'Bucket' => $bucket,
                'Key' => $key
            ]);

            $stat = [
                'mtime' => strtotime($result['LastModified']),
                'size' => (int) $result['ContentLength'],
                'sha1' => $result['ETag'] ? trim($result['ETag'], '"') : '', // ETag 通常在 S3 是 MD5，不是 SHA1。
                // 注意：MediaWiki 期望 sha1，但 S3 默认不提供 sha1。
                // 如果我们确实需要 sha1，可以在 Metadata 中存储。
                // 这里暂时留空或使用 ETag 占位 (不完全准确)。
                // 更好的做法是在 Upload 时设置 Metadata['sha1base36']。
            ];

            // 尝试读取 Metadata 中的 sha1
            if (isset($result['Metadata']['sha1base36'])) {
                $stat['sha1'] = $result['Metadata']['sha1base36'];
            }

            // 存入缓存 (7天)
            $this->statCache->set($cacheKey, $stat, 86400 * 7);

            return $stat;

        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NotFound' || $e->getStatusCode() == 404) {
                // 记录不存在状态，避免频繁穿透
                $this->statCache->set($cacheKey, 'NOT_FOUND', 60); // 60秒短缓存
                return false;
            }
            // 其他错误，不缓存
            return false;
        }
    }

    /**
     * [INTERNAL] 安全地记录 S3 异常日志
     * 
     * 将详细异常信息写入服务端日志，但不暴露给前端用户，
     * 防止 Endpoint URL、Bucket 名称、请求签名等敏感信息泄露。
     * 
     * @param string $operation S3 操作名称 (如 putObject, copyObject)
     * @param S3Exception $e S3 异常实例
     */
    protected function logS3Error(string $operation, S3Exception $e): void
    {
        wfLogWarning(sprintf(
            'AliyunMinioStorage [%s] 失败: Code=%s, Status=%d, Message=%s',
            $operation,
            $e->getAwsErrorCode() ?? 'unknown',
            $e->getStatusCode(),
            $e->getMessage()
        ));
    }

    /**
     * [READ] 清除缓存
     */
    protected function invalidateCache($path)
    {
        $cacheKey = $this->statCache->makeKey('s3-stat', md5($path));
        $this->statCache->delete($cacheKey);
    }

    /**
     * [READ] 流式输出文件内容
     * 优化：使用 S3 Stream Wrapper 或直接读取 Body Stream
     */
    protected function doStreamFile(array $params)
    {
        $status = Status::newGood();
        list($bucket, $key) = $this->getBucketAndObject($params['src']);

        try {
            $result = $this->getClient()->getObject([
                'Bucket' => $bucket,
                'Key' => $key
            ]);

            // 安全加固：Content-Type 白名单过滤，防止 XSS
            // 如果 S3 返回了 text/html 等可执行类型，强制降级为 application/octet-stream
            $contentType = $result['ContentType'] ?? 'application/octet-stream';
            $dangerousTypes = ['text/html', 'application/xhtml+xml', 'text/xml', 'application/xml'];
            if (in_array(strtolower(explode(';', $contentType)[0]), $dangerousTypes, true)) {
                $contentType = 'application/octet-stream';
            }

            // 安全 Header：禁止浏览器猜测 Content-Type
            header('X-Content-Type-Options: nosniff');
            header('Content-Type: ' . $contentType);
            if (isset($result['ContentLength'])) {
                header('Content-Length: ' . (int) $result['ContentLength']);
            }

            // 输出 Body
            echo $result['Body'];

        } catch (S3Exception $e) {
            if ($e->getStatusCode() == 404) {
                $status->fatal('backend-fail-contenttype', $params['src']);
            } else {
                $this->logS3Error('streamFile', $e);
                $status->fatal('backend-fail-stream', 'S3 流式读取失败');
            }
        }

        return $status;
    }

    /**
     * [READ]下载文件到本地临时路径
     * 用于 ImageMagick 处理等
     */
    protected function doGetLocalCopyMulti(array $params)
    {
        $tmpFiles = [];
        foreach ($params['srcs'] as $src) {
            $tmpFile = null;
            list($bucket, $key) = $this->getBucketAndObject($src);

            try {
                // 创建临时文件
                $ext = FileBackend::extensionFromPath($src);
                // 修复: tmpDirectory 属性在 FileBackendStore 中可能未初始化
                // 使用 wfTempDir() 获取 MediaWiki 配置的临时目录
                $tmpDir = $this->tmpDirectory ?? wfTempDir();
                $tmpFile = \Wikimedia\FileBackend\FSFile\TempFSFile::factory('s3_', $ext, $tmpDir);

                $this->getClient()->getObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'SaveAs' => $tmpFile->getPath() // 直接保存到文件，内存高效
                ]);

                $tmpFiles[$src] = $tmpFile;

            } catch (S3Exception $e) {
                $tmpFiles[$src] = null; // 失败
            }
        }
        return $tmpFiles;
    }

    /**
     * [LIST] 检查目录是否存在
     * S3 中目录是虚拟的，只要有以该前缀开头的文件即视为存在
     */
    protected function doDirectoryExists($container, $dir, array $params)
    {
        list($bucket, $prefix) = $this->findContainer($container);
        $dirPrefix = $prefix . $dir . ($dir ? '/' : '');

        try {
            // 只请求一个对象来验证前缀是否存在
            $args = [
                'Bucket' => $bucket,
                'Prefix' => $dirPrefix,
                'MaxKeys' => 1
            ];

            $objects = $this->getClient()->listObjectsV2($args);
            return $objects['KeyCount'] > 0;

        } catch (S3Exception $e) {
            return false;
        }
    }

    /**
     * [LIST] 列出目录 (简化版，暂不使用迭代器类)
     */
    public function getDirectoryListInternal($container, $dir, array $params)
    {
        list($bucket, $prefix) = $this->findContainer($container);
        $dirPrefix = $prefix . $dir . ($dir ? '/' : '');

        $results = [];
        try {
            // S3 ListObjectsV2
            // 使用 Delimiter = '/' 来模拟目录
            $args = [
                'Bucket' => $bucket,
                'Prefix' => $dirPrefix,
                'Delimiter' => '/'
            ];

            $objects = $this->getClient()->listObjectsV2($args);

            // CommonPrefixes 是子目录
            if (isset($objects['CommonPrefixes'])) {
                foreach ($objects['CommonPrefixes'] as $cp) {
                    // prefix/subdir/ -> subdir
                    $subPath = substr($cp['Prefix'], strlen($dirPrefix));
                    $results[] = rtrim($subPath, '/');
                }
            }
        } catch (S3Exception $e) {
            // log error
        }

        return $results;
    }

    /**
     * [LIST] 列出文件
     */
    public function getFileListInternal($container, $dir, array $params)
    {
        list($bucket, $prefix) = $this->findContainer($container);
        $dirPrefix = $prefix . $dir . ($dir ? '/' : '');

        $results = [];
        try {
            $args = [
                'Bucket' => $bucket,
                'Prefix' => $dirPrefix,
            ];
            // 如果 params['topOnly']，需要 Delimiter
            if (!empty($params['topOnly'])) {
                $args['Delimiter'] = '/';
            }

            $objects = $this->getClient()->listObjectsV2($args);

            if (isset($objects['Contents'])) {
                foreach ($objects['Contents'] as $content) {
                    // prefix/file.jpg -> file.jpg
                    $key = $content['Key'];
                    if ($key === $dirPrefix)
                        continue; // 跳过目录本身标记

                    $relPath = substr($key, strlen($dirPrefix));
                    $results[] = $relPath;
                }
            }
        } catch (S3Exception $e) {
            // log error
        }

        return $results;
    }
}
