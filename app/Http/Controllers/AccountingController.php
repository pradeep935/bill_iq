<?php

namespace App\Http\Controllers;

class AccountingController extends Controller
{
    public function chartOfAccounts()
    {
        return ModuleController::render('accounts', 'Chart of Accounts');
    }

    public function vouchers()
    {
        return ModuleController::render('vouchers', 'Vouchers');
    }

    public function ledgers()
    {
        return ModuleController::render('ledgers', 'Ledgers');
    }

    public function expenses()
    {
        return ModuleController::render('expenses', 'Expenses');
    }

    public function gst()
    {
        return ModuleController::render('gst', 'GST');
    }

    public function gstReturns()
    {
        return ModuleController::render('inventory-gst-returns', 'GST Returns');
    }
}
