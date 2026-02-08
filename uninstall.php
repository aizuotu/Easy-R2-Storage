<?php
/**
 * 卸载脚本
 * 
 * 当插件被删除时执行，清理所有数据和设置
 * 
 * @package Easy_R2_Storage
 * 
 * 创作者声明
 * 
 * 本插件由 quanyixia 创建
 * 作者：quanyixia
 * 邮箱：junjunai2009@gmail.com
 * Telegram：t.me/junjunai2009
 * 许可证：GPL v2 or later
 */

// 防止直接访问
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * 删除插件选项
 * 
 * 删除所有插件相关的 WordPress 选项
 */
function easy_r2_storage_delete_options() {
    // 删除主设置
    delete_option('easy_r2_storage_settings');
    
    // 删除自动同步设置
    delete_option('easy_r2_storage_auto_sync_enabled');
    delete_option('easy_r2_storage_auto_sync_batch_size');
    delete_option('easy_r2_storage_auto_sync_interval');
    delete_option('easy_r2_storage_auto_sync_mode');
    
    // 删除版本号
    delete_option('easy_r2_storage_version');
    
    // 删除定时任务
    wp_clear_scheduled_hook('easy_r2_storage_auto_sync_event');
}

/**
 * 删除附件元数据
 * 
 * 删除所有附件的 R2 相关元数据
 * 
 * 注意：此操作是可选的，根据需要注释掉
 * 如果保留这些数据，重新安装插件后可以继续使用
 */
function easy_r2_storage_delete_attachment_metadata() {
    // 获取所有附件
    $attachments = get_posts([
        'post_type' => 'attachment',
        'numberposts' => -1,
        'post_status' => 'any',
        'fields' => 'ids'
    ]);
    
    if (empty($attachments)) {
        return;
    }
    
    // 删除每个附件的 R2 元数据
    foreach ($attachments as $attachment_id) {
        // 删除主 R2 URL 和密钥
        delete_post_meta($attachment_id, '_r2_url');
        delete_post_meta($attachment_id, '_r2_key');
        delete_post_meta($attachment_id, '_r2_local_deleted');
        
        // 获取附件的元数据
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            // 删除所有尺寸的 R2 URL 和密钥
            foreach ($metadata['sizes'] as $size => $size_info) {
                delete_post_meta($attachment_id, '_r2_url_' . $size);
                delete_post_meta($attachment_id, '_r2_key_' . $size);
            }
        }
    }
}

/**
 * 清理缓存
 * 
 * 清除所有插件相关的缓存
 */
function easy_r2_storage_clear_cache() {
    // 清除对象缓存
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // 清除特定缓存键
    wp_cache_delete('alloptions', 'options');
    
    // 刷新重写规则
    flush_rewrite_rules();
}

/**
 * 记录卸载日志
 * 
 * 如果启用了调试日志，记录卸载操作
 */
function easy_r2_storage_log_uninstall() {
    // 尝试记录到 WordPress 调试日志
    error_log('Easy R2 Storage plugin has been uninstalled.');
}

// ====================
// 执行卸载
// ====================

// 1. 删除所有选项
easy_r2_storage_delete_options();

// 2. 删除附件元数据（可选，根据需要启用）
// easy_r2_storage_delete_attachment_metadata();

// 3. 清理缓存
easy_r2_storage_clear_cache();

// 4. 记录卸载日志
easy_r2_storage_log_uninstall();

// 注意：R2 存储桶中的文件不会被删除
// 如果需要删除 R2 中的文件，请手动在 Cloudflare 控制台中操作