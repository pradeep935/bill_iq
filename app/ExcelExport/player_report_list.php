<?php

namespace App;

use App\Models\Utilities;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

error_reporting(0);
ini_set('MAX_EXECUTION_TIME', '-1');

$spreadsheet = new Spreadsheet;
$spreadsheet->setActiveSheetIndex(0);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle('Player Reports');

$assign_arr = \App\Models\TemplateScore::gradePotentialArr();

$ar_fields = [
    'sn',
    'report_id',
    'name',
    'height',
    'weight',
    'display_potential',
    'display_grade',
    'competition_level_id',
    'total_score',
    'note',
    'created_by',
    'created_at',
];

$ar_names = [
    'SN',
    'Report ID',
    'Player',
    'Height',
    'Weight',
    'Potential',
    'Performance',
    'Competition',
    'Score',
    'Note',
    'Filled By',
    'Created At',
];

$ar_width = [
    '10',
    '20',
    '28',
    '18',
    '18',
    '18',
    '18',
    '18',
    '14',
    '50',
    '24',
    '22',
];

$competition_levels = ['', 'District', 'State', 'National'];

$row = 1;
$i = 0;

foreach ($ar_names as $index => $ar){
    $cell_val = Utilities::getNameFromNumber($i);
    $activeSheet->setCellValue($cell_val . $row, $ar);
    $activeSheet->getColumnDimension($cell_val)->setWidth($ar_width[$index]);
    $i++;
}

$max_col = Utilities::getNameFromNumber($i - 1);
$activeSheet->getStyle('A1:' . $max_col . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('b3caf2');
$activeSheet->getStyle('A1:' . $max_col . '1')->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

$count = 1;
$row = 2;

foreach ($report_list as $item){
    $item->display_grade = $assign_arr['performance'][$item->grade_id] ?? '';
    $item->display_potential = $assign_arr['potential'][$item->potential_id] ?? '';

    $i = 0;

    foreach ($ar_fields as $ar){
        $cell_val = Utilities::getNameFromNumber($i);

        if($ar == 'sn'){
            $var = $count++;
        } elseif($ar == 'report_id'){
            $var = 'PR' . sprintf('%012d', $item->id);
        } elseif($ar == 'created_at'){
            $var = !empty($item->$ar) ? date('d-M-Y H:i', strtotime($item->$ar)) : '';
        } elseif($ar == 'competition_level_id'){
            $var = $item->competition_level_id ? $competition_levels[$item->competition_level_id] : '-';
        } elseif($ar == 'height'){
            $var = $item->height ? $item->height . ' cm' : '-';
        } elseif($ar == 'weight'){
            $var = $item->weight ? $item->weight . ' kg' : '-';
        } else {
            $var = isset($item->$ar) ? $item->$ar : '';
        }

        $activeSheet->setCellValue($cell_val . $row, $var);
        $i++;
    }

    $row++;
}

$activeSheet->getStyle('A2:' . $max_col . ($row - 1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

$spreadsheet->setActiveSheetIndex(0);

$filename = 'Player_Report_List_' . strtotime('now') . '.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save(public_path('temp/' . $filename));

$data['export'] = 'temp/' . $filename;
