<?php
namespace Components\PrivacyConsent;

use Core\Component;
use Domain\Auth;
use Domain\Privacy;

final class PrivacyConsentComponent extends Component
{
    public function executeComponent()
    {
        $this->result = ['account'=>Auth::userId() ? Privacy::account(Auth::userId()) : null,'version'=>Privacy::version()];
        $this->includeTemplate();
    }
}
