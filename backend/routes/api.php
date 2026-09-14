<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\PosterController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// 管理员登录 / 注销（Sanctum 令牌）
Route::post('/admin/login', [AuthController::class, 'login'])
    ->middleware('throttle:admin-login');
Route::post('/admin/logout', [AuthController::class, 'logout'])
    ->middleware(['auth:sanctum', 'admin']);

Route::get('/movies', [MovieController::class, 'index']);
Route::get('/movies/{id}', [MovieController::class, 'show']);
Route::post('/upload', [MovieController::class, 'upload']);
Route::get('/proxy-image', [MovieController::class, 'proxyImage']);

/*
|--------------------------------------------------------------------------
| 后台海报管理
|--------------------------------------------------------------------------
| 管理员可以上传本地海报或填写外链；替换时旧记录保留在 posters 表中，
| 可通过 history 接口查看使用记录并随时重新启用。
|
| 鉴权策略：
|   - GET  使用记录（含当前海报）为只读，保持公开，供前台正常展示；
|   - POST/DELETE 写入、替换、找回、删除及外链检测（存在 SSRF 面）
|     一律要求 auth:sanctum + admin 管理员中间件。
*/
Route::prefix('movies/{movie}')->group(function () {
    // 海报使用记录（当前 + 历史）—— 只读，公开可查
    Route::get('/posters', [PosterController::class, 'index']);

    // 以下写操作仅管理员可访问
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        // 仅检测外链可访问性，不保存（会发起服务端请求，同样需要管理员身份，防 SSRF 滥用）
        Route::post('/posters/check-external', [PosterController::class, 'checkExternal']);
        // 上传本地海报（大小/格式/真实图片校验）
        Route::post('/posters/upload', [PosterController::class, 'storeUpload']);
        // 绑定外链海报（先检测可访问性/格式/大小）
        Route::post('/posters/external', [PosterController::class, 'storeExternal']);
        // 将某条历史海报重新设为当前海报
        Route::post('/posters/{poster}/activate', [PosterController::class, 'activate']);
        // 删除一条历史海报记录（当前海报不可删）
        Route::delete('/posters/{poster}', [PosterController::class, 'destroy']);
    });
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});