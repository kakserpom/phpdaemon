<?php

/**
 * @package Network clients
 * @subpackage MySQLClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class MySQLClient extends NetworkClient {

	const CLIENT_LONG_PASSWORD     = 1;       // new more secure passwords
	const CLIENT_FOUND_ROWS        = 2;       // Found instead of affected rows
	const CLIENT_LONG_FLAG         = 4;       // Get all column flags
	const CLIENT_CONNECT_WITH_DB   = 8;       // One can specify db on connect
	const CLIENT_NO_SCHEMA         = 16;      // Don't allow database.table.column
	const CLIENT_COMPRESS          = 32;      // Can use compression protocol
	const CLIENT_ODBC              = 64;      // Odbc client
	const CLIENT_LOCAL_FILES       = 128;     // Can use LOAD DATA LOCAL
	const CLIENT_IGNORE_SPACE      = 256;     // Ignore spaces before '('
	const CLIENT_PROTOCOL_41       = 512;     // New 4.1 protocol
	const CLIENT_INTERACTIVE       = 1024;    // This is an interactive client
	const CLIENT_SSL               = 2048;    // Switch to SSL after handshake
	const CLIENT_IGNORE_SIGPIPE    = 4096;    // IGNORE sigpipes
	const CLIENT_TRANSACTIONS      = 8192;    // Client knows about transactions
	const CLIENT_RESERVED          = 16384;   // Old flag for 4.1 protocol
	const CLIENT_SECURE_CONNECTION = 32768;   // New 4.1 authentication
	const CLIENT_MULTI_STATEMENTS  = 65536;   // Enable/disable multi-stmt support
	const CLIENT_MULTI_RESULTS     = 131072;  // Enable/disable multi-results

	const COM_SLEEP               = 0x00;  // (none, this is an internal thread state)
	const COM_QUIT                = 0x01;  // mysql_close
	const COM_INIT_DB             = 0x02;  // mysql_select_db
	const COM_QUERY               = 0x03;  // mysql_real_query
	const COM_FIELD_LIST          = 0x04;  // mysql_list_fields
	const COM_CREATE_DB           = 0x05;  // mysql_create_db (deprecated)
	const COM_DROP_DB             = 0x06;  // mysql_drop_db (deprecated)
	const COM_REFRESH             = 0x07;  // mysql_refresh
	const COM_SHUTDOWN            = 0x08;  // mysql_shutdown
	const COM_STATISTICS          = 0x09;  // mysql_stat
	const COM_PROCESS_INFO        = 0x0a;  // mysql_list_processes
	const COM_CONNECT             = 0x0b;  // (none, this is an internal thread state)
	const COM_PROCESS_KILL        = 0x0c;  // mysql_kill
	const COM_DEBUG               = 0x0d;  // mysql_dump_debug_info
	const COM_PING                = 0x0e;  // mysql_ping
	const COM_TIME                = 0x0f;  // (none, this is an internal thread state)
	const COM_DELAYED_INSERT      = 0x10;  // (none, this is an internal thread state)
	const COM_CHANGE_USER         = 0x11;  // mysql_change_user
	const COM_BINLOG_DUMP         = 0x12;  // sent by the slave IO thread to request a binlog
	const COM_TABLE_DUMP          = 0x13;  // LOAD TABLE ... FROM MASTER (deprecated)
	const COM_CONNECT_OUT         = 0x14;  // (none, this is an internal thread state)
	const COM_REGISTER_SLAVE      = 0x15;  // sent by the slave to register with the master (optional)
	const COM_STMT_PREPARE        = 0x16;  // mysql_stmt_prepare
	const COM_STMT_EXECUTE        = 0x17;  // mysql_stmt_execute
	const COM_STMT_SEND_LONG_DATA = 0x18;  // mysql_stmt_send_long_data
	const COM_STMT_CLOSE          = 0x19;  // mysql_stmt_close
	const COM_STMT_RESET          = 0x1a;  // mysql_stmt_reset
	const COM_SET_OPTION          = 0x1b;  // mysql_set_server_option
	const COM_STMT_FETCH          = 0x1c;  // mysql_stmt_fetch

	const FIELD_TYPE_DECIMAL      = 0x00;
	const FIELD_TYPE_TINY         = 0x01;
	const FIELD_TYPE_SHORT        = 0x02;
	const FIELD_TYPE_LONG         = 0x03;
	const FIELD_TYPE_FLOAT        = 0x04;
	const FIELD_TYPE_DOUBLE       = 0x05;
	const FIELD_TYPE_NULL         = 0x06;
	const FIELD_TYPE_TIMESTAMP    = 0x07;
	const FIELD_TYPE_LONGLONG     = 0x08;
	const FIELD_TYPE_INT24        = 0x09;
	const FIELD_TYPE_DATE         = 0x0a;
	const FIELD_TYPE_TIME         = 0x0b;
	const FIELD_TYPE_DATETIME     = 0x0c;
	const FIELD_TYPE_YEAR         = 0x0d;
	const FIELD_TYPE_NEWDATE      = 0x0e;
	const FIELD_TYPE_VARCHAR      = 0x0f;
	const FIELD_TYPE_BIT          = 0x10;
	const FIELD_TYPE_NEWDECIMAL   = 0xf6;
	const FIELD_TYPE_ENUM         = 0xf7;
	const FIELD_TYPE_SET          = 0xf8;
	const FIELD_TYPE_TINY_BLOB    = 0xf9;
	const FIELD_TYPE_MEDIUM_BLOB  = 0xfa;
	const FIELD_TYPE_LONG_BLOB    = 0xfb;
	const FIELD_TYPE_BLOB         = 0xfc;
	const FIELD_TYPE_VAR_STRING   = 0xfd;
	const FIELD_TYPE_STRING       = 0xfe;
	const FIELD_TYPE_GEOMETRY     = 0xff;

	const NOT_NULL_FLAG           = 0x1;
	const PRI_KEY_FLAG            = 0x2;
	const UNIQUE_KEY_FLAG         = 0x4;
	const MULTIPLE_KEY_FLAG       = 0x8;
	const BLOB_FLAG               = 0x10;
	const UNSIGNED_FLAG           = 0x20;
	const ZEROFILL_FLAG           = 0x40;
	const BINARY_FLAG             = 0x80;
	const ENUM_FLAG               = 0x100;
	const AUTO_INCREMENT_FLAG     = 0x200;
	const TIMESTAMP_FLAG          = 0x400;
	const SET_FLAG                = 0x800;
	
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'server'               =>  'tcp://root@127.0.0.1/',
			'port'					=> 3306,
			'maxconnperserv'		=> 32,
		];
	}
	
	
	/**
	 * Escapes the special symbols with trailing backslash
	 * @return string
	 */
	public static function escape($string) {
		static $sqlescape = [
			"\x00"  => '\0',
			"\n"	=> '\n',
			"\r"	=> '\r',
			'\\'	=> '\\\\',
			'\''	=> '\\\'',
			'"'		=> '\\"'
		];
		
		return strtr($string, $sqlescape);
	}
	
	/**
	 * Escapes the special symbols with a trailing backslash
	 * @return string
	 */
	public static function likeEscape($string) {
		static $sqlescape = [
			"\x00"	=> '\0',
			"\n"	=> '\n',
			"\r"	=> '\r',
			'\\'	=> '\\\\',
			'\''	=> '\\\'',
			'"'	=> '\\"',
			'%'	=> '\%',
			'_'	=> '\_'
		];

		return strtr($string, $sqlescape);
	}
}
