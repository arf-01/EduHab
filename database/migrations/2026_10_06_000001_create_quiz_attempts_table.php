<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('student_id');
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->string('active_key')->nullable()->unique();
            $table->string('attempt_token', 64)->unique();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'quiz_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
