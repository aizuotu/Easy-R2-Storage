<?php
/**
 * 主协调类
 * 
 * 负责协调插件的所有组件，包括：
 * - 运行自动同步任务
 * - 管理插件生命周期
 * - 协调各组件之间的交互
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
 * 主协调类
 * 
 * 这是插件的核心协调器，负责管理所有组件的交互
 */
class Easy_R2_Main {
    
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
     * 媒体上传器实例
     * 
     * @var Easy_R2_Media_Uploader
     */
    private Easy_R2_Media_Uploader $media_uploader;
    
    /**
     * 批量同步器实例
     * 
     * @var Easy_R2_Bulk_Sync
     */
    private Easy_R2_Bulk_Sync $bulk_sync;
    
    /**
     * 构造函数
     * 
     * @param array $settings 插件设置
     * @param Easy_R2_Storage_Manager $storage_manager 存储管理器
     * @param Easy_R2_Media_Uploader $media_uploader 媒体上传器
     * @param Easy_R2_Bulk_Sync $bulk_sync 批量同步器
     */
    public function __construct(
        array $settings,
        Easy_R2_Storage_Manager $storage_manager,
        Easy_R2_Media_Uploader $media_uploader,
        Easy_R2_Bulk_Sync $bulk_sync
    ) {
        $this->settings = $settings;
        $this->storage_manager = $storage_manager;
        $this->media_uploader = $media_uploader;
        $this->bulk_sync = $bulk_sync;
        
        // 初始化插件钩子
        $this->init_hooks();
    }
    
    /**
     * 初始化插件钩子
     * 
     * 注册所有WordPress钩子
     */
    private function init_hooks(): void {
        // 注册激活钩子
        register_activation_hook(EASY_R2_STORAGE_FILE, [$this, 'on_activation']);
        
        // 注册停用钩子
        register_deactivation_hook(EASY_R2_STORAGE_FILE, [$this, 'on_deactivation']);
        
        // 设置更新钩子
        add_action('easy_r2_storage_settings_updated', [$this, 'on_settings_updated']);
        
        // 自动同步事件钩子
        add_action('easy_r2_storage_auto_sync_event', [$this, 'run_auto_sync']);
        
        // 初始化定时任务
        add_action('init', [$this, 'schedule_auto_sync']);
    }
    
    /**
     * 插件激活回调
     * 
     * 当插件激活时执行
     */
    public function on_activation(): void {
        // 设置默认配置
        $this->set_default_settings();
        
        // 安装定时任务
        $this->schedule_auto_sync();
        
        // 清除缓存
        $this->clear_cache();
        
        // 记录激活日志
        Easy_R2_Logger::get_instance()->info('插件已激活', [
            'version' => EASY_R2_STORAGE_VERSION
        ]);
    }
    
    /**
     * 插件停用回调
     * 
     * 当插件停用时执行
     */
    public function on_deactivation(): void {
        // 清除定时任务
        wp_clear_scheduled_hook('easy_r2_storage_auto_sync_event');
        
        // 清除缓存
        $this->clear_cache();
        
        // 记录停用日志
        Easy_R2_Logger::get_instance()->info('插件已停用');
    }
    
    /**
     * 设置更新回调
     * 
     * 当设置更新时执行
     * 
     * @param array $new_settings 新的设置
     */
    public function on_settings_updated(array $new_settings): void {
        // 更新本地设置
        $this->settings = $new_settings;
        
        // 清除缓存以确保URL重写正确
        $this->clear_cache();
        
        // 重新调度自动同步任务
        $this->schedule_auto_sync();
        
        // 记录设置更新
        Easy_R2_Logger::get_instance()->info('设置已更新', [
            'auto_offload' => $new_settings['auto_offload'],
            'enable_url_rewrite' => $new_settings['enable_url_rewrite'],
            'delete_local_files' => $new_settings['delete_local_files'],
            'auto_sync_enabled' => get_option('easy_r2_storage_auto_sync_enabled', false)
        ]);
    }
    
    /**
     * 运行自动同步
     * 
     * 通过定时任务或手动触发执行自动同步
     */
    public function run_auto_sync(): void {
        $logger = Easy_R2_Logger::get_instance();
        
        $logger->info('开始自动同步任务');
        
        // 检查是否启用自动同步
        $auto_sync_enabled = get_option('easy_r2_storage_auto_sync_enabled', false);
        
        if (!$auto_sync_enabled) {
            $logger->info('自动同步已禁用，跳过同步任务');
            return;
        }
        
        // 检查是否已配置
        if (!$this->storage_manager->is_configured()) {
            $logger->warning('R2存储未配置，跳过自动同步');
            return;
        }
        
        // 获取批量大小
        $batch_size = get_option('easy_r2_storage_auto_sync_batch_size', 10);
        
        // 获取同步模式
        $sync_mode = get_option('easy_r2_storage_auto_sync_mode', 'full');
        
        // 执行同步
        $synced_count = $this->bulk_sync->sync_batch($batch_size, $sync_mode);
        
        $logger->info('自动同步任务完成', [
            'synced_count' => $synced_count,
            'batch_size' => $batch_size,
            'sync_mode' => $sync_mode
        ]);
    }
    
