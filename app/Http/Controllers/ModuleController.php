<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class ModuleController extends Controller
{
    public static function render(string $page, string $title)
    {
        if ($redirect = AppController::guardPage($page)) {
            return $redirect;
        }

        return Inertia::render('Placeholder', [
            'page' => $page,
            'title' => $title,
            'role_id' => AppController::roleId(),
        ]);
    }
}
