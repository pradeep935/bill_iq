<?php

namespace App\Http\Controllers;

class ReportsController extends Controller
{
    public function business()
    {
        return ModuleController::render('reports', 'Business Reports');
    }

    public function inventory()
    {
        return ModuleController::render('inventory-reports', 'Inventory Reports');
    }

    public function stockLedger()
    {
        return ModuleController::render('stock-ledger', 'Stock Ledger');
    }

    public function stockValuation()
    {
        return ModuleController::render('inventory-valuation', 'Stock Valuation');
    }

    public function auditTrail()
    {
        return ModuleController::render('inventory-audit-trail', 'Voucher Audit Trail');
    }

    public function acceptance()
    {
        return ModuleController::render('acceptance', 'Acceptance Matrix');
    }
}
