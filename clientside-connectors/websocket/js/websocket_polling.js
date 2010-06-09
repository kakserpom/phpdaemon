WebSocket = function(url, protocol, proxyHost, proxyPort, headers) {

     var self = this;
     var connection, _ID, _TIME = 0;
     var autoId = 1;

    this.readyState     = 3;
    this.bufferedAmount = 0;

    this.onmessage = function(e){};
    this.onopen = function(){};
    this.onclose = function(){};






    /**
     * Send packet to server
     */
    this.send = function(data) {
      if(!_ID)return;
      
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
      message.type = 'text';
      message.value = data; 
      message.name  = 'data';
      form.appendChild(message);
      
      var id = document.createElement('input');
      id.type = 'text';
      id.value = _ID; 
      id.name  = '_id';
      form.appendChild(id);
      
      if(iframe.window){
    	  iframe.window.document.write("<html><body></body></html>");
    	  iframe.window.document.body.appendChild(form);
      }else if(iframe.contentWindow){
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
    	if(connection){
          this.readyState = 2;
          document.body.removeChild(connection);
          connection = false;
          this.readyState = 3;
          this.onclose();
    	}
        
    };


    /*
    Кодирование данных (простого ассоциативного массива вида { name : value, ...} в
    URL-escaped строку (кодировка UTF-8)
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
      Создание XMLHttpRequest-объекта
      Возвращает созданный объект или null, если XMLHttpRequest не поддерживается
      */
      var createRequestObject = function() {
          var request = null;
          try {
              request = new ActiveXObject('Msxml2.XMLHTTP');
          } catch (e){}

          if(!request){
            try {
              request=new ActiveXObject('Microsoft.XMLHTTP');
            } catch (e){}
          }
          if(!request){
            try {
              request=new XMLHttpRequest();
            } catch (e){}
          }
          return request;
      };
      
      
      
      /*
       * Send request to server
       */
      var $q = function(callback) {

           	var qid = Math.random().toString();
           	qid = qid.substr(3,5);
           	var respname = 'Response'+qid;
           	var reader = document.createElement('script');
           	    reader.setAttribute('charset',     'utf-8');           	     
           	    reader.setAttribute('src', url+'&_script=1&_poll=1'+(!_ID ? '&_init=1' : '&_id='+_ID)+'&q='+qid+'&ts='+_TIME);
           	var c = document.head || document.body;
                c.appendChild(reader);
               if (callback) {
               var __TIMER = 0;
               var __INTERVAL = 50;
               var interval = setInterval(function() {
           				if (eval("typeof " + respname) != 'undefined') {
           				var response = eval(respname);
                           clearInterval(interval);
           				callback(response);
           				c.removeChild(reader);
           				
           			    }else if(__TIMER >= 15000){
           			     clearInterval(interval);
           			      $q(resp);
           			    }
           				__TIMER += __INTERVAL;
           		}, __INTERVAL);
           	}

       };
       
           
   
     /**
      * Server Response
      */
     var resp = function(response){
     	
     	if(!response)alert('Error packet');
      
     	 if(!_ID){
     		_ID = response.id; 
     		 self.readyState = 1;
             self.onopen();
     	 }else{
     		
     		  for(var i = 0; i< response.packets.length; i++){
                   var msg = {data : response.packets[i][1]};
                   _TIME = response.packets[i][2];
                   self.onmessage(msg);
                  }
     		 
     	 }
     	$q(resp);
     };



     var  init = function() {

       this.readyState = 0;        
        $q(resp);

    };

    init();
};
WebSocketServicePrivider = 'polling';