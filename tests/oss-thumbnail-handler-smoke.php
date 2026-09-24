<?php
/**
 * Small offline contract check for OSS thumbnail URL routing.
 * Run: php tests/oss-thumbnail-handler-smoke.php
 */

namespace MediaWiki\FileRepo\File {
	class LocalFile {
		public $url;
		public $backend;

		public function __construct( $url, $backend ) {
			$this->url = $url;
			$this->backend = $backend;
		}

		public function getRepo() {
			return new \TestRepo( $this->backend );
		}

		public function getUrl() {
			return $this->url;
		}

		public function mustRender() {
			return true;
		}

		public function getWidth() {
			return 1600;
		}
	}
}

namespace AliyunMinioStorage {
	class AliyunMinioFileBackend {
	}
}

namespace {
	class TestRepo {
		private $backend;
		public function __construct( $backend ) { $this->backend = $backend; }
		public function getBackend() { return $this->backend; }
	}

	class ThumbnailImage {
		public $file;
		public $url;
		public $path;
		public $params;
		public function __construct( $file, $url, $path, $params ) {
			$this->file = $file;
			$this->url = $url;
			$this->path = $path;
			$this->params = $params;
		}
	}

	class MediaTransformError {
	}

	class JpegHandler {
		public function normaliseParams( $image, &$params ) {
			return isset( $params['width'] ) && (int)$params['width'] > 0;
		}
		public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
			return 'parent-do-transform';
		}
		public function getScriptedTransform( $image, $script, $params ) {
			return 'parent-script-transform';
		}
	}

	require __DIR__ . '/../src/Handlers/AliyunOssThumbnailTrait.php';
	require __DIR__ . '/../src/Handlers/AliyunJpegHandler.php';

	function assertResult( $condition, $message ) {
		if ( !$condition ) {
			fwrite( STDERR, "FAIL: $message\n" );
			exit( 1 );
		}
	}

	$backend = new \AliyunMinioStorage\AliyunMinioFileBackend();
	$file = new \MediaWiki\FileRepo\File\LocalFile(
		'https://wikifarmimg.ihuatuo.com/prodwiki2huawen/5/5a/example.jpg',
		$backend
	);
	$params = [ 'width' => 120, 'physicalWidth' => 120 ];
	$handler = new \AliyunMinioStorage\Handlers\AliyunJpegHandler();
	putenv( 'MW_OSS_SERVICE_TYPE=aliyun' );
	putenv( 'MW_OSS_CUSTOM_DOMAIN=wikifarmimg.ihuatuo.com' );

	$result = $handler->getScriptedTransform( $file, '/thumb.php', $params );
	assertResult( $result instanceof \ThumbnailImage, 'Configured Aliyun OSS files should return a virtual thumbnail.' );
	assertResult(
		$result->url === 'https://wikifarmimg.ihuatuo.com/prodwiki2huawen/5/5a/example.jpg?x-oss-process=image%2Fresize%2Cm_lfit%2Cw_120',
		'Transforms must retain the exact CDN object origin and normalized width.'
	);
	assertResult( $result->path === false, 'OSS transforms must not create a local thumbnail file.' );

	$foreignHostFile = new \MediaWiki\FileRepo\File\LocalFile(
		'https://prodwikifarm.oss-cn-hangzhou.aliyuncs.com/prodwiki2huawen/example.jpg',
		$backend
	);
	assertResult(
		$handler->getScriptedTransform( $foreignHostFile, '/thumb.php', $params ) === 'parent-script-transform',
		'Non-CDN object hosts must fall back to the original MediaWiki scripted transform.'
	);

	putenv( 'MW_OSS_SERVICE_TYPE=minio' );
	assertResult(
		$handler->getScriptedTransform( $file, '/thumb.php', $params ) === 'parent-script-transform',
		'MinIO must not generate OSS process URLs.'
	);

	echo "PASS: OSS transform routing is limited to the exact configured CDN host.\n";
}
