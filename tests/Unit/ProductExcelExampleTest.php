<?php

use App\Models\User;
use App\Services\ProductExcelImportService;

it('writes example prices without decimal places or thousands separators', function () {
    $user = Mockery::mock(User::class);
    $user->shouldReceive('hasRole')->with('super_admin')->andReturnTrue();

    $service = Mockery::mock(ProductExcelImportService::class)->makePartial();
    $service->shouldReceive('nextImportCode')->with($user)->andReturn(1);
    $path    = tempnam(sys_get_temp_dir(), 'product-example-');
    $archive = new ZipArchive;

    try {
        $service->writeExample($path, $user);
        expect($archive->open($path))->toBeTrue();

        $styles = simplexml_load_string($archive->getFromName('xl/styles.xml'));
        $sheet  = simplexml_load_string($archive->getFromName('xl/worksheets/sheet1.xml'));
        $sheet->registerXPathNamespace('spreadsheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $priceCells = $sheet->xpath('//spreadsheet:row[@r > 1]/spreadsheet:c[starts-with(@r, "C") or starts-with(@r, "D")]');

        expect($priceCells)->toHaveCount(2000);

        foreach ($priceCells as $cell) {
            $styleIndex = (int) $cell['s'];

            expect((int) $styles->cellXfs->xf[$styleIndex]['numFmtId'])->toBe(1);
        }
    } finally {
        $archive->close();
        unlink($path);
        Mockery::close();
    }
});
