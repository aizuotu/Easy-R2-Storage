<?php
/**
 * 媒体上传器类
 * 
 * 负责处理WordPress媒体上传到R2的操作，包括：
 * - 自动上传新媒体到R2
 * - 处理附件删除时从R2删除文件
 * - 处理图片缩略图上传
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
 * 媒体上传器类
 * 
 * 管理所有媒体上传和删除操作
 */
class Easy_R2_Media_Uploader {
    
    /**
     * 插件设置
     * 
     * @var array
     */
    private array $settings;
    
    /**
     * 存储管理器实例
     * 
     * @var Easy_R2_Storage_Manager
     */
    private Easy_R2_Storage_Manager $storage_manager;
    
    /**
     * 日志记录器实例
     * 
     * @var Easy_R2_Logger
     */
    private Easy_R2_Logger $logger;
    
    /**
     * 构造函数
     * 
     * @param array $settings 插件设置
     * @param Easy_R2_Storage_Manager $storage_manager 存储管理器
     */
    public function __construct(array $settings, Easy_R2_Storage_Manager $storage_manager) {
        $this->settings = $settings;
        $this->storage_manager = $storage_manager;
        $this->logger = Easy_R2_Logger::get_instance();
        
        // 初始化上传钩子
        $this->init_upload_hooks();
    }
    
    /**
     * 初始化上传钩子
     * 
     * 注册WordPress过滤器来拦截上传操作
     */
    private function init_upload_hooks(): void {
        // 检查是否启用了自动上传
        if (!empty($this->settings['auto_offload'])) {
            // 在生成附件元数据时自动上传
            add_filter('wp_generate_attachment_metadata', [$this, 'handle_auto_upload'], 999, 2);
            
            // 在更新附件元数据时重新上传
            add_filter('wp_update_attachment_metadata', [$this, 'handle_metadata_update'], 999, 2);
        }
        
        // 处理附件删除
        add_action('delete_attachment', [$this, 'handle_attachment_deletion']);
    }
    
    /**
     * 处理自动上传
     * 
     * 在WordPress生成附件元数据后自动上传到R2
     * 
     * @param array $metadata 附件元数据
     * @param int $attachment_id 附件ID
     * @return array 附件元数据
     */
    public function handle_auto_upload(array $metadata, int $attachment_id): array {
        // 如果未配置R2，记录日志并返回
        if (!$this->storage_manager->is_configured()) {
            if ($this->settings['enable_debug_logging'] ?? false) {
                $this->logger->error("Cloudflare R2未配置");
            }
            return $metadata;
        }
        
        // 获取主文件路径
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return $metadata;
        }
        
        // 获取文件类型
        $mime_type = get_post_mime_type($attachment_id);
        $is_image = ($mime_type && strpos($mime_type, 'image/') === 0);
        
        // 获取上传模式
        $upload_mode = $this->settings['upload_mode'] ?? 'full_only';
        
        // 上传主文件（所有类型都上传）
        if ($this->settings['enable_debug_logging'] ?? false) {
            $file_type = $is_image ? '图片' : ($mime_type ? $mime_type : '未知类型');
            $this->logger->debug("上传文件到R2: ID={$attachment_id}, 类型={$file_type}");
        }
        
        $result = $this->storage_manager->upload_file($attachment_id, $file_path, 'full');
        
        if (is_wp_error($result)) {
            if ($this->settings['enable_debug_logging'] ?? false) {
                $this->logger->error("前台主文件上传失败: " . $result->get_error_message());
            }
        } else {
            // 保存R2 URL和key到元数据
            update_post_meta($attachment_id, '_r2_url', $result['url']);
            update_post_meta($attachment_id, '_r2_key', $result['key']);
            
            if ($this->settings['enable_debug_logging'] ?? false) {
                $this->logger->info("前台主文件上传成功: " . $result['url']);
            }
        }
        
        // 使用 shutdown 钩子异步处理缩略图（仅图片且完整模式）
        if ($is_image && $upload_mode === 'all_sizes' && !empty($metadata['sizes'])) {
            // 存储待处理的附件ID
            if (!isset($GLOBALS['easy_r2_pending_thumbnails'])) {
                $GLOBALS['easy_r2_pending_thumbnails'] = [];
            }
            $GLOBALS['easy_r2_pending_thumbnails'][] = $attachment_id;
            
            // 确保只注册一次 shutdown 钩子
            if (!has_action('shutdown', [$this, 'process_pending_thumbnails'])) {
                add_action('shutdown', [$this, 'process_pending_thumbnails'], 999);
            }
            
            if ($this->settings['enable_debug_logging'] ?? false) {
                $this->logger->debug("已安排 shutdown 任务处理缩略图: ID={$attachment_id}");
            }
        }
        
