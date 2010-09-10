<?php
if (ini_get('mbstring.func_overload') >= 2)
{
 function binarySubstr($s,$p,$l = 0xFFFFFFF)
 {
  return substr($s,$p,$l,'ASCII');
 }
}
else
{
 function binarySubstr($s,$p,$l = NULL)
 {
  if ($l === NULL) {$ret = substr($s,$p);}
  else {$ret = substr($s,$p,$l);}
  if ($ret === FALSE) {$ret = '';}
  return $ret;
 }
}
class DestructableLambda
{
 public $id;
 public $hits = 0;
 public function __construct($id) {$this->id = (int) binarySubstr($id,8);}
 public function __invoke() {return call_user_func_array("\x00lambda_".$this->id,func_get_args());}
 public function __destruct() {runkit_function_remove("\x00lambda_".$this->id);}
}
