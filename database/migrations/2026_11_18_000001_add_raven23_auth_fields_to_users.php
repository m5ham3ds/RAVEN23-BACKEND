<?php

use App\Support\Raven23AuthSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Raven23AuthSchema::ensure();
    }

    public function down(): void
    {
        // Preserve account identity columns and token records on rollback.
    }
};
