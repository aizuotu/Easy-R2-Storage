<?php
/**
 * 存储管理器类
 * 
 * 负责处理所有与Cloudflare R2存储的交互，包括：
 * - 文件上传到R2
 * - 从R2删除文件
 * - 生成AWS Signature V4签名
 * - 测试R2连接
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

// 需要先加载日志类
require_once EASY_R2_STORAGE_PATH . 'includes/class-logger.php';

/**
 * 存储管理器类
 * 
 * 管理所有R2存储操作
 */
class Easy_R2_Storage_Manager {
    
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
     * 构造函数
     * 
     * @param array $settings 插件设置
     */
    public function __construct(array $settings) {
        $this->settings = $settings;
        $this->logger = Easy_R2_Logger::get_instance();
    }
    
    /**
     * 检查R2是否已配置
     * 
     * 验证所有必需的配置项是否已填写
     * 
     * @return bool 是否已配置
     */
    public function is_configured(): bool {
        return !empty($this->settings['account_id'])
            && !empty($this->settings['access_key_id'])
            && !empty($this->settings['secret_access_key'])
            && !empty($this->settings['bucket_name']);
    }
    
    /**
     * 获取R2端点URL
     * 
     * @return string R2端点URL
     */
    public function get_endpoint(): string {
        if (empty($this->settings['account_id'])) {
            return '';
        }
        return sprintf('https://%s.r2.cloudflarestorage.com', $this->settings['account_id']);
    }
    
    /**
     * 测试R2连接
     * 
     * 发送HEAD请求验证R2配置是否正确（参考 yctvn 的实现，更轻量级）
     * 
     * @return true|\WP_Error 成功返回true，失败返回WP_Error对象
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', 'R2凭据未配置，请先填写账户ID、访问密钥等信息。');
        }
        
        $endpoint = $this->get_endpoint();
        $bucket = $this->settings['bucket_name'];
        $url = $endpoint . '/' . $bucket . '/';
        
        $this->logger->debug('开始R2连接测试', [
            'endpoint' => $endpoint,
            'bucket' => $bucket
        ]);
        
        // 使用HEAD请求测试连接（参考 yctvn 的实现，更轻量级）
        $response = wp_remote_head($url, [
            'headers' => $this->generate_auth_headers('HEAD', '/' . $bucket . '/', ''),
            'timeout' => 15,
            'sslverify' => true,
            'user-agent' => 'WordPress/Easy-R2-Storage/' . EASY_R2_STORAGE_VERSION
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->error('R2连接测试失败: ' . $response->get_error_message());
            
            // 如果是连接错误，提供更详细的网络建议
            if (strpos($response->get_error_message(), 'cURL error') !== false) {
                return new \WP_Error(
                    'network_error',
                    '网络连接失败: ' . $response->get_error_message() . 
                    '。请检查服务器网络连接和防火墙设置。'
                );
            }
            
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $this->logger->debug('R2连接测试 - 状态码: ' . $code);
        
        // 200 = 成功，403 = 没有列表权限但可以访问（也视为成功）
        if ($code === 200 || $code === 403) {
            $this->logger->info('R2连接测试成功');
            return true;
        }
        
        $error_body = wp_remote_retrieve_body($response);
        return new \WP_Error('connection_failed', 'HTTP ' . $code . ': ' . $error_body);
    }
    
    /**
     * 上传测试文件用于连接测试
     * 
     * @param string $key 对象键
     * @param string $content 文件内容
     * @return true|\WP_Error
     */
    private function upload_test_file(string $key, string $content) {
        $endpoint = $this->get_endpoint();
        $bucket = $this->settings['bucket_name'];
        $url = $endpoint . '/' . $bucket . '/' . $key;
        
        $request_path = '/' . $bucket . '/' . $key;
        $auth_headers = $this->generate_auth_headers('PUT', $request_path, $content, 'text/plain');
        
        $headers = array_merge($auth_headers, [
            'Content-Length' => strlen($content),
        ]);
        
        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'headers' => $headers,
            'body' => $content,
            'timeout' => 20,
            'sslverify' => false, // 某些环境下SSL验证可能有问题
            'user-agent' => 'WordPress/Easy-R2-Storage/' . EASY_R2_STORAGE_VERSION,
            'blocking' => true,
            'httpversion' => '1.1'
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $this->logger->debug('测试文件上传 - 状态码: ' . $code);
        
        if ($code === 200) {
            return true;
        }
        
        $error_body = wp_remote_retrieve_body($response);
        $error_message = 'HTTP ' . $code;
        
        // 尝试解析XML错误响应
        if (strpos($error_body, '<?xml') === 0) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($error_body);
            
            if ($xml !== false) {
                $error_code = (string) $xml->Code;
                $error_desc = (string) $xml->Message;
                
                if ($error_code === 'AccessDenied') {
                    $error_message = '访问被拒绝 - 请检查您的R2凭据是否有对象写入权限';
                } elseif ($error_code === 'NoSuchBucket') {
                    $error_message = '存储桶不存在 - 请检查存储桶名称是否正确';
                } else {
                    $error_message = $error_code . ': ' . $error_desc;
                }
            } else {
                $error_message .= ': ' . substr($error_body, 0, 200);
            }
            
            libxml_clear_errors();
        } else {
            $error_message .= ': ' . substr($error_body, 0, 200);
        }
        
