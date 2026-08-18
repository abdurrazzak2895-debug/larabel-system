<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CurrencyDisplayTest extends TestCase
{
    public function test_portal_templates_use_bdt_instead_of_sar(): void
    {
        $viewRoot = resource_path('views');
        $bladeFiles = File::allFiles($viewRoot);
        $currencyTemplates = [];

        foreach ($bladeFiles as $bladeFile) {
            $contents = $bladeFile->getContents();

            if (stripos($contents, 'BDT') !== false || stripos($contents, 'SAR') !== false) {
                $currencyTemplates[] = $bladeFile->getPathname();
            }
        }

        $this->assertNotEmpty($currencyTemplates, 'Expected portal currency templates to be present.');

        foreach ($currencyTemplates as $template) {
            $this->assertStringNotContainsString(
                'SAR',
                File::get($template),
                "The legacy SAR label remains in {$template}."
            );
        }
    }
}
