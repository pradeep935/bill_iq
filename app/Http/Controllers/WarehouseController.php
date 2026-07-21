<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

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
        if ($redirect = AppController::guardPage('inventory-transfer')) return $redirect;
        return Inertia::render('Inventory/Control', ['page' => 'inventory-transfer', 'title' => 'Stock Transfer', 'initial_tab' => 'transfers']);
    }

    public function transferRequests()
    {
        return ModuleController::render('inventory-transfer-requests', 'Transfer Requests');
    }

    public function adjustment()
    {
        if ($redirect = AppController::guardPage('inventory-adjustment')) return $redirect;
        return Inertia::render('Inventory/Control', ['page' => 'inventory-adjustment', 'title' => 'Stock Adjustment', 'initial_tab' => 'adjustments']);
    }

    public function audit()
    {
        if ($redirect = AppController::guardPage('inventory-audit')) return $redirect;
        return Inertia::render('Inventory/Control', ['page' => 'inventory-audit', 'title' => 'Physical Stock Count', 'initial_tab' => 'counts']);
    }

    public function allocation()
    {
        return ModuleController::render('inventory-allocation', 'Batch / Serial Allocation');
    }
}
