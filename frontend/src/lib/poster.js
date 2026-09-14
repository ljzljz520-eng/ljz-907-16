// 海报 URL 解析 —— 前台卡片与详情页必须共用这一处逻辑，保证两边同一张图源。
import { API_BASE_URL } from './api';

// 已知在浏览器中直接引用会 403、需要后端代理的图片域名
const PROXY_HOSTS = ['playwoool.com'];

/**
 * 根据 movies.poster_url（唯一图源字段）解析出最终 <img src>。
 *
 * 规则：
 * - 空值 -> null（前端显示"无海报"占位）
 * - /storage/... 根相对路径（后台本地上传）-> 拼后端地址
 * - http(s) 外链 -> 命中代理名单时走 /api/proxy-image，否则直接使用
 */
export function resolvePosterUrl(posterUrl) {
  if (!posterUrl || typeof posterUrl !== 'string') return null;

  const url = posterUrl.trim();
  if (!url) return null;

  // 后端本地上传的海报（Laravel public disk 返回根相对路径）
  if (url.startsWith('/storage/')) {
    return `${API_BASE_URL}${url}`;
  }

  // 协议相对 URL，直接交给浏览器
  if (url.startsWith('//')) {
    return url;
  }

  if (/^https?:\/\//i.test(url)) {
    const needsProxy = PROXY_HOSTS.some((host) => url.includes(host));
    if (needsProxy) {
      return `${API_BASE_URL}/api/proxy-image?url=${encodeURIComponent(url)}`;
    }
    return url;
  }

  // 其他非标准值兜底：直接返回，加载失败由占位符处理
  return url;
}

/** 文件大小格式化 */
export function formatFileSize(bytes) {
  if (bytes === null || bytes === undefined) return '-';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}
