// 管理员鉴权：基于后端 Sanctum 令牌（Bearer Token）
// 仅用于后台海报管理等写操作；公开浏览无需登录。
import { ref } from 'vue';
import api from './api';

const TOKEN_KEY = 'cinevault_admin_token';
const USER_KEY = 'cinevault_admin_user';

function readUser() {
  try {
    const raw = localStorage.getItem(USER_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

// 全局单例状态，供任意组件读取
export const adminToken = ref(localStorage.getItem(TOKEN_KEY) || '');
export const adminUser = ref(readUser());
export const isAdmin = ref(Boolean(adminToken.value));

export async function loginAdmin(email, password) {
  const { data } = await api.post('/admin/login', { email, password });
  adminToken.value = data.token;
  adminUser.value = data.user;
  isAdmin.value = true;
  localStorage.setItem(TOKEN_KEY, data.token);
  localStorage.setItem(USER_KEY, JSON.stringify(data.user));
  return data.user;
}

export function logoutAdmin() {
  // 尽力通知后端吊销令牌；无论成败都清空本地状态
  return api
    .post('/admin/logout')
    .catch(() => {})
    .finally(() => clearAdminSession());
}

export function clearAdminSession() {
  adminToken.value = '';
  adminUser.value = null;
  isAdmin.value = false;
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}
