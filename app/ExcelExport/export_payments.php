<?php

namespace App;

use App\Models\Utilities;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use App\Models\User;

error_reporting(0);
ini_set('MAX_EXECUTION_TIME', '-1');

$spreadsheet = new Spreadsheet();
$spreadsheet->setActiveSheetIndex(0);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("Invoices");

$ar_fields = array("sn","student_code","code","invoice_type","name","invoice_number","invoice_date","city_name","center_name","group_name","amount","igst","sgst","cgst","total_amount","balance_amount","p_remark");
$ar_names = array("SN","Student Code","Invoice Code","Invoice Type","Name","Invoice Number","Invoice Date","City","Center","Group","Taxable Amount","IGST","CGST","SGST","Total Amount","Balance Payment","Remarks");
$ar_width = array("10","20","30","15","15","20","10","15","15","15","15","15","15","15","15","15","15","15");

$row = 1;
$i = 0;
foreach ($ar_names as $index => $ar) {
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
foreach ($payments as $payment) {
    if($payment->type == 1){
        $i = 0;

        foreach ($ar_fields as $ar) {
            $var = '';
            $cell = $i;
            $cell_val = Utilities::getNameFromNumber($cell);

            if($ar == 'sn'){
                $var = $count++;
            } else if( in_array($ar, ['sgst','cgst','igst']) ){
                $var = '';

                if($payment->state_id == $gst_info->state_id || !$payment->state_id){
                    if($ar == "cgst" || $ar == "sgst") $var = round($payment->tax/2,1);
                } else {
                    if($ar == "igst") $var = round($payment->tax,1);
                }

            } else{
                $var = (isset($payment->$ar))?$payment->$ar:'';
            }

            $i++;

            $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

        }

        $row++;
    }
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


// creating invoice items
$spreadsheet->createSheet();
$spreadsheet->setActiveSheetIndex(1);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("Invoices Details");

$ar_fields = array("sn","student_code","code","name","invoice_type","invoice_number","invoice_date","category","type_name","hsn_code","city_name","center_name","group_name","start_date","end_date","taxable_amount","igst","sgst","cgst","total_amount","base_price","discount","kit_size","kit_given","kit_given_date","p_remark");
$ar_names = array("SN","Student Code","Invoice Item Code","Student Name","Invoice Type","Invoice Number","Invoice Date","Category","Sub Category","HSN Code","City","Center","Group","Start Date","End Date","Taxable Amount","IGST","SGST","CGST","Total Amount","Base Price","Discount","Kit Size","Kit Given","Kit Given Date","Remarks");
$ar_width = array("10","20","30","15","15","20","10","15","15","15","15","15","15","15","15","15","15","15","15","15","15","15","15","15","15","15","15","15");

if( User::checkTermWiseClient($user->client_id) ){
    $ar_fields[] = "term_name";
    $ar_names[] = "Term";
}

$row = 1;
$i = 0;
foreach ($ar_names as $index => $ar) {
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
foreach ($payment_items as $payment_item) {
    if($payment_item->type == 1){
        $i = 0;

        foreach ($ar_fields as $ar) {
            $var = '';
            $cell = $i;
            $cell_val = Utilities::getNameFromNumber($cell);

            if($ar == 'sn'){
                $var = $count++;
            } else if( in_array( $ar , ["code","name","invoice_number","invoice_date","city_name","center_name","group_name","student_code","p_remark","invoice_type","kit_size"] ) ){
                    
                $var = "";
                $payment = (isset($payment_list[$payment_item->payment_history_id])) ? $payment_list[$payment_item->payment_history_id] : null;
                if($payment){
                    $var = (isset($payment->$ar))?$payment->$ar:'';
                }

            }  else if( in_array($ar, ['sgst','cgst','igst']) ){
                $var = '';

                $payment = (isset($payment_list[$payment_item->payment_history_id])) ? $payment_list[$payment_item->payment_history_id] : null;

                if($payment->state_id == $gst_info->state_id || !$payment->state_id){
                    if($ar == "cgst" || $ar == "sgst") $var = round($payment_item->tax/2,1);
                } else {
                    if($ar == "igst") $var = round($payment_item->tax,1);
                }

            } else if( $ar == 'discount' ){
                    
                $var = "";
                if(!$payment_item->base_price){
                    $var = "0%";
                } else {
                    $var = round(($payment_item->base_price - $payment_item->total_amount)*100/$payment_item->base_price);
                    $var = $var."%";
                }

            }elseif( $ar == 'kit_given'){

                $var = "";
                if($payment_item->kit_given == 1){
                    $var = "Yes";
                }

            }elseif($ar == 'kit_given_date'){
                
                $var = "";
                if($payment_item->kit_given == 1){
                    $var = date('d-m-Y',strtotime($payment_item->kit_date));
                }

            } else{
                $var = (isset($payment_item->$ar))?$payment_item->$ar:'';
            }



            $i++;

            $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

        }

        $row++;
    }
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


// payment details for invoice
$spreadsheet->createSheet();
$spreadsheet->setActiveSheetIndex(2);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("Payments");


$ar_fields = array("sn","student_code","code","name","invoice_number","invoice_date","city_name","center_name","group_name","payment_date","due_date","mode","amount","reference_no");
$ar_names = array("SN","Student Code","Receipt Number","Student Name","Invoice Number","Invoice Date","City","Center","Group","Payment Date","Due Date","Payment Mode","Amount","Reference No.");
$ar_width = array("10","20","30","15","15","20","10","15","15","15","15","15","15","15","15","15","15","15","15");

$row = 1;
$i = 0;
foreach ($ar_names as $index => $ar) {
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
foreach ($payment_logs as $payment_log) {

    if(!$payment_log->name){
        $payment_log->name = $payment_log->o_student_name;
    }

    if($payment_log->type == 1){
        $i = 0;

        foreach ($ar_fields as $ar) {
            $var = '';
            $cell = $i;
            $cell_val = Utilities::getNameFromNumber($cell);

            if($ar == 'sn'){
                $var = $count++;
            } else if($ar == 'code'){
                $var = str_pad($payment_log->id,6,"0",STR_PAD_LEFT);
            } else{
                $var = (isset($payment_log->$ar))?$payment_log->$ar:'';
            }

            $i++;

            $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

        }

        $row++;
    }
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


/*credit notes*/
$spreadsheet->createSheet();
$spreadsheet->setActiveSheetIndex(3);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("Credit Notes");

$ar_fields = array("sn","student_code","ref_invoice_number","name","invoice_number","invoice_date","city_name","center_name","group_name","amount","igst","sgst","cgst","total_amount","balance_amount","p_remark");
$ar_names = array("SN","Student Code","Ref. Invoice Number","Name","Credit Note Number","Credit Note Date","City","Center","Group","Taxable Amount","IGST","CGST","SGST","Total Amount","Balance Payment","Remarks");
$ar_width = array("10","20","30","15","15","20","10","15","15","15","15","15","15","15","15","15","15");

$row = 1;
$i = 0;
foreach ($ar_names as $index => $ar) {
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
foreach ($payments as $payment) {
    if($payment->type == -1){
        $i = 0;

        foreach ($ar_fields as $ar) {
            $var = '';
            $cell = $i;
            $cell_val = Utilities::getNameFromNumber($cell);

            if($ar == 'sn'){
                $var = $count++;
            } else if( in_array($ar, ['sgst','cgst','igst']) ){
                $var = '';

                if($payment->state_id == $gst_info->state_id || !$payment->state_id){
                    if($ar == "cgst" || $ar == "sgst") $var = round($payment->tax/2,1);
                } else {
                    if($ar == "igst") $var = round($payment->tax,1);
                }

            } else{
                $var = (isset($payment->$ar))?$payment->$ar:'';
            }

            $i++;

            $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

        }

        $row++;
    }
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


// creating credit note items
$spreadsheet->createSheet();
$spreadsheet->setActiveSheetIndex(4);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("Credit Note Details");

$ar_fields = array("sn","student_code","code","name","invoice_number","invoice_date","category","type_name","city_name","center_name","group_name","taxable_amount","igst","sgst","cgst","total_amount","p_remark");
$ar_names = array("SN","Student Code","Credit Note Item Code","Student Name","Credit Note Number","Credit Note Date","Category","Sub Category","City","Center","Group","Taxable Amount","IGST","SGST","CGST","Total Amount","Remarks");
$ar_width = array("10","20","30","15","15","20","10","15","15","15","15","15","15","15","15","15","15");

$row = 1;
$i = 0;
foreach ($ar_names as $index => $ar) {
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
foreach ($payment_items as $payment_item) {
    if($payment_item->type == -1){
        $i = 0;

        foreach ($ar_fields as $ar) {
            $var = '';
            $cell = $i;
            $cell_val = Utilities::getNameFromNumber($cell);

            if($ar == 'sn'){
                $var = $count++;
            } else if( in_array( $ar , ["code","name","invoice_number","invoice_date","city_name","center_name","group_name","student_code","p_remark"] ) ){
                    
                $var = "";
                $payment = (isset($payment_list[$payment_item->payment_history_id])) ? $payment_list[$payment_item->payment_history_id] : null;
                if($payment){
                    $var = (isset($payment->$ar))?$payment->$ar:'';
                }

            }  else if( in_array($ar, ['sgst','cgst','igst']) ){
                $var = '';

                $payment = (isset($payment_list[$payment_item->payment_history_id])) ? $payment_list[$payment_item->payment_history_id] : null;

                if($payment->state_id == $gst_info->state_id || !$payment->state_id){
                    if($ar == "cgst" || $ar == "sgst") $var = round($payment_item->tax/2,1);
                } else {
                    if($ar == "igst") $var = round($payment_item->tax,1);
                }

            } else if( $ar == 'discount' ){
                    
                $var = "";
                if(!$payment_item->base_price){
                    $var = "0%";
                } else {
                    $var = round(($payment_item->base_price - $payment_item->total_amount)*100/$payment_item->base_price);
                    $var = $var."%";
                }

            } else{
                $var = (isset($payment_item->$ar))?$payment_item->$ar:'';
            }

            $i++;

            $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

        }

        $row++;
    }
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


// refund details for invoice
$spreadsheet->createSheet();
$spreadsheet->setActiveSheetIndex(5);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle("Refunds");


$ar_fields = array("sn","code","name","invoice_number","invoice_date","city_name","center_name","group_name","payment_date","mode","amount","reference_no");
$ar_names = array("SN","Refund Number","Student Name","Credit Note Number","Credit Note Date","City","Center","Group","Payment Date","Payment Mode","Amount","Reference No");
$ar_width = array("10","20","30","15","15","20","10","15","15","15","15","15","15","15","15","15","15");

$row = 1;
$i = 0;
foreach ($ar_names as $index => $ar) {
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
foreach ($payment_logs as $payment_log) {
    if($payment_log->type == -1){
        $i = 0;

        foreach ($ar_fields as $ar) {
            $var = '';
            $cell = $i;
            $cell_val = Utilities::getNameFromNumber($cell);

            if($ar == 'sn'){
                $var = $count++;
            } else if($ar == 'code'){
                $var = str_pad($payment_log->id,6,"0",STR_PAD_LEFT);
            } else{
                $var = (isset($payment_log->$ar))?$payment_log->$ar:'';
            }

            $i++;

            $spreadsheet ->getActiveSheet()-> setCellValue($cell_val . $row, $var);

        }

        $row++;
    }
}

$spreadsheet->getActiveSheet()->getStyle('A2'.':'.$max_col.($row-1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));


$spreadsheet->setActiveSheetIndex(0);

$filename = "Payments_".strtotime("now").'.xlsx';
$writer = new Xlsx($spreadsheet);

$path = "temp/";
$writer->save($path.$filename);

