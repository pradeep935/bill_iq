<?php
namespace App;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
// error_reporting(0);


$styleTextBold = array(
    'font' => array('bold' => true),
);

$spreadsheet = new Spreadsheet();  /*----Spreadsheet object-----*/
$Excel_writer = new Xlsx($spreadsheet);  /*----- Excel (Xlsx) Object*/
$spreadsheet->setActiveSheetIndex(0);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("item-export-format");


$spreadsheet->setActiveSheetIndex(0)->setCellValue('A1', 'Item Export Format');
$spreadsheet->getActiveSheet()->getStyle('A1')
    ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

$spreadsheet->setActiveSheetIndex(0)->setCellValue('A2', 'SN');
$spreadsheet->setActiveSheetIndex(0)->setCellValue('B2', 'Category');
$spreadsheet->setActiveSheetIndex(0)->setCellValue('C2', 'Code');
$spreadsheet->setActiveSheetIndex(0)->setCellValue('D2', 'Item Name');
$spreadsheet->setActiveSheetIndex(0)->setCellValue('E2', 'Unit');
$spreadsheet->setActiveSheetIndex(0)->setCellValue('F2', 'Brand Name');
$spreadsheet->setActiveSheetIndex(0)->setCellValue('G2', 'Price');
$spreadsheet->setActiveSheetIndex(0)->setCellValue('H2', 'GST');

$spreadsheet->getActiveSheet()->getStyle('A2')->applyFromArray($styleTextBold);
$spreadsheet->getActiveSheet()->getStyle('B2')->applyFromArray($styleTextBold);
$spreadsheet->getActiveSheet()->getStyle('C2')->applyFromArray($styleTextBold);
$spreadsheet->getActiveSheet()->getStyle('D2')->applyFromArray($styleTextBold);
$spreadsheet->getActiveSheet()->getStyle('E2')->applyFromArray($styleTextBold);
$spreadsheet->getActiveSheet()->getStyle('F2')->applyFromArray($styleTextBold);
$spreadsheet->getActiveSheet()->getStyle('G2')->applyFromArray($styleTextBold);
$spreadsheet->getActiveSheet()->getStyle('H2')->applyFromArray($styleTextBold);

$spreadsheet->getActiveSheet()->mergeCells('A1:H1');



$filename = 'Item_Export_format';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="'. $filename); 
header('Cache-Control: max-age=0');

$Excel_writer = IOFactory::createWriter($spreadsheet,'Xlsx');
$Excel_writer->save('temp/'.$filename.'.xlsx');

$data["export"] = url('temp/'.$filename.'.xlsx');