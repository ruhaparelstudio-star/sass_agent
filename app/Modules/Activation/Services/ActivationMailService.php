<?php

namespace App\Modules\Activation\Services;

use App\Mail\ActivationLinkMail;
use Illuminate\Support\Facades\Mail;

class ActivationMailService
{
    public function sendActivationLink(string $email, string $token): void
    {
        Mail::to($email)->send(new ActivationLinkMail($token, $email));
    }
}

