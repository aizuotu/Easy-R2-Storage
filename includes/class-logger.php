<?php
/**
 * 日志记录器类
 * 
 * 负责记录插件运行时的日志信息，用于调试和故障排除
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
 * 日志记录器类
 * 
 * 使用单例模式，提供统一的日志记录接口
 */
class Easy_R2_Logger {
    
    /**
     * 日志记录器单例实例
     * 
     * @var Easy_R2_Logger|null
     */
    private static ?Easy_R2_Logger $instance = null;
    
    /**
     * 是否启用调试日志
     * 
     * @var bool
     */
    private bool $debug_enabled = false;
    
    /**
     * 获取日志记录器单例实例
     * 
     * @return Easy_R2_Logger 日志记录器实例
     */
    public static function get_instance(): Easy_R2_Logger {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 私有构造函数 - 防止直接实例化
     */
    private function __construct() {
        // 从插件设置中读取调试状态
        $settings = get_option('easy_r2_storage_settings', []);
        $this->debug_enabled = isset($settings['enable_debug_logging']) && $settings['enable_debug_logging'];
    }
    
    /**
     * 记录调试信息
     * 
     * 仅在启用调试模式时记录
     * 
     * @param string $message 要记录的消息
     * @param array $context 上下文数据
     * @return void
     */
    public function debug(string $message, array $context = []): void {
        if (!$this->debug_enabled) {
            return;
        }
        
        $formatted_message = $this->format_message('DEBUG', $message, $context);
        $this->write_log($formatted_message);
    }
    
    /**
     * 记录信息消息
     * 
     * 用于记录重要的操作信息
     * 
     * @param string $message 要记录的消息
     * @param array $context 上下文数据
     * @return void
     */
    public function info(string $message, array $context = []): void {
        $formatted_message = $this->format_message('INFO', $message, $context);
        $this->write_log($formatted_message);
    }
    
    /**
     * 记录警告消息
     * 
     * 用于记录潜在的问题
     * 
     * @param string $message 要记录的消息
     * @param array $context 上下文数据
     * @return void
     */
    public function warning(string $message, array $context = []): void {
        $formatted_message = $this->format_message('WARNING', $message, $context);
        $this->write_log($formatted_message);
    }
    
    /**
     * 记录错误消息
     * 
     * 用于记录错误和异常
     * 
     * @param string $message 要记录的消息
     * @param array $context 上下文数据
     * @return void
     */
    public function error(string $message, array $context = []): void {
        $formatted_message = $this->format_message('ERROR', $message, $context);
        $this->write_log($formatted_message);
    }
    
    /**
     * 格式化日志消息
     * 
     * 添加时间戳、日志级别和上下文信息
     * 
     * @param string $level 日志级别
     * @param string $message 原始消息
     * @param array $context 上下文数据
     * @return string 格式化后的消息
     */
    private function format_message(string $level, string $message, array $context = []): string {
        $timestamp = current_time('Y-m-d H:i:s');
        $formatted = "[{$timestamp}] [Easy R2 Storage] [{$level}] {$message}";
        
        if (!empty($context)) {
            $formatted .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        return $formatted;
    }
    
    /**
     * 将日志写入WordPress错误日志
     * 
     * @param string $message 格式化后的消息
     * @return void
     */
    private function write_log(string $message): void {
        if (function_exists('error_log')) {
            error_log($message);
        }
    }
    
    /**
     * 更新调试状态
     * 
     * 当插件设置更新时调用
     * 
     * @param bool $enabled 是否启用调试
     * @return void
     */
    public function set_debug_enabled(bool $enabled): void {
        $this->debug_enabled = $enabled;
    }
    
    /**
     * 检查调试是否启用
     * 
     * @return bool
     */
    public function is_debug_enabled(): bool {
        return $this->debug_enabled;
    }
    
    /**
     * 记录文件上传操作
     * 
     * @param int $attachment_id 附件ID
     * @param string $file_path 文件路径
     * @param bool $success 是否成功
     * @return void
     */
    public function log_upload(int $attachment_id, string $file_path, bool $success): void {
        $message = sprintf(
            'Upload %s - Attachment ID: %d, File: %s',
            $success ? 'successful' : 'failed',
            $attachment_id,
            basename($file_path)
        );
        
        if ($success) {
            $this->info($message);
        } else {
            $this->error($message);
        }
    }
    
    /**
     * 记录文件删除操作
     * 
     * @param int $attachment_id 附件ID
     * @param string $file_key R2文件键
     * @param bool $success 是否成功
     * @return void
     */
    public function log_delete(int $attachment_id, string $file_key, bool $success): void {
        $message = sprintf(
            'Delete %s - Attachment ID: %d, R2 Key: %s',
            $success ? 'successful' : 'failed',
            $attachment_id,
            $file_key
        );
        
        if ($success) {
            $this->info($message);
        } else {
            $this->error($message);
        }
    }
    
    /**
     * 记录API请求
     * 
     * @param string $method HTTP方法
     * @param string $url 请求URL
     * @param int $response_code 响应状态码
     * @return void
     */
    public function log_api_request(string $method, string $url, int $response_code): void {
        $message = sprintf(
            'API Request - Method: %s, URL: %s, Response Code: %d',
            $method,
            $url,
            $response_code
        );
        
        $this->debug($message);
    }
    
    /**
     * 记录同步操作
     * 
     * @param int $processed 处理数量
     * @param int $successful 成功数量
     * @param int $failed 失败数量
     * @return void
     */
    public function log_sync(int $processed, int $successful, int $failed): void {
        $message = sprintf(
            'Sync completed - Processed: %d, Successful: %d, Failed: %d',
            $processed,
            $successful,
            $failed
        );
        
        $this->info($message);
    }
}