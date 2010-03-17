<?php
class Terminal
{
 public $enable_color = FALSE;
 public function readln()
 {
  return fgets(STDIN);
 }
 public function clearScreen() {echo "\x0c";}
 public function setStyle($c)
 {
  if ($this->enable_color) {echo "\033[".$c.'m';}
 }
 public function resetStyle()
 {
  if ($this->enable_color) {echo "\033[0m";}
 }
 public function drawTable($rows)
 {
  $pad = array();
  foreach ($rows as $row)
  {
   foreach ($row as $k => $v)
   {
    if (substr($k,0,1) == '_') {continue;}
    if (!isset($pad[$k]) || (strlen($v) > $pad[$k])) {$pad[$k] = strlen($v);}
   }
  }
  foreach ($rows as $row)
  {
   if (isset($row['_color'])) {$this->setStyle($row['_color']);}
   if (isset($row['_bold'])) {$this->setStyle('1');}
   if (isset($row['_'])) {echo $row['_'];}
   else
   {
    $i = 0;
    foreach ($row as $k => $v)
    {
     if (substr($k,0,1) == '_') {continue;}
     if ($i > 0) {echo "\t";}
     echo str_pad($v,$pad[$k]);
     ++$i;
    }
   }
   $this->resetStyle();
   echo "\n";
  }
 }
}
