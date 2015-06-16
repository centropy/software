<?php

namespace Icinga\Module\Businessprocess;

use Icinga\Web\Url;

class HostNode extends Node
{
    protected $hostname;

    public function __construct(BusinessProcess $bp, $object)
    {
        $this->name     = $object->hostname . ';Hoststatus';
        $this->hostname = $object->hostname;
        $this->bp       = $bp;
        if (isset($object->state)) {
            $this->setState($object->state);
        } else {
            $this->setState(0)->setMissing();
        }
    }

    public function renderLink($view)
    {
        if ($this->isMissing()) {
            return '<span class="missing">' . $view->escape($this->hostname) . '</span>';
        }

        $params = array(
            'host'    => $this->getHostname(),
        );

        if ($this->bp->hasBackendName()) {
            $params['backend'] = $this->bp->getBackendName();
        }
        return $view->qlink($this->hostname, 'monitoring/host/show', $params);
    }

    protected function getActionIcons($view)
    {
        $icons = array();

        if (! $this->bp->isLocked()) {

            $url = Url::fromPath( 'businessprocess/node/simulate', array(
                'config' => $this->bp->getName(),
                'node' => $this->name
            ));

            $icons[] = $this->actionIcon(
                $view,
                'magic',
                $url,
                'Simulation'
            );
        }

        return $icons;
    }

    public function getHostname()
    {
        return $this->hostname;
    }
}
