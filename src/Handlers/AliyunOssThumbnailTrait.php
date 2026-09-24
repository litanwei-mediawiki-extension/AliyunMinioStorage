<?php

namespace AliyunMinioStorage\Handlers;

use ThumbnailImage;
use MediaTransformError;
use MediaWiki\FileRepo\File\LocalFile;

/**
 * Route eligible thumbnail transforms through Aliyun OSS image processing.
 *
 * The handler returns a virtual ThumbnailImage whose URL points to the OSS
 * custom CDN domain and includes x-oss-process. It falls back to MediaWiki's
 * normal transform unless both the backend and the exact configured custom
 * domain match. This keeps MinIO, test buckets, restricted URLs, and unrelated
 * storage domains on the existing MediaWiki path.
 */
trait AliyunOssThumbnailTrait
{
	/**
	 * Build an OSS image-processing URL only for a configured public CDN object.
	 *
	 * @param mixed $image
	 * @param array $params
	 * @return string|false
	 */
	private function getAliyunOssThumbnailUrl( $image, array $params )
	{
		if ( !$image instanceof LocalFile ) {
			return false;
		}

		$backend = $image->getRepo()->getBackend();
		if ( !$backend instanceof \AliyunMinioStorage\AliyunMinioFileBackend ) {
			return false;
		}

		$serviceType = strtolower( trim( getenv( 'MW_OSS_SERVICE_TYPE' ) ?:
			( getenv( 'MW_OSS_ENDPOINT' ) ? 'aliyun' : 'minio' ) ) );
		if ( $serviceType !== 'aliyun' && $serviceType !== 'oss' ) {
			return false;
		}

		$customDomain = strtolower( trim( (string)( getenv( 'MW_OSS_CUSTOM_DOMAIN' ) ?: '' ) ) );
		if ( $customDomain === '' || !preg_match(
			'/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/',
			$customDomain
		) ) {
			return false;
		}

		$originalUrl = $image->getUrl();
		$urlParts = is_string( $originalUrl ) ? parse_url( $originalUrl ) : false;
		if ( !is_array( $urlParts ) || ( $urlParts['scheme'] ?? '' ) !== 'https' ||
			strtolower( $urlParts['host'] ?? '' ) !== $customDomain || isset( $urlParts['user'] ) ||
			isset( $urlParts['pass'] ) || isset( $urlParts['fragment'] ) ) {
			return false;
		}

		$query = [];
		parse_str( $urlParts['query'] ?? '', $query );
		if ( array_key_exists( 'x-oss-process', $query ) ) {
			return false;
		}

		$width = (int)( $params['physicalWidth'] ?? $params['width'] ?? 0 );
		if ( $width < 1 ) {
			return false;
		}

		$separator = isset( $urlParts['query'] ) ? '&' : '?';
		$process = rawurlencode( "image/resize,m_lfit,w_$width" );
		return $originalUrl . $separator . 'x-oss-process=' . $process;
	}

	/** Intercept direct transforms where MediaWiki asks a handler to scale locally. */
	public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 )
	{
		if ( !$this->normaliseParams( $image, $params ) ) {
			return new MediaTransformError(
				'thumbnail_error',
				$params['width'] ?? 0,
				$params['height'] ?? 0,
				'Invalid parameters'
			);
		}

		$thumbUrl = $this->getAliyunOssThumbnailUrl( $image, $params );
		if ( $thumbUrl !== false ) {
			return new ThumbnailImage( $image, $thumbUrl, false, $params );
		}

		return parent::doTransform( $image, $dstPath, $dstUrl, $params, $flags );
	}

	/** Intercept thumb.php scripted transforms and return the OSS CDN URL directly. */
	public function getScriptedTransform( $image, $script, $params )
	{
		if ( !$this->normaliseParams( $image, $params ) ) {
			return false;
		}

		if ( $image instanceof LocalFile &&
			( $image->mustRender() || $params['width'] < $image->getWidth() ) ) {
			$thumbUrl = $this->getAliyunOssThumbnailUrl( $image, $params );
			if ( $thumbUrl !== false ) {
				return new ThumbnailImage( $image, $thumbUrl, false, $params );
			}
		}

		return parent::getScriptedTransform( $image, $script, $params );
	}
}
