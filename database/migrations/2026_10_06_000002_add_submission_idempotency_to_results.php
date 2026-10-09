<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->uuid('submission_id')->nullable()->unique()->after('quiz_id');
            $table->string('submission_payload_hash', 64)->nullable()->after('submission_id');
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropUnique(['submission_id']);
            $table->dropColumn(['submission_id', 'submission_payload_hash']);
        });
    }
};
