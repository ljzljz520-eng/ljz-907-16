<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Poster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PosterManagementTest extends TestCase
{
    use RefreshDatabase;

    // 最小合法 1x1 PNG
    private const PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function movie(array $attrs = []): Movie
    {
        return Movie::create(array_merge([
            'title' => 'Test Movie ' . uniqid(),
            'year' => 2026,
            'poster_url' => null,
        ], $attrs));
    }

    private function pngUpload(string $name = 'poster.png', int $width = 20, int $height = 30): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    public function test_upload_local_poster_validates_and_sets_active(): void
    {
        Storage::fake('public');
        $movie = $this->movie();

        $response = $this->postJson("/api/movies/{$movie->id}/posters/upload", [
            'file' => $this->pngUpload(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('poster.source', Poster::SOURCE_UPLOAD)
            ->assertJsonPath('poster.is_active', true);

        $movie->refresh();
        $this->assertNotNull($movie->poster_url);
        $this->assertStringStartsWith('/storage/', $movie->poster_url);

        // 文件确实落盘
        $relative = str_replace('/storage/', '', $movie->poster_url);
        Storage::disk('public')->assertExists($relative);

        // 记录入库
        $this->assertDatabaseHas('posters', [
            'movie_id' => $movie->id,
            'source' => 'upload',
            'is_active' => true,
        ]);
    }

    public function test_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');
        $movie = $this->movie();

        $big = UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg'); // 6MB > 5MB

        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $big])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file']]);

        $this->assertDatabaseCount('posters', 0);
        $this->assertNull($movie->refresh()->poster_url);
    }

    public function test_upload_rejects_unsupported_format(): void
    {
        Storage::fake('public');
        $movie = $this->movie();

        $txt = UploadedFile::fake()->create('poster.bmp', 10, 'image/bmp');

        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $txt])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file']]);
    }

    public function test_upload_rejects_non_image_content(): void
    {
        Storage::fake('public');
        $movie = $this->movie();

        // 扩展名为 png，但内容是纯文本 —— 服务端真实图片校验应拦截
        $fakePng = UploadedFile::fake()->createWithContent('evil.png', 'this is not an image');

        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $fakePng])
            ->assertStatus(422);

        $this->assertDatabaseCount('posters', 0);
        // 未落盘任何海报文件
        $this->assertEmpty(Storage::disk('public')->allFiles('posters'));
    }

    public function test_replacing_poster_keeps_old_record_as_history(): void
    {
        Storage::fake('public');
        $movie = $this->movie();

        // 第一张
        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $this->pngUpload('a.png')])
            ->assertCreated();
        $first = Poster::where('movie_id', $movie->id)->first();
        $firstPath = $first->url;

        // 第二张替换
        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $this->pngUpload('b.png')])
            ->assertCreated();

        $posters = Poster::where('movie_id', $movie->id)->orderBy('id')->get();
        $this->assertCount(2, $posters);

        $this->assertFalse($posters[0]->is_active, '旧海报应被标记为非当前');
        $this->assertNotNull($posters[0]->replaced_at, '旧海报应记录被替换时间');
        $this->assertTrue($posters[1]->is_active, '新海报应为当前海报');

        // 旧文件依然保留在磁盘上，没有被误删
        Storage::disk('public')->assertExists($firstPath);

        // 使用记录接口可以看到两张
        $this->getJson("/api/movies/{$movie->id}/posters")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_activate_historical_poster_switches_back(): void
    {
        Storage::fake('public');
        $movie = $this->movie();

        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $this->pngUpload('a.png')])->assertCreated();
        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $this->pngUpload('b.png')])->assertCreated();

        $old = Poster::where('movie_id', $movie->id)->orderBy('id')->first();
        $new = Poster::where('movie_id', $movie->id)->orderByDesc('id')->first();

        $this->postJson("/api/movies/{$movie->id}/posters/{$old->id}/activate")
            ->assertOk()
            ->assertJsonPath('poster.is_active', true);

        $this->assertTrue($old->refresh()->is_active);
        $this->assertFalse($new->refresh()->is_active);
        $this->assertStringEndsWith($old->url, $movie->refresh()->poster_url);
    }

    public function test_cannot_delete_active_poster(): void
    {
        Storage::fake('public');
        $movie = $this->movie();

        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $this->pngUpload()])->assertCreated();
        $active = Poster::where('movie_id', $movie->id)->first();

        $this->deleteJson("/api/movies/{$movie->id}/posters/{$active->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('posters', ['id' => $active->id]);
        Storage::disk('public')->assertExists($active->url);
    }

    public function test_deleting_history_record_removes_orphan_file_only(): void
    {
        Storage::fake('public');
        $movie = $this->movie();

        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $this->pngUpload('a.png')])->assertCreated();
        $this->postJson("/api/movies/{$movie->id}/posters/upload", ['file' => $this->pngUpload('b.png')])->assertCreated();

        $old = Poster::where('movie_id', $movie->id)->orderBy('id')->first();
        $oldPath = $old->url;

        $this->deleteJson("/api/movies/{$movie->id}/posters/{$old->id}")
            ->assertOk();

        $this->assertDatabaseMissing('posters', ['id' => $old->id]);
        // 无其他记录引用 -> 物理文件可安全删除
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_external_url_success_is_detected_and_bound(): void
    {
        $movie = $this->movie();
        $png = base64_decode(self::PNG_BYTES);
        $url = 'https://93.184.216.34/poster.png';

        Http::fake(function () use ($png) {
            // 检测 + 绑定会发起两次请求（stream 模式），用闭包确保每次返回独立响应
            return Http::response($png, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($png),
            ]);
        });

        $this->postJson("/api/movies/{$movie->id}/posters/check-external", ['url' => $url])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mime_type', 'image/png');

        $this->postJson("/api/movies/{$movie->id}/posters/external", ['url' => $url])
            ->assertCreated()
            ->assertJsonPath('poster.source', 'external');

        $this->assertEquals($url, $movie->refresh()->poster_url);
        $this->assertDatabaseHas('posters', [
            'movie_id' => $movie->id,
            'source' => 'external',
            'url' => $url,
            'is_active' => true,
        ]);
    }

    public function test_external_url_to_private_network_is_rejected(): void
    {
        $movie = $this->movie();

        Http::fake(); // 即使 fake 也不应发出请求 —— SSRF 防护应先拦截

        $this->postJson("/api/movies/{$movie->id}/posters/external", [
            'url' => 'http://127.0.0.1/secret.png',
        ])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertNull($movie->refresh()->poster_url);
    }

    public function test_external_url_to_non_image_content_is_rejected(): void
    {
        $movie = $this->movie();
        $url = 'https://93.184.216.34/page.html';

        Http::fake([$url => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->postJson("/api/movies/{$movie->id}/posters/external", ['url' => $url])
            ->assertStatus(422);

        $this->assertDatabaseCount('posters', 0);
    }

    public function test_external_url_too_large_is_rejected(): void
    {
        $movie = $this->movie();
        $url = 'https://93.184.216.34/huge.png';

        Http::fake([$url => Http::response('', 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => 6 * 1024 * 1024, // 6MB
        ])]);

        $this->postJson("/api/movies/{$movie->id}/posters/external", ['url' => $url])
            ->assertStatus(422);
    }

    public function test_external_stream_without_content_length_but_oversized_is_rejected(): void
    {
        $movie = $this->movie();
        $url = 'https://93.184.216.34/huge-nolen.png';
        $big = str_repeat('x', 5 * 1024 * 1024 + 100); // 略大于 5MB

        Http::fake(function () use ($big) {
            return Http::response($big, 200, ['Content-Type' => 'image/png']); // 故意不给 Content-Length
        });

        $this->postJson("/api/movies/{$movie->id}/posters/external", ['url' => $url])
            ->assertStatus(422);

        $this->assertDatabaseCount('posters', 0);
    }

    public function test_cross_movie_poster_access_returns_404(): void
    {
        Storage::fake('public');
        $movieA = $this->movie();
        $movieB = $this->movie();

        $this->postJson("/api/movies/{$movieA->id}/posters/upload", ['file' => $this->pngUpload()])->assertCreated();
        $posterA = Poster::where('movie_id', $movieA->id)->first();

        $this->postJson("/api/movies/{$movieB->id}/posters/{$posterA->id}/activate")
            ->assertStatus(404);
    }
}
