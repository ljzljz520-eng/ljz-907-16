// 统一的后端 API 地址，避免各组件硬编码
import axios from 'axios';
import { adminToken, clearAdminSession } from './auth';

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000';

const api = axios.create({
  baseURL: `${API_BASE_URL}/api`,
});

// 请求自动附带管理员令牌（存在时）
api.interceptors.request.use((config) => {
  if (adminToken.value) {
    config.headers = config.headers || {};
    config.headers.Authorization = `Bearer ${adminToken.value}`;
  }
  return config;
});

// 令牌失效 / 缺失时（401）清理本地登录态，避免带着过期令牌反复请求
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 401) {
      clearAdminSession();
    }
    return Promise.reject(error);
  }
);

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
