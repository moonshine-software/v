<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// This project is admin-only. Send the root URL to the admin dashboard
// rather than rendering Laravel's default welcome page.
Route::redirect('/', '/admin');
