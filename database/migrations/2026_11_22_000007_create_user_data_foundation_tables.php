<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('RAVEN23 user data supports PostgreSQL and SQLite only.');
        }

        if (! Schema::hasTable('users')) {
            throw new RuntimeException('The users table must exist before user data foundation is created.');
        }

        if (! Schema::hasTable('user_profiles')) {
            Schema::create('user_profiles', static function (Blueprint $table): void {
                $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
                $table->unsignedSmallInteger('profile_id')->default(0);
                $table->text('photo_url')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_preferences')) {
            Schema::create('user_preferences', static function (Blueprint $table): void {
                $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
                $table->string('locale', 16)->nullable();
                $table->string('timezone', 64)->default('UTC');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_devices')) {
            Schema::create('user_devices', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('device_id', 64);
                $table->string('device_name', 100)->nullable();
                $table->string('platform', 16)->nullable();
                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');
                $table->timestamps();
                $table->unique(['user_id', 'device_id']);
                $table->index(['user_id', 'last_seen_at']);
            });
        }

        $this->assertColumns('user_profiles', ['user_id', 'profile_id', 'photo_url', 'created_at', 'updated_at']);
        $this->assertColumns('user_preferences', ['user_id', 'locale', 'timezone', 'created_at', 'updated_at']);
        $this->assertColumns('user_devices', [
            'id', 'user_id', 'device_id', 'device_name', 'platform',
            'first_seen_at', 'last_seen_at', 'created_at', 'updated_at',
        ]);

        // Existing tables are accepted only when their owner relationship and
        // one-row-per-owner keys are real database constraints, not conventions.
        $this->assertOwnerConstraints('user_profiles', ['user_id']);
        $this->assertOwnerConstraints('user_preferences', ['user_id']);
        $this->assertOwnerConstraints('user_devices', ['user_id', 'device_id']);

        $this->backfillProfilesAndPreferences();
        $this->backfillKnownDevices();
    }

    private function assertOwnerConstraints(string $table, array $uniqueColumns): void
    {
        $hasUniqueOwnerKey = collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => ($index['unique'] || $index['primary'])
                && $index['columns'] === $uniqueColumns,
        );

        if (! $hasUniqueOwnerKey) {
            throw new RuntimeException(
                sprintf('Existing %s table must enforce a unique key on (%s).', $table, implode(', ', $uniqueColumns))
            );
        }

        $hasOwnerForeignKey = collect(Schema::getForeignKeys($table))->contains(
            static fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_id']
                && $foreignKey['foreign_table'] === 'users'
                && $foreignKey['foreign_columns'] === ['id']
                && strtolower((string) ($foreignKey['on_delete'] ?? '')) === 'cascade',
        );

        if (! $hasOwnerForeignKey) {
            throw new RuntimeException(
                sprintf('Existing %s table must cascade its user_id foreign key to users.id.', $table)
            );
        }
    }

    private function assertColumns(string $table, array $required): void
    {
        $actual = Schema::getColumnListing($table);
        $missing = array_values(array_diff($required, $actual));

        if ($missing !== []) {
            throw new RuntimeException(
                sprintf('Existing %s table is missing required columns: %s', $table, implode(', ', $missing))
            );
        }
    }

    private function backfillProfilesAndPreferences(): void
    {
        DB::table('users')
            ->select(['id', 'profile_id', 'photo_url', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $profiles = [];
                $preferences = [];

                foreach ($users as $user) {
                    $createdAt = $user->created_at ?? now();
                    $updatedAt = $user->updated_at ?? $createdAt;

                    $profiles[] = [
                        'user_id' => (int) $user->id,
                        'profile_id' => (int) ($user->profile_id ?? 0),
                        'photo_url' => $user->photo_url,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ];

                    $preferences[] = [
                        'user_id' => (int) $user->id,
                        'locale' => null,
                        'timezone' => 'UTC',
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ];
                }

                if ($profiles !== []) {
                    DB::table('user_profiles')->insertOrIgnore($profiles);
                    DB::table('user_preferences')->insertOrIgnore($preferences);
                }
            });
    }

    private function backfillKnownDevices(): void
    {
        if (! Schema::hasTable('personal_access_tokens')
            || ! Schema::hasColumn('personal_access_tokens', 'device_id')) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereNotNull('device_id')
            ->where('device_id', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($tokens): void {
                $userIds = $tokens->pluck('tokenable_id')->map(static fn ($id): int => (int) $id)->unique()->all();
                $existingUsers = array_fill_keys(
                    DB::table('users')->whereIn('id', $userIds)->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                    true,
                );
                $grouped = [];

                foreach ($tokens as $token) {
                    $userId = (int) $token->tokenable_id;
                    $deviceId = trim((string) $token->device_id);
                    if (! isset($existingUsers[$userId]) || $deviceId === '') {
                        continue;
                    }

                    $createdAt = (string) ($token->created_at ?: now()->toDateTimeString());
                    $lastUsedAt = (string) ($token->last_used_at ?: $createdAt);
                    $lastSeenAt = strtotime($lastUsedAt) > strtotime($createdAt) ? $lastUsedAt : $createdAt;
                    $key = $userId.':'.$deviceId;

                    if (! isset($grouped[$key])) {
                        $grouped[$key] = [
                            'user_id' => $userId,
                            'device_id' => $deviceId,
                            'device_name' => $token->device_name,
                            'platform' => $token->platform,
                            'first_seen_at' => $createdAt,
                            'last_seen_at' => $lastSeenAt,
                            'created_at' => now(),
                            'updated_at' => now(),
                            '_latest_token_created_at' => $createdAt,
                        ];
                        continue;
                    }

                    if (strtotime($createdAt) < strtotime($grouped[$key]['first_seen_at'])) {
                        $grouped[$key]['first_seen_at'] = $createdAt;
                    }
                    if (strtotime($lastSeenAt) > strtotime($grouped[$key]['last_seen_at'])) {
                        $grouped[$key]['last_seen_at'] = $lastSeenAt;
                    }
                    if (strtotime($createdAt) >= strtotime($grouped[$key]['_latest_token_created_at'])) {
                        $grouped[$key]['device_name'] = $token->device_name;
                        $grouped[$key]['platform'] = $token->platform;
                        $grouped[$key]['_latest_token_created_at'] = $createdAt;
                    }
                }

                $rows = array_values($grouped);
                foreach ($rows as &$row) {
                    unset($row['_latest_token_created_at']);
                }
                unset($row);

                if ($rows !== []) {
                    DB::table('user_devices')->upsert(
                        $rows,
                        ['user_id', 'device_id'],
                        ['device_name', 'platform', 'last_seen_at', 'updated_at'],
                    );
                }
            });
    }

    public function down(): void
    {
        // Forward-only by design. Dropping these tables would erase account
        // preferences, profile data or device history during rollback.
    }
};
