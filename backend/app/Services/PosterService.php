<?php

namespace App\Services;

use App\Exceptions\PosterException;
use App\Models\Movie;
use App\Models\Poster;
use App\Support\NetworkGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PosterService
{
    /** 允许的图片扩展名 */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** 扩展名 -> 规范 MIME 映射 */
    public const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /** 允许的 Content-Type（外链检测） */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** 海报文件大小上限 5MB */
    public const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /** 外链请求超时（秒） */
    public const EXTERNAL_TIMEOUT = 10;

    /** 检测图片真实类型时最多读取的字节数 */
    public const SNIFF_BYTES = 8192;

    /**
     * 查询影片的海报使用记录，当前海报在前，其余按最近使用排序。
     */
    public function history(Movie $movie)
    {
        return $movie->posters()
            ->orderByDesc('is_active')
            ->orderByRaw('COALESCE(replaced_at, activated_at, created_at) DESC')
            ->get();
    }

    /**
     * 处理本地上传的海报文件：校验大小/格式/真实图片内容，入库并设为当前海报。
     */
    public function storeUpload(Movie $movie, UploadedFile $file, ?string $note = null): Poster
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new PosterException('海报格式不支持，仅支持 JPG、JPEG、PNG、WEBP、GIF。');
        }

        if (!$file->isValid()) {
            throw new PosterException('文件上传失败，请重试。');
        }

        if ($file->getSize() === false || $file->getSize() > self::MAX_FILE_SIZE) {
            throw new PosterException('海报文件不能超过 5MB。');
        }

        // 服务端二次校验 MIME（不信任浏览器提交的 Content-Type）
        $detectedMime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detectedMime = (string) finfo_file($finfo, $file->getRealPath());
                finfo_close($finfo);
            }
        }

        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            throw new PosterException('文件不是有效的图片，无法作为海报。');
        }

        if ($detectedMime !== '' && !in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new PosterException('图片格式不支持，仅支持 JPG、PNG、WEBP、GIF。');
        }

        $extension = $this->normalizeExtension($imageInfo[2] ?? null, $extension);
        $fileName = sprintf('%s_%s.%s', $movie->id, Str::random(16), $extension);
        $relativePath = "posters/{$movie->id}/{$fileName}";

        try {
            $stored = Storage::disk('public')->putFileAs(
                "posters/{$movie->id}",
                $file,
                $fileName
            );
        } catch (\Throwable $e) {
            throw new PosterException('海报保存失败，请检查存储目录权限。');
        }

        if ($stored === false) {
            throw new PosterException('海报保存失败，请检查存储目录权限。');
        }

        return DB::transaction(function () use ($movie, $relativePath, $file, $imageInfo, $detectedMime, $extension, $note) {
            $poster = $movie->posters()->create([
                'source' => Poster::SOURCE_UPLOAD,
                'url' => $relativePath,
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'mime_type' => $detectedMime ?: (self::MIME_BY_EXTENSION[$extension] ?? null),
                'file_size' => (int) $file->getSize(),
                'width' => $imageInfo[0] ?? null,
                'height' => $imageInfo[1] ?? null,
                'is_active' => false,
                'check_passed' => true,
                'note' => $note,
            ]);

            $this->activate($poster, false);

            return $poster->refresh();
        });
    }

    /**
     * 绑定外链海报：先检测协议、内网地址、可访问性和图片格式，通过后入库并设为当前海报。
     */
    public function storeExternal(Movie $movie, string $url, ?string $note = null): Poster
    {
        $url = trim($url);
        $report = $this->checkExternalUrl($url);

        if (!$report['ok']) {
            throw new PosterException($report['error'] ?? '外链海报不可用。');
        }

        return DB::transaction(function () use ($movie, $url, $report, $note) {
            $poster = $movie->posters()->create([
                'source' => Poster::SOURCE_EXTERNAL,
                'url' => $url,
                'mime_type' => $report['mime_type'] ?? null,
                'file_size' => $report['file_size'] ?? null,
                'width' => $report['width'] ?? null,
                'height' => $report['height'] ?? null,
                'is_active' => false,
                'check_passed' => true,
                'http_status' => $report['http_status'] ?? null,
                'note' => $note,
            ]);

            $this->activate($poster, false);

            return $poster->refresh();
        });
    }

    /**
     * 检测外链地址是否可用：
     * 协议/SSRF 防护 -> HTTP 可访问 -> Content-Type -> 真实图片内容/尺寸。
     *
     * @return array{ok: bool, error?: string, http_status?: int, mime_type?: string, file_size?: int|null, width?: int|null, height?: int|null}
     */
    public function checkExternalUrl(string $url): array
    {
        $url = trim($url);

        if ($url === '' || mb_strlen($url) > 1024) {
            return ['ok' => false, 'error' => '请填写有效的图片链接（长度不超过 1024 字符）。'];
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['ok' => false, 'error' => '链接格式不正确。'];
        }

        if (!NetworkGuard::isPublicHttpUrl($url)) {
            return ['ok' => false, 'error' => '仅支持可公开访问的 http/https 图片链接，不能使用内网或本地地址。'];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                'Accept' => 'image/avif,image/webp,image/png,image/*,*/*;q=0.8',
            ])
                ->timeout(self::EXTERNAL_TIMEOUT)
                ->connectTimeout(5)
                ->withOptions([
                    'stream' => true,
                    // 跟随重定向时逐跳校验目标主机，防止 302 跳转到内网（SSRF）
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                        'on_redirect' => function ($request, $response, $uri) {
                            if (!NetworkGuard::isPublicHttpUrl((string) $uri)) {
                                throw new PosterException('重定向地址指向了不可访问的内网资源。');
                            }
                        },
                    ],
                ])
                ->get($url);
        } catch (PosterException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '链接无法访问，请确认地址可正常打开。'];
        }

        $status = $response->status();
        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'error' => "链接返回异常状态码 {$status}。",
                'http_status' => $status,
            ];
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0] ?? ''));
        if ($contentType !== '' && !in_array($contentType, self::ALLOWED_MIME_TYPES, true)) {
            return [
                'ok' => false,
                'error' => '链接指向的不是图片文件（Content-Type: ' . ($contentType ?: '未知') . '）。',
                'http_status' => $status,
            ];
        }

        // 有 Content-Length 时先卡大小，避免下载超大文件
        $contentLengthHeader = $response->header('Content-Length');
        $declaredSize = is_numeric($contentLengthHeader) ? (int) $contentLengthHeader : null;
        if ($declaredSize !== null && $declaredSize > self::MAX_FILE_SIZE) {
            return [
                'ok' => false,
                'error' => '图片超过 5MB 限制。',
                'http_status' => $status,
            ];
        }

        // 读取字节并嗅探真实类型：
        // - getimagesizefromstring 只需文件头即可识别尺寸/类型，所以图片部分只保留前 8KB；
        // - 当服务器未返回 Content-Length 时，继续排空流以统计真实大小，超过 5MB 立即拒绝，
        //   避免“声明很小、实际巨大”的图片耗尽带宽。
        $body = $response->getBody();
        $head = '';
        $totalRead = 0;
        $oversized = false;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(65536);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $totalRead += strlen($chunk);
                if (strlen($head) < self::SNIFF_BYTES) {
                    $head .= substr($chunk, 0, self::SNIFF_BYTES - strlen($head));
                }
                if ($totalRead > self::MAX_FILE_SIZE) {
                    $oversized = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '读取图片内容失败。', 'http_status' => $status];
        } finally {
            try {
                $body->close();
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($oversized) {
            return ['ok' => false, 'error' => '图片超过 5MB 限制。', 'http_status' => $status];
        }

        $imageInfo = @getimagesizefromstring($head);
        if ($imageInfo === false) {
            return ['ok' => false, 'error' => '链接内容不是有效的图片文件。', 'http_status' => $status];
        }

        $sniffedMime = $imageInfo['mime'] ?? null;
        if ($sniffedMime && !in_array(strtolower($sniffedMime), self::ALLOWED_MIME_TYPES, true)) {
            return ['ok' => false, 'error' => '图片格式不支持，仅支持 JPG、PNG、WEBP、GIF。', 'http_status' => $status];
        }

        // 未声明 Content-Length 时使用真实读取字节数作为大小
        $actualSize = $declaredSize ?? $totalRead;

        return [
            'ok' => true,
            'http_status' => $status,
            'mime_type' => $sniffedMime ?: ($contentType ?: null),
            'file_size' => $actualSize,
            'width' => $imageInfo[0] ?? null,
            'height' => $imageInfo[1] ?? null,
        ];
    }

    /**
     * 将某条历史海报重新设为当前海报（可"找回"刚被替换的旧图）。
     */
    public function activate(Poster $poster, $withinTransaction = true): Poster
    {
        $apply = function () use ($poster) {
            $movie = $poster->movie;

            // 判断是否为“重新启用历史海报”：该记录此前已被替换过
            $wasReactivated = !$poster->is_active && $poster->replaced_at !== null;

            $movie->posters()
                ->where('is_active', true)
                ->whereKeyNot($poster->id)
                ->update([
                    'is_active' => false,
                    'replaced_at' => now(),
                ]);

            $poster->forceFill([
                'is_active' => true,
                'replaced_at' => null,
                'activated_at' => $poster->activated_at ?: now(),
            ])->save();

            // 重新启用历史海报时，追加一条说明（新建海报直接激活时不追加）
            if ($wasReactivated) {
                $poster->note = trim(($poster->note ? $poster->note . ' | ' : '') . '重新启用于 ' . now()->format('Y-m-d H:i'));
                $poster->save();
            }

            $this->syncMoviePosterUrl($movie, $poster);

            return $poster->refresh();
        };

        return $withinTransaction ? DB::transaction($apply) : $apply();
    }

    /**
     * 删除历史海报记录。
     *
     * - 当前生效海报不允许删除（必须先替换/启用其他海报）；
     * - 本地上传文件只有在没有任何其他记录引用时才真正删除，避免误删。
     */
    public function delete(Poster $poster): void
    {
        DB::transaction(function () use ($poster) {
            if ($poster->is_active) {
                throw new PosterException('当前正在使用的海报不能删除，请先替换为其他海报。');
            }

            $path = $poster->source === Poster::SOURCE_UPLOAD ? $poster->url : null;
            $poster->delete();

            if ($path !== null && Storage::disk('public')->exists($path)) {
                $stillReferenced = Poster::query()
                    ->where('source', Poster::SOURCE_UPLOAD)
                    ->where('url', $path)
                    ->exists();

                if (!$stillReferenced) {
                    Storage::disk('public')->delete($path);
                }
            }
        });
    }

    /**
     * CSV 导入时同步海报记录：
     * 若导入数据中的 poster_url 与当前海报不一致，则记录为一张外部海报并启用；
     * 已存在完全相同 URL 的记录时不重复创建。
     */
    public function syncFromImport(Movie $movie, ?string $posterUrl): void
    {
        $posterUrl = $posterUrl !== null ? trim($posterUrl) : '';

        if ($posterUrl === '') {
            return;
        }

        $existing = $movie->posters()
            ->where('source', Poster::SOURCE_EXTERNAL)
            ->where('url', $posterUrl)
            ->latest('id')
            ->first();

        if ($existing) {
            if (!$existing->is_active) {
                $this->activate($existing);
            }
            return;
        }

        DB::transaction(function () use ($movie, $posterUrl) {
            $poster = $movie->posters()->create([
                'source' => Poster::SOURCE_EXTERNAL,
                'url' => $posterUrl,
                'is_active' => false,
                'check_passed' => false,
                'note' => 'CSV 导入（未做可访问性检测）',
            ]);
            $this->activate($poster, false);
        });
    }

    /**
     * 把当前海报同步到 movies.poster_url：
     * 前台卡片与详情页统一只读取这一个字段，保证两处同源。
     *
     * - 外链：直接存原始 URL（是否走图片代理由前端判断）
     * - 本地上传：存 /storage 开头的根相对路径
     */
    protected function syncMoviePosterUrl(Movie $movie, Poster $poster): void
    {
        if ($poster->source === Poster::SOURCE_UPLOAD) {
            $movie->poster_url = '/storage/' . ltrim($poster->url, '/');
        } else {
            $movie->poster_url = $poster->url;
        }

        $movie->save();
    }

    /**
     * 根据 GD 检测出的图片类型确定扩展名，无法确定时回退到原扩展名。
     */
    protected function normalizeExtension($imageType, string $fallback): string
    {
        return match ($imageType) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF => 'gif',
            default => in_array($fallback, self::ALLOWED_EXTENSIONS, true) ? $fallback : 'jpg',
        };
    }
}
