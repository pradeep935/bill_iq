<?php

namespace App\Http\Controllers;

class WarehouseController extends Controller
{
    public function warehouses()
    {
        return ModuleController::render('inventory-warehouses', 'Warehouses / Bins');
    }

    public function bins()
    {
        return ModuleController::render('inventory-bins', 'Bins / Racks');
    }

    public function godownBalances()
    {
        return ModuleController::render('inventory-godown-balance', 'Godown Balances');
    }

    public function transfer()
    {
        return ModuleController::render('inventory-transfer', 'Stock Transfer');
    }

    public function transferRequests()
    {
        return ModuleController::render('inventory-transfer-requests', 'Transfer Requests');
    }

    public function adjustment()
    {
        return ModuleController::render('inventory-adjustment', 'Stock Adjustment');
    }

    public function audit()
    {
        return ModuleController::render('inventory-audit', 'Physical Audit');
    }

    public function allocation()
    {
        return ModuleController::render('inventory-allocation', 'Batch / Serial Allocation');
    }
}
