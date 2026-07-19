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
$activeSheet->setTitle('Staff Details');
$include_privilege = isset($export_type) && (int) $export_type === 2;

$ar_fields = [
    'sn',
    'name',
    'registration_id',
    'mobile',
    'email',
    'nick_name',
    'father_name',
    'dob',
    'age',
    'current_job',
    'position',
    'facebook',
    'instagram',
    'twitter',
    'linkedin',
    'total_players',
    'total_player_reports',
    'total_team_reports',
    'status',
    'created_at',
];

$ar_names = [
    'SN',
    'Name',
    'Registration ID',
    'Mobile',
    'Email',
    'Nick Name',
    'Father Name',
    'Date of Birth',
    'Age',
    'Current Job',
    'Position',
    'Facebook',
    'Instagram',
    'Twitter',
    'LinkedIn',
    'Total Players',
    'Total Player Reports',
    'Total Team Reports',
    'Status',
    'Created At',
];

$ar_width = [
    '10',
    '24',
    '18',
    '18',
    '28',
    '18',
    '24',
    '16',
    '10',
    '24',
    '18',
    '28',
    '28',
    '28',
    '28',
    '14',
    '18',
    '18',
    '14',
    '20',
];

if ($include_privilege) {
    array_splice($ar_fields, 2, 0, 'privilege');
    array_splice($ar_names, 2, 0, 'Privilege');
    array_splice($ar_width, 2, 0, '20');
}

$row = 1;
$i = 0;

foreach ($ar_names as $index => $ar) {
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

foreach ($export_staff_data as $item) {
    $i = 0;

    foreach ($ar_fields as $ar) {
        $cell_val = Utilities::getNameFromNumber($i);

        if ($ar == 'sn') {
            $var = $count++;
        } elseif ($ar == 'privilege') {
            if (isset($item->privilege) && $item->privilege == 1) {
                $var = 'Super Admin';
            } elseif (isset($item->privilege) && $item->privilege == 2) {
                $var = 'Scouting Managers';
            } elseif (isset($item->privilege) && $item->privilege == 3) {
                $var = 'Regional Scouts';
            } else {
                $var = 'Local Scouts';
            }
        } elseif ($ar == 'status') {
            $var = (isset($item->deleted) && (int) $item->deleted === 0) ? 'Active' : 'Inactive';
        } elseif (in_array($ar, ['dob'])) {
            $var = !empty($item->$ar) ? date('d-M-Y', strtotime($item->$ar)) : '';
        } elseif ($ar == 'created_at') {
            $var = !empty($item->$ar) ? date('d-M-Y H:i', strtotime($item->$ar)) : '';
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

$filename = 'Staff_Details_' . strtotime('now') . '.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save(public_path('temp/' . $filename));
