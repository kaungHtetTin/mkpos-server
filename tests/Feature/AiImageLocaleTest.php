<?php

namespace Tests\Feature;

use App\Services\AiImagePolicy;
use App\Support\AiLocale;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiImageLocaleTest extends TestCase
{
    public function test_every_fixed_image_message_has_both_locales_and_preserves_parameters(): void
    {
        $english = require lang_path('en/mkpos_ai.php');
        $myanmar = require lang_path('my/mkpos_ai.php');
        $this->assertSame(array_keys($english), array_keys($myanmar));
        foreach ($english as $key => $value) {
            $this->assertNotEmpty($myanmar[$key]);
            $this->assertMatchesRegularExpression('/\p{Myanmar}/u', $myanmar[$key], $key);
            preg_match_all('/:([a-z_]+)/', $value, $expected);
            preg_match_all('/:([a-z_]+)/', $myanmar[$key], $actual);
            $this->assertSame($expected[1], $actual[1], $key);
        }
        $this->assertStringContainsString('ERR-000123', AiLocale::text('office_submitted', 'my', ['reference' => 'ERR-000123']));
        $this->assertSame($english['unrelated'], AiLocale::text('unrelated', 'unsupported-locale'));
    }

    public function test_receipt_localization_preserves_evidence_and_recipient_checks(): void
    {
        config(['database.connections.image_locale' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('image_locale');
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active');
            $table->string('bank');
            $table->string('account_name');
            $table->string('account_no');
        });
        DB::table('payment_methods')->insert(['is_active' => true, 'bank' => 'Fixture Pay', 'account_name' => 'Fixture Recipient', 'account_no' => '001234']);
        $report = ['kind' => 'receipt', 'summary' => '', 'amount' => '500', 'currency' => 'MMK', 'provider' => 'Fixture Pay',
            'recipient_name' => 'Fixture Recipient', 'recipient_account' => '001234', 'transaction_reference' => '000055', 'paid_at' => '', 'payment_status' => 'pending'];
        $reply = app(AiImagePolicy::class)->reply($report, 'my');
        $this->assertStringContainsString('ငွေလွှဲအမှတ်: 000055', $reply);
        $this->assertStringContainsString('လက်ခံသူအကောင့်: 001234', $reply);
        $this->assertStringContainsString('စောင့်ဆိုင်းဆဲ', $reply);
        $this->assertStringContainsString('အတည်မပြုထားပါ', $reply);
        $this->assertSame('matches_active_recipient', $report['payment_method_check']['status']);
        $this->assertFalse($report['payment_method_check']['funds_verified']);
        $this->assertSame('MMK', $report['currency']);
    }
}
