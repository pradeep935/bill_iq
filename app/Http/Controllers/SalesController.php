<?php

namespace App\Http\Controllers;

class SalesController extends Controller
{
    public function invoices()
    {
        return ModuleController::render('sales', 'Sales Invoices');
    }

    public function customers()
    {
        return ModuleController::render('customers', 'Customers');
    }

    public function stockOutward()
    {
        return ModuleController::render('inventory-outward', 'Stock Outward');
    }

    public function reservedStock()
    {
        return ModuleController::render('inventory-reserved', 'Reserved Stock');
    }
}
