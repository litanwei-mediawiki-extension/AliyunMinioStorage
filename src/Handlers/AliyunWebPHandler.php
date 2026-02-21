<?php

namespace AliyunMinioStorage\Handlers;

use WebPHandler;

/**
 * 阿里云 OSS 专属 WEBP 处理器
 * 
 * 继承原生 WebPHandler，混入 AliyunOssThumbnailTrait。
 * 将拦截所有缩略图生成请求并交由 OSS 处理。
 */
class AliyunWebPHandler extends WebPHandler
{
    use AliyunOssThumbnailTrait;
}
