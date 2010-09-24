WebSocket = function(url, protocol, proxyHost, proxyPort, headers) {
	var self = this;
	var connection, iframediv, _ID;

	this.readyState     = 3;
	this.bufferedAmount = 0;

	this.onmessage = function(e) {};
	this.onopen    = function() {};
	this.onclose   = function() {};

	/**
	 * Send request to  server
	 */
	this.send = function(data) {
		var request = createRequestObject();

		if(!request)
			return false;

		request.onreadystatechange  = function() {};
		request.open('POST', url, true);

		if (typeof(request.setRequestHeader) =='function') {
			request.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
		}

		request.send(urlEncodeData({_id: _ID, 'data': data}));

		return true;
	};

	/**
	 * Close connection
	 */
	this.close = function() {
		if (connection) {
			this.readyState = 2;
			document.body.removeChild(connection);
			connection = false;
			this.readyState = 3;
			this.onclose();
		}
	};

	/**
	 * Data encoding (associative array { name : value, ...} into urlencoded string (utf-8)
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

	/**
	 * Creating XMLHttpRequest object
	 * Returns object or null if XMLHttpRequest is not supported
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
			} catch (e){}
		}

		return request;
	};

	/**
	 * Connection to server
	 */
	var  init = function() {
		this.readyState = 0;

		connection = document.createElement('iframe');
		connection.setAttribute('id', 'WebSocket_iframe');

		with (connection.style) {
			left       = top   = "-100px";
			height     = width = "1px";
			visibility = "hidden";
			position   = 'absolute';
			display    = 'none';
		}

		document.body.appendChild(connection);
         
		if (connection.window) {
			connection.window.document.write("<html><body></body></html>");
			var win = connection.window;
		} else if(connection.contentWindow) {
			connection.contentWindow.window.document.write("<html><body></body></html>");
			var win = connection.contentWindow.window;
		}

		iframediv = document.createElement('iframe');   
		iframediv.setAttribute('src', url + '&_pull=1');
		iframediv.onload = function() {
			self.close();
		};

		var ws = {
			onopen: function(id) {
				_ID = id;

				self.readyState = 1;
				self.onopen();
			},
			onmessage: function (data) {
				var msg = {data : data};
				self.onmessage(msg);
			}
		};

		win.document.body.appendChild(iframediv);

		if (iframediv.window) {
			iframediv.window.WebSocket = ws;
		} else if (iframediv.contentWindow) {
			iframediv.contentWindow.window.WebSocket = ws;
		}
	};

	init();
};

WebSocketServicePrivider = 'comet';
