<?php
namespace GDO\Session;

use GDO\Core\GDO_Module;
use GDO\UI\GDT_Divider;

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
    
    public function getPrivacyRelatedFields(): array
    {
    	return [
    		GDT_Divider::make('info_privacy_session_db'),
    	];
    }

    public function onLoadLanguage(): void
    {
    	$this->loadLanguage('lang/sess_db');
    }

}
