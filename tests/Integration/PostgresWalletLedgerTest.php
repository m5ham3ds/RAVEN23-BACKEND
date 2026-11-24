<?php

namespace Tests\Integration;

use App\Exceptions\WalletTransactionConflict;
use App\Models\User;
use App\Models\UserWallet;
use App\Services\Wallet\WalletLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresWalletLedgerTest extends TestCase
{
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $databaseUrl = trim((string) (getenv('NEON_DB_URL') ?: getenv('DB_URL')));
        if ($databaseUrl === '') {
            $this->markTestSkipped('DB_URL is not configured for PostgreSQL integration tests.');
        }

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.driver' => 'pgsql',
            'database.connections.pgsql.url' => $databaseUrl,
            'database.connections.pgsql.host' => null,
            'database.connections.pgsql.port' => null,
            'database.connections.pgsql.database' => null,
            'database.connections.pgsql.username' => null,
            'database.connections.pgsql.password' => null,
            'database.connections.pgsql.sslmode' => 'require',
            'cache.default' => 'array',
            'app.url' => 'http://localhost',
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        DB::connection()->selectOne('select 1');
    }

    private function createAccount(): User
    {
        $suffix = bin2hex(random_bytes(6));
        $user = User::create([
            'name' => 'RAVEN23 PostgreSQL wallet',
            'email' => 'raven-wallet-'.$suffix.'@example.test',
            'username' => 'wallet_pg_'.$suffix,
            'password' => 'Raven-Wallet-Postgres-42!',
        ]);
        $this->createdUserIds[] = (int) $user->getKey();

        return $user;
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    public function test_postgresql_wallet_ledger_is_atomic_idempotent_immutable_and_deletion_safe(): void
    {
        $user = $this->createAccount();
        $ledger = app(WalletLedger::class);

        $first = $ledger->credit(
            $user,
            1500,
            'topup',
            'internal',
            'pg-wallet-key-0001',
            'synthetic-topup-1',
        );
        $duplicate = $ledger->credit(
            $user,
            1500,
            'topup',
            'internal',
            'pg-wallet-key-0001',
            'synthetic-topup-1',
        );

        $this->assertSame($first->getKey(), $duplicate->getKey());
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('user_wallets', [
            'user_id' => $user->getKey(),
            'balance_cents' => 1500,
            'spendable_cents' => 1500,
            'revision' => 1,
        ]);

        $debit = $ledger->debit(
            $user,
            300,
            'plus_period',
            'internal',
            'pg-wallet-key-0002',
            'synthetic-plus-period-1',
            now('UTC'),
            now('UTC')->addDays(31),
        );

        $this->assertSame(-300, (int) $debit->amount_cents);
        $this->assertSame(1200, (int) $debit->balance_after_cents);
        $this->assertDatabaseHas('user_wallets', [
            'user_id' => $user->getKey(),
            'balance_cents' => 1200,
            'spendable_cents' => 1200,
            'revision' => 2,
        ]);

        try {
            DB::table('wallet_transactions')
                ->where('id', $first->getKey())
                ->update(['reference' => 'tampered']);
            $this->fail('PostgreSQL accepted an UPDATE to an append-only wallet transaction.');
        } catch (QueryException) {
            $this->assertDatabaseHas('wallet_transactions', [
                'id' => $first->getKey(),
                'reference' => 'synthetic-topup-1',
            ]);
        }

        try {
            DB::table('wallet_transactions')->where('id', $first->getKey())->delete();
            $this->fail('PostgreSQL accepted a direct DELETE of an append-only wallet transaction.');
        } catch (QueryException) {
            $this->assertDatabaseHas('wallet_transactions', ['id' => $first->getKey()]);
        }

        $walletId = UserWallet::query()->where('user_id', $user->getKey())->value('id');
        $user->delete();

        $this->assertDatabaseMissing('wallet_transactions', ['id' => $first->getKey()]);
        $this->assertDatabaseMissing('wallet_transactions', ['id' => $debit->getKey()]);
        $this->assertDatabaseMissing('user_wallets', ['id' => $walletId]);
    }

    public function test_postgresql_wallet_idempotency_key_cannot_be_rebound_and_balance_cannot_go_negative(): void
    {
        $user = $this->createAccount();
        $ledger = app(WalletLedger::class);

        $ledger->credit($user, 100, 'topup', 'internal', 'pg-wallet-key-0101');

        try {
            $ledger->credit($user, 200, 'topup', 'internal', 'pg-wallet-key-0101');
            $this->fail('A wallet idempotency key was rebound to different content.');
        } catch (WalletTransactionConflict) {
            $this->assertDatabaseHas('user_wallets', [
                'user_id' => $user->getKey(),
                'balance_cents' => 100,
            ]);
        }

        try {
            $ledger->debit($user, 101, 'plus_period', 'internal', 'pg-wallet-key-0102');
            $this->fail('A wallet debit made the spendable balance negative.');
        } catch (\DomainException) {
            $this->assertDatabaseHas('user_wallets', [
                'user_id' => $user->getKey(),
                'balance_cents' => 100,
            ]);
        }
    }
}
