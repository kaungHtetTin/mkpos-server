<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;

class LandingController extends Controller
{
    public function index()
    {
        return view('landing', [
            'releases' => AppRelease::query()->get()->keyBy('platform'),
        ]);
    }
}
