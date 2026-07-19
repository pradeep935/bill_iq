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
$activeSheet->setTitle("Expense Details");

$ar_fields = array("sn","type","purpose","total_cost","status_name","show_date","display_start_date","display_end_date");
$ar_names = array("SN","Type","Purpose","Total Cost (Rs)","Status","Create Date","Start Date","End Date");
$ar_width = array("10","20","45","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20");

$row = 1;
$i = 0;
$max_item = 0;
$current_item = 0;
foreach ($ar_names as $index => $ar) {
    $cell_val = Utilities::getNameFromNumber($i);
    $spreadsheet->getActiveSheet()->setCellValue($cell_val .$row, $ar);
    $spreadsheet->getActiveSheet()->getColumnDimension($cell_val)->setWidth(isset($ar_width[$index]) ? $ar_width[$index] : 15);
    $i++;
}
$j = $i;

$max_col = $i-1;

$count = 1;
$row = 2;

foreach ($expenses as $expense) {
    
    $i = 0;

    foreach ($ar_fields as $ar) {
        $var = '';
        $cell = $i;
        $cell_val = Utilities::getNameFromNumber($cell);

        if($ar == 'sn'){
            $var = $count++;
        } elseif($ar == 'type'){
            $var = isset($expense->$ar) ? ($expense->$ar == 1 ? 'Advance' : 'Expense') : 'NIL';
        } else{
            $var = (isset($expense->$ar))?$expense->$ar:'';
        }

        $i++;

        $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

    }
    $current_item = 1;

    if(isset($expense_arr[$expense->id]) && count($expense_arr[$expense->id])>0){

        foreach ($expense_arr[$expense->id] as $expense_arr_data){
            if($current_item > $max_item){
                $max_item++;
                $cell_val = Utilities::getNameFromNumber($j);
                $spreadsheet->getActiveSheet()->setCellValue($cell_val.'1', 'Expense Type');
                $spreadsheet->getActiveSheet()->getColumnDimension($cell_val)->setWidth(isset($ar_width[1]) ? $ar_width[1] : 15);
                $j++;
                $cell_val = Utilities::getNameFromNumber($j);
                $spreadsheet->getActiveSheet()->setCellValue($cell_val.'1', 'Amount');
                $spreadsheet->getActiveSheet()->getColumnDimension($cell_val)->setWidth(isset($ar_width[1]) ? $ar_width[1] : 15);
                $j++;
                $cell_val = Utilities::getNameFromNumber($j);
                $spreadsheet->getActiveSheet()->setCellValue($cell_val.'1', 'Attachment');
                $spreadsheet->getActiveSheet()->getColumnDimension($cell_val)->setWidth(isset($ar_width[1]) ? $ar_width[1] : 15);
                $j++;
            }
            $cell_val = Utilities::getNameFromNumber($i);
            $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, isset($expense_arr_data->expense_name) ? $expense_arr_data->expense_name : 'NIL');
            $i++;
            $cell_val = Utilities::getNameFromNumber($i);
            $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, isset($expense_arr_data->amount) ? $expense_arr_data->amount : 0);
            $i++;
            $cell_val = Utilities::getNameFromNumber($i);
            $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, isset($expense_arr_data->attachment) ? 'Yes' : 'No');
            $i++;
            $current_item++;
        }
    }


    $row++;
}

$max_col = $max_col + ($max_item*3);
$max_col = Utilities::getNameFromNumber($max_col);
$spreadsheet->getActiveSheet()->getStyle('A1:'.$max_col.'1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('b3caf2');
$spreadsheet->getActiveSheet()->getStyle('A1:'.$max_col.'1')->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


$filename = "Expense_Details_".strtotime("now").'.xlsx';
$writer = new Xlsx($spreadsheet);

$path = "temp/";
$writer->save($path.$filename);

$data['export'] = $path.$filename;