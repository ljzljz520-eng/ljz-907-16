<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Movie extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'translated_title',
        'director',
        'writer',
        'actors',
        'year',
        'release_date',
        'country',
        'language',
        'runtime',
        'genre',
        'rating',
        'imdb_rating',
        'imdb_link',
        'douban_link',
        'poster_url',
        'description',
        'awards',
        'screenshots',
    ];

    protected $casts = [
        'year' => 'integer',
        'rating' => 'decimal:1',
        'screenshots' => 'array',
    ];

    /**
     * 全部海报使用记录（当前 + 历史）。
     */
    public function posters(): HasMany
    {
        return $this->hasMany(Poster::class);
    }

    /**
     * 当前生效的海报（每部影片至多一张）。
     */
    public function activePoster(): HasOne
    {
        return $this->hasOne(Poster::class)->where('is_active', true);
    }
}
