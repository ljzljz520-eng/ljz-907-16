<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * 创建（幂等）一个管理员账号，供后台海报管理登录使用。
     *
     * 凭据来自 config/admin.php（可通过 ADMIN_* 环境变量覆盖）。
     * 生产环境务必注入强口令，不要使用默认值。
     */
    public function run(): void
    {
        $email = (string) config('admin.email', 'admin@cinevault.local');
        $password = (string) config('admin.password', 'admin123456');
        $name = (string) config('admin.name', '管理员');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
