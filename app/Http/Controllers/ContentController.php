<?php

namespace App\Http\Controllers;

use View;

class ContentController extends Controller
{
    public function getTracks()
    {
        return View::make('shared.null');
    }

    public function getAlbums()
    {
        return View::make('shared.null');
    }

    public function getPlaylists()
    {
        return View::make('shared.null');
    }
}