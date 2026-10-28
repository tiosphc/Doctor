<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PharData;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use RecursiveIteratorIterator;
use Throwable;

class DealerPriceImportParser
{
    /** @return list<array<string, mixed>> */
    public function paste(string $source): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $source) as $index => $line) {
            if (trim($line) !== '') {
                $rows[] = $this->row($index + 1, preg_split('/\s+/u', trim($line)));
            }
        }

        return $this->limit($rows);
    }

    /** @return list<array<string, mixed>> */
    public function file(UploadedFile $file): array
    {
        if ($file->getSize() > 5 * 1024 * 1024) {
            $this->fail('File không được lớn hơn 5 MB.');
        }

        return match (strtolower($file->getClientOriginalExtension())) {
            'csv' => $this->csv($file),
            'xls' => $this->xls($file),
            'xlsx' => $this->xlsx($file),
            default => $this->fail('Chỉ hỗ trợ file CSV, XLS hoặc XLSX.'),
        };
    }

    /** @return list<array<string, mixed>> */
    private function xls(UploadedFile $file): array
    {
        try {
            $spreadsheet = (new Xls)->setReadEmptyCells(false)->load($file->getRealPath());
        } catch (Throwable) {
            $this->fail('File XLS không hợp lệ.');
        }

        try {
            $sheet = $spreadsheet->getSheet(0);
            $rows = [];
            $header = false;
            foreach ($sheet->getRowIterator() as $sheetRow) {
                $values = ['', '', '', ''];
                $outside = false;
                foreach ($sheetRow->getCellIterator(iterateOnlyExistingCells: true) as $cell) {
                    if ($cell->isFormula()) {
                        $this->fail('File XLS không được chứa công thức.');
                    }
                    $index = Coordinate::columnIndexFromString($cell->getColumn()) - 1;
                    $value = trim($cell->getFormattedValue());
                    if ($index < 4) {
                        $values[$index] = $value;
                    } elseif ($value !== '') {
                        $outside = true;
                    }
                }
                if ($this->blank($values) && ! $outside) {
                    continue;
                }
                if (! $header) {
                    $this->assertHeader($values);
                    if ($outside) {
                        $this->fail('File cần đúng bốn cột Tier, SKU, MOQ, Price.');
                    }
                    $header = true;

                    continue;
                }
                $row = $this->row($sheetRow->getRowIndex(), $values);
                if ($outside) {
                    $row['format_error'] = 'Dòng có dữ liệu ngoài bốn cột.';
                }
                $rows[] = $row;
                $this->limitCount($rows);
            }

            return $this->limit($rows);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @return list<array<string, mixed>> */
    private function csv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            $this->fail('Không đọc được file CSV.');
        }
        try {
            $rows = [];
            $header = false;
            for ($line = 1; ($values = fgetcsv($handle)) !== false; $line++) {
                if ($line === 1) {
                    $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0] ?? '');
                }
                if ($this->blank($values)) {
                    continue;
                }
                if (! $header) {
                    $this->assertHeader($values);
                    $header = true;

                    continue;
                }
                $rows[] = $this->row($line, $values);
                $this->limitCount($rows);
            }

            return $this->limit($rows);
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array<string, mixed>> */
    private function xlsx(UploadedFile $file): array
    {
        $path = $file->storeAs('dealer-price-import-tmp', Str::uuid().'.zip', 'local');
        if ($path === false) {
            $this->fail('Không thể đọc file XLSX.');
        }
        try {
            try {
                $zip = new PharData(Storage::disk('local')->path($path));
            } catch (Throwable) {
                $this->fail('File XLSX không hợp lệ.');
            }
            $bytes = 0;
            $entries = 0;
            foreach (new RecursiveIteratorIterator($zip) as $entry) {
                $name = str_replace('\\', '/', $entry->getPathname());
                if (++$entries > 150 || str_contains($name, '../') || preg_match('~(?:^|/)(?:vbaProject\.bin|externalLinks)(?:/|$)~i', $name)) {
                    $this->fail('File XLSX chứa nội dung không được hỗ trợ.');
                }
                if (($bytes += $entry->getSize()) > 10 * 1024 * 1024) {
                    $this->fail('File XLSX quá lớn sau khi giải nén.');
                }
            }
            $book = new DOMXPath($this->xml($zip, 'xl/workbook.xml'));
            $rels = new DOMXPath($this->xml($zip, 'xl/_rels/workbook.xml.rels'));
            $sheetNode = $book->query('//*[local-name()="sheet"]')->item(0);
            $relationId = $sheetNode?->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = null;
            foreach ($rels->query('//*[local-name()="Relationship"]') as $relation) {
                if (strtolower($relation->getAttribute('TargetMode')) === 'external') {
                    $this->fail('File XLSX chứa liên kết ngoài.');
                }
                if ($relation->getAttribute('Id') === $relationId) {
                    $target = $relation->getAttribute('Target');
                }
            }
            if (! $target || str_contains($target, '..') || preg_match('/^[a-z]+:/i', $target)) {
                $this->fail('Không tìm thấy trang tính đầu tiên.');
            }
            $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.ltrim($target, '/');
            $sheet = new DOMXPath($this->xml($zip, str_replace('xl/xl/', 'xl/', $sheetPath)));
            $strings = [];
            if (isset($zip['xl/sharedStrings.xml'])) {
                $shared = new DOMXPath($this->xml($zip, 'xl/sharedStrings.xml'));
                foreach ($shared->query('//*[local-name()="si"]') as $item) {
                    $strings[] = $shared->evaluate('string(.)', $item);
                }
            }
            $rows = [];
            $header = false;
            foreach ($sheet->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $sheetRow) {
                $line = (int) $sheetRow->getAttribute('r');
                $values = ['', '', '', ''];
                $outside = false;
                foreach ($sheet->query('./*[local-name()="c"]', $sheetRow) as $cell) {
                    if ($sheet->query('./*[local-name()="f"]', $cell)->length) {
                        $this->fail('File XLSX không được chứa công thức.');
                    }
                    preg_match('/^[A-Z]+/', strtoupper($cell->getAttribute('r')), $column);
                    $index = $this->columnIndex($column[0] ?? '');
                    $type = $cell->getAttribute('t');
                    $value = $type === 'inlineStr' ? $sheet->evaluate('string(.)', $cell)
                        : $sheet->evaluate('string(./*[local-name()="v"])', $cell);
                    if ($type === 's') {
                        $value = $strings[(int) $value] ?? '';
                    }
                    if ($type === 'e') {
                        $this->fail('File XLSX chứa ô lỗi.');
                    }
                    if ($index < 4) {
                        $values[$index] = trim($value);
                    } elseif (trim($value) !== '') {
                        $outside = true;
                    }
                }
                if ($this->blank($values) && ! $outside) {
                    continue;
                }
                if (! $header) {
                    $this->assertHeader($values);
                    if ($outside) {
                        $this->fail('File cần đúng bốn cột Tier, SKU, MOQ, Price.');
                    }
                    $header = true;

                    continue;
                }
                $row = $this->row($line, $values);
                if ($outside) {
                    $row['format_error'] = 'Dòng có dữ liệu ngoài bốn cột.';
                }
                $rows[] = $row;
                $this->limitCount($rows);
            }

            return $this->limit($rows);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    private function xml(PharData $zip, string $path): DOMDocument
    {
        if (! isset($zip[$path])) {
            $this->fail('File XLSX thiếu dữ liệu cần thiết.');
        }
        $document = new DOMDocument;
        if (! $document->loadXML($zip[$path]->getContent(), LIBXML_NONET | LIBXML_COMPACT)) {
            $this->fail('File XLSX chứa XML không hợp lệ.');
        }

        return $document;
    }

    private function columnIndex(string $column): int
    {
        if (preg_match('/^[A-Z]{1,2}$/', $column) !== 1) {
            $this->fail('File XLSX chứa cột không hợp lệ.');
        }
        $index = 0;
        foreach (str_split($column) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }

    /** @param list<mixed> $values @return array<string, mixed> */
    private function row(int $line, array $values): array
    {
        $values = array_map(fn ($value): string => trim((string) $value), $values);

        return [
            'line' => $line, 'tier' => $values[0] ?? '', 'sku' => $values[1] ?? '',
            'moq' => $values[2] ?? '', 'price' => $values[3] ?? '',
            'format_error' => count($values) < 4 || collect(array_slice($values, 4))->contains(fn (string $value): bool => $value !== '')
                ? 'Mỗi dòng cần đúng 4 giá trị: Tier SKU MOQ Giá.' : null,
        ];
    }

    /** @param list<mixed> $values */
    private function assertHeader(array $values): void
    {
        if (array_map(fn ($value): string => strtolower(trim((string) $value)), array_slice($values, 0, 4)) !== ['tier', 'sku', 'moq', 'price']
            || collect(array_slice($values, 4))->contains(fn ($value): bool => trim((string) $value) !== '')) {
            $this->fail('File cần đúng bốn cột Tier, SKU, MOQ, Price.');
        }
    }

    /** @param list<mixed> $values */
    private function blank(array $values): bool
    {
        return collect($values)->every(fn ($value): bool => trim((string) $value) === '');
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function limit(array $rows): array
    {
        $this->limitCount($rows);
        if ($rows === []) {
            $this->fail('Không có dòng giá nào để import.');
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function limitCount(array $rows): void
    {
        if (count($rows) > 60) {
            $this->fail('Tối đa 60 dòng giá mỗi lần import.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['import' => $message]);
    }
}
