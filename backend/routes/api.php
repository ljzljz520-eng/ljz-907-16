<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
| 注意：当前项目尚未接入管理员登录鉴权，上线前应在此处增加
| auth:sanctum + 管理员角色中间件保护以下写操作。
*/
Route::prefix('movies/{movie}')->group(function () {
    // 海报使用记录（当前 + 历史）
    Route::get('/posters', [PosterController::class, 'index']);
    // 仅检测外链可访问性，不保存
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

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});