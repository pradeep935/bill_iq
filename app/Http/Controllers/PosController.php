<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class PosController extends Controller
{
    public function index()
    {
        if ($redirect = AppController::guardPage('pos')) {
            return $redirect;
        }

        return Inertia::render('Pos', [
            'page' => 'pos',
            'title' => 'POS Billing',
            'role_id' => AppController::roleId(),
            'cart' => [
                ['name' => 'Premium Notebook A5', 'qty' => 2, 'rate' => '₹180', 'amount' => '₹360'],
                ['name' => 'Thermal Paper Roll', 'qty' => 5, 'rate' => '₹65', 'amount' => '₹325'],
            ],
        ]);
    }
}