    /**
     * 调度自动同步任务
     * 
     * 根据设置安排定时任务
     */
    public function schedule_auto_sync(): void {
        // 清除现有任务
        wp_clear_scheduled_hook('easy_r2_storage_auto_sync_event');
        
        // 检查是否启用自动同步
        $auto_sync_enabled = get_option('easy_r2_storage_auto_sync_enabled', false);
        
        if (!$auto_sync_enabled) {
            return;
        }
        
        // 获取同步间隔
        $interval = get_option('easy_r2_storage_auto_sync_interval', 'hourly');
        
        // 验证间隔是否有效
        $schedules = wp_get_schedules();
        if (!isset($schedules[$interval])) {
            $interval = 'hourly'; // 默认为每小时
        }
        
        // 调度任务
        wp_schedule_event(time(), $interval, 'easy_r2_storage_auto_sync_event');
        
        Easy_R2_Logger::get_instance()->info('自动同步任务已调度', [
            'interval' => $interval,
            'next_run' => wp_next_scheduled('easy_r2_storage_auto_sync_event')
        ]);
    }
    
    /**
     * 设置默认配置
     * 
     * 如果配置不存在，设置默认值
     */
    private function set_default_settings(): void {
        $default_settings = [
            'account_id' => '',
            'access_key_id' => '',
            'secret_access_key' => '',
            'bucket_name' => '',
            'public_url' => '',
            'auto_offload' => true,
            'enable_url_rewrite' => true,
            'delete_local_files' => false,
            'keep_local_copy' => true,
            'auto_fix_thumbnails' => true,
            'enable_debug_logging' => false,
            'upload_mode' => 'all_sizes',
            'file_path_pattern' => 'uploads/{year}/{month}/{filename}'
        ];
        
        $current_settings = get_option('easy_r2_storage_settings', []);
        
        // 合并默认设置
        if (empty($current_settings)) {
            update_option('easy_r2_storage_settings', $default_settings);
        }
        
        // 设置自动同步默认值
        if (get_option('easy_r2_storage_auto_sync_enabled') === false) {
            update_option('easy_r2_storage_auto_sync_enabled', false);
        }
        
        if (get_option('easy_r2_storage_auto_sync_batch_size') === false) {
            update_option('easy_r2_storage_auto_sync_batch_size', 10);
        }
        
        if (get_option('easy_r2_storage_auto_sync_interval') === false) {
            update_option('easy_r2_storage_auto_sync_interval', 'hourly');
        }
        
        if (get_option('easy_r2_storage_auto_sync_mode') === false) {
            update_option('easy_r2_storage_auto_sync_mode', 'full');
        }
    }
    
    /**
     * 清除缓存
     * 
     * 清除WordPress缓存以确保URL重写正确
     */
    private function clear_cache(): void {
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
     * 获取当前设置
     * 
     * @return array 当前设置
     */
    public function get_settings(): array {
        return $this->settings;
    }
    
    /**
     * 获取存储管理器
     * 
     * @return Easy_R2_Storage_Manager
     */
    public function get_storage_manager(): Easy_R2_Storage_Manager {
        return $this->storage_manager;
    }
    
    /**
     * 获取媒体上传器
     * 
     * @return Easy_R2_Media_Uploader
     */
    public function get_media_uploader(): Easy_R2_Media_Uploader {
        return $this->media_uploader;
    }
    
    /**
     * 获取批量同步器
     * 
     * @return Easy_R2_Bulk_Sync
     */
    public function get_bulk_sync(): Easy_R2_Bulk_Sync {
        return $this->bulk_sync;
    }
    
    /**
     * 检查插件是否已完全配置
     * 
     * @return bool 是否已配置
     */
    public function is_fully_configured(): bool {
        return $this->storage_manager->is_configured() && !empty($this->settings['bucket_name']);
    }
    
    /**
     * 获取插件状态
     * 
     * @return array 插件状态信息
     */
    public function get_status(): array {
        $total = $this->bulk_sync->get_total_media();
        $synced = $this->bulk_sync->get_synced_media();
        
        return [
            'configured' => $this->is_fully_configured(),
            'auto_offload' => $this->settings['auto_offload'],
            'enable_url_rewrite' => $this->settings['enable_url_rewrite'],
            'delete_local_files' => $this->settings['delete_local_files'],
            'auto_sync_enabled' => get_option('easy_r2_storage_auto_sync_enabled', false),
            'total_media' => $total,
            'synced_media' => $synced,
            'unsynced_media' => $total - $synced,
            'debug_logging' => $this->settings['enable_debug_logging'],
            'upload_mode' => $this->settings['upload_mode']
        ];
    }
}