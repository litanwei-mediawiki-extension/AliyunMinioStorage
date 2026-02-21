<?php

namespace AliyunMinioStorage\Handlers;

use JpegHandler;

/**
 * 阿里云 OSS 专属 JPEG 处理器
 * 
 * 继承原生 JpegHandler，混入 AliyunOssThumbnailTrait。
 * 将拦截所有缩略图生成请求并交由 OSS 处理。
 */
class AliyunJpegHandler extends JpegHandler
{
    use AliyunOssThumbnailTrait;
}
