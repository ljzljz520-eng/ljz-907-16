<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * 管理员凭邮箱 + 密码登录，签发 Sanctum API Token。
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => '请填写邮箱。',
            'email.email' => '邮箱格式不正确。',
            'password.required' => '请填写密码。',
        ]);

        $user = User::where('email', $request->input('email'))->first();

        // 凭证错误与"非管理员"统一提示，避免暴露账号是否存在
        if (!$user || !Hash::check($request->input('password'), $user->password) || !$user->is_admin) {
            throw ValidationException::withMessages([
                'email' => ['管理员账号或密码不正确。'],
            ]);
        }

        $token = $user->createToken('admin-api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => (bool) $user->is_admin,
            ],
        ]);
    }

    /**
     * 注销当前令牌。
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => '已退出登录。']);
    }
}
