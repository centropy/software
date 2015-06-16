<?php

namespace Icinga\Module\Businessprocess;

use Icinga\Application\Benchmark;
use Icinga\Module\Monitoring\Backend\MonitoringBackend;
use Icinga\Data\Filter\Filter;
use Exception;

class BusinessProcess
{
    const SOFT_STATE = 0;

    const HARD_STATE = 1;

    /**
     * Name of the configured monitoring backend
     *
     * @var string
     */
    protected $backendName;

    /**
     * Monitoring backend to retrieve states from
     *
     * @var MonitoringBackend
     */
    protected $backend;

    /**
     * Business process name
     *
     * @var string
     */
    protected $name;

    /**
     * Business process title
     *
     * @var string
     */
    protected $title;

    /**
     * State type, soft or hard
     *
     * @var int
     */
    protected $state_type = self::HARD_STATE;

    /**
     * Warnings, usually filled at process build time
     *
     * @var array
     */
    protected $warnings = array();

    /**
     * Errors, usually filled at process build time
     *
     * @var array
     */
    protected $errors = array();

    /**
     * All used node objects
     *
     * @var array
     */
    protected $nodes = array();

    /**
     * Root node objects
     *
     * @var array
     */
    protected $root_nodes = array();

    /**
     * All check names { 'hostA;ping' => true, ... }
     *
     * @var array
     */
    protected $all_checks = array();

    /**
     * All host names { 'hostA' => true, ... }
     *
     * @var array
     */
    protected $hosts = array();

    /**
     * Applied state simulation
     *
     * @var Simulation
     */
    protected $simulation;

    /**
     * Whether we are in edit mode
     *
     * @var boolean
     */
    protected $editMode = false;

    protected $locked = true;

    protected $changeCount = 0;

    protected $simulationCount = 0;

    protected $appliedChanges;

    public function __construct()
    {
    }

    public function applyChanges(ProcessChanges $changes)
    {
        $cnt = 0;
        foreach ($changes->getChanges() as $change) {
            $cnt++;
            $change->applyTo($this);
        }
        $this->changeCount = $cnt;

        $this->appliedChanges = $changes;

        return $this;
    }

    public function applySimulation(Simulation $simulation)
    {
        $cnt = 0;

        foreach ($simulation->simulations() as $node => $s) {
            if (! $this->hasNode($node)) {
                continue;
            }
            $cnt++;
            $this->getNode($node)
                 ->setState($s->state)
                 ->setAck($s->acknowledged)
                 ->setDowntime($s->in_downtime);
        }

        $this->simulationCount = $cnt;
    }
    public function countChanges()
    {
        return $this->changeCount;
    }

    public function hasChanges()
    {
        return $this->countChanges() > 0;
    }

    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    public function getTitle()
    {
        return $this->title ?: $this->getName();
    }

    public function hasTitle()
    {
        return $this->title !== null;
    }

    public function setBackendName($name)
    {
        $this->backendName = $name;
        return $this;
    }

    public function getBackendName()
    {
        return $this->backendName;
    }

    public function hasBackendName()
    {
        return $this->backendName !== null;
    }

    public function setBackend(MonitoringBackend $backend)
    {
        $this->backend = $backend;
        return $this;
    }

    public function getBackend()
    {
        if ($this->backend === null) {
            $this->backend = MonitoringBackend::createBackend(
                $this->getBackendName()
            );
        }

        return $this->backend;
    }

    public function hasBackend()
    {
        return $this->backend !== null;
    }

    public function hasBeenChanged()
    {
        return false;
    }

    public function isLocked()
    {
        return $this->locked;
    }

    public function lock($lock = true)
    {
        $this->locked = (bool) $lock;
        return $this;
    }

    public function unlock()
    {
        return $this->lock(false);
    }

    public function hasSimulations()
    {
        return $this->countSimulations() > 0;
    }

    public function countSimulations()
    {
        return $this->simulationCount;
    }

    public function setEditMode($mode = true)
    {
        $this->editMode = (bool) $mode;
        return $this;
    }

    public function isEditMode()
    {
        return $this->editMode;
    }

    public function clearAppliedChanges()
    {
        if ($this->appliedChanges !== null) {
            $this->appliedChanges->clear();
        }
        return $this;
    }

    public function useSoftStates()
    {
        $this->state_type = self::SOFT_STATE;
        return $this;
    }

    public function useHardStates()
    {
        $this->state_type = self::HARD_STATE;
        return $this;
    }

    public function usesSoftStates()
    {
        return $this->state_type === self::SOFT_STATE;
    }

    public function usesHardStates()
    {
        $this->state_type === self::HARD_STATE;
    }

