<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_ConfigParse
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Config parser class.
/**************************************************************************/

class Daemon_ConfigParser {

	public $file;
	public $line = 1;
	public $col = 1;
	public $p = 0;
	public $state = array();
	const T_ALL = 1;
	const T_COMMENT = 2;
	const T_VAR = 3;
	const T_STRING = 4;
	const T_BLOCK = 5;
	const T_CVALUE = 5;
	public $result;
	public $errorneus = FALSE;
	/**
	 * @method __construct
	 * @description Constructor
	 * @return void
	 */
	public function __construct($file)
	{
	 $cfg = $this;
	 $cfg->file = $file;
	 $cfg->result = new stdClass;
	 $cfg->data = file_get_contents($file);
	 $cfg->data = str_replace("\r",'',$cfg->data);
	 $cfg->len = strlen($cfg->data);
	 $cfg->state[] = array(self::T_ALL,$cfg->result);
	 $cfg->tokens = array(
	  self::T_COMMENT => function($cfg,$c)
	  {
	   if ($c == "\n") {
	    array_pop($cfg->state);
	   }
	  },
	  self::T_STRING => function($cfg,$q)
	  {
	   $str = '';
	   ++$cfg->p;
	   for (;$cfg->p < $cfg->len;++$cfg->p)
	   {
	    $c = $cfg->getCurrentChar();
	    if ($c == $q)
	    {
	     ++$cfg->p;
	     break;
	    }
	    elseif ($c == '\\')
	    {
	     if ($cfg->getNextChar() == $q)
	     {
	      $str .= $q;
	      ++$cfg->p;
	     }
	    }
	    else
	    {
	     $str .= $c;
	    }
	   }
	   if ($cfg->p >= $cfg->len) {
			$cfg->raiseError('Unexpected End-Of-File.');
		 }
	   return $str;
	  },
	  self::T_ALL => function($cfg,$c)
	  {
	   if (ctype_space($c)) {
	    
	   }	  
	   elseif ($c == '#') {
	    $cfg->state[] = array(Daemon_ConfigParser::T_COMMENT);
	   }
	   elseif ($c == '}') {
			if (sizeof($cfg->state) > 1) {
				array_pop($cfg->state);
			}
			else {
				$cfg->raiseError('Unexpected \'}\'');
			}
	   }
	   elseif (ctype_alnum($c)) {
	    $elements = array('');
	    $elTypes = array(NULL);
	    $i = 0;
	    $tokenType = 0;
	    for (;$cfg->p < $cfg->len;++$cfg->p)
	    {
				$c = $cfg->getCurrentChar();
				if ($c === "\n") {
				  continue;
				}
				if (ctype_space($c)) {
					++$i;
					$elTypes[$i] = NULL;
				}
				elseif (($c == '"') || ($c == '\''))
				{
				 if ($elTypes[$i] != NULL)	 {
						$cfg->raiseError('Unexpected T_STRING.');
				 }
				 $string = call_user_func($cfg->tokens[Daemon_ConfigParser::T_STRING],$cfg,$c);
				 --$cfg->p;
				 if ($elTypes[$i] == NULL)	 {
						$elements[$i] = $string;
						$elTypes[$i] = Daemon_ConfigParser::T_STRING;
					}
				}
				elseif ($c === ';') {
				 $tokenType = Daemon_ConfigParser::T_VAR;
				 break;
				}
				elseif ($c === '{') {
				 $tokenType = Daemon_ConfigParser::T_BLOCK;
				 break;
				}
				else {
				 if ($elTypes[$i] == Daemon_ConfigParser::T_STRING)	 {
						$cfg->raiseError('Unexpected T_CVALUE.');
				 }
				 else {
				    if (!isset($elements[$i])) {$elements[$i] = '';}
						$elements[$i] .= $c;
						$elTypes[$i] = Daemon_ConfigParser::T_CVALUE;
					}
				}
	    }
	    foreach ($elTypes as $k => $v) {
				if (Daemon_ConfigParser::T_CVALUE == $v)
				{
					if (ctype_digit($elements[$k])) {
						$elements[$k] = (int) $elements[$k];
					}
					elseif (is_numeric($elements[$k])) {
						$elements[$k] = (float) $elements[$k];
					}
					else
					{
						$l = strtolower($elements[$k]);
						if ($l == 'true') {
							$elements[$k] = 1;
						}
						elseif ($l == 'false') {
							$elements[$k] = 0;
						}
					}
				}
			}
			if ($tokenType == 0) {
				$cfg->raiseError('Expected \';\' or \'{\''); 
			}
			elseif ($tokenType == Daemon_ConfigParser::T_VAR) {
				$name = str_replace('-','',strtolower($elements[0]));
    	  $cfg->getCurrentScope()->{$name} = isset($elements[1])?$elements[1]:1;
			}
			elseif ($tokenType == Daemon_ConfigParser::T_BLOCK) {;
				$cfg->state[] = array(Daemon_ConfigParser::T_ALL,$cfg->getCurrentScope()->{implode('-',$elements)} = new stdClass);
			}
	   }
	   else {
	    $cfg->raiseError('Unexpected char \''.Daemon::exportBytes($c).'\'');
	   }
	  }
	 );
	 
	 for (;$cfg->p < $cfg->len;++$cfg->p)
	 {
	  $c = $cfg->getCurrentChar();
	  $e = end($this->state);
	  $cfg->token($e[0],$c);
	 }
	}
	/**
	 * @method getCurrentScope
	 * @description Returns current variable scope
	 * @return object Scope.
	 */
	public function getCurrentScope()
	{
	 $e = end($this->state);
	 return $e[1];
	}
	/**
	 * @method raiseError
	 * @description Raises error message.
	 * @param string Message.
	 * @param string Level.
	 * @return void
	 */
	public function raiseError($msg,$level = 'emerg')
	{
	 if ($level == 'emerg') {
	  $this->errorneus = TRUE;
	 }
	 Daemon::log('[conf#'.$level.']['.$this->file.' L:'.$this->line.' C: '.($this->col-1).'] '.$msg);
	}
	/**
	 * @method token
	 * @description executes token-parse callback.
	 * @return void
	 */
	public function token($token,$c) {
		call_user_func($this->tokens[$token],$this,$c);
	}
	/**
	 * @method getCurrentChar
	 * @description Returns current character.
	 * @return string Character.
	 */
	public function getCurrentChar() {
		$c = substr($this->data,$this->p,1);
		if ($c == "\n") {
			++$this->line;
			$this->col = 1;
		}
		else {
			++$this->col;
		}
		return $c;
	}
	/**
	 * @method getNextChar
	 * @description Returns next character.
	 * @return string Character.
	 */
	public function getNextChar()
	{
	 return substr($this->data,$this->p+1,1);
	}
	/**
	 * @method rewind
	 * @description Rewinds the pointer back.
	 * @param integer Number of characters to rewind back.
	 * @return void
	 */
  public function rewind($n)
	{
	 $this->p -= $n;
	}
}
