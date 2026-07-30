<?php

use App\Services\Import\SheetData;
use App\Services\Import\SpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * The reader against the files people actually upload — a Spanish Excel export,
 * a latin1 dump from a legacy system, a sheet with a title row above the table.
 * Every one of these produced a plausible-looking wrong result before it was
 * handled, which is the dangerous kind: the import "works" and the data is off.
 */
function readCsv(string $contents): SheetData
{
    return (new SpreadsheetReader)->readString($contents);
}

function writeTempCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('sniffs a semicolon delimiter — how Excel exports in a Spanish locale', function () {
    $sheet = readCsv("Nombre;Precio;Activo\nAcme;1.200,50;sí\nGlobex;900,00;no\n");

    expect($sheet->headers)->toBe(['Nombre', 'Precio', 'Activo'])
        ->and($sheet->rows)->toHaveCount(2)
        ->and($sheet->rows[0])->toBe(['Nombre' => 'Acme', 'Precio' => '1.200,50', 'Activo' => 'sí']);
});

it('sniffs tabs and pipes too', function (string $delimiter) {
    $sheet = readCsv("a{$delimiter}b\n1{$delimiter}2\n");

    expect($sheet->headers)->toBe(['a', 'b'])
        ->and($sheet->rows[0])->toBe(['a' => '1', 'b' => '2']);
})->with(['tab' => ["\t"], 'pipe' => ['|']]);

it('strips the UTF-8 BOM so the first column still matches its field', function () {
    $sheet = readCsv("\xEF\xBB\xBFNombre,Email\nAna,ana@example.com\n");

    // Without the strip this header is "\u{FEFF}Nombre" and maps to nothing.
    expect($sheet->headers[0])->toBe('Nombre');
});

it('converts a latin1 export instead of mangling the accents', function () {
    $latin1 = mb_convert_encoding("Nombre,Ciudad\nJosé,Bogotá\n", 'ISO-8859-1', 'UTF-8');
    $sheet = readCsv($latin1);

    expect($sheet->rows[0]['Nombre'])->toBe('José')
        ->and($sheet->rows[0]['Ciudad'])->toBe('Bogotá');
});

it('leaves a valid UTF-8 file alone rather than double-encoding it', function () {
    $sheet = readCsv("Nombre\nJosé\n");

    expect($sheet->rows[0]['Nombre'])->toBe('José');
});

it('names blank headers and de-duplicates repeated ones', function () {
    $sheet = readCsv("Total,,Total\n1,2,3\n");

    // Two "Total" columns must stay two columns, not collapse into one.
    expect($sheet->headers)->toBe(['Total', 'column_2', 'Total (2)'])
        ->and($sheet->rows[0])->toBe(['Total' => '1', 'column_2' => '2', 'Total (2)' => '3']);
});

it('drops blank rows without counting them as imported', function () {
    $sheet = readCsv("a,b\n1,2\n,\n3,4\n");

    expect($sheet->rows)->toHaveCount(2)
        ->and($sheet->totalRows)->toBe(2);
});

it('reads an empty cell as null, not as an empty string', function () {
    $sheet = readCsv("a,b\n1,\n");

    expect($sheet->rows[0]['b'])->toBeNull();
});

it('reads a real XLSX, resolving serial dates and formulas', function () {
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([
        ['Producto', 'Precio', 'Cantidad', 'Total', 'Fecha'],
        ['Café', 25.5, 4, '=B2*C2', null],
    ]);
    // A real date cell: the value is a serial, the FORMAT is what makes it a date.
    $sheet->setCellValue('E2', Date::PHPToExcel(new DateTime('2026-03-15')));
    $sheet->getStyle('E2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');

    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new XlsxWriter($book))->save($path);

    $data = (new SpreadsheetReader)->readFile($path, 'ventas.xlsx');

    expect($data->format)->toBe('xlsx')
        ->and($data->headers)->toBe(['Producto', 'Precio', 'Cantidad', 'Total', 'Fecha'])
        ->and($data->rows[0]['Producto'])->toBe('Café')
        // The formula contributes its result, not "=B2*C2".
        ->and($data->rows[0]['Total'])->toBe('102')
        // The serial is resolved to a date, not left as 46096.
        ->and($data->rows[0]['Fecha'])->toBe('2026-03-15');

    unlink($path);
});

it('skips a title row above the real table in a spreadsheet', function () {
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([
        [null, null],
        ['Nombre', 'Email'],
        ['Ana', 'ana@example.com'],
    ]);

    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new XlsxWriter($book))->save($path);

    $data = (new SpreadsheetReader)->readFile($path, 'contactos.xlsx');

    expect($data->headers)->toBe(['Nombre', 'Email'])
        ->and($data->rows)->toHaveCount(1);

    unlink($path);
});

it('detects a spreadsheet by its bytes even when it is named .csv', function () {
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['a'], ['1']]);

    // Google Drive hands out XLSX under a .csv name often enough to matter.
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    (new XlsxWriter($book))->save($path);

    expect((new SpreadsheetReader)->readFile($path, 'export.csv')->format)->toBe('xlsx');

    unlink($path);
});

it('refuses a file it cannot read instead of importing nothing quietly', function () {
    expect(fn () => (new SpreadsheetReader)->readFile('/does/not/exist.csv'))
        ->toThrow(RuntimeException::class);
});
