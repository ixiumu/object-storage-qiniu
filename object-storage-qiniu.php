<?php

/**
 * Plugin Name: 云存储（七牛）
 * Plugin URI: https://github.com/ixiumu/object-storage-qiniu
 * Description: 上传本地附件到云存储空间
 * Author: 朽木
 * Author URI: http://www.xiumu.org/
 * Text Domain: 云存储 七牛
 * Version: 0.0.2
 * License: GPLv2
*/

// 七牛云存储 SDK
require_once dirname(__FILE__) . '/qiniu-php-sdk/autoload.php';

function add_pages() {
    add_submenu_page('options-general.php', "云存储", "云存储", 'manage_options', basename(__FILE__), 'option_page');
}

function option_page() {
    // wp_enqueue_script('storage-script', plugins_url( '/script/test.js' , __FILE__ ), array( 'jquery' ), '1.2.4',true);

    // 默认选项
    if (get_option('storage-extensions') == null) {
        update_option('storage-extensions', '*');
    }

    if (get_option('storage-delobject') == null) {
        update_option('storage-delobject', 1);
    }

    $messages = array();
    if(isset($_POST['resync']) && $_POST['resync']) {

        $files = storage_resync();

        if (count($files) == 0) {
            $messages[] = "没有需要同步的文件。";
        }else{
            $messages[] = "同步结果：";
        }
        foreach($files as $file => $stat) {
            if($stat === true) {
                $messages[] = "$file 上传成功。";
            } else if($stat === false) {
                $messages[] = "$file 上传失败。";
            } else {
                $messages[] = "$file 跳过。";
            }
        }
    }

    include "tpl/setting.php";
}


function storage_options()
{
    // 基础设置
    register_setting('storage-options', 'storage-accessKey', 'strval');
    register_setting('storage-options', 'storage-secretKey', 'strval');
    register_setting('storage-options', 'storage-bucket', 'strval');
    
    // 拓展名
    register_setting('storage-options', 'storage-extensions', 'strval');

    // CDN域名
    register_setting('storage-options', 'storage-baseurl', 'strval');

    // 同步
    register_setting('storage-options', 'storage-delobject', 'boolval');

    // register_setting('storage-resync', 'storage-resync', 'intval');
}

// 测试连接
function storage_connect_test()
{
    $accessKey = '';
    if(isset($_POST['storage-accessKey'])) {
        $accessKey = sanitize_text_field($_POST['storage-accessKey']);
    }

    $secretKey = '';
    if(isset($_POST['storage-secretKey'])) {
        $secretKey = sanitize_text_field($_POST['storage-secretKey']);
    }

    $bucket = '';
    if(isset($_POST['storage-bucket'])) {
        $bucket = sanitize_text_field($_POST['storage-bucket']);
    }

    // 鉴权对象
    $auth = _get_qiniu_auth($accessKey, $secretKey);

    //初始化BucketManager
    $bucketMgr = new Qiniu\Storage\BucketManager($auth);

    // 获取bucket列表
    list($ret, $err) = $bucketMgr->buckets();

    if ($err !== null) {

        $message = json_decode( $err->getResponse()->body, true );

        $message = "ERROR: ".$message['error'];

        $is_error = true;

    } elseif ( ! is_array($ret) OR ! in_array($bucket, $ret)) {

        $message = "bucket不存在"; // bucket isn't exists.

        $is_error = true;

    }else{

        $message = "测试连接成功。"; // Connection was Successfully.

        $is_error = false;

    }

    die( json_encode(array(
                         'message' => $message,
                         'is_error' => $is_error
                 )));
}

// 同步
function storage_resync() {
    $args = array(
        'post_type' => 'attachment',
        'numberposts' => null,
        'post_status' => null,
        'post_parent' => null,
        'orderby' => null,
        'order' => null,
        'exclude' => null,
    );

    $attachments = get_posts($args);
    if( ! $attachments) {
        return array();
    }


    $retval = array();
    foreach($attachments as $attach) {
        $filepath = get_attached_file($attach->ID);
        $object_name = __generate_object_name_from_path($filepath);

        $obj = __head_object($object_name);

        $do_upload = false;
        if( ! $obj OR ! file_exists($filepath)) {
            $do_upload = true;

        } else {
            // 对比本地和远程文件时间
            $mod1 = new DateTime('@'.$obj['putTime']);
            $mod2 = new DateTime('@'.filemtime($filepath));

            $d = $mod2->diff($mod1);
            
            if($d->invert === 1) {
                $do_upload = true;
            }
        }

        // 上传文件
        if( $do_upload ) {

            // 上传文件
            $retval[$object_name] = __upload_object($filepath);

            if ( $retval[$object_name] ) {
                // 获取缩略图信息
                $metadatas = wp_get_attachment_metadata($attach->ID);
                // 上传缩略图
                storage_thumb_upload($metadatas);
            }

        } else {
            $retval[$object_name] = null;
        }
    }
    return $retval;
}

