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
ini_set('MAX_EXECUTION_TIME', '-1');

$spreadsheet = new Spreadsheet();  /*----Spreadsheet object-----*/
$spreadsheet->setActiveSheetIndex(0);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("Current Stocks");

$ar_fields = array("sn","category_name","item_name","quantity_rec","quantity");
$ar_names = array("SN"," Category Name","Item Name","Quantity (To be rec. from vendor)","Quantity");
$ar_width = array("10","35","45","35","15");

$row = 1;
$i = 0;
foreach ($ar_names as $index => $ar) {
    $cell_val = Utilities::getNameFromNumber($i);
    $spreadsheet->getActiveSheet()->setCellValue($cell_val .$row, $ar);
    $spreadsheet->getActiveSheet()->getColumnDimension($cell_val)->setWidth(isset($ar_width[$index]) ? $ar_width[$index] : 15);
    $i++;
}

$max_col = Utilities::getNameFromNumber($i-1);
$spreadsheet->getActiveSheet()->getStyle('A1:'.$max_col.'1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('b3caf2');
$spreadsheet->getActiveSheet()->getStyle('A1:'.$max_col.'1')->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

$count = 1;
$row = 2;

foreach ($stocks as $stock) {
    
    $i = 0;

    foreach ($ar_fields as $ar) {
        $var = '';
        $cell = $i;
        $cell_val = Utilities::getNameFromNumber($cell);

        if($ar == 'sn'){
            $var = $count++;
        } else{
            $var = (isset($stock->$ar))?$stock->$ar:'';
        }

        $i++;

        $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

    }

    $row++;
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


$filename = "Current_stocks".strtotime("now").'.xlsx';
$writer = new Xlsx($spreadsheet);

$path = "temp/";
$writer->save($path.$filename);

