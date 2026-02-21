<?php

namespace AliyunMinioStorage\Handlers;

use PNGHandler;

/**
 * 阿里云 OSS 专属 PNG 处理器
 * 
 * 继承原生 PNGHandler，混入 AliyunOssThumbnailTrait。
 * 将拦截所有缩略图生成请求并交由 OSS 处理。
 */
class AliyunPngHandler extends PNGHandler
{
    use AliyunOssThumbnailTrait;
}