// 上传文件
function storage_upload_file($file_id) {

    $filepath = get_attached_file($file_id);

    if( ! __file_has_upload_extensions($filepath)) {
        return null;
    }

    return __upload_object($filepath);
}

// 上传缩略图
function storage_thumb_upload($metadatas) {

    if( ! isset($metadatas['sizes'])) {
        return $metadatas;
    }

    $dir = wp_upload_dir();
    foreach($metadatas['sizes'] as $thumb) {
        $filepath = $dir['path'] . DIRECTORY_SEPARATOR . $thumb['file'];

        if( ! __file_has_upload_extensions($path)) {
            return false;
        }

        if( ! __upload_object($filepath)) {
            throw new Exception("upload thumb error");
        }
    }

    return $metadatas;
}

// 删除 object
function storage_delete_object($filepath) {
    if( ! __file_has_upload_extensions($path)) {
        return true;
    }
    return __delete_object($filepath);
}

// -------------------- WordPress hooks --------------------

add_action('admin_menu', 'add_pages');
add_action('admin_init', 'storage_options' );
add_action('wp_ajax_storage_connect_test', 'storage_connect_test');

add_action('add_attachment', 'storage_upload_file');
add_action('edit_attachment', 'storage_upload_file');
add_action('delete_attachment', 'storage_delete_object');
add_filter('wp_update_attachment_metadata', 'storage_thumb_upload');

if($baseurl = get_option('storage-baseurl')) {
    add_filter( 'upload_dir', function( $args ) {
        $args['baseurl'] = $baseurl;
        return $args;
    });
}


if(get_option('storage-delobject') == 1) {
    add_filter('wp_delete_file', 'storage_delete_object');
}

// -------------------- 私有函数 --------------------

// 转换文件路径
function __generate_object_name_from_path($path) {
    return str_replace( array(ABSPATH, '\\'), array('', '/'), $path);
}

// 确认文件拓展名
function __file_has_upload_extensions($file) {

    $extensions = get_option('storage-extensions');

    if($extensions == '' OR $extensions == '*') {
        return true;
    }

    $f = new SplFileInfo($file);

    if( ! $f->isFile()) {
        return false;
    }

    $fileext = $f->getExtension();

    $fileext = strtolower($fileext);

    foreach(explode(',', $extensions) as $ext) {
        if($fileext == strtolower($ext)) {
            return true;
        }
    }
    return false;
}

// 上传文件
function __upload_object($filepath) {

    // 获取token
    $token = __get_qiniu_token();

    // 上传文件
    if(is_readable($filepath)) {
        
        $object_name = __generate_object_name_from_path($filepath);

        // 初始化 UploadManager 对象并进行文件的上传。
        $uploadMgr = new Qiniu\Storage\UploadManager();

        list($ret, $err) = $uploadMgr->putFile($token, $object_name, $filepath);

        if ($err != null) {
            // var_dump($err);exit;
            return false;
        }

    }

    return true;

}

// 获取object信息
function __head_object($object_name) {

    $bucket = get_option('storage-bucket');

    // 鉴权对象
    $auth = _get_qiniu_auth();

    //初始化BucketManager
    $bucketMgr = new Qiniu\Storage\BucketManager($auth);

    try {
        // 读取$bucket 中的文件 $object_name 信息
        $object = $bucketMgr->stat($bucket, $object_name);
        return $object[0];

    } catch(Exception $ex) {
        return false;
    }
}

// 删除object
function __delete_object($filepath) {

    $bucket = get_option('storage-bucket');

    // 鉴权对象
    $auth = _get_qiniu_auth();

    //初始化BucketManager
    $bucketMgr = new Qiniu\Storage\BucketManager($auth);

    $object_name = __generate_object_name_from_path($filepath);

    // 读取$bucket 中的文件 $object_name 信息
    list($ret, $err) = $bucketMgr->stat($bucket, $object_name);

    if ($ret == null) {
        // 然而文件并不存在
        return true;
    }

    // 删除$bucket 中的文件 $object_name
    $res = $bucketMgr->delete($bucket, $object_name);

    return true;

}

// 鉴权对象
function _get_qiniu_auth($accessKey = null, $secretKey = null) {

    static $auth = null;

    if( ! $auth) {
        if($accessKey == null) {
            $accessKey = get_option('storage-accessKey');
        }
        if($secretKey == null) {
            $secretKey = get_option('storage-secretKey');
        }

        // 构建鉴权对象
        $auth = new Qiniu\Auth($accessKey, $secretKey);

    }

    return $auth;

}

// 获取token
function __get_qiniu_token($accessKey = null, $secretKey = null, $bucket = null) {

    static $token = null;

    if( ! $token) {

        // 构建鉴权对象
        $auth = _get_qiniu_auth();

        $bucket = get_option('storage-bucket');

        // 生成上传 Token
        $token = $auth->uploadToken($bucket);

    }

    return $token;

}
