<?php

namespace App\Http\Controllers;

use App\Exceptions\PosterException;
use App\Models\Movie;
use App\Models\Poster;
use App\Services\PosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PosterController extends Controller
{
    public function __construct(private readonly PosterService $posters)
    {
    }

    /**
     * 某部影片的海报使用记录（当前 + 历史）。
     */
    public function index(Movie $movie): JsonResponse
    {
        $posters = $this->posters->history($movie);

        return response()->json([
            'data' => $posters->map(fn (Poster $poster) => $poster->present())->all(),
            'active_id' => optional($posters->firstWhere('is_active', true))->id,
        ]);
    }

    /**
     * 仅检测外链是否可访问（不保存），供后台输入链接时实时校验。
     */
    public function checkExternal(Request $request): JsonResponse
    {
        $validated = $request->validate(
            ['url' => ['required', 'string', 'max:1024']],
            ['url.required' => '请填写图片链接。']
        );

        $report = $this->posters->checkExternalUrl($validated['url']);

        return response()->json($report, $report['ok'] ? 200 : 422);
    }

    /**
     * 上传本地海报文件。
     */
    public function storeUpload(Request $request, Movie $movie): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:5120', // 5MB（单位 KB）
                'mimes:jpg,jpeg,png,webp,gif',
            ],
        ], [
            'file.required' => '请选择要上传的海报文件。',
            'file.max' => '海报文件不能超过 5MB。',
            'file.mimes' => '海报仅支持 JPG、JPEG、PNG、WEBP、GIF 格式。',
            'file.file' => '提交的内容不是有效的文件。',
        ], [
            'file' => '海报文件',
        ]);

        try {
            $poster = $this->posters->storeUpload($movie, $request->file('file'), '后台本地上传');
        } catch (PosterException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => '海报已上传并设为当前海报。',
            'poster' => $poster->present(),
            'movie' => $movie->fresh(),
        ], 201);
    }

    /**
     * 填写外链地址并绑定为当前海报（服务端检测大小/格式/可访问性）。
     */
    public function storeExternal(Request $request, Movie $movie): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'string', 'max:1024'],
        ], [
            'url.required' => '请填写图片外链地址。',
            'url.max' => '图片链接长度不能超过 1024 字符。',
        ]);

        try {
            $poster = $this->posters->storeExternal($movie, $request->input('url'), '后台填写外链');
        } catch (PosterException $e) {
            throw ValidationException::withMessages(['url' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => '外链海报已绑定并设为当前海报。',
            'poster' => $poster->present(),
            'movie' => $movie->fresh(),
        ], 201);
    }

    /**
     * 将历史海报重新设为当前海报。
     */
    public function activate(Movie $movie, Poster $poster): JsonResponse
    {
        if ($poster->movie_id !== $movie->id) {
            return response()->json(['error' => '该海报不属于此影片。'], 404);
        }

        $poster = $this->posters->activate($poster);

        return response()->json([
            'message' => '已切换为该海报。',
            'poster' => $poster->present(),
            'movie' => $movie->fresh(),
        ]);
    }

    /**
     * 删除一条历史海报记录（当前海报不可删；旧文件确认无引用才物理删除）。
     */
    public function destroy(Movie $movie, Poster $poster): JsonResponse
    {
        if ($poster->movie_id !== $movie->id) {
            return response()->json(['error' => '该海报不属于此影片。'], 404);
        }

        try {
            $this->posters->delete($poster);
        } catch (PosterException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => '历史海报记录已删除。']);
    }
}
