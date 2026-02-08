<?php
/**
 * 管理面板类
 * 
 * 负责处理WordPress后台管理界面，包括：
 * - 添加管理菜单
 * - 渲染设置页面
 * - 处理AJAX请求
 * - 注册设置
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
 * 管理面板类
 * 
 * 管理所有后台界面和设置
 */
class Easy_R2_Admin_Panel {
    
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
     * 批量同步器实例
     * 
     * @var Easy_R2_Bulk_Sync
     */
    private Easy_R2_Bulk_Sync $bulk_sync;
    
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
     * @param Easy_R2_Bulk_Sync $bulk_sync 批量同步器
     */
    public function __construct(
        array $settings,
        Easy_R2_Storage_Manager $storage_manager,
        Easy_R2_Bulk_Sync $bulk_sync
    ) {
        $this->settings = $settings;
        $this->storage_manager = $storage_manager;
        $this->bulk_sync = $bulk_sync;
        $this->logger = Easy_R2_Logger::get_instance();
        
        // 初始化管理钩子
        $this->init_admin_hooks();
        
        // 注册异步连接测试钩子
        add_action('easy_r2_storage_async_test_connection', [$this, 'handle_async_test_connection']);
    }
    
    /**
     * 初始化管理钩子
     * 
     * 注册所有WordPress后台相关的钩子
     */
    private function init_admin_hooks(): void {
        // 添加管理菜单
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // 注册设置
        add_action('admin_init', [$this, 'register_settings']);
        
        // 加载管理脚本和样式
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // AJAX处理程序
        add_action('wp_ajax_easy_r2_storage_save_settings', [$this, 'handle_save_settings']);
        add_action('wp_ajax_easy_r2_storage_test_connection', [$this, 'handle_test_connection']);
        add_action('wp_ajax_easy_r2_storage_run_auto_sync', [$this, 'handle_run_auto_sync']);
        add_action('wp_ajax_easy_r2_storage_get_debug_info', [$this, 'handle_get_debug_info']);
    }
    
    /**
     * 添加管理菜单
     * 
     * 在WordPress后台添加插件菜单项
     */
    public function add_admin_menu(): void {
        // 主插件页面
        add_options_page(
            'Easy Cloudflare R2 Storage',
            'Easy R2 Storage',
            'manage_options',
            'easy-r2-storage',
            [$this, 'render_settings_page']
        );
        
        // 批量同步页面
        add_submenu_page(
            'options-general.php',
            '批量同步',
            '批量同步',
            'manage_options',
            'easy-r2-storage-bulk-sync',
            [$this, 'render_bulk_sync_page']
        );
    }
    
    /**
     * 注册设置
     * 
     * 注册WordPress设置API
     */
    public function register_settings(): void {
        register_setting(
            'easy_r2_storage_settings',
            'easy_r2_storage_settings',
            [
                'sanitize_callback' => [$this, 'sanitize_settings']
            ]
        );
    }
    
    /**
     * 清理设置
     * 
     * @param array $input 输入的设置
     * @return array 清理后的设置
     */
    public function sanitize_settings(array $input): array {
        $sanitized = [];
        
        // 清理文本字段
        $sanitized['account_id'] = sanitize_text_field($input['account_id'] ?? '');
        $sanitized['access_key_id'] = sanitize_text_field($input['access_key_id'] ?? '');
        $sanitized['secret_access_key'] = sanitize_text_field($input['secret_access_key'] ?? '');
        $sanitized['bucket_name'] = sanitize_text_field($input['bucket_name'] ?? '');
        $sanitized['public_url'] = esc_url_raw($input['public_url'] ?? '');
        
        // 清理复选框（布尔值）
        $sanitized['auto_offload'] = !empty($input['auto_offload']);
        $sanitized['enable_url_rewrite'] = !empty($input['enable_url_rewrite']);
        $sanitized['delete_local_files'] = !empty($input['delete_local_files']);
        $sanitized['auto_fix_thumbnails'] = !empty($input['auto_fix_thumbnails']);
        $sanitized['enable_debug_logging'] = !empty($input['enable_debug_logging']);
        $sanitized['keep_local_copy'] = !empty($input['keep_local_copy']);
        
        // 清理上传模式（白名单）
        $valid_modes = ['full_only', 'all_sizes'];
        $upload_mode = isset($input['upload_mode']) ? sanitize_text_field($input['upload_mode']) : 'full_only';
        $sanitized['upload_mode'] = in_array($upload_mode, $valid_modes, true) ? $upload_mode : 'full_only';
        
        // 清理文件路径模式
        $sanitized['file_path_pattern'] = sanitize_text_field($input['file_path_pattern'] ?? 'uploads/{year}/{month}/{filename}');
        
        return $sanitized;
    }
    
