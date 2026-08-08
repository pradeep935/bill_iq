<?php

namespace App\Http\Controllers;

use App\Services\HsnSacSearchService;
use Illuminate\Http\Request;

class HsnSacController extends Controller
{
    public function search(Request $request, HsnSacSearchService $search)
    {
        return response()->json($search->search($request->query()));
    }
}
