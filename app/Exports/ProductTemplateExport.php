<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProductTemplateExport implements FromArray, WithHeadings, WithTitle
{
    public function headings(): array
    {
        return [
            'sku',
            'name', 
            'category',
            'type',
            'unit',
            'min_stock',
            'stock',
            'purchase_price',
            'selling_price',
            'description',
            'supplier',
        ];
    }

    public function array(): array
    {
        // Sample data rows
        return [
            ['PRD-001', 'Oli Shell Helix', 'Oli & Pelumas', 'product', 'liter', 5, 50, 55000, 75000, 'Oli mesin berkualitas', 'PT Shell Indonesia'],
            ['SVC-001', 'Ganti Oli', 'Jasa & Service', 'service', 'jasa', 0, 999, 0, 50000, 'Jasa ganti oli mesin', ''],
        ];
    }

    public function title(): string
    {
        return 'Template';
    }
}
