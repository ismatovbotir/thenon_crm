<?php

namespace App\Services\Reports;

use RuntimeException;
use ZipArchive;

/**
 * Minimal dependency-free .xlsx writer (text and numbers, bold header row).
 */
class XlsxWriter
{
    /** @var list<array{name: string, rows: list<list<scalar|null>>}> */
    private array $sheets = [];

    /**
     * @param  list<list<scalar|null>>  $rows  The first row is treated as the header.
     */
    public function addSheet(string $name, array $rows): static
    {
        $clean = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name) ?? '');

        $this->sheets[] = ['name' => mb_substr($clean !== '' ? $clean : 'Sheet', 0, 31), 'rows' => $rows];

        return $this;
    }

    /**
     * Build the workbook and return the path of a temporary file (caller deletes it).
     */
    public function build(): string
    {
        if ($this->sheets === []) {
            $this->addSheet('Sheet1', []);
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the spreadsheet file.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->sheetXml($sheet['rows']));
        }

        $zip->close();

        return $path;
    }

    private function header(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
    }

    private function contentTypes(): string
    {
        $sheets = '';

        foreach ($this->sheets as $index => $_) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $this->header().'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets.'</Types>';
    }

    private function rootRels(): string
    {
        return $this->header().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';

        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="'.$this->escape($sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return $this->header().'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $relations = '';

        foreach ($this->sheets as $index => $_) {
            $id = $index + 1;
            $relations .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
        }

        $styles = '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return $this->header().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.$styles.'</Relationships>';
    }

    private function styles(): string
    {
        return $this->header().'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>';
    }

    /**
     * @param  list<list<scalar|null>>  $rows
     */
    private function sheetXml(array $rows): string
    {
        $xml = '';

        foreach ($rows as $rowIndex => $row) {
            $cells = '';

            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->column($columnIndex).($rowIndex + 1);
                $style = $rowIndex === 0 ? ' s="1"' : '';

                if ($value === null || $value === '') {
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';
                } else {
                    $cells .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>';
                }
            }

            $xml .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }

        return $this->header().'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>';
    }

    private function column(int $index): string
    {
        $name = '';

        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + ($number - 1) % 26).$name;
        }

        return $name;
    }

    private function escape(string $value): string
    {
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
