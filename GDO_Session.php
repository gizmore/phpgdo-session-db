<?php
namespace GDO\Session;

use GDO\Core\Application;
use GDO\Core\GDO;
use GDO\Core\GDT_AutoInc;
use GDO\Core\GDT_CreatedAt;
use GDO\Core\GDT_EditedAt;
use GDO\Core\GDT_Serialize;
use GDO\Core\GDT_Token;
use GDO\Net\GDT_IP;
use GDO\Net\GDT_Url;
use GDO\User\GDO_User;
use GDO\Core\Logger;
use GDO\DB\Database;
use GDO\Date\Time;
use GDO\User\GDT_User;
use GDO\Util\Math;

/**
 * GDO Database Session handler.
 * 
 * @author gizmore
 * @version 7.0.0
 * @since 3.0.0
 */
class GDO_Session extends GDO
{
	const DUMMY_COOKIE_EXPIRES = 300;
	const DUMMY_COOKIE_CONTENT = 'GDO_like_16_byte';
	
	public static $INSTANCE;
	public static bool $STARTED = false;
	
	public static function isDB() { return true; }
	
	public  static string $COOKIE_NAME = 'GDOv7';
	private static string $COOKIE_DOMAIN = 'localhost';
	private static bool   $COOKIE_JS = true;
	private static bool   $COOKIE_HTTPS = true;
	private static string $COOKIE_SAMESITE = 'Lax';
	private static int    $COOKIE_SECONDS = 72600;
	
	###########
	### GDO ###
	###########
// 	public function gdoCached() { return false; }
	public function gdoEngine() : string { return self::MYISAM; }
	public function gdoColumns() : array
	{
		return [
			GDT_AutoInc::make('sess_id'),
			GDT_Token::make('sess_token')->notNull(),
			GDT_User::make('sess_user'),
			GDT_IP::make('sess_ip'),
			GDT_CreatedAt::make('sess_created'),
			GDT_EditedAt::make('sess_time'),
			GDT_Url::make('sess_last_url'),
			GDT_Serialize::make('sess_data'),
		];
	}
	public function getID() : ?string { return $this->gdoVar('sess_id'); }
	public function getToken() { return $this->gdoVar('sess_token'); }
	public function getUser() { return $this->gdoValue('sess_user'); }
	public function getIP() { return $this->gdoVar('sess_ip'); }
	public function getTime() { return $this->gdoValue('sess_time'); }
	public function getData() { return $this->gdoValue('sess_data'); }
	public function getLastURL() { return $this->gdoVar('sess_last_url'); }
	
	private $lock;
	public function setLock($lock)
	{
	    $this->lock = $lock;
	}
	
	public function __destruct()
	{
	    if ($this->lock)
	    {
	        Database::instance()->unlock($this->lock);
	    }
	}
	
	/**
	 * Get current user or ghost.
	 * @return GDO_User
	 */
	public static function user()
	{
	    if (self::$INSTANCE)
	    {
    		if ($user = self::$INSTANCE->getUser())
    		{
    		    return $user;
    		}
	    }
		return GDO_User::ghost();
	}
	
	/**
	 * @return self
	 */
	public static function instance()
	{
		if (!isset(self::$INSTANCE))
	    {
	        if (!self::$STARTED)
	        {
	            self::$STARTED = true; # only one try
	            self::$INSTANCE = self::start();
	        }
	    }
		return self::$INSTANCE;
	}
	
	public static function reset()
	{
		self::$INSTANCE = null;
		self::$STARTED = false;
	}
	
	public static function init($cookieName='GDOv7', $domain='localhost', $seconds=-1, $httpOnly=true, $https=false, $samesite='Lax')
	{
		$tls = Application::$INSTANCE->isTLS();
		self::$COOKIE_NAME = (string) $cookieName;
		self::$COOKIE_DOMAIN = (string) $domain;
		self::$COOKIE_SECONDS = Math::clampInt($seconds, -1, Time::ONE_YEAR);
		self::$COOKIE_JS = !$httpOnly;
		self::$COOKIE_HTTPS = $https && $tls;
		self::$COOKIE_SAMESITE = (string) $samesite;
		if ($tls)
		{
			self::$COOKIE_NAME .= '_tls'; # SSL cookies have a different name to prevent locking
		}
	}
	
	######################
	### Get/Set/Remove ###
	######################
	public static function get($key, $default=null)
	{
		$session = self::instance();
		$data = $session ? $session->getData() : [];
		return isset($data[$key]) ? $data[$key] : $default;
	}
	
	public static function set($key, $value)
	{
		if ($session = self::instance())
		{
			$data = $session->getData();
			$data[$key] = $value;
			$session->setValue('sess_data', $data);
		}
	}
	
	public static function remove($key)
	{
		if ($session = self::instance())
		{
			$data = $session->getData();
			unset($data[$key]);
			$session->setValue('sess_data', $data);
		}
	}
	
