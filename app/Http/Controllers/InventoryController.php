<?php

namespace App\Http\Controllers;

class InventoryController extends Controller
{
    public function dashboard()
    {
        return ModuleController::render('inventory', 'Inventory Dashboard');
    }

    public function add()
    {
        return ModuleController::render('inventory-add', 'Add Inventory');
    }

    public function currentStock()
    {
        return ModuleController::render('inventory-current-stock', 'Current Stock');
    }

    public function vouchers()
    {
        return ModuleController::render('inventory-vouchers', 'Inventory Vouchers');
    }

    public function batches()
    {
        return ModuleController::render('inventory-batches', 'Batch & Expiry');
    }

    public function serials()
    {
        return ModuleController::render('inventory-serials', 'Serial Numbers');
    }

    public function barcodeCenter()
    {
        return ModuleController::render('inventory-barcode-center', 'Barcode Center');
    }

    public function manufacturing()
    {
        return ModuleController::render('inventory-manufacturing', 'Manufacturing / BOM');
    }
}
