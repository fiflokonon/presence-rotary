<?php

namespace App\Exports;

use App\Models\Position;
use App\Models\Title;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MembersImportTemplateExport implements FromArray, WithEvents, WithHeadings
{
    private const MAX_ROWS = 500;

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'];
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $this->applyDropdown($sheet, 'F', Title::where('is_active', true)->orderBy('name')->pluck('name')->all());
                $this->applyDropdown($sheet, 'G', Position::where('is_active', true)->orderBy('name')->pluck('name')->all());
            },
        ];
    }

    /**
     * @param  array<int, string>  $options
     */
    private function applyDropdown(Worksheet $sheet, string $column, array $options): void
    {
        if ($options === []) {
            return;
        }

        $list = '"'.implode(',', $options).'"';

        for ($row = 2; $row <= self::MAX_ROWS; $row++) {
            $validation = $sheet->getCell("{$column}{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(true);
            $validation->setShowDropDown(true);
            $validation->setFormula1($list);
        }
    }
}