    public function addRootNode($name)
    {
        $this->root_nodes[$name] = $this->getNode($name);
        return $this;
    }

    public function removeRootNode($name)
    {
        if ($this->isRootNode($name)) {
            unset($this->root_nodes[$name]);
        }

        return $this;
    }

    public function isRootNode($name)
    {
        return array_key_exists($name, $this->root_nodes);
    }

    public function retrieveStatesFromBackend()
    {
        try {
            $this->reallyRetrieveStatesFromBackend();
        } catch (Exception $e) {
            $this->error(
                $this->translate('Could not retrieve process state: %s'),
                $e->getMessage()
            );
        }
    }

    public function reallyRetrieveStatesFromBackend()
    {
        Benchmark::measure('Retrieving states for business process ' . $this->getName());
        $backend = $this->getBackend();
        // TODO: Split apart, create a dedicated function.
        //       Separate "parse-logic" from "retrieve-state-logic"
        //       Allow DB-based backend
        //       Use IcingaWeb2 Multi-Backend-Support
        $check_results = array();
        $hostFilter = array_keys($this->hosts);

        if ($this->state_type === self::HARD_STATE) {
            $hostStateColumn          = 'host_hard_state';
            $hostStateChangeColumn    = 'host_last_hard_state_change';
            $serviceStateColumn       = 'service_hard_state';
            $serviceStateChangeColumn = 'service_last_hard_state_change';
        } else {
            $hostStateColumn          = 'host_state';
            $hostStateChangeColumn    = 'host_last_state_change';
            $serviceStateColumn       = 'service_state';
            $serviceStateChangeColumn = 'service_last_state_change';
        }
        $filter = Filter::matchAny();
        foreach ($hostFilter as $host) {
            $filter->addFilter(Filter::where('host_name', $host));
        }

        if ($filter->isEmpty()) {
            return $this;
        }

        $hostStatus = $backend->select()->from('hostStatus', array(
            'hostname'          => 'host_name',
            'last_state_change' => $hostStateChangeColumn, 
            'in_downtime'       => 'host_in_downtime',
            'ack'               => 'host_acknowledged',
            'state'             => $hostStateColumn
        ))->applyFilter($filter)->getQuery()->fetchAll();

        $serviceStatus = $backend->select()->from('serviceStatus', array(
            'hostname'          => 'host_name',
            'service'           => 'service_description',
            'last_state_change' => $serviceStateChangeColumn, 
            'in_downtime'       => 'service_in_downtime',
            'ack'               => 'service_acknowledged',
            'state'             => $serviceStateColumn
        ))->applyFilter($filter)->getQuery()->fetchAll();

        foreach ($serviceStatus + $hostStatus as $row) {
            $key = $row->hostname;
            if ($row->service) {
                $key .= ';' . $row->service;
            } else {
                $key .= ';Hoststatus';
            }
            // We fetch more states than we need, so skip unknown ones
            if (! $this->hasNode($key)) continue;
            $node = $this->getNode($key);

            if ($row->state !== null) {
                $node->setState($row->state)->setMissing(false);
            }
            if ($row->last_state_change !== null) {
                $node->setLastStateChange($row->last_state_change);
            }
            if ((int) $row->in_downtime === 1) {
                $node->setDowntime(true);
            }
            if ((int) $row->ack === 1) {
                $node->setAck(true);
            }
        }
        ksort($this->root_nodes);
        Benchmark::measure('Got states for business process ' . $this->getName());

        return $this;
    }

    public function getChildren()
    {
        return $this->getRootNodes();
    }

    public function countChildren()
    {
        return count($this->root_nodes);
    }

    public function getRootNodes()
    {
        ksort($this->root_nodes);
        return $this->root_nodes;
    }

    public function getNodes()
    {
        return $this->nodes;
    }

    public function hasNode($name)
    {
        return array_key_exists($name, $this->nodes);
    }

    public function createService($host, $service)
    {
        $node = new ServiceNode(
            $this,
            (object) array(
                'hostname' => $host,
                'service'  => $service
            )
        );
        $this->nodes[$host . ';' . $service] = $node;
        $this->hosts[$host] = true;
        return $node;
    }

    public function createHost($host)
    {
        $node = new HostNode($this, (object) array('hostname' => $host));
        $this->nodes[$host . ';Hoststatus'] = $node;
        $this->hosts[$host] = true;
        return $node;
    }

    public function createImportedNode($config, $name)
    {
        $node = new ImportedNode($this, (object) array('name' => $name, 'configName' => $config));
        $this->nodes[$name] = $node;
        return $node;
    }