    /**
     * 加载管理脚本和样式
     * 
     * @param string $hook 当前页面钩子
     */
    public function enqueue_admin_scripts(string $hook): void {
        // 只在插件页面加载
        if (!in_array($hook, ['settings_page_easy-r2-storage', 'settings_page_easy-r2-storage-bulk-sync'])) {
            return;
        }
        
        // 加载CSS
        wp_enqueue_style(
            'easy-r2-storage-admin',
            EASY_R2_STORAGE_URL . 'assets/css/admin.css',
            [],
            EASY_R2_STORAGE_VERSION
        );
        
        // 加载jQuery
        wp_enqueue_script('jquery');
        
        // 根据页面加载不同的JS
        if ($hook === 'settings_page_easy-r2-storage') {
            wp_enqueue_script(
                'easy-r2-storage-admin-settings',
                EASY_R2_STORAGE_URL . 'assets/js/admin-settings.js',
                ['jquery'],
                EASY_R2_STORAGE_VERSION,
                true
            );
            
            // 本地化脚本数据
            wp_localize_script('easy-r2-storage-admin-settings', 'easy_r2_storage_admin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'credentials_nonce' => wp_create_nonce('easy_r2_storage_credentials_nonce'),
                'tool_nonce' => wp_create_nonce('easy_r2_storage_tool_nonce'),
                'url_nonce' => wp_create_nonce('easy_r2_storage_url_nonce'),
                'sync_nonce' => wp_create_nonce('easy_r2_storage_sync_nonce'),
                'debug_nonce' => wp_create_nonce('easy_r2_storage_debug_nonce'),
                'save_settings_nonce' => wp_create_nonce('easy_r2_storage_save_settings'),
                'test_connection_nonce' => wp_create_nonce('easy_r2_storage_test_connection'),
                'run_sync_nonce' => wp_create_nonce('easy_r2_storage_run_auto_sync'),
                'get_debug_nonce' => wp_create_nonce('easy_r2_storage_get_debug_info'),
                'test_connection_text' => __('测试连接', 'easy-cloudflare-r2-storage'),
                'run_sync_text' => __('立即运行同步', 'easy-cloudflare-r2-storage'),
                'refresh_debug_text' => __('刷新调试信息', 'easy-cloudflare-r2-storage'),
            ]);
        } elseif ($hook === 'settings_page_easy-r2-storage-bulk-sync') {
            wp_enqueue_script(
                'easy-r2-storage-bulk-sync',
                EASY_R2_STORAGE_URL . 'assets/js/bulk-sync.js',
                ['jquery'],
                EASY_R2_STORAGE_VERSION,
                true
            );
            
            // 本地化脚本数据
            wp_localize_script('easy-r2-storage-bulk-sync', 'easy_r2_storage_bulk', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('easy_r2_storage_bulk_sync'),
            ]);
        }
    }
    
    /**
     * 渲染设置页面
     * 
     * 显示插件的主设置界面
     */
    public function render_settings_page(): void {
        // 1. 处理R2凭据表单提交
        if (isset($_POST['submit_credentials'])) {
            check_admin_referer('easy_r2_storage_credentials_nonce', 'credentials_nonce');
            
            $input = isset($_POST['easy_r2_storage_settings']) 
                ? map_deep(wp_unslash($_POST['easy_r2_storage_settings']), 'sanitize_text_field') 
                : [];
            
            // 只清理凭据相关的设置
            $current_settings = get_option('easy_r2_storage_settings', []);
            $new_settings = array_merge($current_settings, [
                'account_id' => sanitize_text_field($input['account_id'] ?? ''),
                'access_key_id' => sanitize_text_field($input['access_key_id'] ?? ''),
                'secret_access_key' => sanitize_text_field($input['secret_access_key'] ?? ''),
                'bucket_name' => sanitize_text_field($input['bucket_name'] ?? ''),
            ]);
            
            // 更新设置
            update_option('easy_r2_storage_settings', $new_settings);
            $this->settings = $new_settings;
            
            echo '<div class="notice notice-success"><p>' . 
                esc_html__('R2凭据已保存！', 'easy-cloudflare-r2-storage') . 
                '</p></div>';
            
            // 测试连接
            if ($this->storage_manager->is_configured()) {
                $test_result = $this->storage_manager->test_connection();
                if (is_wp_error($test_result)) {
                    echo '<div class="notice notice-warning"><p><strong>警告：</strong> ' . 
                        esc_html($test_result->get_error_message()) . 
                        '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>' . 
                        esc_html__('R2连接测试成功！', 'easy-cloudflare-r2-storage') . 
                        '</p></div>';
                }
            }
        }
        
        // 2. 处理工具设置表单提交
        if (isset($_POST['submit_tool_settings'])) {
            check_admin_referer('easy_r2_storage_tool_nonce', 'tool_nonce');
            
            $input = isset($_POST['easy_r2_storage_settings']) 
                ? map_deep(wp_unslash($_POST['easy_r2_storage_settings']), 'sanitize_text_field') 
                : [];
            
            // 清理并更新工具设置
            $current_settings = get_option('easy_r2_storage_settings', []);
            $new_settings = array_merge($current_settings, [
                'auto_offload' => !empty($input['auto_offload']),
                'upload_mode' => sanitize_text_field($input['upload_mode'] ?? 'full_only'),
                'delete_local_files' => !empty($input['delete_local_files']),
                'keep_local_copy' => !empty($input['keep_local_copy']),
                'file_path_pattern' => sanitize_text_field($input['file_path_pattern'] ?? 'uploads/{year}/{month}/{filename}'),
            ]);
            
            update_option('easy_r2_storage_settings', $new_settings);
            $this->settings = $new_settings;
            
            echo '<div class="notice notice-success"><p>' . 
                esc_html__('工具设置已保存！', 'easy-cloudflare-r2-storage') . 
                '</p></div>';
        }
        
        // 3. 处理URL设置表单提交
        if (isset($_POST['submit_url_settings'])) {
            check_admin_referer('easy_r2_storage_url_nonce', 'url_nonce');
            
            $input = isset($_POST['easy_r2_storage_settings']) 
                ? map_deep(wp_unslash($_POST['easy_r2_storage_settings']), 'sanitize_text_field') 
                : [];
            
            // 清理并更新URL设置
            $current_settings = get_option('easy_r2_storage_settings', []);
            $new_settings = array_merge($current_settings, [
                'enable_url_rewrite' => !empty($input['enable_url_rewrite']),
                'public_url' => esc_url_raw($input['public_url'] ?? ''),
                'auto_fix_thumbnails' => !empty($input['auto_fix_thumbnails']),
            ]);
            
            update_option('easy_r2_storage_settings', $new_settings);
            $this->settings = $new_settings;
            
            echo '<div class="notice notice-success"><p>' . 
                esc_html__('URL设置已保存！', 'easy-cloudflare-r2-storage') . 
                '</p></div>';
        }
        
        // 4. 处理同步设置表单提交
        if (isset($_POST['submit_sync_settings'])) {
            check_admin_referer('easy_r2_storage_sync_nonce', 'sync_nonce');
            
            $auto_sync_enabled = isset($_POST['easy_r2_storage_auto_sync_enabled']);
            update_option('easy_r2_storage_auto_sync_enabled', $auto_sync_enabled);
            
            if (isset($_POST['easy_r2_storage_auto_sync_batch_size'])) {
                $batch_size = intval($_POST['easy_r2_storage_auto_sync_batch_size']);
                $batch_size = max(1, min(50, $batch_size));
                update_option('easy_r2_storage_auto_sync_batch_size', $batch_size);
            }
            
            if (isset($_POST['easy_r2_storage_auto_sync_interval'])) {
                update_option(
                    'easy_r2_storage_auto_sync_interval',
                    sanitize_text_field(wp_unslash($_POST['easy_r2_storage_auto_sync_interval']))
                );
            }
            
            echo '<div class="notice notice-success"><p>' . 
                esc_html__('同步设置已保存！', 'easy-cloudflare-r2-storage') . 
                '</p></div>';
        }
        
        // 5. 处理调试设置表单提交
        if (isset($_POST['submit_debug_settings'])) {
            check_admin_referer('easy_r2_storage_debug_nonce', 'debug_nonce');
            
            $input = isset($_POST['easy_r2_storage_settings']) 
                ? map_deep(wp_unslash($_POST['easy_r2_storage_settings']), 'sanitize_text_field') 
                : [];
            
            $current_settings = get_option('easy_r2_storage_settings', []);
            $new_settings = array_merge($current_settings, [
                'enable_debug_logging' => !empty($input['enable_debug_logging']),
            ]);
            
            update_option('easy_r2_storage_settings', $new_settings);
            $this->settings = $new_settings;
            
            echo '<div class="notice notice-success"><p>' . 
                esc_html__('调试设置已保存！', 'easy-cloudflare-r2-storage') . 
                '</p></div>';
        }
        
        // 加载视图文件
        require EASY_R2_STORAGE_PATH . 'views/admin-settings.php';
    }
    
    /**
     * 渲染批量同步页面
     * 
     * 显示批量同步界面
     */
    public function render_bulk_sync_page(): void {
        $total = $this->bulk_sync->get_total_media();
        $synced = $this->bulk_sync->get_synced_media();
        $remaining = $total - $synced;
        
        // 加载视图文件
        require EASY_R2_STORAGE_PATH . 'views/bulk-sync.php';
    }
    
    /**
     * 处理保存设置AJAX请求
     *
     * @return void
     */
    public function handle_save_settings(): void {
        // 验证nonce
        if (!check_ajax_referer('easy_r2_storage_save_settings', 'nonce', false)) {
            wp_send_json_error('安全验证失败，请刷新页面重试');
            return;
        }
        
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
            return;
        }
        
        // 解析序列化的表单数据（参考 yctvn 的实现）
        if (!isset($_POST['settings'])) {
            wp_send_json_error('请求数据不完整，请刷新页面重试');
            return;
        }
        
        parse_str(wp_unslash($_POST['settings']), $form_data);
        
        if (!isset($form_data['easy_r2_storage_settings'])) {
            wp_send_json_error('无效的表单数据');
            return;
        }
        
        $input = $form_data['easy_r2_storage_settings'];
            
        // 清理设置
        $new_settings = $this->sanitize_settings($input);
        
        // 更新设置
        update_option('easy_r2_storage_settings', $new_settings);
            
        // 处理自动同步设置
        $auto_sync_enabled = isset($form_data['easy_r2_storage_auto_sync_enabled']);
        update_option('easy_r2_storage_auto_sync_enabled', $auto_sync_enabled);
            
        if (isset($form_data['easy_r2_storage_auto_sync_batch_size'])) {
            $batch_size = intval($form_data['easy_r2_storage_auto_sync_batch_size']);
            $batch_size = max(1, min(50, $batch_size));
            update_option('easy_r2_storage_auto_sync_batch_size', $batch_size);
        }
            
        if (isset($form_data['easy_r2_storage_auto_sync_interval'])) {
            update_option(
                'easy_r2_storage_auto_sync_interval',
                sanitize_text_field($form_data['easy_r2_storage_auto_sync_interval'])
            );
        }
        
        // 触发设置更新钩子
        do_action('easy_r2_storage_settings_updated', $new_settings);
        
        // 更新本地设置
        $this->settings = $new_settings;
        
        // 准备响应（参考 yctvn 的实现）
        $response = [
            'message' => __('设置保存成功！', 'easy-cloudflare-r2-storage')
        ];
        
        // 测试连接并返回警告信息（同步测试，参考 yctvn）
        // 创建新的存储管理器实例，使用新设置
        $test_storage_manager = new Easy_R2_Storage_Manager($new_settings);
        if ($test_storage_manager->is_configured()) {
            $test_result = $test_storage_manager->test_connection();
            if (is_wp_error($test_result)) {
                $response['warning'] = '警告：' . $test_result->get_error_message();
            }
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * 处理测试连接AJAX请求
     *
     * @return void
     */
    public function handle_test_connection(): void {
        try {
            if (!check_ajax_referer('easy_r2_storage_test_connection', 'nonce', false)) {
                wp_send_json_error('安全验证失败，请刷新页面重试');
                return;
            }
            
            if (!$this->storage_manager->is_configured()) {
                $this->logger->warning('测试连接：R2未配置');
                wp_send_json_error('请先配置R2凭据');
                return;
            }
            
            $this->logger->info('测试连接：开始测试');
            $result = $this->storage_manager->test_connection();
            
            if (is_wp_error($result)) {
                $this->logger->error('测试连接：失败', ['error' => $result->get_error_message()]);
                wp_send_json_error($result->get_error_message());
            } else {
                $this->logger->info('测试连接：成功');
                wp_send_json_success('连接成功！R2存储桶可以访问。');
            }
        } catch (Exception $e) {
            $this->logger->error('测试连接：发生异常', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            wp_send_json_error('测试失败：' . $e->getMessage());
        }
    }
    
    /**
     * 处理运行自动同步AJAX请求
     *
     * @return void
     */
    public function handle_run_auto_sync(): void {
        try {
            if (!check_ajax_referer('easy_r2_storage_run_auto_sync', 'nonce', false)) {
                wp_send_json_error('安全验证失败，请刷新页面重试');
                return;
            }
            
            if (!current_user_can('manage_options')) {
                $this->logger->error('运行自动同步：权限不足');
                wp_send_json_error('权限不足');
                return;
            }
            
            $this->logger->info('运行自动同步：开始执行');
            
            // 运行自动同步过程
            do_action('easy_r2_storage_auto_sync_event');
            
            $this->logger->info('运行自动同步：执行完成');
            wp_send_json_success('自动同步过程已完成。请查看日志了解详情。');
        } catch (Exception $e) {
            $this->logger->error('运行自动同步：发生异常', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            wp_send_json_error('同步失败：' . $e->getMessage());
        }
    }
    
    /**
     * 处理获取调试信息AJAX请求
     *
     * @return void
     */
    public function handle_get_debug_info(): void {
        try {
            if (!check_ajax_referer('easy_r2_storage_get_debug_info', 'nonce', false)) {
                wp_send_json_error('安全验证失败，请刷新页面重试');
                return;
            }
            
            if (!current_user_can('manage_options')) {
                $this->logger->error('获取调试信息：权限不足');
                wp_send_json_error('权限不足');
                return;
            }
            
            $this->logger->debug('获取调试信息：开始生成');
            $debug_info = $this->get_debug_info();
            
            wp_send_json_success(['debug_info' => $debug_info]);
        } catch (Exception $e) {
            $this->logger->error('获取调试信息：发生异常', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            wp_send_json_error('获取失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取调试信息
     * 
     * @return string 调试信息HTML
     */
    private function get_debug_info(): string {
        $debug_info = '';
        
        // 获取最近的附件
        $attachments = get_posts([
            'post_type' => 'attachment',
            'numberposts' => 10,
            'orderby' => 'ID',
            'order' => 'DESC',
            'post_status' => 'inherit'
        ]);
        
        $debug_info .= '<h3>📊 调试信息</h3>';
        $debug_info .= '<p>以下是最近的附件状态信息：</p>';
        
        $debug_info .= '<div class="recent-attachments">';
        $debug_info .= '<table class="wp-list-table widefat fixed striped">';
        $debug_info .= '<thead><tr><th>ID</th><th>标题</th><th>R2 URL</th><th>状态</th></tr></thead>';
        $debug_info .= '<tbody>';
        
        $synced_count = 0;
        $error_count = 0;
        
        foreach ($attachments as $attachment) {
            $metadata = wp_get_attachment_metadata($attachment->ID);
            $is_synced = isset($metadata['_r2_url']) && !empty($metadata['_r2_url']);
            $status = $is_synced ? '已同步' : '未同步';
            
            if ($is_synced) {
                $synced_count++;
            } else {
                $error_count++;
            }
            
            $r2_url = $is_synced ? get_post_meta($attachment->ID, '_r2_url', true) : '未同步';
            $truncated_url = strlen($r2_url) > 50 ? substr($r2_url, 0, 50) . '...' : $r2_url;
            
            $debug_info .= '<tr>';
            $debug_info .= '<td>' . $attachment->ID . '</td>';
            $debug_info .= '<td>' . esc_html(get_the_title($attachment->ID)) . '</td>';
            $debug_info .= '<td title="' . esc_attr($r2_url) . '">' . esc_html($truncated_url) . '</td>';
            $debug_info .= '<td>' . $status . '</td>';
            $debug_info .= '</tr>';
        }
        
        $debug_info .= '</tbody></table>';
        $debug_info .= '<p class="sync-stats" style="margin-top: 10px;">';
        $debug_info .= '<strong>同步统计：</strong> 已同步 ' . $synced_count . ' 个附件，' . $error_count . ' 个未同步。';
        $debug_info .= '</p>';
        $debug_info .= '</div>';
        
        // 设置状态
        $debug_info .= '<h3>当前设置</h3>';
        $debug_info .= '<pre>';
        $debug_info .= '自动上传: ' . ($this->settings['auto_offload'] ? '是' : '否') . "\n";
        $debug_info .= 'URL重写: ' . ($this->settings['enable_url_rewrite'] ? '是' : '否') . "\n";
        $debug_info .= '删除本地文件: ' . ($this->settings['delete_local_files'] ? '是' : '否') . "\n";
        $debug_info .= '保留本地副本: ' . ($this->settings['keep_local_copy'] ? '是' : '否') . "\n";
        $debug_info .= '调试日志: ' . ($this->settings['enable_debug_logging'] ? '是' : '否') . "\n";
        $debug_info .= '已配置: ' . ($this->storage_manager->is_configured() ? '是' : '否') . "\n";
        $debug_info .= '</pre>';
        
        return $debug_info;
    }
    
    /**
     * 处理异步连接测试
     * 
     * 在后台异步执行连接测试，不影响前端体验
     *
     * @return void
     */
    public function handle_async_test_connection(): void {
        if (!$this->storage_manager->is_configured()) {
            $this->logger->warning('异步连接测试：R2未配置，跳过测试');
            return;
        }
        
        $this->logger->info('异步连接测试：开始执行');
        $result = $this->storage_manager->test_connection();
        
        if (is_wp_error($result)) {
            $this->logger->error('异步连接测试：失败', ['error' => $result->get_error_message()]);
        } else {
            $this->logger->info('异步连接测试：成功完成');
        }
    }
}