<?php
/**
 * Plugin Name: Easy Cloudflare R2 Storage
 * Plugin URI: https://www.quanyixia.com
 * Description: 简单易用的WordPress媒体文件存储到Cloudflare R2的插件，支持自动上传、URL重写、批量同步等功能。
 * Version: 1.0.0
 * Author: quanyixia
 * Author URI: https://quanyixia.com
 * Email: junjunai2009@gmail.com
 * Telegram: t.me/junjunai2009
 * License: GPL v2 or later
 * Text Domain: easy-cloudflare-r2-storage
 * Requires PHP: 8.2
 * Requires at least: 6.9.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 抑制 PHP 8.2+ 的动态属性弃用警告
// 这些警告来自第三方主题/插件，不影响插件功能
if (version_compare(PHP_VERSION, '8.2.0', '>=')) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

/**
 * 插件版本号
 */
define('EASY_R2_STORAGE_VERSION', '1.0.0');

/**
 * 插件主文件路径
 */
define('EASY_R2_STORAGE_FILE', __FILE__);

/**
 * 插件目录路径
 */
define('EASY_R2_STORAGE_PATH', plugin_dir_path(__FILE__));

/**
 * 插件URL
 */
define('EASY_R2_STORAGE_URL', plugin_dir_url(__FILE__));

/**
 * 插件基础名称
 */
define('EASY_R2_STORAGE_BASENAME', plugin_basename(__FILE__));

/**
 * 插件主类
 * 
 * 使用单例模式确保只有一个实例
 * 负责初始化所有插件组件
 */
class Easy_R2_Storage_Plugin {
    
    /**
     * 插件单例实例
     * 
     * @var Easy_R2_Storage_Plugin|null
     */
    private static ?Easy_R2_Storage_Plugin $instance = null;
    
    /**
     * 插件设置
     * 
     * @var array
     */
    private array $settings = [];
    
    /**
     * 组件实例
     * 
     * @var array
     */
    private array $components = [];
    
    /**
     * 获取插件单例实例
     * 
     * @return Easy_R2_Storage_Plugin 插件实例
     */
    public static function get_instance(): Easy_R2_Storage_Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 私有构造函数 - 防止直接实例化
     */
    private function __construct() {
        $this->load_plugin_files();
        $this->load_plugin_settings();
        
        // 在init钩子中初始化插件，确保WordPress核心已加载
        add_action('init', [$this, 'initialize_plugin']);
    }
    
