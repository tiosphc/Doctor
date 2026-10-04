<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Exceptions\HttpResponseException;
use Phar;
use PharData;
use RecursiveIteratorIterator;
use Throwable;

class DealerOrderImportWorkbook
{
    public const HEADERS = ['SKU', 'Customer Name', 'Phone', 'Email', 'Province / City',
        'District', 'Ward', 'Street', 'Quantity'];

    private const REQUIRED_HEADERS = ['SKU', 'Customer Name', 'Phone', 'Province / City',
        'Ward', 'Street', 'Quantity'];

    public const TEMPLATE_HEADERS = self::HEADERS;

    private const FORBIDDEN = ['order code', 'order ref', 'province code', 'district code', 'ward code',
        'zip code', 'external order ref', 'order_id', 'group_id', 'dealer_id', 'size', 'style',
        'dealer code', 'dealer account id', 'tier', 'tier id', 'unit price',
        'dealer price', 'retail price', 'moq', 'warehouse', 'warehouse id', 'subtotal',
        'grand total', 'currency', 'sales channel', 'order source', 'order status',
        'payment status', 'fulfillment status', 'discount', 'discount amount', 'discount percent',
        'final price', 'final total', 'promotion amount', 'voucher code', 'promotion code'];

    /** @return list<array{row: int, values: array<string, string>}> */
    public function parse(string $path): array
    {
        try {
            $zip = new PharData($path);
        } catch (Throwable) {
            $this->fail('INVALID_XLSX');
        }
        $size = 0;
        $count = 0;
        foreach (new RecursiveIteratorIterator($zip) as $entry) {
            $name = str_replace('\\', '/', $entry->getPathname());
            if (++$count > 150 || str_contains($name, '../')
                || preg_match('~(?:^|/)(?:vbaProject\.bin|externalLinks)(?:/|$)~i', $name)) {
                $this->fail('UNSAFE_XLSX');
            }
            if (($size += $entry->getSize()) > config('dealer_order_import.max_uncompressed_bytes')) {
                $this->fail('XLSX_TOO_LARGE');
            }
        }
        $book = new DOMXPath($this->xml($zip, 'xl/workbook.xml'));
        $relations = new DOMXPath($this->xml($zip, 'xl/_rels/workbook.xml.rels'));
        $relationId = null;
        foreach ($book->query('//*[local-name()="sheet"]') as $sheet) {
            if ($sheet->getAttribute('name') === 'Orders') {
                $relationId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            }
        }
        if (! $relationId) {
            $this->fail('ORDERS_SHEET_MISSING');
        }
        $target = null;
        foreach ($relations->query('//*[local-name()="Relationship"]') as $relationship) {
            if (strtolower($relationship->getAttribute('TargetMode')) === 'external') {
                $this->fail('UNSAFE_XLSX');
            }
            if ($relationship->getAttribute('Id') === $relationId) {
                $target = $relationship->getAttribute('Target');
            }
        }
        if (! $target || str_contains($target, '..') || preg_match('/^[a-z]+:/i', $target)) {
            $this->fail('INVALID_XLSX');
        }
        $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.ltrim($target, '/');
        $sheetPath = str_replace('xl/xl/', 'xl/', $sheetPath);
        $sheet = new DOMXPath($this->xml($zip, $sheetPath));
        $strings = [];
        if (isset($zip['xl/sharedStrings.xml'])) {
            $shared = new DOMXPath($this->xml($zip, 'xl/sharedStrings.xml'));
            foreach ($shared->query('//*[local-name()="si"]') as $item) {
                $strings[] = $this->text($shared, $item);
            }
        }
        $headers = null;
        $rows = [];
        $visited = 0;
        foreach ($sheet->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            if (++$visited > 5000) {
                $this->fail('IMPORT_ROW_LIMIT');
            }
            $number = (int) $row->getAttribute('r');
            $cells = [];
            foreach ($sheet->query('./*[local-name()="c"]', $row) as $cell) {
                preg_match('/^[A-Z]+/', strtoupper($cell->getAttribute('r')), $column);
                if ($column === []) {
                    $this->fail('INVALID_XLSX');
                }
                if ($sheet->query('./*[local-name()="f"]', $cell)->length) {
                    $this->fail('FORMULA_NOT_ALLOWED', ['row' => $number, 'column' => $column[0]]);
                }
                $type = $cell->getAttribute('t');
                $value = $type === 'inlineStr' ? $this->text($sheet, $cell)
                    : $sheet->evaluate('string(./*[local-name()="v"])', $cell);
                if ($type === 's') {
                    $value = $strings[(int) $value] ?? null;
                }
                if ($value === null || $type === 'e') {
                    $this->fail('INVALID_XLSX', ['row' => $number, 'column' => $column[0]]);
                }
                $cells[$this->columnIndex($column[0])] = ['value' => trim($value), 'type' => $type];
            }
            if ($cells === [] || collect($cells)->every(fn (array $cell): bool => $cell['value'] === '')) {
                continue;
            }
            if ($headers === null) {
                $headers = $this->headers($cells);

                continue;
            }
            if (count($rows) >= config('dealer_order_import.max_rows')) {
                $this->fail('IMPORT_ROW_LIMIT');
            }
            $values = [];
            foreach ($headers as $index => $header) {
                $cell = $cells[$index] ?? ['value' => '', 'type' => 'inlineStr'];
                if (in_array($header, ['SKU', 'Phone'], true)
                    && ! in_array($cell['type'], ['s', 'inlineStr', 'str'], true) && $cell['value'] !== '') {
                    $this->fail('TEXT_CELL_REQUIRED', ['row' => $number, 'field' => $header]);
                }
                $values[$header] = $cell['value'];
            }
            $rows[] = ['row' => $number, 'values' => $values];
        }
        if ($headers === null) {
            $this->fail('MISSING_REQUIRED_COLUMN');
        }

        return $rows;
    }

