<?php

namespace App\Http\Controllers;

class PurchaseController extends Controller
{
    public function bills()
    {
        return ModuleController::render('purchases', 'Purchases');
    }

    public function suppliers()
    {
        return ModuleController::render('suppliers', 'Suppliers');
    }

    public function grn()
    {
        return ModuleController::render('inventory-inward', 'Stock Inward / GRN');
    }

    public function reorder()
    {
        return ModuleController::render('inventory-reorder', 'Reorder Suggestions');
    }

    public function orders()
    {
        return ModuleController::render('inventory-orders', 'Inventory Orders');
    }
}
