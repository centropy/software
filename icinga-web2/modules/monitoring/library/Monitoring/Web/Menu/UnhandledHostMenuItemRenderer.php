<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

namespace Icinga\Module\Monitoring\Web\Menu;

class UnhandledHostMenuItemRenderer extends MonitoringMenuItemRenderer
{
    protected $columns = array(
        'hosts_down_unhandled',
    );
}
