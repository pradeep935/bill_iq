<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class WarehouseController extends Controller
{
    public function warehouses()
    {
        if ($redirect = AppController::guardPage('masters')) return $redirect;

        return Inertia::render('Setup/Masters', [
            'page' => 'inventory-warehouses',
            'title' => 'Warehouses / Bins',
            'initial_tab' => 'warehouse',
        ]);
    }

    public function bins()
    {
        if ($redirect = AppController::guardPage('inventory')) return $redirect;
        return Inertia::render('Warehouse/Operations', ['page' => 'inventory-bins', 'title' => 'Bins / Racks', 'initial_tab' => 'bins']);
    }

    public function godownBalances()
    {
        if ($redirect = AppController::guardPage('inventory')) return $redirect;
        return Inertia::render('Warehouse/Operations', ['page' => 'inventory-godown-balance', 'title' => 'Godown Balances', 'initial_tab' => 'balances']);
    }

    public function transfer()
    {
        if ($redirect = AppController::guardPage('inventory-transfer')) return $redirect;
        return Inertia::render('Inventory/Control', ['page' => 'inventory-transfer', 'title' => 'Stock Transfer', 'initial_tab' => 'transfers']);
    }

    public function transferRequests()
    {
        if ($redirect = AppController::guardPage('inventory-transfer')) return $redirect;
        return Inertia::render('Warehouse/Operations', ['page' => 'inventory-transfer-requests', 'title' => 'Transfer Requests', 'initial_tab' => 'requests']);
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
        if ($redirect = AppController::guardPage('inventory')) return $redirect;
        return Inertia::render('Warehouse/Operations', ['page' => 'inventory-allocation', 'title' => 'Batch / Serial Allocation', 'initial_tab' => 'allocation']);
    }
}
