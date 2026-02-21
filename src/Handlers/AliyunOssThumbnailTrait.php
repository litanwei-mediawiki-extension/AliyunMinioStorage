<?php

namespace AliyunMinioStorage\Handlers;

use File;
use ThumbnailImage;
use MediaTransformError;
use MediaWiki\FileRepo\File\LocalFile;

/**
 * Trait AliyunOssThumbnailTrait
 * 
 * 拦截内置的 doTransform 和 getScriptedTransform 方法。
 * 如果存储后端是 AliyunMinioFileBackend，则直接利用阿里云 OSS 的图片处理能力 (x-oss-process) 
 * 动态输出缩略图 URL，避免 MediaWiki 在本地启动 ImageMagick 下载原图进行 CPU/内存密集型转换。
 */
trait AliyunOssThumbnailTrait
{

    /**
     * 拦截静态/预生成的缩略图请求
     */
    public function doTransform($image, $dstPath, $dstUrl, $params, $flags = 0)
    {
        if (!$this->normaliseParams($image, $params)) {
            return new MediaTransformError('thumbnail_error', $params['width'] ?? 0, $params['height'] ?? 0, 'Invalid parameters');
        }

        if ($image instanceof LocalFile) {
            $repo = $image->getRepo();
            $backend = $repo->getBackend();

            if ($backend instanceof \AliyunMinioStorage\AliyunMinioFileBackend) {
                $serviceType = getenv('MW_OSS_SERVICE_TYPE') ?: (getenv('MW_OSS_ENDPOINT') ? 'aliyun' : 'minio');

                // 仅针对真正的 Aliyun OSS 服务开启动态处理
                if ($serviceType === 'aliyun' || $serviceType === 'oss') {
                    $width = $params['physicalWidth'] ?? $params['width'];

                    // 获取原图的 URL（直连 OSS 域名）
                    $originalUrl = $image->getUrl();

                    // 构造 OSS 图片处理后缀: image/resize,m_lfit,w_{width}
                    $ossProcess = "image/resize,m_lfit,w_{$width}";

                    // 拼接 URL 参数
                    $separator = strpos($originalUrl, '?') === false ? '?' : '&';
                    $thumbUrl = $originalUrl . $separator . 'x-oss-process=' . $ossProcess;

                    // 强制返回 ThumbnailImage，令系统认为生成已“成功”
                    // 路径置为 false，告知系统此文件完全是云端的虚拟文件，不落本地磁盘
                    return new ThumbnailImage($image, $thumbUrl, false, $params);
                }
            }
        }

        // 退回父类的 doTransform (如: JpegHandler::doTransform)
        return parent::doTransform($image, $dstPath, $dstUrl, $params, $flags);
    }

    /**
     * 拦截动态请求 (thumb.php)
     * 系统尝试通过 thumbScriptUrl 动态生成缩略图时，直接劫持返回原图 + OSS 参数
     */
    public function getScriptedTransform($image, $script, $params)
    {
        if (!$this->normaliseParams($image, $params)) {
            return false;
        }

        if ($image instanceof LocalFile) {
            $repo = $image->getRepo();
            $backend = $repo->getBackend();

            if ($backend instanceof \AliyunMinioStorage\AliyunMinioFileBackend) {
                $serviceType = getenv('MW_OSS_SERVICE_TYPE') ?: (getenv('MW_OSS_ENDPOINT') ? 'aliyun' : 'minio');

                if ($serviceType === 'aliyun' || $serviceType === 'oss') {
                    $width = $params['physicalWidth'] ?? $params['width'];

                    $originalUrl = $image->getUrl();
                    $ossProcess = "image/resize,m_lfit,w_{$width}";
                    $separator = strpos($originalUrl, '?') === false ? '?' : '&';
                    $thumbUrl = $originalUrl . $separator . 'x-oss-process=' . $ossProcess;

                    if ($image->mustRender() || $params['width'] < $image->getWidth()) {
                        return new ThumbnailImage($image, $thumbUrl, false, $params);
                    }
                }
            }
        }

        // 退回父类的 getScriptedTransform
        return parent::getScriptedTransform($image, $script, $params);
    }
}
