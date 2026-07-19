<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        if ($redirect = AppController::guardPage('dashboard')) {
            return $redirect;
        }

        return Inertia::render('Dashboard', [
            'page' => 'dashboard',
            'title' => 'Business Dashboard',
            'role_id' => AppController::roleId(),
            'stats' => [
                ['label' => 'Today Sales', 'value' => '₹48,250', 'hint' => '+18% vs yesterday'],
                ['label' => 'Gross Margin', 'value' => '31%', 'hint' => 'After item cost'],
                ['label' => 'GST Payable', 'value' => '₹6,840', 'hint' => 'Output minus input'],
                ['label' => 'Stock Value', 'value' => '₹12.4L', 'hint' => 'Across branches'],
            ],
            'recentSales' => [
                ['invoice' => 'BIQ-1008', 'customer' => 'Walk-in Customer', 'total' => '₹4,280', 'payment' => 'UPI'],
                ['invoice' => 'BIQ-1007', 'customer' => 'Metro Traders', 'total' => '₹12,900', 'payment' => 'Credit'],
                ['invoice' => 'BIQ-1006', 'customer' => 'Anaya Stores', 'total' => '₹8,750', 'payment' => 'Cash'],
            ],
        ]);
    }
}
