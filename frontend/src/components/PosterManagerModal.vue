<script setup>
import { ref, watch } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import {
  X, UploadCloud, History, CheckCircle2, AlertCircle,
  Loader2, Trash2, RotateCcw, ImageOff, BadgeCheck, HardDrive, Globe
} from 'lucide-vue-next';
import api, { extractError } from '../lib/api';
import { resolvePosterUrl, formatFileSize } from '../lib/poster';

const props = defineProps({
  isOpen: Boolean,
  movie: Object,
});

const emit = defineEmits(['close', 'updated']);

const MAX_SIZE = 5 * 1024 * 1024; // 与后端一致：5MB
const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const ACCEPT_ATTR = '.jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif';

const loading = ref(false);
const posters = ref([]);
const actionError = ref('');

// 本地上传
const selectedFile = ref(null);
const filePreviewUrl = ref('');
const uploading = ref(false);
const uploadProgress = ref(0);
const fileError = ref('');

// 外链
const externalUrl = ref('');
const externalChecking = ref(false);
const externalSubmitting = ref(false);
const externalReport = ref(null); // 后端检测结果
const externalError = ref('');

watch(() => props.isOpen, (open) => {
  if (open && props.movie?.id) {
    resetState();
    loadHistory();
  }
});

watch(() => props.movie?.id, () => {
  if (props.isOpen && props.movie?.id) {
    resetState();
    loadHistory();
  }
});

// 输入内容变化后清空旧的检测结果，避免展示过期报告
watch(externalUrl, (val, oldVal) => {
  if (val !== oldVal) {
    externalReport.value = null;
    externalError.value = '';
  }
});

function resetState() {
  clearSelectedFile();
  externalUrl.value = '';
  externalReport.value = null;
  externalError.value = '';
  actionError.value = '';
}

async function loadHistory() {
  loading.value = true;
  actionError.value = '';
  try {
    const { data } = await api.get(`/movies/${props.movie.id}/posters`);
    posters.value = data.data || [];
  } catch (err) {
    actionError.value = extractError(err, '海报使用记录加载失败。');
  } finally {
    loading.value = false;
  }
}

/* ---------------- 本地上传 ---------------- */

function clearSelectedFile() {
  if (filePreviewUrl.value) URL.revokeObjectURL(filePreviewUrl.value);
  selectedFile.value = null;
  filePreviewUrl.value = '';
  fileError.value = '';
  uploadProgress.value = 0;
}

function validateFile(file) {
  const name = file.name.toLowerCase();
  const extOk = ['.jpg', '.jpeg', '.png', '.webp', '.gif'].some((ext) => name.endsWith(ext));
  const mimeOk = ACCEPTED.includes(file.type);
  if (!extOk || (!mimeOk && file.type !== '')) {
    return '海报仅支持 JPG、JPEG、PNG、WEBP、GIF 格式。';
  }
  if (file.size > MAX_SIZE) {
    return `海报文件不能超过 5MB（当前 ${formatFileSize(file.size)}）。`;
  }
  return '';
}

function onFileChange(event) {
  const file = event.target.files?.[0];
  event.target.value = ''; // 允许重复选择同一文件
  fileError.value = '';
  if (!file) return;

  const err = validateFile(file);
  if (err) {
    fileError.value = err;
    return;
  }
  selectedFile.value = file;
  filePreviewUrl.value = URL.createObjectURL(file);
}

async function submitUpload() {
  if (!selectedFile.value || uploading.value) return;
  uploading.value = true;
  uploadProgress.value = 0;
  fileError.value = '';
  actionError.value = '';

  const form = new FormData();
  form.append('file', selectedFile.value);

  try {
    const { data } = await api.post(`/movies/${props.movie.id}/posters/upload`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        if (e.total) uploadProgress.value = Math.round((e.loaded * 100) / e.total);
      },
    });
    clearSelectedFile();
    await loadHistory();
    emit('updated', data.movie);
  } catch (err) {
    fileError.value = extractError(err, '海报上传失败。');
  } finally {
    uploading.value = false;
  }
}

/* ---------------- 外链 ---------------- */

async function checkExternal(notifyOnly = false) {
  const url = externalUrl.value.trim();
  if (!url) {
    externalError.value = '请填写图片外链地址。';
    return false;
  }
  externalChecking.value = true;
  externalError.value = '';
  externalReport.value = null;

  try {
    const { data } = await api.post(`/movies/${props.movie.id}/posters/check-external`, { url });
    externalReport.value = data;
    if (notifyOnly) externalError.value = '';
    return true;
  } catch (err) {
    // 422 时后端同样返回检测报告体
    const report = err.response?.data;
    if (report && typeof report === 'object' && 'ok' in report) {
      externalReport.value = report;
    }
    externalError.value = extractError(err, '外链检测失败。');
    return false;
  } finally {
    externalChecking.value = false;
  }
}