    public function getNode($name)
    {
        if (array_key_exists($name, $this->nodes)) {
            return $this->nodes[$name];
        }

        // Fallback: if it is a service, create an empty one:
        $this->warn(sprintf('The node "%s" doesn\'t exist', $name));
        $pos = strpos($name, ';');
        if ($pos !== false) {
            $host = substr($name, 0, $pos);
            $service = substr($name, $pos + 1);
            return $this->createService($host, $service);
        }

        throw new Exception(
            sprintf('The node "%s" doesn\'t exist', $name)
        );
    }

    public function addObjectName($name)
    {
        $this->all_checks[$name] = 1;
        return $this;
    }

    public function addNode($name, Node $node)
    {
        if (array_key_exists($name, $this->nodes)) {
            $this->warn(
                sprintf(
                    mt('businessprocess', 'Node "%s" has been defined twice'),
                    $name
                )
            );
        }

        $this->nodes[$name] = $node;

        if ($node->getDisplay() > 0) {
            if (! $this->isRootNode($name)) {
                $this->addRootNode($name);
            }
        } else {
            if ($this->isRootNode($name)) {
                $this->removeRootNode($name);
            }
        }


        return $this;
    }

    public function getUnboundNodes()
    {
        $nodes = array();

        foreach ($this->nodes as $node) {
            if (! $node instanceof BpNode) {
                continue;
            }

            if ($node->hasParents()) {
                continue;
            }

            if ($node->getDisplay() === 0) {
                $nodes[(string) $node] = $node;
            }
        }

        return $nodes;
    }

    public function hasWarnings()
    {
        return ! empty($this->warnings);
    }

    public function getWarnings()
    {
        return $this->warnings;
    }

    public function hasErrors()
    {
        return ! empty($this->errors) || $this->isEmpty();
    }

    public function getErrors()
    {
        $errors = $this->errors;
        if ($this->isEmpty()) {
            $errors[] = sprintf(
                $this->translate(
                    'No business process nodes for "%s" have been defined yet'
                ),
                $this->getTitle()
            );
        }
        return $errors;
    }

    protected function translate($msg)
    {
        return mt('businessprocess', $msg);
    }

    protected function warn($msg)
    {
        $args = func_get_args();
        array_shift($args);
        if (isset($this->parsing_line_number)) {
            $this->warnings[] = sprintf(
                $this->translate('Parser waring on %s:%s: %s'),
                $this->filename,
                $this->parsing_line_number,
                vsprintf($msg, $args)
            );
        } else {
            $this->warnings[] = vsprintf($msg, $args);
        }
    }

    protected function error($msg)
    {
        $args = func_get_args();
        array_shift($args);
        $this->errors[] = vsprintf($msg, $args);
    }

    public function toLegacyConfigString()
    {
        $settings = array();
        if ($this->hasTitle()) {
            $settings['Title'] = $this->getTitle();
        }
        // TODO: backendName?
        if ($this->backend) {
            $settings['Backend'] = $this->backend->getName();
        }
        $settings['Statetype'] = $this->usesSoftStates() ? 'soft' : 'hard';

        if (false) {
            $settings['SLA Hosts'] = implode(', ', array());
        }

        $conf = "### Business Process Config File ###\n#\n";
        foreach ($settings as $key => $value) {
            $conf .= sprintf("# %-9s : %s\n", $key, $value);
        }

        $conf .= sprintf(
            "#\n"
          . "### %s - autogenerated ###\n\n",
            date('Y-m-d H:i:s')
        );

        $rendered = array();
        foreach ($this->getChildren() as $child) {
            $rendered[(string) $child] = true;
            $conf .= $child->toLegacyConfigString($rendered);
        }
        foreach ($this->getUnboundNodes() as $node) {
            $rendered[(string) $node] = true;
            $conf .= $node->toLegacyConfigString($rendered);
        }
        return $conf . "\n";
    }

    public function isEmpty()
    {
        return $this->countChildren() === 0;
    }

    public function renderHtml($view)
    {
        $html = '';
        foreach ($this->getRootNodes() as $name => $node) {
            $html .= $node->renderHtml($view);
        }
        return $html;
    }

    public function renderUnbound($view)
    {
        $html = '';

        $unbound = $this->getUnboundNodes();
        if (empty($unbound)) {
            return $html;
        }

        $parent = new BpNode($this, (object) array(
            'name'        => '__unbound__',
            'operator'    => '|',
            'child_names' => array_keys($unbound)
        ));
        $parent->getState();
        $parent->setMissing()
            ->setDowntime(false)
            ->setAck(false)
            ->setAlias('Unbound nodes');

        $html .= $parent->renderHtml($view);
        return $html;
    }
}