    public function template(string $path): void
    {
        $zip = new PharData($path, 0, null, Phar::ZIP);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Orders" sheetId="1" r:id="rId1"/>'
            .'<sheet name="Instructions" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet([self::TEMPLATE_HEADERS], true));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->worksheet([
            ['Điền một dòng cho mỗi SKU. Cùng số điện thoại và địa chỉ được gộp thành một đơn.'],
            ['Nhập tên tỉnh/thành phố và phường/xã như trong địa chỉ giao hàng. Quận/huyện có thể để trống.'],
            ['Giữ số điện thoại và SKU ở dạng Text để không mất số 0 đầu.'],
            ['Không thêm mã đơn, mã địa chỉ, giá, kho hay tổng tiền. Mã đơn được hệ thống tạo.'],
            ['Sửa file rồi tải lại nếu báo lỗi. Xem trước không giữ tồn kho.'],
        ]));
    }

    /** @param array<int, array<int, string>> $rows */
    private function worksheet(array $rows, bool $orders = false): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($orders) {
            $xml .= '<cols><col min="1" max="1" style="1" width="22" customWidth="1"/>'
                .'<col min="3" max="3" style="1" width="20" customWidth="1"/></cols>';
        }
        $xml .= '<sheetData>';
        foreach ($rows as $rowIndex => $values) {
            $xml .= '<row r="'.($rowIndex + 1).'">';
            foreach ($values as $index => $value) {
                $xml .= '<c r="'.$this->columnName($index + 1).'" t="inlineStr"><is><t>'
                    .htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    /** @param array<int, array{value: string, type: string}> $cells
     * @return array<int, string>
     */
    private function headers(array $cells): array
    {
        $headers = [];
        foreach ($cells as $index => $cell) {
            $value = trim($cell['value']);
            if (in_array(strtolower(preg_replace('/\s+/', ' ', $value)), self::FORBIDDEN, true)) {
                $this->fail('FORBIDDEN_IMPORT_COLUMN', ['field' => $value]);
            }
            if (! in_array($value, self::HEADERS, true)) {
                $this->fail('UNKNOWN_COLUMN', ['field' => $value]);
            }
            if (in_array($value, $headers, true)) {
                $this->fail('DUPLICATE_COLUMN', ['field' => $value]);
            }
            $headers[$index] = $value;
        }
        foreach (self::REQUIRED_HEADERS as $header) {
            if (! in_array($header, $headers, true)) {
                $this->fail('MISSING_REQUIRED_COLUMN', ['field' => $header]);
            }
        }

        return $headers;
    }

    private function xml(PharData $zip, string $name): DOMDocument
    {
        if (! isset($zip[$name]) || $zip[$name]->getSize() > 20 * 1024 * 1024) {
            $this->fail('INVALID_XLSX');
        }
        $source = $zip[$name]->getContent();
        if (stripos($source, '<!DOCTYPE') !== false) {
            $this->fail('UNSAFE_XLSX');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($source, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->fail('INVALID_XLSX');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function text(DOMXPath $query, DOMElement $element): string
    {
        $value = '';
        foreach ($query->query('.//*[local-name()="t"]', $element) as $text) {
            $value .= $text->textContent;
        }

        return $value;
    }

    private function columnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split($column) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /** @param array<string, mixed> $details */
    private function fail(string $code, array $details = []): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code, ...$details], 409));
    }
}
