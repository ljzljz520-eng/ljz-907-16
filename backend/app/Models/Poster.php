<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Poster extends Model
{
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_EXTERNAL = 'external';

    protected $fillable = [
        'movie_id',
        'source',
        'url',
        'original_name',
        'mime_type',
        'file_size',
        'width',
        'height',
        'is_active',
        'check_passed',
        'http_status',
        'note',
        'activated_at',
        'replaced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'check_passed' => 'boolean',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'http_status' => 'integer',
        'activated_at' => 'datetime',
        'replaced_at' => 'datetime',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * 前台展示用的 URL：
     * - 本地上传文件走 /storage 软链；
     * - 外链保持原始 URL（由前端决定是否走图片代理）。
     */
    public function getDisplayUrlAttribute(): ?string
    {
        if ($this->source === self::SOURCE_UPLOAD) {
            return asset('storage/' . ltrim($this->url, '/'));
        }

        return $this->url;
    }

    /**
     * 序列化为接口响应结构。
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'movie_id' => $this->movie_id,
            'source' => $this->source,
            'url' => $this->url,
            'display_url' => $this->display_url,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'width' => $this->width,
            'height' => $this->height,
            'is_active' => $this->is_active,
            'check_passed' => $this->check_passed,
            'http_status' => $this->http_status,
            'note' => $this->note,
            'activated_at' => optional($this->activated_at)->toDateTimeString(),
            'replaced_at' => optional($this->replaced_at)->toDateTimeString(),
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}