        return new \WP_Error('upload_test_failed', $error_message);
    }
    
    /**
     * 删除测试文件
     * 
     * @param string $key 对象键
     * @return true|\WP_Error
     */
    private function delete_test_file(string $key) {
        $endpoint = $this->get_endpoint();
        $bucket = $this->settings['bucket_name'];
        $url = $endpoint . '/' . $bucket . '/' . $key;
        
        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'headers' => $this->generate_auth_headers('DELETE', '/' . $bucket . '/' . $key, ''),
            'timeout' => 10,
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        // 204表示删除成功，404表示文件已不存在
        if ($code === 204 || $code === 404) {
            return true;
        }
        
        return new \WP_Error('delete_test_failed', '删除测试文件失败，HTTP状态码: ' . $code);
    }
    
    /**
     * 上传文件到R2
     * 
     * @param int $attachment_id 附件ID
     * @param string $file_path 本地文件路径
     * @param string $size 图片尺寸名称（full, thumbnail等）
     * @return array|\WP_Error 成功返回包含key、url、size的数组，失败返回WP_Error对象
     */
    public function upload_file(int $attachment_id, string $file_path, string $size = 'full') {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', 'R2凭据未配置。请检查账户ID、访问密钥、秘密密钥和存储桶名称是否已正确填写。');
        }
        
        if (!file_exists($file_path)) {
            return new \WP_Error('file_not_found', '文件未找到: ' . $file_path);
        }
        
        // 检查文件大小
        $file_size = filesize($file_path);
        if ($file_size === false || $file_size === 0) {
            return new \WP_Error('file_empty', '文件为空或无法读取文件大小: ' . $file_path);
        }
        
        $filename = basename($file_path);
        $object_key = $this->generate_object_key($attachment_id, $filename, $size);
        
        $endpoint = $this->get_endpoint();
        $bucket = $this->settings['bucket_name'];
        $url = $endpoint . '/' . $bucket . '/' . $object_key;
        
        // 读取文件内容
        $file_content = file_get_contents($file_path);
        if ($file_content === false) {
            return new \WP_Error('file_read_error', '无法读取文件内容: ' . $file_path);
        }
        
        // 获取MIME类型
        $mime_type = wp_check_filetype($filename)['type'] ?: 'application/octet-stream';
        
        $request_path = '/' . $bucket . '/' . $object_key;
        
        // 生成认证头
        $auth_headers = $this->generate_auth_headers('PUT', $request_path, $file_content, $mime_type);
        
        // 合并请求头（auth_headers已经包含Content-Type，不要重复添加）
        $headers = array_merge($auth_headers, [
            'Content-Length' => strlen($file_content)
        ]);
        
        $this->logger->debug('准备上传文件到R2', [
            'attachment_id' => $attachment_id,
            'file_path' => $file_path,
            'file_size' => $file_size,
            'object_key' => $object_key,
            'url' => $url
        ]);
        
        // 发送PUT请求上传文件 - 增加重试机制
        $max_retries = 2;
        $retry_count = 0;
        
        while ($retry_count <= $max_retries) {
            $response = wp_remote_request($url, [
                'method' => 'PUT',
                'headers' => $headers,
                'body' => $file_content,
                'timeout' => 45, // 增加超时时间
                'sslverify' => false, // 禁用SSL验证以避免证书问题
                'user-agent' => 'WordPress/Easy-R2-Storage/' . EASY_R2_STORAGE_VERSION,
                'blocking' => true,
                'httpversion' => '1.1',
                'redirection' => 0 // 禁用重定向以避免问题
            ]);
            
            if (is_wp_error($response)) {
                $retry_count++;
                $this->logger->warning('文件上传失败，重试 ' . $retry_count . '/' . $max_retries, [
                    'error' => $response->get_error_message(),
                    'attachment_id' => $attachment_id
                ]);
                
                if ($retry_count > $max_retries) {
                    $this->logger->error('文件上传最终失败: ' . $response->get_error_message());
                    $this->logger->log_upload($attachment_id, $file_path, false);
                    return $response;
                }
                
                // 等待一段时间后重试
                sleep(1);
                continue;
            }
            
            $code = wp_remote_retrieve_response_code($response);
            $this->logger->debug('文件上传响应 - 状态码: ' . $code);
            
            if ($code === 200) {
                $public_url = $this->get_public_url($object_key);
                
                $this->logger->info('文件上传成功', [
                    'attachment_id' => $attachment_id,
                    'url' => $public_url,
                    'size' => $file_size,
                    'object_key' => $object_key
                ]);
                $this->logger->log_upload($attachment_id, $file_path, true);
                
                return [
                    'key' => $object_key,
                    'url' => $public_url,
                    'size' => $file_size
                ];
            }
            
            // 处理错误响应
            $error_body = wp_remote_retrieve_body($response);
            $error_message = 'HTTP ' . $code;
            
            // 尝试解析XML错误响应
            if (strpos($error_body, '<?xml') === 0) {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($error_body);
                
                if ($xml !== false) {
                    $error_code = (string) $xml->Code;
                    $error_desc = (string) $xml->Message;
                    
                    if ($error_code === 'AccessDenied') {
                        $error_message = '访问被拒绝 - 请检查您的R2凭据是否有对象写入权限';
                    } elseif ($error_code === 'NoSuchBucket') {
                        $error_message = '存储桶不存在 - 请检查存储桶名称: ' . $bucket;
                    } elseif ($error_code === 'SignatureDoesNotMatch') {
                        $error_message = '签名不匹配 - 请检查访问密钥和秘密密钥是否正确';
                    } else {
                        $error_message = $error_code . ': ' . $error_desc;
                    }
                } else {
                    $error_message .= ': ' . substr($error_body, 0, 200);
                }
                
                libxml_clear_errors();
            } else {
                $error_message .= ': ' . substr($error_body, 0, 200);
            }
            
            $retry_count++;
            if ($retry_count <= $max_retries) {
                $this->logger->warning('文件上传失败，重试 ' . $retry_count . '/' . $max_retries, [
                    'error' => $error_message,
                    'attachment_id' => $attachment_id
                ]);
                sleep(1);
                continue;
            }
            
            break;
        }
        
        $this->logger->error('文件上传最终失败', [
            'error' => $error_message,
            'attachment_id' => $attachment_id,
            'file_path' => $file_path,
            'object_key' => $object_key
        ]);
        $this->logger->log_upload($attachment_id, $file_path, false);
        
        return new \WP_Error('upload_failed', $error_message);
    }
    
    /**
     * 上传所有图片尺寸
     *
     * @param int $attachment_id 附件ID
     * @param array $files_to_upload 要上传的文件数组（尺寸 => 文件路径）
     * @return bool 是否全部上传成功
     */
    public function upload_all_sizes(int $attachment_id, array $files_to_upload): bool {
        // 检查上传模式设置
        $upload_mode = $this->settings['upload_mode'] ?? 'full_only';
        
        $this->logger->debug('开始上传附件', [
            'attachment_id' => $attachment_id,
            'upload_mode' => $upload_mode,
            'files_count' => count($files_to_upload)
        ]);
        
        // 快速模式只上传主文件
        if ($upload_mode === 'full_only') {
            $files_to_upload = ['full' => $files_to_upload['full']];
            $this->logger->debug('快速模式：只上传主文件');
        }
        
        // 验证主文件是否存在
        if (!isset($files_to_upload['full']) || !file_exists($files_to_upload['full'])) {
            $this->logger->error('主文件不存在', [
                'attachment_id' => $attachment_id,
                'file_path' => $files_to_upload['full'] ?? 'not set'
            ]);
            return false;
        }
        
        $uploaded_files = [];
        $upload_success = true;
        $failed_sizes = [];
        
        // 上传每个文件
        foreach ($files_to_upload as $size => $file_path) {
            $this->logger->debug('准备上传文件', [
                'size' => $size,
                'file_path' => $file_path,
                'file_exists' => file_exists($file_path),
                'file_size' => file_exists($file_path) ? filesize($file_path) : 0
            ]);
            
            $result = $this->upload_file($attachment_id, $file_path, $size);
            
            if (!is_wp_error($result)) {
                // 保存R2 URL和键到附件元数据
                // 如果配置了自定义域名，使用自定义域名
                $saved_url = $this->get_public_url($result['key']);
                
                if ($size === 'full') {
                    update_post_meta($attachment_id, '_r2_url', $saved_url);
                    update_post_meta($attachment_id, '_r2_key', $result['key']);
                    $this->logger->info('主文件上传成功', [
                        'attachment_id' => $attachment_id,
                        'url' => $saved_url
                    ]);
                } else {
                    update_post_meta($attachment_id, '_r2_url_' . $size, $saved_url);
                    update_post_meta($attachment_id, '_r2_key_' . $size, $result['key']);
                    $this->logger->debug('缩略图上传成功', [
                        'attachment_id' => $attachment_id,
                        'size' => $size,
                        'url' => $saved_url
                    ]);
                }
                
                $uploaded_files[$size] = [
                    'url' => $result['url'],
                    'key' => $result['key']
                ];
            } else {
                $error_message = $result->get_error_message();
                $this->logger->error('文件上传失败', [
                    'attachment_id' => $attachment_id,
                    'size' => $size,
                    'error' => $error_message
                ]);
                
                $failed_sizes[] = $size;
                
                // 主文件上传失败则标记失败
                if ($size === 'full') {
                    $upload_success = false;
                }
                // 缩略图失败不影响整体
            }
        }
        
        // 保存上传的文件信息
        if (!empty($uploaded_files)) {
            update_post_meta($attachment_id, '_r2_uploaded_files', $uploaded_files);
        }
        
        // 记录上传结果
        $this->logger->info('附件上传完成', [
            'attachment_id' => $attachment_id,
            'total_files' => count($files_to_upload),
            'successful' => count($uploaded_files),
            'failed' => count($failed_sizes),
            'failed_sizes' => $failed_sizes,
            'overall_success' => $upload_success
        ]);
        
        // 如果启用了删除本地文件，删除已上传的本地文件
        if ($upload_success && !empty($this->settings['delete_local_files'])) {
            $this->logger->info('开始删除本地文件', [
                'attachment_id' => $attachment_id,
                'files_to_delete' => count($uploaded_files)
            ]);
            
            foreach ($uploaded_files as $size => $data) {
                $file = $files_to_upload[$size] ?? null;
                if ($file && file_exists($file)) {
                    if (wp_delete_file($file)) {
                        $this->logger->debug('本地文件已删除', [
                            'size' => $size,
                            'file_path' => $file
                        ]);
                    }
                }
            }
            update_post_meta($attachment_id, '_r2_local_deleted', true);
        }
        
        return $upload_success;
    }
    
    /**
     * 从R2删除文件
     * 
     * @param string $object_key R2对象键
     * @return true|\WP_Error 成功返回true，失败返回WP_Error对象
     */
    public function delete_file(string $object_key) {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', 'R2未配置');
        }
        
        $endpoint = $this->get_endpoint();
        $bucket = $this->settings['bucket_name'];
        $url = $endpoint . '/' . $bucket . '/' . $object_key;
        
        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'headers' => $this->generate_auth_headers('DELETE', '/' . $bucket . '/' . $object_key, ''),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        // 204表示删除成功，404表示文件已不存在
        if ($code === 204 || $code === 404) {
            return true;
        }
        
        return new \WP_Error('delete_failed', '删除失败，HTTP状态码: ' . $code);
    }
    
    /**
     * 生成R2对象键
     *
     * 根据附件ID和文件名生成在R2中的存储路径
     * 使用WordPress原有的目录结构：年/月/文件名
     *
     * @param int $attachment_id 附件ID
     * @param string $filename 文件名
     * @param string $size 图片尺寸（full, thumbnail等）
     * @return string R2对象键
     */
    private function generate_object_key(int $attachment_id, string $filename, string $size = 'full'): string {
        // 获取附件上传时间（更可靠的方法）
        $post_time = get_post_time('Y/m', false, $attachment_id, true);
        
        // 如果无法获取时间，使用当前时间
        if (!$post_time) {
            $post_time = current_time('Y/m');
        }
        
        // 清理文件名
        $safe_filename = sanitize_file_name($filename);
        
        // 如果不是完整尺寸且文件名包含尺寸后缀，保持原有文件名
        if ($size !== 'full' && strpos($filename, '-') !== false) {
            // 检查是否已经有尺寸后缀（如 image-150x150.jpg）
            // 如果有，直接使用，不添加额外的前缀
            $safe_filename = $filename;
        }
        
        // 保持WordPress原有的路径结构：年/月/文件名
        $object_key = $post_time . '/' . $safe_filename;
        
        // 调试日志
        if ($this->settings['enable_debug_logging'] ?? false) {
            $this->logger->debug('生成R2对象键', [
                'attachment_id' => $attachment_id,
                'size' => $size,
                'filename' => $filename,
                'object_key' => $object_key
            ]);
        }
        
        return $object_key;
    }
    
    /**
     * 获取R2对象的公共URL
     * 
     * 如果配置了自定义域名则使用自定义域名，否则使用R2默认URL
     * 
     * @param string $object_key R2对象键
     * @return string 公共URL
     */
    public function get_public_url(string $object_key): string {
        if (!empty($this->settings['public_url'])) {
            return rtrim($this->settings['public_url'], '/') . '/' . $object_key;
        }
        
        return $this->get_endpoint() . '/' . $this->settings['bucket_name'] . '/' . $object_key;
    }
    
    /**
     * 生成AWS Signature V4认证头
     * 
     * 这是与R2 API通信所必需的签名认证
     * 
     * @param string $method HTTP方法（GET, PUT, DELETE等）
     * @param string $path 请求路径
     * @param string $body 请求体
     * @param string $content_type 内容类型
     * @return array 认证头数组
     */
    private function generate_auth_headers(string $method, string $path, string $body = '', string $content_type = ''): array {
        $service = 's3';
        $region = 'auto'; // R2使用'auto'区域
        $access_key = $this->settings['access_key_id'];
        $secret_key = $this->settings['secret_access_key'];
        $host = wp_parse_url($this->get_endpoint(), PHP_URL_HOST);
        
        // 处理路径编码（最终解决方案）
        $canonical_uri = $path;
        if (substr($canonical_uri, 0, 1) !== '/') {
            $canonical_uri = '/' . $canonical_uri;
        }
        // 对路径中的每个部分单独编码（除了分隔符）
        $path_parts = explode('/', ltrim($canonical_uri, '/'));
        $encoded_parts = [];
        foreach ($path_parts as $part) {
            $encoded_parts[] = str_replace('%2F', '/', rawurlencode($part));
        }
        $canonical_uri = '/' . implode('/', $encoded_parts);
        
        // 生成时间戳
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        // 计算请求体的SHA256哈希
        $payload_hash = hash('sha256', $body);
        
        // 构建请求头数组
        $req_headers = [
            'Host' => $host,
            'X-Amz-Date' => $timestamp,
            'X-Amz-Content-Sha256' => $payload_hash,
        ];
        
        // 为PUT请求添加Content-Type
        if ($method === 'PUT' && !empty($content_type)) {
            $req_headers['Content-Type'] = $content_type;
        }
        
        // 创建规范头部
        $canonical_headers = '';
        $signed_headers_arr = [];
        
        // 按小写键排序（AWS规范要求）
        ksort($req_headers, SORT_STRING | SORT_FLAG_CASE);
        
        foreach ($req_headers as $key => $value) {
            $lower_key = strtolower($key);
            $signed_headers_arr[] = $lower_key;
            $canonical_headers .= $lower_key . ':' . trim($value) . "\n";
        }
        
        $signed_headers = implode(';', $signed_headers_arr);
        
        // 构建规范请求（增强调试）
        $canonical_request = implode("\n", [
            $method,
            $canonical_uri,
            '', // 查询字符串
            $canonical_headers,
            $signed_headers,
            $payload_hash
        ]);
        // 计算签名密钥（分步HMAC）- 严格遵循AWS签名算法
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret_key, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $signing_key = hash_hmac('sha256', 'aws4_request', $kService, true);
        
        // 创建待签名字符串（参考yctvn样例，使用$region而非硬编码的us-east-1）
        $credential_scope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $credential_scope,
            hash('sha256', $canonical_request)
        ]);

        // 计算签名
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        
        // 构建授权头
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // 调试日志（合并为一个）
        if ($this->settings['enable_debug_logging'] ?? false) {
            $this->logger->debug('签名计算详情:', [
                'method' => $method,
                'original_path' => $path,
                'encoded_path' => $canonical_uri,
                'canonical_request' => $canonical_request,
                'signed_headers' => $signed_headers,
                'payload_hash' => $payload_hash,
                'credential_scope' => $credential_scope,
                'string_to_sign' => $string_to_sign,
                'signature' => $signature,
                'authorization_header' => $authorization,
                'timestamp' => $timestamp,
                'date' => $date,
                'host' => $host,
                'access_key' => $access_key,
                'secret_key_prefix' => substr($secret_key, 0, 4) . '...',
                'content_type' => $content_type,
                'request_path' => $path,
                'encoded_path_parts' => $encoded_parts,
                'canonical_headers' => $canonical_headers,
                'kDate' => bin2hex($kDate),
                'kRegion' => bin2hex($kRegion),
                'kService' => bin2hex($kService),
                'signing_key' => bin2hex($signing_key),
                'string_to_sign_hash' => hash('sha256', $string_to_sign)
            ]);
        }
        
        $req_headers['Authorization'] = $authorization;
        
        return $req_headers;
    }
}