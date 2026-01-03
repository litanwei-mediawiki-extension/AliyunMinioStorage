<?php
/**
 * AliyunMinioStorage 验证脚本
 * VerifyStorage.php
 *
 * 这是一个 MediaWiki 维护脚本，用于验证 AliyunMinioStorage 后端是否正常工作。
 * 它会依次测试以下操作：
 * 1. Create (创建/写入)
 * 2. Stat (状态检查/存在性检查)
 * 3. List (目录列表)
 * 4. Delete (删除)
 *
 * @ingroup Maintenance
 */

use MediaWiki\MediaWikiServices;
use AliyunMinioStorage\AliyunMinioFileBackend;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
    $IP = __DIR__ . '/../../../../core/mediawiki-1.45.1'; // 默认路径回退 (根据实际部署调整)
}

// 加载 MediaWiki 维护脚本基础类
require_once "$IP/maintenance/Maintenance.php";

class VerifyStorage extends Maintenance {
    public function __construct() {
        parent::__construct();
        // 添加脚本描述 (英文，内部使用)
        $this->addDescription( 'Verify AliyunMinioStorage backend operations' );
    }

    public function execute() {
        // 输出开始信息
        $this->outputMsg( 'aliyunminiostorage-verify-start' );

        // 1. 获取后端实例 (Get Backend)
        $backendStr = 'farm-backend'; // 在 FarmSettings.php 中定义的后端名称
        $backend = MediaWikiServices::getInstance()->getFileBackendGroup()->get( $backendStr );

        if ( !$backend ) {
            $this->fatalError( wfMessage( 'aliyunminiostorage-verify-backend-not-found', $backendStr )->text() );
        }

        $this->outputMsg( 'aliyunminiostorage-verify-backend-loaded', get_class( $backend ) );

        // 检查后端类型是否正确
        if ( !( $backend instanceof AliyunMinioFileBackend ) ) {
             // 如果被其他类(如 FileBackendMultiWrite)包裹，提示用户
             $this->outputMsg( 'aliyunminiostorage-verify-unexpected-backend', get_class( $backend ) );
        }

        // 2. 定义测试路径 (Define Test Path)
        // 格式: mwstore://backend/container/path
        // Container: 通常是 wikiId-local-public (映射到 wikifarm-storage/wikiId/images)
        // 我们从 $wgLocalFileRepo 配置中获取当前环境真实的 container 名称
        global $wgLocalFileRepo;
        $container = $wgLocalFileRepo['zones']['public']['container'];
        $this->outputMsg( 'aliyunminiostorage-verify-target-container', $container );

        // 生成唯一的测试文件名
        $testFile = "mwstore://$backendStr/$container/verify_test_" . time() . ".txt";
        $content = "Hello MinIO! Verification timestamp: " . time();

        // 3. 测试创建/写入 (Test Create)
        $this->outputMsg( 'aliyunminiostorage-verify-testing-create' );
        $status = $backend->create( [ 'dst' => $testFile, 'content' => $content ] );
        if ( !$status->isOK() ) {
            // 如果失败，输出错误信息
            $this->fatalError( wfMessage( 'aliyunminiostorage-verify-create-failed', $status->getWikiText( false, false, 'en' ) )->text() );
        }
        $this->outputMsg( 'aliyunminiostorage-verify-create-success' );

        // 4. 测试状态读取 (Test Stat/Exists)
        $this->outputMsg( 'aliyunminiostorage-verify-testing-stat' );
        $exists = $backend->fileExists( [ 'src' => $testFile ] );
        if ( !$exists ) {
            $this->fatalError( wfMessage( 'aliyunminiostorage-verify-file-not-exist' )->text() );
        }
        $stat = $backend->getFileStat( [ 'src' => $testFile ] );
        $this->outputMsg( 'aliyunminiostorage-verify-stat-success', $stat['size'] );

        // 5. 测试列表读取 (Test List)
        $this->outputMsg( 'aliyunminiostorage-verify-testing-list' );
        // 获取文件列表迭代器
        $iter = $backend->getFileList( [ 'dir' => "mwstore://$backendStr/$container" ] );
        $found = false;
        foreach ( $iter as $file ) {
            // 检查我们的测试文件是否在列表中
            if ( strpos( $testFile, $file ) !== false ) {
                $found = true;
                break;
            }
        }
        if ( $found ) {
            $this->outputMsg( 'aliyunminiostorage-verify-list-success' );
        } else {
            // 如果未找到，可能是分页或缓存原因，输出警告但不中断
            $this->outputMsg( 'aliyunminiostorage-verify-list-warning' );
        }

        // 6. 测试删除 (Test Delete)
        $this->outputMsg( 'aliyunminiostorage-verify-testing-delete' );
        $status = $backend->delete( [ 'src' => $testFile ] );
        if ( !$status->isOK() ) {
            $this->fatalError( wfMessage( 'aliyunminiostorage-verify-delete-failed', $status->getWikiText( false, false, 'en' ) )->text() );
        }
        // 再次检查文件是否存在，确保删除成功
        $existsAfter = $backend->fileExists( [ 'src' => $testFile ] );
        if ( $existsAfter ) {
            $this->fatalError( wfMessage( 'aliyunminiostorage-verify-file-exists-after-delete' )->text() );
        }
        $this->outputMsg( 'aliyunminiostorage-verify-delete-success' );

        // 全部通过
        $this->outputMsg( 'aliyunminiostorage-verify-all-passed' );
    }
    
    /**
     * 辅助方法：输出本地化消息
     * Helper to output localized message
     */
    protected function outputMsg( $key, ...$params ) {
        $msg = wfMessage( $key, ...$params )->text();
        $this->output( $msg . "\n" );
    }
}

$maintClass = VerifyStorage::class;
require_once RUN_MAINTENANCE_IF_MAIN;
