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
$activeSheet->setTitle("Staff Attendance");

$ar_fields = array("sn","name");


$ar_names = array("SN","Staff Name");

$ar_width = array("10","30");


foreach ($dates as $date) {
    array_push($ar_names,$date['date_show']);
    array_push($ar_width,10);
    array_push($ar_fields,$date['date']);
}

array_push($ar_names,"Total Present","Total Absent","Total Leave","Weekly Off","Total Cancel","Overtime");
array_push($ar_width,20,20,20,20,20,20,20);
array_push($ar_fields,'present','absent','leave','weeklyoff','cancel','total_overtime');



$row = 2;
$i = 0;
$spreadsheet->getActiveSheet()->setCellValue('A1',"Staff Attendance From ".date('d-m-Y',strtotime($start_date)).' To '.date('d-m-Y',strtotime($end_date)).' in '.$site_name->site_name);
$spreadsheet->getActiveSheet()->getStyle("A1:D1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('DFDBDA');

foreach ($ar_names as $index => $ar) {
    $cell_val = Utilities::getNameFromNumber($i);
    $spreadsheet->getActiveSheet()->setCellValue($cell_val .$row, $ar);
    $spreadsheet->getActiveSheet()->getColumnDimension($cell_val)->setWidth($ar_width[$index]);
    $i++;
}

$max_col = Utilities::getNameFromNumber($i-1);
$spreadsheet->getActiveSheet()->getStyle('A2:'.$max_col.'2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('b3caf2');
$spreadsheet->getActiveSheet()->getStyle('A2:'.$max_col.'2')->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

$count = 1;
$row = 3;
foreach ($staffMembers as $member) {
    $i = 0;

    foreach ($ar_fields as $ar) {
        $var = '';
        $cell = $i;
        $cell_val = Utilities::getNameFromNumber($cell);

        if($ar == 'sn'){
            $var = $count++;
        }elseif ($ar == 'name') {
            $var = (isset($member->$ar))?$member->$ar:'';
        }elseif ($ar == 'present') {
            $var = (isset($member->$ar))?sizeof($member->$ar):'';
        }elseif ($ar == 'absent') {
            $var = (isset($member->$ar))?sizeof($member->$ar):'';
        }elseif ($ar == 'leave') {
            $var = (isset($member->$ar))?sizeof($member->$ar):'';
        }elseif ($ar == 'cancel') {
            $var = (isset($member->$ar))?sizeof($member->$ar):'';
        }elseif ($ar == 'weeklyoff') {
            $var = (isset($member->$ar))?sizeof($member->$ar):'';
        }elseif ($ar == 'extra_class') {
            $var = (isset($member->$ar))?sizeof($member->$ar):'';
        }elseif ($ar == 'total_overtime') {
            $var = (isset($member->$ar))? $member->$ar :'';
        }else {

            if(in_array($ar, $member->absent)){
                $var = "A";
            } elseif (in_array($ar, $member->present)) {
                 $var = "P";
            } elseif (in_array($ar, $member->leave)) {
                 $var = "L";
            } elseif (in_array($ar, $member->cancel)) {
                 $var = "C";
            } elseif (in_array($ar, $member->weeklyoff)) {
                 $var = "W";
            } elseif (in_array($ar, $holidays)) {
                if($var != "P")
                 $var = "H";
            } else {
                $var = "";
            }
        }

        $i++;

        $spreadsheet->getActiveSheet()-> setCellValue($cell_val . $row, $var);

    }

    $row++;
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));




// check in
$spreadsheet->createSheet();
$spreadsheet->setActiveSheetIndex(1);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("Staff Check In Data");

$ar_fields = array("sn","userName","date","site_name","attendance","created_at");
$ar_names = array("SN","Name","Date","Site","Coach Attendance Status","Check In");
$ar_width = array("10","20","30","15","15","20");

$spreadsheet->getActiveSheet()->setCellValue('A1',"Check In Attendance From ".date('d-m-Y',strtotime($start_date)).' To '.date('d-m-Y',strtotime($end_date)).' in '.$site_name->site_name);
$spreadsheet->getActiveSheet()->getStyle("A1:D1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('DFDBDA');

$row = 2;
$i = 0;
foreach ($ar_names as $index => $ar) {
    $cell_val = Utilities::getNameFromNumber($i);
    $spreadsheet->getActiveSheet()->setCellValue($cell_val .$row, $ar);
    $spreadsheet->getActiveSheet()->getColumnDimension($cell_val)->setWidth($ar_width[$index]);
    $i++;
}

$max_col = Utilities::getNameFromNumber($i-1);
$spreadsheet->getActiveSheet()->getStyle('A2:'.$max_col.'2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('b3caf2');
$spreadsheet->getActiveSheet()->getStyle('A2:'.$max_col.'2')->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

$count = 1;
$row = 3;
foreach ($staffCheckins as $checkin) {
    $i = 0;
        foreach ($ar_fields as $ar) {
        $var = '';
        $cell = $i;
        $cell_val = Utilities::getNameFromNumber($cell);

        if($ar == 'sn'){
            $var = $count++;
        }elseif ($ar == 'date') {
            $var = (isset($checkin->$ar))?date("m-d-Y",strtotime($checkin->$ar)):'';
        }elseif ($ar == 'attendance') {
            $var = (isset($checkin->$ar))?$checkin->$ar == 1 ? "P" : "A":'';
        }else {
            $var = (isset($checkin->$ar))?$checkin->$ar:'';
        }

        $i++;

        $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

    }

    $row++;
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


$spreadsheet->setActiveSheetIndex(0);

$filename = "Staff_Attendance_".strtotime("now").'.xlsx';
$writer = new Xlsx($spreadsheet);
$path = "temp/";
$writer->save($path.$filename);

