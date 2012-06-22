<?php
/* .. and here */

$base = event_base_new();
$event = event_new();
$fd = eio_get_event_stream();
eio_nop(EIO_PRI_DEFAULT, function($d,$r) {var_dump($d);}, "nop data2");
// set event flags
event_set($event, $fd, EV_READ , function($fd, $events, $arg) {
	if (eio_nreqs()) {
			eio_poll();
	}

}, array($event, $base));
event_base_set($event, $base);
event_add($event);
event_base_loop($base);
