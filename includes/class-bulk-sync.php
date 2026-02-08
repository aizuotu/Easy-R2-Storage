<?php
/**
 * 批量同步类
 * 
 * 负责处理批量同步操作，包括：
 * - 获取未同步的附件
 * - 获取已同步但缺失尺寸的附件
 * - 执行批量同步
 * - AJAX处理程序
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

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 批量同步类
 * 
 * 管理所有批量同步操作
 */
class Easy_R2_Bulk_Sync {
    
    /**
     * 存储管理器实例
     * 
     * @var Easy_R2_Storage_Manager
     */
    private Easy_R2_Storage_Manager $storage_manager;
    
    /**
     * 插件设置
     * 
     * @var array
     */
    private array $settings;
    
    /**
     * 日志记录器实例
     * 
     * @var Easy_R2_Logger
     */
    private Easy_R2_Logger $logger;
    
    /**
     * 媒体上传器实例
     * 
     * @var Easy_R2_Media_Uploader|null
     */
    private ?Easy_R2_Media_Uploader $media_uploader = null;
    
    /**
     * 构造函数
     * 
     * @param Easy_R2_Storage_Manager $storage_manager 存储管理器
     * @param array $settings 插件设置
     */
    public function __construct(Easy_R2_Storage_Manager $storage_manager, array $settings) {
        $this->storage_manager = $storage_manager;
        $this->settings = $settings;
        $this->logger = Easy_R2_Logger::get_instance();
        
        // 初始化AJAX钩子
        $this->init_ajax_hooks();
    }
    
    /**
     * 设置媒体上传器
     * 
     * @param Easy_R2_Media_Uploader $uploader 媒体上传器
     * @return void
     */
    public function set_media_uploader(Easy_R2_Media_Uploader $uploader): void {
        $this->media_uploader = $uploader;
    }
    
    /**
     * 初始化AJAX钩子
     * 
     * 注册所有批量同步相关的AJAX处理程序
     */
    private function init_ajax_hooks(): void {
        // 前端和后台AJAX
        add_action('wp_ajax_easy_r2_storage_bulk_sync', [$this, 'handle_bulk_sync']);
        add_action('wp_ajax_easy_r2_storage_bulk_sync_batch', [$this, 'handle_bulk_sync_batch']);
        add_action('wp_ajax_easy_r2_storage_get_sync_count', [$this, 'handle_get_sync_count']);
    }
    
