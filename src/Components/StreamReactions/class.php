<?php
namespace Components\StreamReactions;

use Core\Component;

/** Shared first-party reaction picker for the player and standalone chat. */
class StreamReactionsComponent extends Component
{
    public function executeComponent()
    {
        $this->result['player_controls']=!empty($this->params['player_controls']);
        $this->result['toolbar']=$this->result['player_controls']&&!empty($this->params['toolbar']);
        $this->includeTemplate();
    }
}
