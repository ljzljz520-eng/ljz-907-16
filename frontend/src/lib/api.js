// 统一的后端 API 地址，避免各组件硬编码
import axios from 'axios';

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000';

const api = axios.create({
  baseURL: `${API_BASE_URL}/api`,
});

export default api;

/**
 * 从后端错误响应中提取人类可读的提示信息。
 * 兼容 Laravel 校验错误（422: {message, errors: {field: [msg]}}）与 {error: '...'} 两种格式。
 */
export function extractError(error, fallback = '操作失败，请稍后重试。') {
  const data = error?.response?.data;
  if (!data) return fallback;

  if (data.errors && typeof data.errors === 'object') {
    const first = Object.values(data.errors).flat()[0];
    if (first) return first;
  }

  if (typeof data.message === 'string' && data.message !== '') {
    return data.message;
  }

  if (typeof data.error === 'string' && data.error !== '') {
    return data.error;
  }

  return fallback;
}
