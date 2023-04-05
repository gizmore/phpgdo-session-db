<?php
declare(strict_types=1);
namespace GDO\Session;

use GDO\Core\Application;
use GDO\Core\GDO;
use GDO\Core\GDT_AutoInc;
use GDO\Core\GDT_CreatedAt;
use GDO\Core\GDT_EditedAt;
use GDO\Core\GDT_Serialize;
use GDO\Core\GDT_Token;
use GDO\Core\Logger;
use GDO\Date\Time;
use GDO\Net\GDT_IP;
use GDO\User\GDO_User;
use GDO\User\GDT_User;
use GDO\Util\Math;

/**
 * GDO Database Session handler.
 *
 * @version 7.0.3
 * @since 3.0.0
 * @author gizmore
 */
class GDO_Session extends GDO
{

	final public const DUMMY_COOKIE_EXPIRES = 300;
	final public const DUMMY_COOKIE_CONTENT = 'GDO_like_16_byte';

	public static ?self $INSTANCE = null;

	public static bool $STARTED = false;
	public static string $COOKIE_NAME = 'GDOv7';
	private static string $COOKIE_DOMAIN = 'localhost';
	private static bool $COOKIE_JS = true;
	private static bool $COOKIE_HTTPS = true;
	private static string $COOKIE_SAMESITE = 'Lax';
	private static int $COOKIE_SECONDS = 72600;

	public static function isDB(): true { return true; }

	###########
	### GDO ###
	###########
	/**
	 * Get current user or ghost.
	 */
	public static function user(): GDO_User
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

	public function getUser(): ?GDO_User
	{
		return $this->gdoValue('sess_user');
	}

	public static function init(string $cookieName = 'GDOv7', string $domain = 'localhost', int $seconds = -1, bool $httpOnly = true, bool $https = false, string $samesite = 'Lax'): void
	{
		$tls = Application::$INSTANCE->isTLS();
		self::$COOKIE_NAME = $cookieName;
		self::$COOKIE_DOMAIN = $domain;
		self::$COOKIE_SECONDS = Math::clampInt($seconds, -1, Time::ONE_YEAR);
		self::$COOKIE_JS = !$httpOnly;
		self::$COOKIE_HTTPS = $https && $tls;
		self::$COOKIE_SAMESITE = $samesite;
		if ($tls)
		{
			self::$COOKIE_NAME .= '_tls'; # SSL cookies have a different name to prevent locking
		}
	}

	public static function get($key, $default = null): mixed
	{
		$session = self::instance();
		$data = $session ? $session->getData() : [];
		return $data[$key] ?? $default;
	}

	public static function instance(): ?self
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

	/**
	 * Start and get user session
	 */
	private static function start(string $cookieValue = null, bool $cookieIP = true): ?self
	{
		$app = Application::$INSTANCE;
		if ($app->isInstall())
		{
			return null;
		}

		if ($app->isCLI() && (!$app->isWebsocket()))
		{
			self::createSession($cookieIP);
			return self::reloadCookie($_COOKIE[self::$COOKIE_NAME]);
		}

		# Parse cookie value
		if (!$cookieValue)
		{
			if (!isset($_COOKIE[self::$COOKIE_NAME]))
			{
				self::setDummyCookie();
				return null;
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
			return null;
		}

		return $session;
	}

	private static function createSession(bool $sessIP): self
	{
		$session = self::blank([
			'sess_time' => Time::getDate(),
			'sess_ip' => $sessIP ? GDT_IP::current() : null,
		])->insert();
		$session->setCookie();
		return $session;
	}

	private function setCookie(): void
	{
		if (Application::$INSTANCE->isWebserver())
		{
			if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS')
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

	public function cookieContent(): string
	{
		return "{$this->getID()}-{$this->getToken()}";
	}

	public function getID(): ?string { return $this->gdoVar('sess_id'); }

	public function getToken(): ?string { return $this->gdoVar('sess_token'); }

	private static function cookieSecure(): bool
	{
		return self::$COOKIE_HTTPS;
	}

	public static function reloadCookie(string $cookieValue): ?self
	{
		if (!strpos($cookieValue, '-'))
		{
			Logger::logError('Invalid Sess Cookie!');
			return null;
		}

		[$sessId, $sessToken] = @explode('-', $cookieValue, 2);

		# Fetch from possibly from cache via find :)
		if (!($session = self::getById($sessId)))
		{
			Logger::logError('Invalid SessID!');
			return null;
		}

		if ($session->getToken() !== $sessToken)
		{
			Logger::logError('Invalid Sess Token!');
			return null;
		}

		# IP Check?
		if (($ip = $session->getIP()) && ($ip !== GDT_IP::current()))
		{
			if (!GDT_IP::isLocal())
			{
				Logger::logError("Invalid Sess IP! $ip != " . GDT_IP::current());
				return null;
			}
		}

		self::$INSTANCE = $session;

		$app = Application::$INSTANCE;
		if ((!$app->isCLI()) || ($app->isWebsocket()))
		{
			if (!($user = $session->getUser()))
			{
				$user = GDO_User::ghost();
			}
			GDO_User::setCurrent($user);
		}

		return $session;
	}

	######################
	### Get/Set/Remove ###
	######################

	public function getIP(): ?string
	{
		return $this->gdoVar('sess_ip');
	}

	private static function setDummyCookie(): void
	{
		if (!Application::$INSTANCE->isCLIOrUnitTest())
		{
			if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS')
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

	public function getData(): ?array
	{
		return $this->gdoValue('sess_data');
	}

	public function reset(): static
	{
		self::$INSTANCE = null;
		self::$STARTED = false;
		return $this;
	}


	public function gdoEngine(): string { return self::MYISAM; }


	public function gdoColumns(): array
	{
		return [
			GDT_AutoInc::make('sess_id'),
			GDT_Token::make('sess_token')->notNull(),
			GDT_User::make('sess_user'),
			GDT_IP::make('sess_ip'),
			GDT_CreatedAt::make('sess_created'),
			GDT_EditedAt::make('sess_time'),
			GDT_Serialize::make('sess_data'),
		];
	}

	public static function set(string $key, $value): void
	{
		if ($session = self::instance())
		{
			$data = $session->getData();
			$data[$key] = $value;
			$session->setValue('sess_data', $data);
		}
	}

	public static function remove(string $key): void
	{
		if ($session = self::instance())
		{
			$data = $session->getData();
			unset($data[$key]);
			$session->setValue('sess_data', $data);
		}
	}

	public static function commit(): void
	{
		self::$INSTANCE?->save();
	}

	public static function getCookieValue(): ?string
	{
		return isset($_COOKIE[self::$COOKIE_NAME]) ? (string)$_COOKIE[self::$COOKIE_NAME] : null;
	}

	public static function reloadID(string $id): ?self
	{
		return self::$INSTANCE = self::getById($id);
	}

	public function getTime(): int
	{
		return $this->gdoValue('sess_time');
	}

}
