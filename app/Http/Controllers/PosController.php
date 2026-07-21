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
        ]);
    }
}
