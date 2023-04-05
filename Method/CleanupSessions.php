<?php
declare(strict_types=1);
namespace GDO\Session\Method;

use GDO\Core\Application;
use GDO\Cronjob\MethodCronjob;
use GDO\Date\Time;
use GDO\DB\Database;
use GDO\Login\Method\Form;
use GDO\Login\Method\Logout;
use GDO\Register\Method\Activate;
use GDO\Register\Method\Guest;
use GDO\Session\GDO_Session;

/**
 * Cronjob that deletes old sessions.
 *
 * @version 7.0.3
 * @since 6.1.0
 *
 * @author gizmore
 * @see Form
 * @see Logout
 * @see Activate
 * @see Guest
 */
final class CleanupSessions extends MethodCronjob
{

	public function runAt(): string
	{
		return $this->runHourly();
	}

	public function run(): void
	{
		$cut = Time::getDate(Application::$MICROTIME - GDO_SESS_TIME);
		GDO_Session::table()->deleteWhere("sess_time < '{$cut}'");
		if ($deleted = Database::instance()->affectedRows())
		{
			$this->log("Deleted $deleted sessions.");
		}
	}

}
