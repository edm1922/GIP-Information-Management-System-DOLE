<?php

namespace App\Providers;

use App\Models\Intern;
use App\Models\InternDocument;
use App\Policies\InternDocumentPolicy;
use App\Policies\InternPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Intern::class => InternPolicy::class,
        InternDocument::class => InternDocumentPolicy::class,
    ];
}