        return $metadata;
    }
    /**
     * 处理待上传的缩略图（在 shutdown 钩子中执行）
     */
    public function process_pending_thumbnails(): void {
        // 检查是否有待处理的附件
        if (empty($GLOBALS['easy_r2_pending_thumbnails'])) {
            return;
        }
        
        // 获取待处理的附件ID列表
        $pending_attachments = $GLOBALS['easy_r2_pending_thumbnails'];
        
        // 清空全局变量，防止重复处理
        $GLOBALS['easy_r2_pending_thumbnails'] = [];
        
        // 关键：确保即使用户关闭浏览器也能继续执行
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        
        // 关键：增加执行时间限制
        if (function_exists('set_time_limit')) {
            set_time_limit(300); // 5分钟
        }
        
        if ($this->settings['enable_debug_logging'] ?? false) {
            $this->logger->debug("Shutdown 钩子开始处理缩略图", [
                'count' => count($pending_attachments),
                'attachment_ids' => $pending_attachments
            ]);
        }
        
        foreach ($pending_attachments as $attachment_id) {
            $this->handle_async_thumbnail_upload($attachment_id);
        }
        
        if ($this->settings['enable_debug_logging'] ?? false) {
            $this->logger->debug("Shutdown 钩子缩略图处理完成");
        }
    }
    
    
    /**
     * 处理后台缩略图上传
     */
    public function handle_async_thumbnail_upload(int $attachment_id): void {
        if ($this->settings['enable_debug_logging'] ?? false) {
            $this->logger->debug("后台开始处理缩略图: ID={$attachment_id}");
        }
        
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return;
        }
        
        if (!$this->storage_manager->is_configured()) {
            return;
        }
        
        $r2_url = get_post_meta($attachment_id, '_r2_url', true);
        if (empty($r2_url)) {
            return;
        }
        
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata) || empty($metadata['sizes'])) {
            return;
        }
        
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return;
        }
        
        $upload_dir = dirname($file_path);
        
        foreach ($metadata['sizes'] as $size => $size_data) {
            $existing = get_post_meta($attachment_id, '_r2_url_' . $size, true);
            if (!empty($existing)) {
                continue;
            }
            
            $size_file = $upload_dir . '/' . $size_data['file'];
            if (file_exists($size_file)) {
                $result = $this->storage_manager->upload_file($attachment_id, $size_file, $size);
                
                if (!is_wp_error($result)) {
                    update_post_meta($attachment_id, '_r2_url_' . $size, $result['url']);
                    update_post_meta($attachment_id, '_r2_key_' . $size, $result['key']);
                    
                    if ($this->settings['enable_debug_logging'] ?? false) {
                        $this->logger->debug("缩略图上传成功: {$size}: " . $result['url']);
                    }
                } else {
                    if ($this->settings['enable_debug_logging'] ?? false) {
                        $this->logger->error("缩略图上传失败: {$size}: " . $result->get_error_message());
                    }
                }
            }
        }
    }
    
    /**
     * 处理元数据更新时的上传
     * 
     * 当附件元数据更新时，如果已经上传过则重新上传
     * 
     * @param array $metadata 附件元数据
     * @param int $attachment_id 附件ID
     * @return array 附件元数据
     */
    public function handle_metadata_update(array $metadata, int $attachment_id): array {
        // 检查是否已经上传过
        $existing = get_post_meta($attachment_id, '_r2_url', true);
        if (!empty($existing)) {
            // 重新上传所有尺寸以确保同步
            if ($this->settings['enable_debug_logging'] ?? false) {
                $this->logger->debug("在更新时重新同步附件 {$attachment_id}");
            }
            $this->process_attachment_upload($attachment_id, $metadata);
        }
        
        return $metadata;
    }
    
    /**
     * 处理附件上传
     * 
     * 收集所有需要上传的文件并上传到R2
     * 
     * @param int $attachment_id 附件ID
     * @param array $metadata 附件元数据
     * @return bool 是否成功
     */
    private function process_attachment_upload(int $attachment_id, array $metadata): bool {
        // 获取主文件路径
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        // 快速模式只上传主文件
        $files_to_upload = ['full' => $file_path];
        
        // 仅在完整模式下添加缩略图
        $upload_mode = $this->settings['upload_mode'] ?? 'full_only';
        if ($upload_mode === 'all_sizes') {
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
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
        return $this->storage_manager->upload_all_sizes($attachment_id, $files_to_upload);
    }
    
    /**
     * 处理附件删除
     * 
     * 当附件从WordPress删除时，也从R2删除对应的文件
     * 
     * @param int $attachment_id 附件ID
     * @return void
     */
    public function handle_attachment_deletion(int $attachment_id): void {
        // 获取R2文件键
        $r2_key = get_post_meta($attachment_id, '_r2_key', true);
        
        if (!empty($r2_key)) {
            $result = $this->storage_manager->delete_file($r2_key);
            
            if ($this->settings['enable_debug_logging'] ?? false) {
                if (is_wp_error($result)) {
                    $this->logger->error("删除附件 {$attachment_id} 失败: " . $result->get_error_message());
                } else {
                    $this->logger->info("从Cloudflare R2删除: {$r2_key}");
                }
            }
        }
        
        // 同时删除缩略图键
        $sizes = get_intermediate_image_sizes();
        foreach ($sizes as $size) {
            $size_key = get_post_meta($attachment_id, '_r2_key_' . $size, true);
            if (!empty($size_key)) {
                $this->storage_manager->delete_file($size_key);
            }
        }
    }
    
    /**
     * 上传单个附件（用于批量同步）
     * 
     * @param int $attachment_id 附件ID
     * @param bool $regenerate_metadata 是否重新生成元数据
     * @return bool 是否成功
     */
    public function upload_single_attachment(int $attachment_id, bool $regenerate_metadata = false): bool {
        if (!$this->storage_manager->is_configured()) {
            return false;
        }
        
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        // 获取元数据
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        // 如果需要，重新生成元数据
        if ($regenerate_metadata) {
            $mime_type = get_post_mime_type($attachment_id);
            if (strpos($mime_type, 'image/') === 0) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
                if (!empty($metadata)) {
                    wp_update_attachment_metadata($attachment_id, $metadata);
                }
            }
        }
        
        // 收集要上传的文件：主文件 + 缩略图（如果是图片）
        $files_to_upload = ['full' => $file_path];
        
        $mime_type = get_post_mime_type($attachment_id);
        if (strpos($mime_type, 'image/') === 0) {
            $upload_mode = $this->settings['upload_mode'] ?? 'full_only';
            if ($upload_mode === 'all_sizes' && !empty($metadata['sizes'])) {
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
        return $this->storage_manager->upload_all_sizes($attachment_id, $files_to_upload);
    }
}