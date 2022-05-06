<?php
namespace GDO\Session\Method;

use GDO\DB\Database;
use GDO\Session\GDO_Session;
use GDO\Date\Time;
use GDO\Cronjob\MethodCronjob;
use GDO\Core\Application;

/**
 * Cronjob that deletes old sessions.
 * 
 * @author gizmore
 * @version 6.11.4
 * @since 6.1.0
 * 
 * @see Login_Form
 * @see Login_Logout
 * @see Register_Activate
 * @see Register_Guest
 */
final class CleanupSessions extends MethodCronjob
{
	public function runAt() { return $this->runHourly(); }
	
	public function run()
	{
		$cut = Time::getDate(Application::$MICROTIME - GDO_SESS_TIME);
		GDO_Session::table()->deleteWhere("sess_time < '{$cut}'");
		if ($deleted = Database::instance()->affectedRows())
		{
			$this->log("Deleted $deleted sessions.");
		}
	}
	
}
