<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReceivingReportsExport implements FromCollection, WithHeadings, WithMapping, WithTitle, WithStyles, ShouldAutoSize
{
    protected $reports;

    public function __construct($reports)
    {
        $this->reports = $reports;
    }

    /**
     * Return the collection of data
     */
    public function collection()
    {
        $data = collect();

        foreach ($this->reports as $report) {
            foreach ($report->items as $item) {
                $data->push([
                    'report' => $report,
                    'item' => $item,
                ]);
            }
        }

        return $data;
    }

    /**
     * Map the data for each row
     */
    public function map($row): array
    {
        $report = $row['report'];
        $item = $row['item'];

        return [
            $report->report_number,
            $report->created_at->format('Y-m-d H:i:s'),
            $report->user->name ?? 'N/A',
            $report->status,
            $item->product->sku ?? 'N/A',
            $item->product->name ?? 'N/A',
            $item->quantity,
            number_format($item->unit_cost, 2),
            number_format($item->total_cost, 2),
            $item->notes ?? '',
            $report->notes ?? '',
            $report->confirmed_at ? $report->confirmed_at->format('Y-m-d H:i:s') : 'N/A',
            $report->confirmedBy->name ?? 'N/A',
        ];
    }

    /**
     * Define the headings
     */
    public function headings(): array
    {
        return [
            'Report Number',
            'Date Created',
            'Created By',
            'Status',
            'Product SKU',
            'Product Name',
            'Quantity',
            'Unit Cost',
            'Total Cost',
            'Item Notes',
            'Report Notes',
            'Confirmed At',
            'Confirmed By',
        ];
    }

    /**
     * Set the sheet title
     */
    public function title(): string
    {
        return 'Receiving Reports';
    }

    /**
     * Style the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold
            1 => ['font' => ['bold' => true]],
        ];
    }
}
