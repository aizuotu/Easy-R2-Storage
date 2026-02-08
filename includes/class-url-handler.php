<?php
/**
 * URL处理类
 * 
 * 负责处理所有URL相关的操作，包括：
 * - 将本地URL重写为R2 URL
 * - 处理响应式图片的srcset
 * - 修复媒体库中的缩略图显示
 * - 替换文章内容中的图片URL
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
 * URL处理类
 * 
 * 管理所有URL重写和转换操作
 */
class Easy_R2_URL_Handler {
    
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
        
        // 初始化URL重写钩子
        $this->init_rewrite_hooks();
    }
    
    /**
     * 初始化URL重写钩子
     * 
     * 注册所有WordPress过滤器来拦截和重写URL
     */
    private function init_rewrite_hooks(): void {
        // 重写附件URL
        add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 10, 2);
        
        // 重写响应式图片的srcset
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_srcset'], 10, 5);
        
        // 重写图片源数组
        add_filter('wp_get_attachment_image_src', [$this, 'rewrite_image_src'], 10, 3);
        
        // 重写图片属性
        add_filter('wp_get_attachment_image_attributes', [$this, 'rewrite_image_attributes'], 10, 3);
        
        // 重写文章内容中的图片
        add_filter('the_content', [$this, 'rewrite_content_images'], 10);
        
        // 修复媒体库中缺失的缩略图
        add_filter('wp_prepare_attachment_for_js', [$this, 'fix_media_library_thumbnails'], 10, 3);
        add_filter('wp_get_attachment_image_src', [$this, 'fix_missing_thumbnail_src'], 999, 3);
    }
    
    /**
     * 重写附件URL为R2 URL
     * 
     * 支持图片、音频、视频等所有类型的附件
     * 
     * @param string $url 原始URL
     * @param int $attachment_id 附件ID
     * @return string 修改后的URL
     */
    public function rewrite_attachment_url(string $url, int $attachment_id): string {
        // 检查是否启用了URL重写
        if (empty($this->settings['enable_url_rewrite'])) {
            return $url;
        }
        
        // 首先尝试获取主R2 URL
        $r2_url = get_post_meta($attachment_id, '_r2_url', true);
        
        if (!empty($r2_url)) {
            // 检查是否是缩略图（仅图片）
            $mime_type = get_post_mime_type($attachment_id);
            
            if (strpos($mime_type, 'image/') === 0) {
                // 图片：处理缩略图
                $is_thumbnail = preg_match('/-([0-9]+x[0-9]+)(\.[a-z]+)$/i', $url, $matches);
                
                if ($is_thumbnail) {
                    $size_suffix = $matches[1];
                    // 将R2原始URL替换为缩略图版本
                    $r2_thumbnail_url = preg_replace(
                        '/(\.[a-z]+)$/i', 
                        '-' . $size_suffix . '$1', 
                        $r2_url
                    );
                    
                    if ($this->settings['enable_debug_logging'] ?? false) {
                        $this->logger->debug(
                            "URL重写(缩略图) - 附件 {$attachment_id}: 原始={$url}, R2={$r2_thumbnail_url}"
                        );
                    }
                    
                    return $r2_thumbnail_url;
                }
            }
            
            // 非图片或原始文件，直接返回R2 URL
            if ($this->settings['enable_debug_logging'] ?? false) {
                $this->logger->debug(
                    "URL重写 - 附件 {$attachment_id} ({$mime_type}): 原始={$url}, R2={$r2_url}"
                );
            }
            
            return $r2_url;
        }
        
        return $url;
    }
    
    /**
     * 获取附件的R2 URL
     * 
     * 支持所有类型的附件（图片、音频、视频、文档等）
     * 
     * @param int $attachment_id 附件ID
     * @return string|null R2 URL或null
     */
    public function get_attachment_r2_url(int $attachment_id): ?string {
        return get_post_meta($attachment_id, '_r2_url', true);
    }
    
    /**
     * 检查附件是否已同步到R2
     * 
     * @param int $attachment_id 附件ID
     * @return bool 是否已同步
     */
    public function is_attachment_synced(int $attachment_id): bool {
        return !empty(get_post_meta($attachment_id, '_r2_url', true));
    }
    
    /**
     * 重写响应式图片的srcset
     *
     * @param array|false $sources 图片源数组（可能为false）
     * @param array $size_array 图片尺寸数组
     * @param string $image_src 图片源URL
     * @param array $image_meta 图片元数据
     * @param int $attachment_id 附件ID
     * @return array|false 修改后的源数组
     */
    public function rewrite_srcset($sources, array $size_array, string $image_src, array $image_meta, int $attachment_id) {
        // 如果sources为false或未启用URL重写，直接返回
        if (empty($this->settings['enable_url_rewrite']) || $sources === false) {
            return $sources;
        }
        
        // 确保sources是数组
        if (!is_array($sources)) {
            return $sources;
        }
        
        $r2_base_url = get_post_meta($attachment_id, '_r2_url', true);
        
        if (empty($r2_base_url)) {
            return $sources;
        }
        
        // 获取不带文件扩展名的URL
        $r2_base = preg_replace('/(\.[a-z]+)$/i', '', $r2_base_url);
        $extension = preg_match('/(\.[a-z]+)$/i', $r2_base_url, $matches) ? $matches[1] : '';
        
        foreach ($sources as $width => &$source) {
            // 从描述符中提取尺寸（例如：image-300x200.jpg）
            if (preg_match('/-([0-9]+x[0-9]+)\.[a-z]+$/i', $source['url'], $matches)) {
                $size_suffix = $matches[1];
                $source['url'] = $r2_base . '-' . $size_suffix . $extension;
            } else {
                // 完整尺寸图片
                $source['url'] = $r2_base_url;
            }
        }
        
        return $sources;
    }
    
    /**
     * 重写图片源数组
     * 
     * @param array|false $image 图片数据数组或false
     * @param int $attachment_id 附件ID
     * @param string|int $size 图片尺寸
     * @return array|false 修改后的图片数据
     */
    public function rewrite_image_src($image, int $attachment_id, $size) {
        if (empty($this->settings['enable_url_rewrite']) || empty($image)) {
            return $image;
        }
        
        $r2_url = $this->rewrite_attachment_url($image[0], $attachment_id);
        
        if ($r2_url !== $image[0]) {
            $image[0] = $r2_url;
        }
        
        return $image;
    }
    
    /**
     * 重写图片属性
     * 
     * @param array $attr 图片属性
     * @param \WP_Post $attachment 附件对象
     * @param string|int $size 图片尺寸
     * @return array 修改后的属性
     */
    public function rewrite_image_attributes(array $attr, \WP_Post $attachment, $size): array {
        if (empty($this->settings['enable_url_rewrite'])) {
            return $attr;
        }
        
        // 重写src
        if (isset($attr['src'])) {
            $attr['src'] = $this->rewrite_attachment_url($attr['src'], $attachment->ID);
        }
        
        // 重写srcset
        if (isset($attr['srcset'])) {
            $r2_base_url = get_post_meta($attachment->ID, '_r2_url', true);
            
            if (!empty($r2_base_url)) {
                $srcset_parts = explode(',', $attr['srcset']);
                $new_srcset = [];
                
                foreach ($srcset_parts as $part) {
                    $part = trim($part);
                    if (preg_match('/^(.+?)\s+(\d+w|\d+x)$/', $part, $matches)) {
                        $url = $matches[1];
                        $descriptor = $matches[2];
                        
                        // 替换为R2 URL
                        $new_url = $this->rewrite_attachment_url($url, $attachment->ID);
                        $new_srcset[] = $new_url . ' ' . $descriptor;
                    }
                }
                
                $attr['srcset'] = implode(', ', $new_srcset);
            }
        }
        
        return $attr;
    }
    
    /**
     * 重写文章内容中的图片
     * 
     * @param string $content 文章内容
     * @return string 修改后的内容
     */
    public function rewrite_content_images(string $content): string {
        if (empty($this->settings['enable_url_rewrite'])) {
            return $content;
        }
        
        // 获取上传目录信息
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        
        // 查找内容中的所有图片
        if (preg_match_all('/<img[^>]+>/i', $content, $matches)) {
            foreach ($matches[0] as $img_tag) {
                // 提取src
                if (preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_match)) {
                    $src = $src_match[1];
                    
                    // 检查是否是本地上传
                    if (strpos($src, $upload_url) !== false) {
                        // 尝试从URL获取附件ID
                        $attachment_id = $this->get_attachment_id_from_url($src);
                        
                        if ($attachment_id) {
                            $new_src = $this->rewrite_attachment_url($src, $attachment_id);
                            
                            if ($new_src !== $src) {
                                $new_img_tag = str_replace($src, $new_src, $img_tag);
                                
                                // 同时更新srcset（如果存在）
                                if (preg_match('/srcset=["\']([^"\']+)["\']/i', $new_img_tag, $srcset_match)) {
                                    $srcset = $srcset_match[1];
                                    $new_srcset = $this->rewrite_srcset_string($srcset, $attachment_id);
                                    $new_img_tag = str_replace($srcset, $new_srcset, $new_img_tag);
                                }
                                
                                $content = str_replace($img_tag, $new_img_tag, $content);
                            }
                        }
                    }
                }
            }
        }
        
        return $content;
    }
    
    /**
     * 从URL获取附件ID
     * 
     * @param string $url 媒体URL
     * @return int|null 附件ID或null
     */
    private function get_attachment_id_from_url(string $url): ?int {
        global $wpdb;
        
        // 从URL中移除尺寸后缀
        $url = preg_replace('/-\d+x\d+(?=\.[a-z]+$)/i', '', $url);
        
        // 移除域名以获取路径
        $upload_dir = wp_upload_dir();
        $path = str_replace($upload_dir['baseurl'] . '/', '', $url);
        
        // 查询数据库
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
            $path
        ));
        
        return $attachment_id ? (int) $attachment_id : null;
    }
    
    /**
     * 重写srcset字符串
     * 
     * @param string $srcset srcset字符串
     * @param int $attachment_id 附件ID
     * @return string 修改后的srcset
     */
    private function rewrite_srcset_string(string $srcset, int $attachment_id): string {
        $r2_base_url = get_post_meta($attachment_id, '_r2_url', true);
        
        if (empty($r2_base_url)) {
            return $srcset;
        }
        
        $parts = explode(',', $srcset);
        $new_parts = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^(.+?)\s+(\d+w)$/', $part, $matches)) {
                $url = $matches[1];
                $descriptor = $matches[2];
                $new_url = $this->rewrite_attachment_url($url, $attachment_id);
                $new_parts[] = $new_url . ' ' . $descriptor;
            }
        }
        
        return implode(', ', $new_parts);
    }
    
    /**
     * 修复缺失缩略图的src
     * 
     * 当本地文件被删除时，使用R2 URL作为后备
     * 
     * @param array|false $image 图片数据
     * @param int $attachment_id 附件ID
     * @param string|int $size 图片尺寸
     * @return array|false 修改后的图片数据
     */
    public function fix_missing_thumbnail_src($image, int $attachment_id, $size) {
        // 如果图片已找到或URL重写被禁用，则原样返回
        if (!empty($image) || empty($this->settings['enable_url_rewrite'])) {
            return $image;
        }
        
        // 检查是否有此尺寸的R2 URL
        if ($size === 'full') {
            $r2_url = get_post_meta($attachment_id, '_r2_url', true);
        } else {
            // 尝试获取特定尺寸的URL
            $r2_url = get_post_meta($attachment_id, '_r2_url_' . $size, true);
            
            // 如果未找到，尝试从主URL构造
            if (empty($r2_url)) {
                $r2_base_url = get_post_meta($attachment_id, '_r2_url', true);
                if (!empty($r2_base_url)) {
                    // 获取图片元数据
                    $metadata = wp_get_attachment_metadata($attachment_id);
                    if (isset($metadata['sizes'][$size])) {
                        // 将文件名替换为调整大小的版本
                        $sized_file = $metadata['sizes'][$size]['file'];
                        $r2_url = preg_replace('/[^\/]+$/', $sized_file, $r2_base_url);
                    }
                }
            }
        }
        
        if (!empty($r2_url)) {
            // 从元数据获取尺寸
            $metadata = wp_get_attachment_metadata($attachment_id);
            $width = 0;
            $height = 0;
            
            if ($size === 'full') {
                $width = $metadata['width'] ?? 0;
                $height = $metadata['height'] ?? 0;
            } elseif (isset($metadata['sizes'][$size])) {
                $width = $metadata['sizes'][$size]['width'];
                $height = $metadata['sizes'][$size]['height'];
            }
            
            return [$r2_url, $width, $height, true];
        }
        
        return $image;
    }
    
    /**
     * 修复媒体库中的缩略图
     * 
     * @param array $response 附件数据
     * @param \WP_Post $attachment 附件对象
     * @param array $meta 附件元数据
     * @return array 修改后的响应
     */
    public function fix_media_library_thumbnails(array $response, \WP_Post $attachment, array $meta): array {
        if (empty($this->settings['enable_url_rewrite'])) {
            return $response;
        }
        
        $attachment_id = $attachment->ID;
        
        // 检查本地文件是否存在
        $file_path = get_attached_file($attachment_id);
        $local_missing = !file_exists($file_path);
        
        // 获取R2 URL
        $r2_url = get_post_meta($attachment_id, '_r2_url', true);
        
        if (!empty($r2_url)) {
            // 如果本地文件缺失，修复主URL
            if ($local_missing) {
                $response['url'] = $r2_url;
            }
            
            // 修复尺寸
            if (isset($response['sizes'])) {
                foreach ($response['sizes'] as $size => &$size_data) {
                    // 检查本地缩略图是否存在
                    $thumb_path = str_replace(basename($file_path), $size_data['url'], $file_path);
                    
                    if ($local_missing || !file_exists($thumb_path)) {
                        // 尝试获取此尺寸的R2 URL
                        $size_r2_url = get_post_meta($attachment_id, '_r2_url_' . $size, true);
                        
                        if (empty($size_r2_url) && !empty($r2_url)) {
                            // 从R2基础URL构造URL
                            $sized_filename = basename($size_data['url']);
                            $size_r2_url = preg_replace('/[^\/]+$/', $sized_filename, $r2_url);
                        }
                        
                        if (!empty($size_r2_url)) {
                            $size_data['url'] = $size_r2_url;
                        }
                    }
                }
            }
            
            // 添加同步状态指示器
            $response['r2_synced'] = true;
            $response['local_deleted'] = get_post_meta($attachment_id, '_r2_local_deleted', true) ? true : false;
        }
        
        return $response;
    }
    
    /**
     * 更新文章中引用该附件的所有URL
     * 
     * 在附件同步到R2后，自动更新文章中的媒体链接
     * 支持：图片(img src)、音频(audio src)、视频(video src)、a标签href等
     * 
     * @param int $attachment_id 附件ID
     * @return int 更新的文章数量
     */
    public function update_post_content_urls(int $attachment_id): int {
        if (empty($this->settings['enable_url_rewrite'])) {
            return 0;
        }
        
        $r2_url = get_post_meta($attachment_id, '_r2_url', true);
        if (empty($r2_url)) {
            return 0;
        }
        
        // 获取附件信息
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return 0;
        }
        
        // 获取原始文件名
        $file_path = get_attached_file($attachment_id);
        $filename = basename($file_path);
        
        // 获取上传目录基础URL
        $upload_dir = wp_upload_dir();
        $local_base_url = $upload_dir['baseurl'];
        
        // 构建本地URL模式
        $local_url_pattern = $local_base_url . '/' . dirname($file_path);
        
        // 查找引用该附件的所有文章
        $posts = get_posts([
            'post_type' => ['post', 'page', 'custom-post-type'],
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            's' => $filename, // 简单搜索文件名
        ]);
        
        $updated_count = 0;
        
        foreach ($posts as $post) {
            $content = $post->post_content;
            $original_content = $content;
            
            // 匹配各种媒体URL格式
            $patterns = [
                // 图片：<img src="URL" ...>
                '/<img[^>]+src=["\']' . preg_quote($local_base_url, '/') . '[^"\']+' . preg_quote($filename, '/') . '[^"\']*["\'][^>]*>/i',
                // 链接：<a href="URL">...</a>
                '/<a[^>]+href=["\']' . preg_quote($local_base_url, '/') . '[^"\']+' . preg_quote($filename, '/') . '["\'][^>]*>.*?<\/a>/i',
                // 音频：<audio src="URL">
                '/<audio[^>]+src=["\']' . preg_quote($local_base_url, '/') . '[^"\']+' . preg_quote($filename, '/') . '["\'][^>]*>/i',
                // 视频：<video src="URL">
                '/<video[^>]+src=["\']' . preg_quote($local_base_url, '/') . '[^"\']+' . preg_quote($filename, '/') . '["\'][^>]*>/i',
                // source标签（在audio/video内）
                '/<source[^>]+src=["\']' . preg_quote($local_base_url, '/') . '[^"\']+' . preg_quote($filename, '/') . '["\'][^>]*>/i',
            ];
            
            $new_content = $content;
            
            foreach ($patterns as $pattern) {
                $new_content = preg_replace($pattern, function($matches) use ($local_base_url, $filename, $r2_url) {
                    // 提取URL部分并替换
                    return preg_replace(
                        '/' . preg_quote($local_base_url, '/') . '[^"\']+' . preg_quote($filename, '/') . '[^"\']*/i',
                        $r2_url,
                        $matches[0]
                    );
                }, $new_content);
            }
            
            // 如果内容有更新
            if ($new_content !== $content) {
                wp_update_post([
                    'ID' => $post->ID,
                    'post_content' => $new_content,
                ]);
                
                $updated_count++;
                
                if ($this->settings['enable_debug_logging'] ?? false) {
                    $this->logger->debug("文章内容URL已更新: ID={$post->ID}, 附件={$filename}");
                }
            }
        }
        
        if ($updated_count > 0 && ($this->settings['enable_debug_logging'] ?? false)) {
            $this->logger->info("批量更新文章URL完成: 附件={$filename}, 更新文章数={$updated_count}");
        }
        
        return $updated_count;
    }
    
    /**
     * 查找引用指定媒体文件的所有文章
     * 
     * @param string $filename 文件名
     * @return array 文章ID列表
     */
    public function find_posts_with_media(string $filename): array {
        global $wpdb;
        
        // 在文章内容中搜索
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type IN ('post', 'page')
            AND post_status IN ('publish', 'draft', 'private')
            AND post_content LIKE %s",
            '%' . $wpdb->esc_like($filename) . '%'
        ));
        
        return array_map('intval', $post_ids);
    }
}