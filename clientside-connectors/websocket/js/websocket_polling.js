WebSocket = function(url, protocol, proxyHost, proxyPort, headers) {
	var self = this;
	var reader, _ID;
	var _TIME = 0;
	var container, interval;
	var autoId = 1;

	this.readyState     = 0;
	this.bufferedAmount = 0;

	this.onmessage = function(e) {};
	this.onopen    = function() {};
	this.onclose   = function() {};


	if (!window.console) console = {
		log: function() { }, 
		error: function() { }
	};

	var readerIframe = document.createElement('iframe');

	with (readerIframe.style) {
		left       = top   = "-100px";
		height     = width = "1px";
		visibility = "hidden";
		position   = 'absolute';
		display    = 'none';
	}

	document.body.appendChild(readerIframe);
	
	var readerIframeWindow;

	if(readerIframe.window) {
		readerIframeWindow = readerIframe.window;
	} else if (readerIframe.contentWindow){
		readerIframeWindow = readerIframe.contentWindow;
	}

	readerIframeWindow.document.write("<html><body></body></html>");
	container = readerIframeWindow.document.body;

	/**
	 * Send packet to server
	 */
	this.send = function(data) {
		if (!_ID)
			return;

		console.log('[WebSocket] send: ' + data);

		var iframe = document.createElement('iframe');

		var name = 'WebSocket_iframe_write' + autoId++;

		iframe.setAttribute('id',     name);
		iframe.setAttribute('name',   name);

		with (iframe.style) {
			left       = top   = "-100px";
			height     = width = "1px";
			visibility = "hidden";
			position   = 'absolute';
			display    = 'none';
		}

		document.body.appendChild(iframe);

		var form = document.createElement('form');
		form.action = url;
		form.method = 'POST';

		var message = document.createElement('input');
		message.type = 'hidden';
		message.value = data; 
		message.name  = 'data';
		form.appendChild(message);

		var id = document.createElement('input');
		id.type = 'hidden';
		id.value = _ID; 
		id.name  = '_id';
		form.appendChild(id);

		var poll = document.createElement('input');
		poll.type = 'hidden';
		poll.value = 1; 
		poll.name  = '_poll';
		form.appendChild(poll);

		if(iframe.window){
			iframe.window.document.write("<html><body></body></html>");
			iframe.window.document.body.appendChild(form);
		} else if(iframe.contentWindow) {
			iframe.contentWindow.window.document.write("<html><body></body></html>");
			iframe.contentWindow.window.document.body.appendChild(form);
		}

		form.submit();
		
		iframe.onload = function(){    	  
			document.body.removeChild(iframe);  
		};
	};

	/**
	 * Close connection
	 */
	this.close = function(){
		if(reader){
			this.readyState = 2;
			clear(); 
			this.readyState = 3;
			this.onclose();
		}
	};

	/**
	 * Encoding the associative array { name : value, ...} into urlescaped string in utf-8
	 */
	function urlEncodeData(data) {
		var query = [];

		if (data instanceof Object) {
			for (var k in data) {
				query.push(encodeURIComponent(k) + "=" + encodeURIComponent(data[k]));
			}

			return query.join('&');
		} else {
			return encodeURIComponent(data);
		}
	};

	/*
	 * Creating the XMLHttpRequest object
	 * Returns object or null is XMLHttpRequest is not supported
	 */
	var createRequestObject = function() {
		var request = null;

		try {
			request = new ActiveXObject('Msxml2.XMLHTTP');
		} catch (e) {}

		if (!request) {
			try {
				request = new ActiveXObject('Microsoft.XMLHTTP');
			} catch (e) {}
		}

		if (!request) {
			try {
				request = new XMLHttpRequest();
			} catch (e) {}
		}

		return request;
	};

	var clear = function(){
		if (interval) {
			clearInterval(interval); 
		}

		if (reader) {
			container.removeChild(reader);
			reader = false;    		 
		}
	};

	/*
	 * Send request to server
	 */
	var $q = function(callback) {
		var qid = Math.random().toString();

		qid = qid.substr(3,5);

		var respname = 'Response'+qid;
		var loaded = false;               	

		if (reader) {
			container.removeChild(reader);
			reader = false;
		}

		reader = document.createElement('script');
		
		reader.setAttribute('charset', 'utf-8');           	     
		reader.setAttribute('src',     url + '&_script=1&_poll=1' + (!_ID ? '&_init=1' : '&_id=' + _ID) + '&q=' + qid + '&ts=' + _TIME);
		reader.onload = function() {
			loaded = true;
		};

		container.appendChild(reader);

		if (callback) {
			var __TIMER = 0;
			var __INTERVAL = 10;
			var __WAIT_TIME = 10000;

			interval = setInterval(function() {
				if (typeof(readerIframeWindow[respname]) != 'undefined') {
					clear();           					
					console.log('[WebSocket] received: ' + respname);
					var response = readerIframeWindow[respname];                            
					callback(response);
					$q(resp);
				} else if (__TIMER >= __WAIT_TIME) {
					clear();            			      
					$q(resp);
				} else if (loaded) {
					self.close();
				}

				__TIMER += __INTERVAL;
			}, __INTERVAL);
		}
	};

	/**
	 * Server Response
	 */
	var resp = function(response) {
		if (!response)
			alert('Error packet');

		if (!_ID) {
			_ID = response.id; 
		
			self.readyState = 1;
			self.onopen();
		} else {
			for (var i = 0; i < response.packets.length; i++) {
				var msg = {
					data : response.packets[i][1]
				};

				_TIME = response.packets[i][2];
				
				self.onmessage(msg);
			}
		}     	
	};

	var init = function() {
		this.readyState = 0;        
		$q(resp);
	};

	init();
};

WebSocketServicePrivider = 'polling';
