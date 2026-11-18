<?php

use App\Support\Raven23AuthSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // New migration ID repairs databases where an earlier migration row
        // exists but the runtime DB still lacks the RAVEN23 authentication schema.
        Raven23AuthSchema::ensure();
    }

    public function down(): void
    {
        // Never remove account identity fields or invalidate existing sessions.
    }
};