    /**
     * 获取总媒体数量
     * 
     * @return int 媒体文件总数
     */
    public function get_total_media(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );
    }
    
    /**
     * 获取已同步的媒体数量
     * 
     * @return int 已同步的媒体数量
     */
    public function get_synced_media(): int {
        global $wpdb;
        
        return (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND pm.meta_key = '_r2_url'
            AND pm.meta_value != ''
        ");
    }
    
    /**
     * 获取未同步的附件
     *
     * @param int $offset 偏移量（默认0）
     * @param int $limit 限制数量（默认100）
     * @return array 附件对象数组
     */
    public function get_unsynced_attachments(int $offset = 0, int $limit = 100): array {
        global $wpdb;
        
        $query = "SELECT p.* FROM {$wpdb->posts} p
                  LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_r2_url'
                  WHERE p.post_type = 'attachment'
                  AND p.post_status = 'inherit'
                  AND (pm.meta_value IS NULL OR pm.meta_value = '')
                  ORDER BY p.ID ASC
                  LIMIT %d OFFSET %d";
        
        return $wpdb->get_results($wpdb->prepare($query, $limit, $offset));
    }
    
    /**
     * 获取部分同步的附件（用于增量同步）
     *
     * @param int $offset 偏移量（默认0）
     * @param int $limit 限制数量（默认100）
     * @return array 附件对象数组
     */
    public function get_partially_synced_attachments(int $offset = 0, int $limit = 100): array {
        global $wpdb;
        
        // 获取主文件已同步但可能缺失尺寸的附件
        $query = "SELECT p.* FROM {$wpdb->posts} p
                  INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_r2_url'
                  WHERE p.post_type = 'attachment'
                  AND p.post_status = 'inherit'
                  AND pm.meta_value != ''
                  ORDER BY p.ID ASC
                  LIMIT %d OFFSET %d";
        
        return $wpdb->get_results($wpdb->prepare($query, $limit, $offset));
    }
    
    /**
     * 处理批量同步AJAX请求
     * 
     * @return void
     */
    public function handle_bulk_sync(): void {
        $this->logger->debug('[批量同步] 开始批量同步请求');
        
        // 验证nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'easy_r2_storage_bulk_sync')) {
            $this->logger->error('[批量同步] Nonce验证失败');
            wp_send_json_error('安全检查失败');
            return;
        }
        
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
        $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'full'; // 'full' 或 'incremental'
        $regenerate_metadata = isset($_POST['regenerate_metadata']) && $_POST['regenerate_metadata'] === 'true';
        
        $this->logger->debug('[批量同步] 处理批次: offset=' . $offset . ', batch_size=' . $batch_size . ', mode=' . $mode);
        
        // 检查插件是否已配置
        if (!$this->storage_manager->is_configured()) {
            $this->logger->error('[批量同步] 插件未配置');
            wp_send_json_error('插件未配置。请检查R2凭据。');
            return;
        }
        
        $this->logger->debug('[批量同步] 插件已配置');
        
        // 根据模式获取附件
        if ($mode === 'incremental') {
            $attachments = $this->get_partially_synced_attachments($offset, $batch_size);
            $this->logger->debug('[批量同步] 找到 ' . count($attachments) . ' 个部分同步的附件');
        } else {
            $attachments = $this->get_unsynced_attachments($offset, $batch_size);
            $this->logger->debug('[批量同步] 找到 ' . count($attachments) . ' 个未同步的附件');
        }
        
        $messages = [];
        $processed = 0;
        
        foreach ($attachments as $attachment) {
            if ($mode === 'incremental') {
                $result = $this->sync_missing_sizes($attachment, $regenerate_metadata);
            } else {
                $result = $this->sync_attachment($attachment, $regenerate_metadata);
            }
            $messages[] = $result;
            $processed++;
        }
        
        wp_send_json_success([
            'processed' => $processed,
            'messages' => $messages
        ]);
    }
    
    /**
     * 处理批量同步批次AJAX请求
     * 
     * @return void
     */
    public function handle_bulk_sync_batch(): void {
        // 验证nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'easy_r2_storage_bulk_sync')) {
            wp_send_json_error('安全检查失败');
            return;
        }
        
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
        
        $attachments = $this->get_unsynced_attachments($offset, $batch_size);
        
        $processed = 0;
        $successful = 0;
        
        foreach ($attachments as $attachment) {
            $result = $this->sync_attachment($attachment, false);
            
            if ($result['type'] === 'success') {
                $successful++;
            }
            
            $processed++;
        }
        
        wp_send_json_success([
            'processed' => $processed,
            'successful' => $successful,
            'remaining' => max(0, $this->get_total_media() - $this->get_synced_media() - $processed)
        ]);
    }
    
    /**
     * 处理获取同步计数AJAX请求
     * 
     * @return void
     */
    public function handle_get_sync_count(): void {
        $total = $this->get_total_media();
        $synced = $this->get_synced_media();
        
        wp_send_json_success([
            'total' => $total,
            'synced' => $synced,
            'remaining' => $total - $synced
        ]);
    }
    
    /**
     * 同步单个附件
     * 
     * @param \stdClass $attachment 附件对象
     * @param bool $regenerate_metadata 是否重新生成元数据
     * @return array 消息数组
     */
    private function sync_attachment(\stdClass $attachment, bool $regenerate_metadata = false): array {
        $file_path = get_attached_file($attachment->ID);
        $title = get_the_title($attachment->ID) ?: basename($file_path);
        
        if (!$file_path || !file_exists($file_path)) {
            return [
                'type' => 'error',
                'message' => "跳过 {$title}: 文件未找到"
            ];
        }
        
        // 检查是否已同步（完整）
        $existing_url = get_post_meta($attachment->ID, '_r2_url', true);
        if (!empty($existing_url)) {
            return [
                'type' => 'info',
                'message' => "跳过 {$title}: 已同步"
            ];
        }
        
        // 如果需要，重新生成元数据
        $mime_type = get_post_mime_type($attachment->ID);
        if ($regenerate_metadata && strpos($mime_type, 'image/') === 0) {
            $this->logger->debug("[批量同步] 为附件 {$attachment->ID} 重新生成元数据");
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $metadata = wp_generate_attachment_metadata($attachment->ID, $file_path);
            if (!empty($metadata)) {
                wp_update_attachment_metadata($attachment->ID, $metadata);
            }
        }
        
        // 收集文件：主文件 + 尺寸（如果是图片）
        $files_to_upload = ['full' => $file_path];
        
        if (strpos($mime_type, 'image/') === 0) {
            $metadata = wp_get_attachment_metadata($attachment->ID);
            if ($metadata && !empty($metadata['sizes'])) {
                $upload_dir = dirname($file_path);
                foreach ($metadata['sizes'] as $size => $size_data) {
                    $size_file = $upload_dir . '/' . $size_data['file'];
                    if (file_exists($size_file)) {
                        $files_to_upload[$size] = $size_file;
                    }
                }
            }
        }
        
        // 上传所有文件
        $result = $this->storage_manager->upload_all_sizes($attachment->ID, $files_to_upload);
        
        if ($result) {
            return [
                'type' => 'success',
                'message' => "已同步 {$title} -> R2"
            ];
        }
        
        return [
            'type' => 'error',
            'message' => "同步 {$title} 失败"
        ];
    }
    
    /**
     * 同步缺失的尺寸（用于增量同步）
     * 
     * @param \stdClass $attachment 附件对象
     * @param bool $regenerate_metadata 是否重新生成元数据
     * @return array 消息数组
     */
    private function sync_missing_sizes(\stdClass $attachment, bool $regenerate_metadata = false): array {
        $file_path = get_attached_file($attachment->ID);
        $title = get_the_title($attachment->ID) ?: basename($file_path);
        
        if (!$file_path || !file_exists($file_path)) {
            return [
                'type' => 'error',
                'message' => "跳过 {$title}: 主文件未找到"
            ];
        }
        
        $mime_type = get_post_mime_type($attachment->ID);
        
        // 只处理图片的缺失尺寸
        if (strpos($mime_type, 'image/') !== 0) {
            return [
                'type' => 'info',
                'message' => "跳过 {$title}: 不是图片，无需检查尺寸"
            ];
        }
        
        // 如果需要，重新生成元数据
        if ($regenerate_metadata) {
            $this->logger->debug("[增量同步] 为附件 {$attachment->ID} 重新生成元数据");
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $metadata = wp_generate_attachment_metadata($attachment->ID, $file_path);
            if (!empty($metadata)) {
                wp_update_attachment_metadata($attachment->ID, $metadata);
            }
        }
        
        // 获取元数据以检查可用尺寸
        $metadata = wp_get_attachment_metadata($attachment->ID);
        if (!$metadata || empty($metadata['sizes'])) {
            return [
                'type' => 'info',
                'message' => "跳过 {$title}: 元数据中未找到图片尺寸"
            ];
        }
        
        // 检查R2上缺失的尺寸
        $files_to_upload = [];
        $upload_dir = dirname($file_path);
        $missing_sizes = [];
        
        foreach ($metadata['sizes'] as $size => $size_data) {
            // 检查此尺寸是否已同步
            $size_url = get_post_meta($attachment->ID, '_r2_url_' . $size, true);
            
            if (empty($size_url)) {
                // 尺寸未同步，检查本地文件是否存在
                $size_file = $upload_dir . '/' . $size_data['file'];
                if (file_exists($size_file)) {
                    $files_to_upload[$size] = $size_file;
                    $missing_sizes[] = $size;
                } else {
                    $this->logger->warning("[增量同步] 尺寸 {$size} 文件未找到: {$size_file}");
                }
            }
        }
        
        // 如果没有缺失尺寸，全部已同步
        if (empty($files_to_upload)) {
            return [
                'type' => 'info',
                'message' => "跳过 {$title}: 所有尺寸已同步"
            ];
        }
        
        // 上传缺失的尺寸
        $uploaded_count = 0;
        $failed_sizes = [];
        
        foreach ($files_to_upload as $size => $path) {
            $result = $this->storage_manager->upload_file($attachment->ID, $path, $size);
            
            if (is_wp_error($result)) {
                $failed_sizes[] = $size;
                $this->logger->error("[增量同步] 上传 {$size} 失败: " . $result->get_error_message());
            } else {
                $uploaded_count++;
                update_post_meta($attachment->ID, '_r2_url_' . $size, $result['url']);
                update_post_meta($attachment->ID, '_r2_key_' . $size, $result['key']);
                $this->logger->info("[增量同步] 上传 {$size} 用于 ID {$attachment->ID}");
            }
        }
        
        if ($uploaded_count > 0) {
            $message = "已同步 {$title}: 上传了 {$uploaded_count} 个缺失尺寸";
            if (!empty($failed_sizes)) {
                $message .= " (失败: " . implode(', ', $failed_sizes) . ")";
            }
            
            return [
                'type' => 'success',
                'message' => $message
            ];
        }
        
        return [
            'type' => 'error',
            'message' => "同步 {$title} 的缺失尺寸失败"
        ];
    }
    
    /**
     * 同步批次文件
     * 
     * 执行批量同步操作
     *
     * @param int $batch_size 每个批次的文件数量
     * @param string $sync_mode 同步模式（full 或 incremental）
     * @return int 成功同步的文件数量
     */
    public function sync_batch(int $batch_size, string $sync_mode = 'full'): int {
        $this->logger->info('开始同步批次', [
            'batch_size' => $batch_size,
            'sync_mode' => $sync_mode
        ]);
        
        $successful = 0;
        
        if ($sync_mode === 'incremental') {
            $attachments = $this->get_partially_synced_attachments(0, $batch_size);
        } else {
            $attachments = $this->get_unsynced_attachments(0, $batch_size);
        }
        
        foreach ($attachments as $attachment) {
            if ($sync_mode === 'incremental') {
                $result = $this->sync_missing_sizes($attachment, false);
            } else {
                $result = $this->sync_attachment($attachment, false);
            }
            
            if ($result['type'] === 'success') {
                $successful++;
            }
        }
        
        $this->logger->info('批次同步完成', [
            'total_processed' => count($attachments),
            'successful' => $successful,
            'sync_mode' => $sync_mode
        ]);
        
        return $successful;
    }
}