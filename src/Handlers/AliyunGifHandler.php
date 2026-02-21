<?php

namespace AliyunMinioStorage\Handlers;

use GIFHandler;

/**
 * 阿里云 OSS 专属 GIF 处理器
 * 
 * 继承原生 GIFHandler，混入 AliyunOssThumbnailTrait。
 * 将拦截所有缩略图生成请求并交由 OSS 处理。
 */
class AliyunGifHandler extends GIFHandler
{
    use AliyunOssThumbnailTrait;
}
