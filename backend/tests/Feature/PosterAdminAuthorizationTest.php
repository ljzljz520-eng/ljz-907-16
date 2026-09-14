<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Poster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosterAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function movie(): Movie
    {
        return Movie::create([
            'title' => 'Auth Movie ' . uniqid(),
            'year' => 2026,
            'poster_url' => null,
        ]);
    }

    /**
     * 所有需要保护的海报写操作 + 外链检测。
     */
    public static function protectedPosterEndpoints(): array
    {
        // [method, uri(以 {movie}/{poster} 占位), 是否需要伪造文件/请求体]
        return [
            '上传本地海报' => ['post', '/api/movies/%d/posters/upload'],
            '绑定外链海报' => ['post', '/api/movies/%d/posters/external'],
            '检测外链' => ['post', '/api/movies/%d/posters/check-external'],
            '找回历史海报' => ['post', '/api/movies/%d/posters/%d/activate'],
            '删除历史海报' => ['delete', '/api/movies/%d/posters/%d'],
        ];
    }

    private function callEndpoint(string $method, string $uri, Movie $movie, ?Poster $poster = null)
    {
        $uri = sprintf($uri, $movie->id, $poster?->id ?? 0);

        if (str_contains($uri, '/upload')) {
            return $this->{$method . 'Json'}($uri, ['file' => UploadedFile::fake()->image('a.png')]);
        }
        if (str_contains($uri, '/external') || str_contains($uri, '/check-external')) {
            return $this->{$method . 'Json'}($uri, ['url' => 'http://127.0.0.1/x.png']);
        }

        return $this->{$method . 'Json'}($uri);
    }

    /**
     * @dataProvider protectedPosterEndpoints
     */
    public function test_guest_cannot_access_protected_poster_endpoint(string $method, string $uriPattern): void
    {
        Storage::fake('public');
        $movie = $this->movie();
        $poster = Poster::create([
            'movie_id' => $movie->id,
            'source' => Poster::SOURCE_EXTERNAL,
            'url' => 'https://example.com/old.png',
            'is_active' => false,
        ]);

        $this->callEndpoint($method, $uriPattern, $movie, $poster)
            ->assertUnauthorized();

        $this->assertDatabaseCount('posters', 1);
    }

    /**
     * @dataProvider protectedPosterEndpoints
     */
    public function test_non_admin_user_cannot_access_protected_poster_endpoint(string $method, string $uriPattern): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create()); // 已登录但非管理员

        $movie = $this->movie();
        $poster = Poster::create([
            'movie_id' => $movie->id,
            'source' => Poster::SOURCE_EXTERNAL,
            'url' => 'https://example.com/old.png',
            'is_active' => false,
        ]);

        $this->callEndpoint($method, $uriPattern, $movie, $poster)
            ->assertForbidden();

        $this->assertDatabaseCount('posters', 1);
    }

    public function test_poster_history_remains_publicly_readable(): void
    {
        $movie = $this->movie();

        $this->getJson("/api/movies/{$movie->id}/posters")
            ->assertOk()
            ->assertJsonStructure(['data', 'active_id']);
    }

    public function test_admin_can_login_and_use_token_to_upload(): void
    {
        Storage::fake('public');
        $movie = $this->movie();

        $admin = User::factory()->admin()->create([
            'email' => 'boss@cinevault.local',
            'password' => 'secret-pass-123',
        ]);

        // 登录拿 token
        $login = $this->postJson('/api/admin/login', [
            'email' => 'boss@cinevault.local',
            'password' => 'secret-pass-123',
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'is_admin']]);

        $token = $login->json('token');
        $this->assertTrue($login->json('user.is_admin'));

        // 未带 token：401
        $this->postJson("/api/movies/{$movie->id}/posters/upload", [
            'file' => UploadedFile::fake()->image('a.png'),
        ])->assertUnauthorized();

        // 带 token：成功写入
        $this->withToken($token)->postJson("/api/movies/{$movie->id}/posters/upload", [
            'file' => UploadedFile::fake()->image('a.png'),
        ])->assertCreated();

        $this->assertDatabaseHas('posters', ['movie_id' => $movie->id, 'is_active' => true]);
    }

    public function test_non_admin_cannot_login_to_admin_api(): void
    {
        User::factory()->create([
            'email' => 'plain@cinevault.local',
            'password' => 'secret-pass-123',
        ]);

        $this->postJson('/api/admin/login', [
            'email' => 'plain@cinevault.local',
            'password' => 'secret-pass-123',
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::factory()->admin()->create([
            'email' => 'boss@cinevault.local',
            'password' => 'secret-pass-123',
        ]);

        $this->postJson('/api/admin/login', [
            'email' => 'boss@cinevault.local',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_admin_can_logout_and_token_stops_working(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('admin-api')->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/logout')->assertOk();

        // 令牌已被撤销
        $movie = $this->movie();
        $this->withToken($token)->postJson("/api/movies/{$movie->id}/posters/external", [
            'url' => 'https://example.com/a.png',
        ])->assertUnauthorized();
    }

    public function test_check_external_does_not_trigger_outbound_request_for_guest(): void
    {
        Http::fake(); // 未鉴权应在 SSRF/HTTP 校验之前被拦截
        $movie = $this->movie();

        $this->postJson("/api/movies/{$movie->id}/posters/check-external", [
            'url' => 'http://127.0.0.1/secret.png',
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }
}
