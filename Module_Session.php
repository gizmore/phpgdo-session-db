<?php
namespace GDO\Session;

use GDO\Core\GDO_Module;

/**
 * Session module.
 *
 * @version 7.0.0
 * @since 5.0.1
 */
final class Module_Session extends GDO_Module
{
    public int $priority = 9;
    
    public function getClasses() : array
    {
        return [
			GDO_Session::class
		];
    }
    
}
