<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 海报使用记录表。
     *
     * 设计要点：
     * - 每部影片的当前海报为 is_active=1 的记录；
     * - 替换海报时旧记录不会被删除，仅将 is_active 置为 0 并记录 replaced_at；
     * - source=upload 的物理文件路径记录在 storage_path，清理前会检查是否仍被引用。
     */
    public function up(): void
    {
        Schema::create('posters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('movie_id');
            $table->enum('source', ['upload', 'external'])->comment('本地上传 或 外链');
            // upload: storage/app/public 下的相对路径，如 posters/1/xxx.jpg
            // external: 原始外链 URL
            $table->text('url');
            $table->string('original_name')->nullable()->comment('上传文件的原始文件名');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable()->comment('字节数；外链为检测时的 Content-Length');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->boolean('check_passed')->default(true)->comment('外链可访问性检测是否通过');
            $table->unsignedSmallInteger('http_status')->nullable()->comment('外链检测时的 HTTP 状态码');
            $table->string('note', 512)->nullable()->comment('备注（迁移/替换说明）');
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('replaced_at')->nullable();
            $table->timestamps();

            $table->foreign('movie_id')->references('id')->on('movies')->cascadeOnDelete();
            $table->index(['movie_id', 'is_active']);
            $table->index(['movie_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posters');
    }
};
