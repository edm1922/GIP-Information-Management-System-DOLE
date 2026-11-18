<?php

namespace App\Providers;

use App\Models\Intern;
use App\Models\InternApplication;
use App\Models\InternDocument;
use App\Models\InternRegistrationLink;
use App\Policies\InternApplicationPolicy;
use App\Policies\InternDocumentPolicy;
use App\Policies\InternPolicy;
use App\Policies\InternRegistrationLinkPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Intern::class => InternPolicy::class,
        InternDocument::class => InternDocumentPolicy::class,
        InternApplication::class => InternApplicationPolicy::class,
        InternRegistrationLink::class => InternRegistrationLinkPolicy::class,
    ];
}
