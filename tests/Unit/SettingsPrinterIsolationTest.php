<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SettingsPrinterIsolationTest extends TestCase
{
    public function test_shared_settings_accept_omitted_device_fields_without_inserting_defaults(): void
    {
        $request = $this->getMockBuilder(Request::class)->addMethods(['validate'])->getMock();
        $request->expects($this->once())->method('validate')->willReturnCallback(function (array $rules) {
            $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
            $data = $factory->make(['shop_name' => 'Shop', 'language' => 'en', 'receipt_footer' => 'Shared wording'], $rules)->validate();
            $this->assertArrayNotHasKey('receipt_paper_size', $data);
            $this->assertArrayNotHasKey('receipt_header_alignment', $data);
            $this->assertArrayNotHasKey('printer_name', $data);
            $this->assertSame('Shared wording', $data['receipt_footer']);
            throw new RuntimeException('Validated before database access');
        });
        $this->expectExceptionMessage('Validated before database access');
        (new SettingsController())->update($request);
    }
}
