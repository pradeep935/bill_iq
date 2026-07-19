<?php

namespace App;

use App\Models\Utilities;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;


error_reporting(0);
ini_set('MAX_EXECUTION_TIME','-1');

$spreadsheet = new Spreadsheet;
$spreadsheet->setActiveSheetIndex(0);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("Expense Reports");

$ar_fields = ['sn','type','purpose','total_cost','status_name','site_name','created_at'];

$ar_names = ['SN','Type','Purpose','Amount','Status','Site Name','Start Date'];

$ar_width = ['10','20','45','20','30','30','30'];

$row = 1;
$i = 0;

foreach($ar_names as $index => $ar){
	$cell_val = Utilities::getNameFromNumber($i);
	$spreadsheet->getActiveSheet()->setCellValue($cell_val .$row, $ar);
	$spreadsheet->getActiveSheet()->getColumnDimension($cell_val)->setWidth($ar_width[$index]);

	$i++;
}

$max_col = Utilities::getNameFromNumber($i-1);
$spreadsheet->getActiveSheet()->getStyle('A1:'.$max_col.'1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('b3caf2');
$spreadsheet->getActiveSheet()->getStyle('A1:'.$max_col.'1')->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

$count = 1;
$row = 2;

foreach ($export_expense_data as $key => $item) {

	// dd($export_expense_data);
	$i = 0;

    foreach ($ar_fields as $ar) {
        $var = '';
        $cell = $i;
        $cell_val = Utilities::getNameFromNumber($cell);

        if($ar == 'sn'){
            $var = $count++;
        }else if($ar == 'type'){
        	if ($item->$ar == 1) {
        		$var = 'Advance';
        	}
        	if ($item->$ar == 2) {
        		$var = 'Expense';
        	} 	
        }else{
        	$var = isset($item->$ar) ? $item->$ar : ''; 	
        }

        $i++;

        $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

    }

    $row++;
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


$spreadsheet->setActiveSheetIndex(0);

$filename = "Expense_req_Export_".strtotime("now").'.xlsx';
$writer = new Xlsx($spreadsheet);

$path = "temp/";
$writer->save($path.$filename);

$data['export'] = $path.$filename;
