<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Wallet\WalletLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    private function account(string $label): array
    {
        $suffix = bin2hex(random_bytes(5));
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'RAVEN23 Wallet '.$label,
            'email' => 'wallet-'.$suffix.'@example.test',
            'username' => 'wallet_'.$suffix,
            'password' => 'Raven-Wallet-Test-Password-42!',
        ])->assertCreated();

        return [
            'id' => (int) $response->json('user.id'),
            'token' => (string) $response->json('token'),
        ];
    }

    public function test_wallet_read_requires_authentication_and_returns_a_zero_balance_contract(): void
    {
        $this->getJson('/api/v1/wallet')->assertUnauthorized();

        $account = $this->account('zero balance');

        $this->withToken($account['token'])
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('entitlements.ad_free', false)
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('wallet.currency', 'USD')
            ->assertJsonPath('wallet.balance_cents', 0)
            ->assertJsonPath('wallet.spendable_cents', 0)
            ->assertJsonPath('wallet.status', 'active')
            ->assertJsonPath('wallet.frozen', false)
            ->assertJsonPath('wallet.revision', 0)
            ->assertJsonPath('wallet.periods_fundable', 0)
            ->assertJsonPath('pending_topups', []);
    }

    public function test_wallet_history_is_owner_scoped_and_cursor_paginated(): void
    {
        $owner = $this->account('history owner');
        $other = $this->account('other owner');
        $user = User::query()->findOrFail($other['id']);
        $ledger = app(WalletLedger::class);

        for ($index = 1; $index <= 31; $index++) {
            $ledger->credit(
                $user,
                100,
                'topup',
                'internal',
                sprintf('wallet-history-%04d', $index),
                'fixture-'.$index,
            );
        }

        $this->withToken($owner['token'])
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('transactions', [])
            ->assertJsonPath('next_cursor', null);

        Auth::forgetGuards();
        $response = $this->withToken($other['token'])
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk();

        $this->assertCount(30, $response->json('transactions'));
        $this->assertNotNull($response->json('next_cursor'));
        $cursor = $response->json('next_cursor');

        Auth::forgetGuards();
        $second = $this->withToken($other['token'])
            ->getJson('/api/v1/wallet/transactions?cursor='.urlencode($cursor))
            ->assertOk();

        $this->assertCount(1, $second->json('transactions'));
        $firstIds = array_column($response->json('transactions'), 'id');
        $secondIds = array_column($second->json('transactions'), 'id');
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
        $this->assertNull($second->json('next_cursor'));
    }

    public function test_guest_cannot_open_a_prepaid_wallet(): void
    {
        $guest = $this->postJson('/api/v1/auth/guest', [])->assertOk();
        $token = (string) $guest->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/wallet')
            ->assertForbidden()
            ->assertJsonPath('code', 'wallet_unavailable_for_guest');
    }

    public function test_account_deletion_requires_explicit_acknowledgement_of_positive_wallet_credit(): void
    {
        $account = $this->account('delete protection');
        $user = User::query()->findOrFail($account['id']);

        app(WalletLedger::class)->credit(
            $user,
            1250,
            'topup',
            'internal',
            'wallet-delete-fixture-01',
            'synthetic-reference',
        );

        $this->withToken($account['token'])
            ->deleteJson('/api/v1/user/account')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['wallet_credit_remaining']);

        $this->assertDatabaseHas('users', ['id' => $account['id']]);
        $this->assertDatabaseHas('user_wallets', [
            'user_id' => $account['id'],
            'balance_cents' => 1250,
        ]);

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->deleteJson('/api/v1/user/account', ['acknowledge_wallet_credit' => true])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $account['id']]);
        $this->assertDatabaseMissing('user_wallets', ['user_id' => $account['id']]);
        $this->assertDatabaseMissing('wallet_transactions', [
            'idempotency_key' => 'wallet-delete-fixture-01',
        ]);
    }
}
