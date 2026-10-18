<?php

namespace App\Http\Controllers;

use App\Services\Checks\Catalog;

class ControlController extends Controller
{
    public function index()
    {
        return view('controls.index', ['catalog' => Catalog::all()]);
    }
}