async function submitExternal() {
  if (externalSubmitting.value) return;
  const ok = await checkExternal();
  if (!ok) return;

  externalSubmitting.value = true;
  try {
    const { data } = await api.post(`/movies/${props.movie.id}/posters/external`, {
      url: externalUrl.value.trim(),
    });
    externalUrl.value = '';
    externalReport.value = null;
    await loadHistory();
    emit('updated', data.movie);
  } catch (err) {
    externalError.value = extractError(err, '外链海报绑定失败。');
  } finally {
    externalSubmitting.value = false;
  }
}

/* ---------------- 历史记录操作 ---------------- */

const actingId = ref(null);

async function activatePoster(poster) {
  if (poster.is_active || actingId.value) return;
  actingId.value = poster.id;
  actionError.value = '';
  try {
    const { data } = await api.post(`/movies/${props.movie.id}/posters/${poster.id}/activate`);
    await loadHistory();
    emit('updated', data.movie);
  } catch (err) {
    actionError.value = extractError(err, '切换海报失败。');
  } finally {
    actingId.value = null;
  }
}

async function removePoster(poster) {
  if (poster.is_active || actingId.value) return;
  const confirmed = window.confirm(
    '确定删除这条历史海报记录吗？\n（当前正在使用的海报不受影响；若为本地文件且无其他记录引用，物理文件也会被删除）'
  );
  if (!confirmed) return;

  actingId.value = poster.id;
  actionError.value = '';
  try {
    await api.delete(`/movies/${props.movie.id}/posters/${poster.id}`);
    await loadHistory();
  } catch (err) {
    actionError.value = extractError(err, '删除失败。');
  } finally {
    actingId.value = null;
  }
}

function displaySrc(poster) {
  // 历史记录里的上传文件同样走统一解析
  return resolvePosterUrl(poster.source === 'upload' ? `/storage/${poster.url.replace(/^\/?/, '')}` : poster.url);
}

function formatTime(value) {
  if (!value) return '-';
  return value.replace('T', ' ').slice(0, 16);
}
</script>

