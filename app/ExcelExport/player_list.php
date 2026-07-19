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
$activeSheet->setTitle('Players');

$ar_fields = [
    'sn',
    'aiff_id',
    'first_name',
    'last_name',
    'name',
    'father_name',
    'dob',
    'gender',
    'height',
    'weight',
    'biotype_name',
    'height_comparative',
    'maturity_rate',
    'email',
    'mobile',
    'nationality',
    'second_nationality',
    'international_player',
    'state',
    'district',
    'city',
    'address',
    'registered_state',
    'locally_developed',
    'studies',
    'on_loan',
    'club_name',
    'tournament',
    'shirt_name',
    // 'contract_expiry_date',
    'foot',
    'first_position',
    'second_position',
    'position_profile',
    // 'market_value',
    // 'annual_salary',
    'notes',
    'created_at',
    'updated_at'
];
$ar_names = [
    'SN',
    'AIFF ID',
    'First Name',
    'Last Name',
    'Full Name',
    'Father Name',
    'Date of Birth',
    'Gender',
    'Height (cm)',
    'Weight (kg)',
    'Biotype',
    'Comparative Height',
    'Maturity Rate',
    'Email',
    'Mobile',
    'Nationality',
    'Second Nationality',
    'International Player',
    'State',
    'District',
    'City',
    'Address',
    'Registered State',
    'Locally Developed',
    'Studies',
    'On Loan',
    'Club',
    'Tournament',
    'Shirt Name',
    // 'Contract Expiry',
    'Preferred Foot',
    'First Position',
    'Second Position',
    'Position Profile',
    // 'Market Value',
    // 'Annual Salary',
    'Notes',
    'Created At',
    'Updated At'
];
$ar_width = [
    '10',
    '18',
    '18',
    '18',
    '24',
    '24',
    '16',
    '12',
    '14',
    '14',
    '18',
    '18',
    '16',
    '28',
    '18',
    '18',
    '20',
    '18',
    '18',
    '18',
    '18',
    '36',
    '20',
    '18',
    '20',
    '14',
    '24',
    '20',
    '18',
    '18',
    '16',
    '18',
    '18',
    '30',
    '16',
    '16',
    '36',
    '20',
    '20'
];

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

foreach ($export_player_data as $item) {
    $i = 0;

    foreach ($ar_fields as $ar) {
        $cell_val = Utilities::getNameFromNumber($i);

        if ($ar == 'sn') {
            $var = $count++;
        } elseif (in_array($ar, ['international_player', 'locally_developed', 'on_loan'])) {
            $var = isset($item->$ar) ? ((int) $item->$ar === 1 ? 'Yes' : 'No') : 'No';
        } elseif ($ar == 'gender') {
            $var = isset($item->$ar) ? ((int) $item->$ar === 1 ? 'Male' : (((int) $item->$ar === 2) ? 'Female' : '')) : '';
        } elseif (in_array($ar, ['dob', 'contract_expiry_date'])) {
            $var = !empty($item->$ar) ? date('d-M-Y', strtotime($item->$ar)) : '';
        } elseif (in_array($ar, ['created_at', 'updated_at'])) {
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

$filename = 'Player_List_' . strtotime('now') . '.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save(public_path('temp/' . $filename));

$data['export'] = 'temp/' . $filename;