	public static function commit()
	{
		if (self::$INSTANCE)
		{
			self::$INSTANCE->save();
		}
	}
	
	public static function getCookieValue()
	{
		return isset($_COOKIE[self::$COOKIE_NAME]) ? (string)$_COOKIE[self::$COOKIE_NAME] : null;
	}
	
	/**
	 * Start and get user session
	 * @param string $cookieval
	 * @param string $cookieip
	 * @return self
	 */
	private static function start($cookieValue=true, $cookieIP=true)
	{
		$app = Application::$INSTANCE;
	    if ($app->isInstall())
	    {
	        return false;
	    }
	    
	    if ( ($app->isCLI()) && (!$app->isWebsocket()) )
	    {
	    	self::createSession($cookieIP);
	        return self::reloadCookie($_COOKIE[self::$COOKIE_NAME]);
	    }
	    
		# Parse cookie value
		if ($cookieValue === true)
		{
			if (!isset($_COOKIE[self::$COOKIE_NAME]))
			{
				self::setDummyCookie();
// 				self::createSession($cookieIP);
// 				self::setCookie();
				return false;
			}
			$cookieValue = (string)$_COOKIE[self::$COOKIE_NAME];
		}
		
		# Special first cookie
		if ($cookieValue === self::DUMMY_COOKIE_CONTENT)
		{
			$session = self::createSession($cookieIP);
		}
		# Try to reload
		elseif ($session = self::reloadCookie($cookieValue))
		{
		}
		# Set special first dummy cookie
		else
		{
			self::setDummyCookie();
			return false;
		}
		
		return $session;
	}
	
	public static function reloadID($id)
	{
		self::$INSTANCE = self::getById($id);
		return self::$INSTANCE;
	}
	
	public static function reloadCookie($cookieValue)
	{
		if (!strpos($cookieValue, '-'))
		{
			Logger::logError("Invalid Sess Cookie!");
			return false;
		}
		list($sessId, $sessToken) = @explode('-', $cookieValue, 2);
		# Fetch from possibly from cache via find :)
		if (!($session = self::table()->find($sessId, false)))
		{
			Logger::logError("Invalid SessID!");
			return false;
		}
		
		if ($session->getToken() !== $sessToken)
		{
			Logger::logError("Invalid Sess Token!");
			return false;
		}
		
		# IP Check?
		if ( ($ip = $session->getIP()) && ($ip !== GDT_IP::current()) )
		{
			Logger::logError("Invalid Sess IP! $ip != ".GDT_IP::current());
			return false;
		}
		
		self::$INSTANCE = $session;
		
		$app = Application::$INSTANCE;
		if ( (!$app->isCLI()) || ($app->isWebsocket()) )
		{
		    if (!($user = $session->getUser()))
		    {
		        $user = GDO_User::ghost();
		    }
    		GDO_User::setCurrent($user);
		}
		
		return $session;
	}
	
	private function setCookie()
	{
		if (!Application::$INSTANCE->isCLI())
		{
			if (@$_SERVER['REQUEST_METHOD'] !== 'OPTIONS')
			{
				setcookie(self::$COOKIE_NAME, $this->cookieContent(), [
					'expires' => Application::$TIME + self::$COOKIE_SECONDS,
					'path' => GDO_WEB_ROOT,
					'domain' => self::$COOKIE_DOMAIN,
					'samesite' => self::$COOKIE_SAMESITE,
					'secure' => self::cookieSecure(),
					'httponly' => !self::$COOKIE_JS,
				]);
			}
		}
		else
		{
		    $_COOKIE[self::$COOKIE_NAME] = $this->cookieContent();
		}
	}
	
	public function cookieContent()
	{
		return "{$this->getID()}-{$this->getToken()}";
	}
	
	private static function cookieSecure()
	{
		return self::$COOKIE_HTTPS;
	}
	
	private static function setDummyCookie()
	{
	    $app = Application::$INSTANCE;
		if ( (!$app->isCLI()) && (!$app->isUnitTests()) )
		{
			if (@$_SERVER['REQUEST_METHOD'] !== 'OPTIONS')
			{
				setcookie(self::$COOKIE_NAME, self::DUMMY_COOKIE_CONTENT, [
					'expires' => Application::$TIME + self::DUMMY_COOKIE_EXPIRES,
					'path' => GDO_WEB_ROOT,
					'domain' => self::$COOKIE_DOMAIN,
					'samesite' => self::$COOKIE_SAMESITE,
					'secure' => self::cookieSecure(),
					'httponly' => !self::$COOKIE_JS,
				]);
			}
		}
	}
	
	private static function createSession($sessIP=null)
	{
		$session = self::table()->blank([
		    'sess_time' => Time::getDate(),
			'sess_ip' => $sessIP ? GDT_IP::current() : null,
		])->insert();
		$session->setCookie();
		return $session;
	}
}
