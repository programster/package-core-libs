<?php

/*
 * Exception thrown when a network interface that was asked for does not exist on this machine.
 */

namespace Programster\CoreLibs\Exceptions;


class NetworkInterfaceDoesNotExistException extends CoreLibsException
{
    private readonly string $m_interface;


    /**
     * Create the exception.
     * @param string $interface - the name of the network interface that does not exist.
     */
    public function __construct(string $interface)
    {
        parent::__construct("The network interface \"{$interface}\" does not exist.");
        $this->m_interface = $interface;
    }


    # Accessors
    public function getInterface(): string { return $this->m_interface; }
}
