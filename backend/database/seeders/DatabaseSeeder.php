<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 先确保存在可用的管理员账号（海报写接口需管理员鉴权）
        $this->call(AdminUserSeeder::class);
        $this->call(MovieSeeder::class);
    }
}