<template>
  <TransitionRoot appear :show="isOpen" as="template">
    <Dialog as="div" @close="emit('close')" class="relative z-[60]">
      <TransitionChild
        as="template"
        enter="duration-300 ease-out"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="duration-200 ease-in"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/85 backdrop-blur-sm" />
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <TransitionChild
            as="template"
            enter="duration-300 ease-out"
            enter-from="opacity-0 scale-95"
            enter-to="opacity-100 scale-100"
            leave="duration-200 ease-in"
            leave-from="opacity-100 scale-100"
            leave-to="opacity-0 scale-95"
          >
            <DialogPanel class="w-full max-w-3xl transform overflow-hidden rounded-2xl bg-dark-800 shadow-2xl border border-white/10">
              <!-- Header -->
              <div class="flex items-center justify-between border-b border-white/5 px-6 py-4">
                <div>
                  <DialogTitle as="h3" class="text-lg font-semibold text-white">海报管理</DialogTitle>
                  <p v-if="movie" class="mt-0.5 truncate text-xs text-gray-500">
                    {{ movie.translated_title || movie.title }} ({{ movie.year }})
                  </p>
                </div>
                <button @click="emit('close')" class="rounded-full p-2 text-gray-400 hover:bg-white/10 hover:text-white transition">
                  <X class="h-5 w-5" />
                </button>
              </div>

              <div class="max-h-[75vh] overflow-y-auto px-6 py-5">
                <!-- 全局错误 -->
                <div v-if="actionError" class="mb-4 flex items-start gap-2 rounded-lg bg-red-500/10 p-3 text-sm text-red-300">
                  <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                  <span>{{ actionError }}</span>
                </div>

                <!-- 当前海报 -->
                <section class="mb-6">
                  <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-300">
                    <BadgeCheck class="h-4 w-4 text-purple-400" />
                    当前使用（前台卡片与详情页同源）
                  </div>
                  <div class="flex items-center gap-4 rounded-xl bg-white/5 p-4">
                    <div class="h-28 w-20 shrink-0 overflow-hidden rounded-lg bg-dark-900 ring-1 ring-white/10">
                      <img v-if="movie?.poster_url" :src="resolvePosterUrl(movie.poster_url)" alt="当前海报" class="h-full w-full object-cover" />
                      <div v-else class="flex h-full w-full items-center justify-center text-gray-600">
                        <ImageOff class="h-6 w-6" />
                      </div>
                    </div>
                    <div class="min-w-0 flex-1 text-sm">
                      <p class="break-all text-gray-300">{{ movie?.poster_url || '尚未设置海报' }}</p>
                      <p class="mt-1 text-xs text-gray-500">
                        替换后旧海报不会被删除，可在下方使用记录中随时找回。
                      </p>
                    </div>
                  </div>
                </section>

                <!-- 两种来源 -->
                <section class="mb-6 grid gap-4 md:grid-cols-2">
                  <!-- 本地上传 -->
                  <div class="rounded-xl border border-white/10 p-4">
                    <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-200">
                      <HardDrive class="h-4 w-4 text-purple-400" /> 上传本地海报
                    </div>

                    <label
                      class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-white/15 px-4 py-6 text-center transition hover:border-purple-500/60 hover:bg-purple-500/5"
                    >
                      <UploadCloud class="mb-2 h-7 w-7 text-gray-400" />
                      <span class="text-xs text-gray-400">点击选择文件</span>
                      <span class="mt-1 text-[11px] text-gray-600">JPG / PNG / WEBP / GIF，最大 5MB</span>
                      <input type="file" :accept="ACCEPT_ATTR" class="hidden" @change="onFileChange" />
                    </label>

                    <div v-if="selectedFile" class="mt-3 flex items-center gap-3 rounded-lg bg-black/30 p-2">
                      <img :src="filePreviewUrl" alt="预览" class="h-12 w-9 shrink-0 rounded object-cover" />
                      <div class="min-w-0 flex-1">
                        <p class="truncate text-xs text-gray-200">{{ selectedFile.name }}</p>
                        <p class="text-[11px] text-gray-500">{{ formatFileSize(selectedFile.size) }}</p>
                      </div>
                      <button type="button" @click="clearSelectedFile" class="text-gray-500 hover:text-red-400">
                        <X class="h-4 w-4" />
                      </button>
                    </div>

                    <div v-if="uploading" class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-white/10">
                      <div class="h-full bg-purple-500 transition-all" :style="{ width: `${uploadProgress}%` }" />
                    </div>

                    <p v-if="fileError" class="mt-2 flex items-start gap-1.5 text-xs text-red-400">
                      <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" /> {{ fileError }}
                    </p>

                    <button
                      type="button"
                      :disabled="!selectedFile || uploading"
                      @click="submitUpload"
                      class="mt-3 flex w-full items-center justify-center gap-2 rounded-lg bg-purple-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-purple-500 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                      <Loader2 v-if="uploading" class="h-4 w-4 animate-spin" />
                      {{ uploading ? `上传中 ${uploadProgress}%` : '上传并替换' }}
                    </button>
                  </div>

                  <!-- 外链 -->
                  <div class="rounded-xl border border-white/10 p-4">
                    <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-200">
                      <Globe class="h-4 w-4 text-blue-400" /> 填写外链地址
                    </div>

                    <textarea
                      v-model="externalUrl"
                      rows="3"
                      placeholder="https://example.com/poster.jpg"
                      class="w-full resize-none rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-xs text-gray-200 placeholder-gray-600 outline-none focus:border-purple-500/60"
                    />

                    <div class="mt-2 flex gap-2">
                      <button
                        type="button"
                        :disabled="externalChecking || !externalUrl.trim()"
                        @click="checkExternal(true)"
                        class="flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-white/15 px-3 py-2 text-xs font-medium text-gray-200 transition hover:bg-white/5 disabled:cursor-not-allowed disabled:opacity-40"
                      >
                        <Loader2 v-if="externalChecking" class="h-3.5 w-3.5 animate-spin" />
                        检测可访问性
                      </button>
                      <button
                        type="button"
                        :disabled="externalSubmitting || externalChecking || !externalUrl.trim()"
                        @click="submitExternal"
                        class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-40"
                      >
                        <Loader2 v-if="externalSubmitting" class="h-3.5 w-3.5 animate-spin" />
                        保存并替换
                      </button>
                    </div>

                    <!-- 检测结果 -->
                    <div v-if="externalReport?.ok" class="mt-2 flex items-start gap-1.5 rounded-lg bg-green-500/10 p-2 text-[11px] text-green-300">
                      <CheckCircle2 class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                      <span>
                        检测通过（HTTP {{ externalReport.http_status }}）：
                        {{ (externalReport.mime_type || '').replace('image/', '').toUpperCase() }}
                        <template v-if="externalReport.width && externalReport.height"> · {{ externalReport.width }}×{{ externalReport.height }}</template>
                        <template v-if="externalReport.file_size != null"> · {{ formatFileSize(externalReport.file_size) }}</template>
                      </span>
                    </div>
                    <p v-if="externalError" class="mt-2 flex items-start gap-1.5 text-xs text-red-400">
                      <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" /> {{ externalError }}
                    </p>
                    <p v-else-if="externalReport && !externalReport.ok" class="mt-2 flex items-start gap-1.5 text-xs text-red-400">
                      <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" /> {{ externalReport.error }}
                    </p>
                  </div>
                </section>

                <!-- 使用记录 -->
                <section>
                  <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-300">
                    <History class="h-4 w-4 text-gray-400" />
                    使用记录
                    <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] text-gray-400">{{ posters.length }}</span>
                  </div>

                  <div v-if="loading" class="flex items-center justify-center py-10 text-gray-500">
                    <Loader2 class="mr-2 h-5 w-5 animate-spin" /> 加载中...
                  </div>

                  <div v-else-if="posters.length === 0" class="rounded-xl border border-dashed border-white/10 py-10 text-center text-sm text-gray-600">
                    暂无海报记录
                  </div>

                  <ul v-else class="space-y-2">
                    <li
                      v-for="poster in posters"
                      :key="poster.id"
                      :class="[
                        'flex items-center gap-3 rounded-xl border p-3',
                        poster.is_active ? 'border-purple-500/40 bg-purple-500/5' : 'border-white/10 bg-white/[0.02]'
                      ]"
                    >
                      <div class="relative h-16 w-12 shrink-0 overflow-hidden rounded-md bg-dark-900 ring-1 ring-white/10">
                        <img :src="displaySrc(poster)" :alt="`海报记录 ${poster.id}`" class="h-full w-full object-cover" />
                      </div>

                      <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                          <span
                            :class="[
                              'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium',
                              poster.source === 'upload' ? 'bg-purple-500/15 text-purple-300' : 'bg-blue-500/15 text-blue-300'
                            ]"
                          >
                            <component :is="poster.source === 'upload' ? HardDrive : Globe" class="h-3 w-3" />
                            {{ poster.source === 'upload' ? '本地上传' : '外链' }}
                          </span>
                          <span v-if="poster.is_active" class="inline-flex items-center gap-1 rounded bg-green-500/15 px-1.5 py-0.5 text-[10px] font-medium text-green-300">
                            <CheckCircle2 class="h-3 w-3" /> 使用中
                          </span>
                          <span v-if="poster.source === 'upload' && poster.check_passed === false" class="rounded bg-yellow-500/15 px-1.5 py-0.5 text-[10px] text-yellow-300">
                            未检测
                          </span>
                        </div>
                        <p class="mt-1 truncate text-[11px] text-gray-500" :title="poster.source === 'upload' ? poster.original_name : poster.url">
                          {{ poster.source === 'upload' ? (poster.original_name || poster.url) : poster.url }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-gray-600">
                          {{ poster.mime_type || '-' }}
                          <template v-if="poster.width && poster.height"> · {{ poster.width }}×{{ poster.height }}</template>
                          <template v-if="poster.file_size != null"> · {{ formatFileSize(poster.file_size) }}</template>
                          · {{ formatTime(poster.replaced_at || poster.activated_at || poster.created_at) }}
                          <template v-if="poster.note"> · {{ poster.note }}</template>
                        </p>
                      </div>

                      <div class="flex shrink-0 items-center gap-1">
                        <button
                          v-if="!poster.is_active"
                          type="button"
                          :disabled="actingId === poster.id"
                          @click="activatePoster(poster)"
                          title="重新启用这张海报"
                          class="rounded-lg p-2 text-gray-400 transition hover:bg-purple-500/15 hover:text-purple-300 disabled:opacity-40"
                        >
                          <RotateCcw class="h-4 w-4" />
                        </button>
                        <button
                          v-if="!poster.is_active"
                          type="button"
                          :disabled="actingId === poster.id"
                          @click="removePoster(poster)"
                          title="删除该历史记录"
                          class="rounded-lg p-2 text-gray-400 transition hover:bg-red-500/15 hover:text-red-300 disabled:opacity-40"
                        >
                          <Trash2 class="h-4 w-4" />
                        </button>
                      </div>
                    </li>
                  </ul>
                </section>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