    /**
     * 加载插件所需的类文件
     * 
     * 按照依赖顺序加载所有类文件
     */
    private function load_plugin_files(): void {
        $files = [
            'class-logger.php',
            'class-storage-manager.php',
            'class-url-handler.php',
            'class-media-uploader.php',
            'class-bulk-sync.php',
            'class-admin-panel.php',
            'class-main.php',
        ];
        
        foreach ($files as $file) {
            $file_path = EASY_R2_STORAGE_PATH . 'includes/' . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * 加载插件设置
     * 
     * 从WordPress选项中读取插件设置，如果没有则使用默认值
     */
    private function load_plugin_settings(): void {
        $default_settings = [
            'account_id' => '',
            'access_key_id' => '',
            'secret_access_key' => '',
            'bucket_name' => '',
            'public_url' => '',
            'auto_offload' => false,
            'enable_url_rewrite' => false,
            'delete_local_files' => false,
            'auto_fix_thumbnails' => false,
            'upload_mode' => 'full_only',
            'enable_debug_logging' => false,
            'keep_local_copy' => false,
            'file_path_pattern' => 'uploads/{year}/{month}/{filename}',
        ];
        
        $saved_settings = get_option('easy_r2_storage_settings', []);
        $this->settings = wp_parse_args($saved_settings, $default_settings);
    }
    
    /**
     * 在WordPress init钩子中初始化插件
     * 
     * 初始化所有组件并注册必要的钩子
     */
    public function initialize_plugin(): void {
        // 加载文本域
        $this->load_text_domain();
        
        // 初始化组件
        $this->initialize_components();
        
        // 注册激活和停用钩子
        register_activation_hook(__FILE__, [$this, 'plugin_activate']);
        register_deactivation_hook(__FILE__, [$this, 'plugin_deactivate']);
    }
    
    /**
     * 加载插件文本域
     * 
     * 用于国际化翻译
     */
    private function load_text_domain(): void {
        load_plugin_textdomain(
            'easy-cloudflare-r2-storage',
            false,
            dirname(EASY_R2_STORAGE_BASENAME) . '/languages'
        );
    }
    
    /**
     * 初始化所有插件组件
     * 
     * 按照正确的顺序初始化各个功能模块
     */
    private function initialize_components(): void {
        // 初始化日志记录器
        $this->components['logger'] = Easy_R2_Logger::get_instance();
        
        // 初始化存储管理器（处理R2 API操作）
        $this->components['storage_manager'] = new Easy_R2_Storage_Manager($this->settings);
        
        // 初始化URL处理器（处理URL重写）
        $this->components['url_handler'] = new Easy_R2_URL_Handler($this->settings);
        
        // 初始化媒体上传器（处理文件上传）
        $this->components['media_uploader'] = new Easy_R2_Media_Uploader(
            $this->settings,
            $this->components['storage_manager']
        );
        
        // 初始化批量同步器（处理批量同步）
        $this->components['bulk_sync'] = new Easy_R2_Bulk_Sync(
            $this->components['storage_manager'],
            $this->settings
        );
        
        // 初始化管理面板（后台界面）
        $this->components['admin_panel'] = new Easy_R2_Admin_Panel(
            $this->settings,
            $this->components['storage_manager'],
            $this->components['bulk_sync']
        );
        
        // 初始化主类（协调各个组件）
        $this->components['main'] = new Easy_R2_Main(
            $this->settings,
            $this->components['storage_manager'],
            $this->components['media_uploader'],
            $this->components['bulk_sync']
        );
    }
    
    /**
     * 插件激活时的回调函数
     * 
     * 创建默认选项并设置定时任务
     */
    public function plugin_activate(): void {
        // 创建默认选项
        $default_options = [
            'account_id' => '',
            'access_key_id' => '',
            'secret_access_key' => '',
            'bucket_name' => '',
            'public_url' => '',
            'auto_offload' => false,
            'enable_url_rewrite' => false,
            'delete_local_files' => false,
            'auto_fix_thumbnails' => false,
            'upload_mode' => 'full_only',
            'enable_debug_logging' => false,
            'keep_local_copy' => false,
            'file_path_pattern' => 'uploads/{year}/{month}/{filename}',
        ];
        
        add_option('easy_r2_storage_settings', $default_options);
        add_option('easy_r2_storage_auto_sync_enabled', false);
        add_option('easy_r2_storage_auto_sync_batch_size', 10);
        add_option('easy_r2_storage_auto_sync_interval', 'hourly');
        
        // 设置定时任务钩子
        if (!wp_next_scheduled('easy_r2_storage_auto_sync_event')) {
            wp_schedule_event(time(), 'hourly', 'easy_r2_storage_auto_sync_event');
        }
    }
    
    /**
     * 插件停用时的回调函数
     * 
     * 清理定时任务
     */
    public function plugin_deactivate(): void {
        // 清除自动同步定时任务
        $timestamp = wp_next_scheduled('easy_r2_storage_auto_sync_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'easy_r2_storage_auto_sync_event');
        }
    }
    
    /**
     * 获取插件设置
     * 
     * @return array 插件设置数组
     */
    public function get_settings(): array {
        return $this->settings;
    }
    
    /**
     * 获取组件实例
     * 
     * @param string $component_name 组件名称
     * @return mixed|null 组件实例或null
     */
    public function get_component(string $component_name) {
        return $this->components[$component_name] ?? null;
    }
}

/**
 * 初始化插件
 * 
 * 在plugins_loaded钩子中初始化插件主类
 */
function easy_r2_storage_init(): void {
    Easy_R2_Storage_Plugin::get_instance();
}
add_action('plugins_loaded', 'easy_r2_storage_init');

/**
 * 插件自动同步事件
 * 
 * 由WordPress Cron定时触发
 */
function easy_r2_storage_auto_sync_event(): void {
    $plugin = Easy_R2_Storage_Plugin::get_instance();
    $main = $plugin->get_component('main');
    
    if ($main) {
        $main->run_auto_sync();
    }
}
add_action('easy_r2_storage_auto_sync_event', 'easy_r2_storage_auto_sync_event');