<?php
namespace Components\AdminUsage;

class AdminUsageComponent extends \Core\Component
{
    public function executeComponent()
    {
        if(!\Domain\Auth::isAdmin())return;
        $this->includeTemplate();
    }
}
