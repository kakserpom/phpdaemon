<?php
namespace PHPDaemon\Utils;

/**
 * IRC
 * @package PHPDaemon\Utils
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class IRC {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var array IRC codes
	 */
	public static $codes = [
		'1' => 'RPL_WELCOME', 2 => 'RPL_YOURHOST',
		3   => 'RPL_CREATED', 4 => 'RPL_MYINFO',
		5   => 'RPL_BOUNCE', 200 => 'RPL_TRACELINK',
		201 => 'RPL_TRACECONNECTING', 202 => 'RPL_TRACEHANDSHAKE',
		203 => 'RPL_TRACEUNKNOWN', 204 => 'RPL_TRACEOPERATOR',
		205 => 'RPL_TRACEUSER', 206 => 'RPL_TRACESERVER',
		207 => 'RPL_TRACESERVICE', 208 => 'RPL_TRACENEWTYPE',
		209 => 'RPL_TRACECLASS', 210 => 'RPL_TRACERECONNECT',
		211 => 'RPL_STATSLINKINFO', 212 => 'RPL_STATSCOMMANDS',
		219 => 'RPL_ENDOFSTATS', 221 => 'RPL_UMODEIS',
		234 => 'RPL_SERVLIST', 235 => 'RPL_SERVLISTEND',
		242 => 'RPL_STATSUPTIME', 243 => 'RPL_STATSOLINE',
		250 => 'RPL_STATSCONN',
		251 => 'RPL_LUSERCLIENT', 252 => 'RPL_LUSEROP',
		253 => 'RPL_LUSERUNKNOWN', 254 => 'RPL_LUSERCHANNELS',
		255 => 'RPL_LUSERME', 256 => 'RPL_ADMINME',
		257 => 'RPL_ADMINLOC1', 258 => 'RPL_ADMINLOC2',
		259 => 'RPL_ADMINEMAIL', 261 => 'RPL_TRACELOG',
		262 => 'RPL_TRACEEND', 263 => 'RPL_TRYAGAIN',
		265 => 'RRPL_LOCALUSERS', 266 => 'RPL_GLOBALUSERS',
		301 => 'RPL_AWAY', 302 => 'RPL_USERHOST',
		303 => 'RPL_ISON', 305 => 'RPL_UNAWAY',
		306 => 'RPL_NOWAWAY', 311 => 'RPL_WHOISUSER',
		312 => 'RPL_WHOISSERVER', 313 => 'RPL_WHOISOPERATOR',
		314 => 'RPL_WHOWASUSER', 315 => 'RPL_ENDOFWHO',
		317 => 'RPL_WHOISIDLE', 318 => 'RPL_ENDOFWHOIS',
		319 => 'RPL_WHOISCHANNELS', 321 => 'RPL_LISTSTART',
		322 => 'RPL_LIST', 323 => 'RPL_LISTEND',
		324 => 'RPL_CHANNELMODEIS', 325 => 'RPL_UNIQOPIS',
		328 => 'RPL_CHANNEL_URL', 329 => 'RPL_CREATIONTIME',
		331 => 'RPL_NOTOPIC', 332 => 'RPL_TOPIC', 333 => 'RPL_TOPIC_TS',
		341 => 'RPL_INVITING', 342 => 'RPL_SUMMONING',
		346 => 'RPL_INVITELIST', 347 => 'RPL_ENDOFINVITELIST',
		348 => 'RPL_EXCEPTLIST', 349 => 'RPL_ENDOFEXCEPTLIST',
		351 => 'RPL_VERSION', 352 => 'RPL_WHOREPLY',
		353 => 'RPL_NAMREPLY', 364 => 'RPL_LINKS',
		365 => 'RPL_ENDOFLINKS', 366 => 'RPL_ENDOFNAMES',
		367 => 'RPL_BANLIST', 368 => 'RPL_ENDOFBANLIST',
		369 => 'RPL_ENDOFWHOWAS', 371 => 'RPL_INFO',
		372 => 'RPL_MOTD', 374 => 'RPL_ENDOFINFO',
		375 => 'RPL_MOTDSTART', 376 => 'RPL_ENDOFMOTD',
		381 => 'RPL_YOUREOPER', 382 => 'RPL_REHASHING',
		383 => 'RPL_YOURESERVICE', 391 => 'RPL_TIME',
		392 => 'RPL_USERSSTART', 393 => 'RPL_USERS',
		394 => 'RPL_ENDOFUSERS', 395 => 'RPL_NOUSERS',
		401 => 'ERR_NOSUCHNICK', 402 => 'ERR_NOSUCHSERVER',
		403 => 'ERR_NOSUCHCHANNEL', 404 => 'ERR_CANNOTSENDTOCHAN',
		405 => 'ERR_TOOMANYCHANNELS', 406 => 'ERR_WASNOSUCHNICK',
		407 => 'ERR_TOOMANYTARGETS', 408 => 'ERR_NOSUCHSERVICE',
		409 => 'ERR_NOORIGIN', 411 => 'ERR_NORECIPIENT',
		412 => 'ERR_NOTEXTTOSEND', 413 => 'ERR_NOTOPLEVEL',
		414 => 'ERR_WILDTOPLEVEL', 415 => 'ERR_BADMASK',
		421 => 'ERR_UNKNOWNCOMMAND', 422 => 'ERR_NOMOTD',
		423 => 'ERR_NOADMININFO', 424 => 'ERR_FILEERROR',
		431 => 'ERR_NONICKNAMEGIVEN', 432 => 'ERR_ERRONEUSNICKNAME',
		433 => 'ERR_NICKNAMEINUSE', 436 => 'ERR_NICKCOLLISION',
		437 => 'ERR_UNAVAILRESOURCE', 441 => 'ERR_USERNOTINCHANNEL',
		442 => 'ERR_NOTONCHANNEL', 443 => 'ERR_USERONCHANNEL',
		444 => 'ERR_NOLOGIN', 445 => 'ERR_SUMMONDISABLED',
		446 => 'ERR_USERSDISABLED', 451 => 'ERR_NOTREGISTERED',
		461 => 'ERR_NEEDMOREPARAMS', 462 => 'ERR_ALREADYREGISTRED',
		463 => 'ERR_NOPERMFORHOST', 464 => 'ERR_PASSWDMISMATCH',
		465 => 'ERR_YOUREBANNEDCREEP', 466 => 'ERR_YOUWILLBEBANNED',
		467 => 'ERR_KEYSET', 471 => 'ERR_CHANNELISFULL',
		472 => 'ERR_UNKNOWNMODE', 473 => 'ERR_INVITEONLYCHAN',
		474 => 'ERR_BANNEDFROMCHAN', 475 => 'ERR_BADCHANNELKEY',
		476 => 'ERR_BADCHANMASK', 477 => 'ERR_NOCHANMODES',
		478 => 'ERR_BANLISTFULL', 481 => 'ERR_NOPRIVILEGES',
		482 => 'ERR_CHANOPRIVSNEEDED', 483 => 'ERR_CANTKILLSERVER',
		484 => 'ERR_RESTRICTED', 485 => 'ERR_UNIQOPPRIVSNEEDED',
		491 => 'ERR_NOOPERHOST', 501 => 'ERR_UMODEUNKNOWNFLAG',
		502 => 'ERR_USERSDONTMATCH',
	];

	/**
	 * @var array Flipped IRC codes
	 */
	public static $codesFlip;

	/**
	 * @param  integer $code Code
	 * @return string
	 */
	public static function getCommandByCode($code) {
		if (isset(self::$codes[$code])) {
			return self::$codes[$code];
		}
		return false;
	}

	/**
	 * @param  string  $cmd Command
	 * @return integer
	 */
	public static function getCodeByCommand($cmd) {
		if (self::$codesFlip === null) {
			self::$codesFlip = array_flip(self::$codes);
		}
		if (isset(self::$codesFlip[$cmd])) {
			return sprintf('%03u', self::$codesFlip[$cmd]);
		}
		return $cmd;
	}

	/**
	 * @param  string $mask
	 * @return array
	 */
	public static function parseUsermask($mask) {
		preg_match('~^(?:(.*?)!(\~?)(.*?)@)?(.*)$~D', $mask, $m);
		return [
			'nick'       => $m[1],
			'unverified' => $m[2] === '~',
			'user'       => $m[3],
			'host'       => $m[4],
			'orig'       => $mask,
		];
	}
}

