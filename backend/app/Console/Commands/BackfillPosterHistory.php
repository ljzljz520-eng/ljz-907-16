<?php

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\Poster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPosterHistory extends Command
{
    protected $signature = 'posters:backfill {--chunk=200}';

    protected $description = '为已存在但没有海报使用记录的影片，按 movies.poster_url 补建一条外链历史记录';

    public function handle(): int
    {
        $created = 0;
        $skipped = 0;

        Movie::query()
            ->whereNotNull('poster_url')
            ->where('poster_url', '!=', '')
            ->chunkById((int) $this->option('chunk'), function ($movies) use (&$created, &$skipped) {
                foreach ($movies as $movie) {
                    $hasAny = Poster::where('movie_id', $movie->id)->exists();
                    if ($hasAny) {
                        $skipped++;
                        continue;
                    }

                    DB::transaction(function () use ($movie, &$created) {
                        $isUpload = str_starts_with((string) $movie->poster_url, '/storage/');

                        Poster::create([
                            'movie_id' => $movie->id,
                            'source' => $isUpload ? Poster::SOURCE_UPLOAD : Poster::SOURCE_EXTERNAL,
                            // 历史本地上传路径去掉 /storage 前缀，还原为磁盘相对路径
                            'url' => $isUpload
                                ? preg_replace('#^/storage/#', '', $movie->poster_url)
                                : $movie->poster_url,
                            'is_active' => true,
                            'check_passed' => $isUpload,
                            'activated_at' => $movie->created_at,
                            'note' => '历史数据回填',
                        ]);

                        $created++;
                    });
                }
            });

        $this->info("回填完成：新建 {$created} 条记录，跳过 {$skipped} 部已有记录的影片。");

        return self::SUCCESS;
    }
}
