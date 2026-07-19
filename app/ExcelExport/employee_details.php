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
$activeSheet->setTitle("Employee Details");

$ar_fields = array("sn","employee_id","name","father_name","dob_display","email","mobile","address","aadhar_number","uan_number","pan_number","esi_number","bank_account_name","bank_account_number","bank_name","ifsc_code","designation_name","position_name","department_name","joining_date_display","exit_date_display","employee_type");
$ar_names = array("SN","Employee Id","Name","Father's Name","DoB","Email","Mobile","Address","Aadhar Number","UAN Number","PAN Number","ESI Number","Name (As Per Bank)","Bank Account Number","Bank Name","IFSC Code","Designation","Position","Department","Joining Date","Exit Date","Employee Type");
$ar_width = array("10","20","45","45","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20","20");

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

foreach ($employees as $employee) {
    
    $i = 0;

    foreach ($ar_fields as $ar) {
        $var = '';
        $cell = $i;
        $cell_val = Utilities::getNameFromNumber($cell);

        if($ar == 'sn'){
            $var = $count++;
        } elseif($ar == 'employee_type'){
            $var = isset($employee->$ar) ? ($employee->$ar == 1 ? 'Regular' : 'Contract') : 'NIL';
        } else{
            $var = (isset($employee->$ar))?$employee->$ar:'';
        }

        $i++;

        $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

    }
    $current_item = 1;

    $row++;
}

$max_col = $max_col + ($max_item*3);
$max_col = Utilities::getNameFromNumber($max_col);
$spreadsheet->getActiveSheet()->getStyle('A1:'.$max_col.'1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('b3caf2');
$spreadsheet->getActiveSheet()->getStyle('A1:'.$max_col.'1')->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


$filename = "Employee_Details_".strtotime("now").'.xlsx';
$writer = new Xlsx($spreadsheet);

$path = "temp/";
$writer->save($path.$filename);

$data['export'] = $path.$filename;